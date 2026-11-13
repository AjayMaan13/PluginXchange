using PluginXchange.Client;

namespace PluginXchange.Cli;

/// <summary>Quick end-to-end check used by the release pipeline: health, list, place one order, fetch it.</summary>
internal static class Smoke
{
    public static async Task<int> RunAsync(PluginXchangeClient client, int? productId)
    {
        var failed = false;

        void Step(string name, bool ok, string detail)
        {
            Console.WriteLine($"{(ok ? "PASS" : "FAIL")}  {name}: {detail}");
            failed |= !ok;
        }

        var health = await client.GetHealthAsync();
        Step("health", health.Status == "ok" && health.Db == "up", $"status={health.Status} db={health.Db}");

        var page = await client.ListProductsAsync(perPage: 100);
        Step("list products", page.Items.Count > 0, $"{page.Items.Count} on page 1, {page.Meta.Total} total");
        if (page.Items.Count == 0)
        {
            return 1;
        }

        Product? product = null;
        foreach (var candidate in productId is { } id ? [await client.GetProductAsync(id)] : page.Items)
        {
            var detail = await client.GetProductAsync(candidate.Id);
            if (detail.AvailableKeys > 0)
            {
                product = detail;
                break;
            }
        }

        Step("find product with stock", product is not null, product is null ? "none has keys" : $"#{product.Id} {product.Sku}");
        if (product is null)
        {
            return 1;
        }

        var order = await client.PlaceOrderAsync(product.Id, 1);
        Step("place order", order.Status == "fulfilled" && order.Keys.Count == 1, $"#{order.Id} {order.Status}, {order.Keys.Count} key");

        var fetched = await client.GetOrderAsync(order.Id);
        Step("fetch order", fetched.Id == order.Id && fetched.Keys.SequenceEqual(order.Keys), $"#{fetched.Id} has the same key");

        Console.WriteLine(failed ? "SMOKE FAILED" : "SMOKE OK");

        return failed ? 1 : 0;
    }
}
