using PluginXchange.Cli;
using PluginXchange.Client;

const string Usage = """
    Usage: pxc <command> [options]

    Commands:
      loadtest   fire parallel orders and check no key is sold twice
                 --orders 50 --qty 3 --runs 10 [--product ID | --keys 100]
      smoke      health, list products, place and fetch one order [--product ID]

    Common options (or environment variables):
      --base-url  PXC_BASE_URL   default http://localhost:8080
      --api-key   PXC_API_KEY    reseller key, default is the local seed key
      --admin-key PXC_ADMIN_KEY  admin key (loadtest creates its own product), default is the local key
    """;

if (args.Length == 0 || args[0] is "-h" or "--help")
{
    Console.WriteLine(Usage);
    return args.Length == 0 ? 2 : 0;
}

Dictionary<string, string> options;
try
{
    options = Args.Parse(args.Skip(1).ToArray());
}
catch (ArgumentException e)
{
    Console.Error.WriteLine(e.Message);
    Console.Error.WriteLine(Usage);
    return 2;
}

var baseUrl = Args.Get(options, "base-url", "PXC_BASE_URL", "http://localhost:8080");
var apiKey = Args.Get(options, "api-key", "PXC_API_KEY", "pxc_local_reseller_1_key");
var adminKey = Args.Get(options, "admin-key", "PXC_ADMIN_KEY", "pxc_local_admin_key");

using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(60) };
using var adminHttp = new HttpClient { Timeout = TimeSpan.FromSeconds(60) };
var reseller = new PluginXchangeClient(http, new ClientOptions { BaseUrl = baseUrl, ApiKey = apiKey });
var admin = new PluginXchangeClient(adminHttp, new ClientOptions { BaseUrl = baseUrl, ApiKey = adminKey });

try
{
    switch (args[0])
    {
        case "loadtest":
            var config = new LoadTestConfig(
                Orders: Args.GetInt(options, "orders", 50),
                Quantity: Args.GetInt(options, "qty", 3),
                Runs: Args.GetInt(options, "runs", 10),
                Product: options.ContainsKey("product") ? Args.GetInt(options, "product", 0) : null,
                Keys: Args.GetInt(options, "keys", 100));
            return await LoadTest.RunAsync(reseller, admin, config);

        case "smoke":
            return await Smoke.RunAsync(reseller, options.ContainsKey("product") ? Args.GetInt(options, "product", 0) : null);

        default:
            Console.Error.WriteLine($"Unknown command '{args[0]}'");
            Console.Error.WriteLine(Usage);
            return 2;
    }
}
catch (Exception e) when (e is ApiException or HttpRequestException or TaskCanceledException or InvalidOperationException)
{
    Console.Error.WriteLine($"FAILED: {e.Message}");
    return 1;
}
