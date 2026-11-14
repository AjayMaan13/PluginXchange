using System.Diagnostics;
using PluginXchange.Client;

namespace PluginXchange.Cli;

internal sealed record LoadTestConfig(int Orders, int Quantity, int Runs, int? Product, int Keys);

/// <summary>
/// Fires N orders at once at one product and checks what the API handed out: no key twice, nothing oversold,
/// every request accounted for, and an idempotent replay returns the same keys.
/// Without --product, each run creates its own product with --keys fresh keys (through the admin import).
/// The database side is checked afterwards with `make integrity`.
/// </summary>
internal static class LoadTest
{
    private sealed record Outcome(string IdempotencyKey, Order? Order, bool OutOfStock, string? Error, double Millis);

    private sealed record RunResult(
        int Run,
        int ProductId,
        int KeysBefore,
        int Fulfilled,
        int Failed,
        int Errors,
        int Duplicates,
        double P50,
        double P95,
        string Storm,
        List<string> Violations);

    public static async Task<int> RunAsync(PluginXchangeClient reseller, PluginXchangeClient admin, LoadTestConfig config)
    {
        Console.WriteLine(
            $"Load test: {config.Runs} run(s) x {config.Orders} parallel orders, quantity {config.Quantity}"
            + (config.Product is { } p ? $", product #{p}" : $", a fresh product with {config.Keys} keys per run"));
        Console.WriteLine();
        Console.WriteLine("run  product  keys  orders  fulfilled  failed  errors  dup_keys  p50_ms  p95_ms  storm  result");

        var results = new List<RunResult>();
        var latencies = new List<double>();
        for (var run = 1; run <= config.Runs; run++)
        {
            var (result, runLatencies) = await OneRunAsync(reseller, admin, config, run);
            results.Add(result);
            latencies.AddRange(runLatencies);

            Console.WriteLine(
                $"{run,3}  {result.ProductId,7}  {result.KeysBefore,4}  {config.Orders,6}  {result.Fulfilled,9}  "
                + $"{result.Failed,6}  {result.Errors,6}  {result.Duplicates,8}  {result.P50,6:F0}  {result.P95,6:F0}  {result.Storm,5}  "
                + (result.Violations.Count == 0 ? "ok" : "VIOLATION"));
            foreach (var violation in result.Violations)
            {
                Console.WriteLine($"       -> {violation}");
            }
        }

        var violations = results.Sum(r => r.Violations.Count);
        Console.WriteLine();
        Console.WriteLine(
            $"Total: {results.Sum(r => r.Fulfilled)} fulfilled, {results.Sum(r => r.Failed)} failed (out of stock), "
            + $"{results.Sum(r => r.Errors)} errors, {results.Sum(r => r.Duplicates)} duplicate keys, "
            + $"p50 {Percentile(latencies, 50):F0} ms, p95 {Percentile(latencies, 95):F0} ms");
        Console.WriteLine(violations == 0
            ? "LOAD TEST OK. Now check the database side: make integrity"
            : $"LOAD TEST FAILED: {violations} violation(s)");

        return violations == 0 ? 0 : 1;
    }

    private static async Task<(RunResult Result, List<double> Latencies)> OneRunAsync(
        PluginXchangeClient reseller,
        PluginXchangeClient admin,
        LoadTestConfig config,
        int run)
    {
        var productId = config.Product ?? await Provision.CreateProductAsync(admin, config.Keys);
        var tag = Guid.NewGuid().ToString("N")[..8];
        var violations = new List<string>();

        // First the storm: the same idempotency key sent 10 times at once must make exactly one order
        var storm = "skip";
        var stock = (await reseller.GetProductAsync(productId)).AvailableKeys ?? 0;
        if (stock >= config.Quantity)
        {
            var stormViolations = await StormAsync(reseller, productId, config.Quantity, $"lt-{tag}-storm", stock);
            storm = stormViolations.Count == 0 ? "ok" : "FAIL";
            violations.AddRange(stormViolations);
        }

        var before = (await reseller.GetProductAsync(productId)).AvailableKeys ?? 0;

        var outcomes = await Task.WhenAll(Enumerable.Range(0, config.Orders)
            .Select(i => PlaceAsync(reseller, productId, config.Quantity, $"lt-{tag}-{i}")));

        var fulfilled = outcomes.Where(o => o.Order is not null).ToList();
        var failed = outcomes.Count(o => o.OutOfStock);
        var errors = outcomes.Where(o => o.Error is not null).ToList();
        var keys = fulfilled.SelectMany(o => o.Order!.Keys).ToList();
        var duplicates = keys.Count - keys.Distinct().Count();
        var after = (await reseller.GetProductAsync(productId)).AvailableKeys ?? 0;

        if (errors.Count > 0)
        {
            violations.Add($"{errors.Count} request(s) errored, first: {errors[0].Error}");
        }

        if (fulfilled.Count + failed != config.Orders)
        {
            violations.Add($"fulfilled ({fulfilled.Count}) + failed ({failed}) != orders ({config.Orders})");
        }

        if (duplicates > 0)
        {
            violations.Add($"{duplicates} license key(s) were handed out more than once");
        }

        if (fulfilled.Any(o => o.Order!.Status != "fulfilled" || o.Order.Keys.Count != config.Quantity))
        {
            violations.Add($"a fulfilled order did not have exactly {config.Quantity} keys");
        }

        if (keys.Count > before)
        {
            violations.Add($"{keys.Count} keys handed out but only {before} were available");
        }

        if (after != before - keys.Count)
        {
            violations.Add($"stock is {after}, expected {before} - {keys.Count} = {before - keys.Count}");
        }

        if (failed > 0 && after >= config.Quantity)
        {
            violations.Add($"{failed} order(s) failed as out of stock although {after} keys were left");
        }

        if (fulfilled.Count > 0)
        {
            violations.AddRange(await CheckReplayAsync(reseller, productId, config.Quantity, fulfilled[0], after));
        }

        var millis = outcomes.Select(o => o.Millis).ToList();

        return (new RunResult(
            run, productId, before, fulfilled.Count, failed, errors.Count, duplicates,
            Percentile(millis, 50), Percentile(millis, 95), storm, violations), millis);
    }

    /// <summary>The same idempotency key, 10 requests at once: one order, same keys for everyone, stock down once.</summary>
    private static async Task<List<string>> StormAsync(
        PluginXchangeClient client,
        int productId,
        int quantity,
        string key,
        int stockBefore)
    {
        const int Parallel = 10;
        var outcomes = await Task.WhenAll(
            Enumerable.Range(0, Parallel).Select(_ => PlaceAsync(client, productId, quantity, key)));

        var violations = new List<string>();
        var orders = outcomes.Where(o => o.Order is not null).Select(o => o.Order!).ToList();
        if (orders.Count != Parallel)
        {
            var error = outcomes.FirstOrDefault(o => o.Order is null);
            violations.Add($"same-key storm: only {orders.Count} of {Parallel} requests succeeded"
                + (error?.Error is { } message ? $", first error: {message}" : " (out of stock)"));
        }

        if (orders.Select(o => o.Id).Distinct().Count() > 1)
        {
            violations.Add($"same-key storm created {orders.Select(o => o.Id).Distinct().Count()} different orders");
        }

        if (orders.Select(o => string.Join(',', o.Keys)).Distinct().Count() > 1)
        {
            violations.Add("same-key storm returned different keys for the same idempotency key");
        }

        var stockAfter = (await client.GetProductAsync(productId)).AvailableKeys ?? 0;
        if (stockAfter != stockBefore - quantity)
        {
            violations.Add($"same-key storm changed stock from {stockBefore} to {stockAfter}, expected {stockBefore - quantity}");
        }

        return violations;
    }

    /// <summary>Sends one finished order's idempotency key again: same order and keys back, no extra stock sold.</summary>
    private static async Task<List<string>> CheckReplayAsync(
        PluginXchangeClient client,
        int productId,
        int quantity,
        Outcome original,
        int stockBefore)
    {
        var violations = new List<string>();
        var replay = await client.PlaceOrderAsync(productId, quantity, original.IdempotencyKey);
        if (replay.Id != original.Order!.Id || !replay.Keys.SequenceEqual(original.Order.Keys))
        {
            violations.Add($"idempotent replay of '{original.IdempotencyKey}' did not return the same order and keys");
        }

        var stockAfter = (await client.GetProductAsync(productId)).AvailableKeys ?? 0;
        if (stockAfter != stockBefore)
        {
            violations.Add($"idempotent replay changed stock from {stockBefore} to {stockAfter}");
        }

        return violations;
    }

    private static async Task<Outcome> PlaceAsync(PluginXchangeClient client, int productId, int quantity, string key)
    {
        var watch = Stopwatch.StartNew();
        try
        {
            var order = await client.PlaceOrderAsync(productId, quantity, key);
            return new Outcome(key, order, false, null, watch.Elapsed.TotalMilliseconds);
        }
        catch (ConflictApiException e) when (e.Code == "INSUFFICIENT_STOCK")
        {
            return new Outcome(key, null, true, null, watch.Elapsed.TotalMilliseconds);
        }
        catch (Exception e) when (e is ApiException or HttpRequestException or TaskCanceledException)
        {
            return new Outcome(key, null, false, e.Message, watch.Elapsed.TotalMilliseconds);
        }
    }

    /// <summary>Nearest-rank percentile.</summary>
    internal static double Percentile(List<double> values, int percentile)
    {
        if (values.Count == 0)
        {
            return 0;
        }

        var sorted = values.Order().ToList();
        var rank = (int)Math.Ceiling(percentile / 100.0 * sorted.Count);

        return sorted[Math.Max(rank, 1) - 1];
    }
}
