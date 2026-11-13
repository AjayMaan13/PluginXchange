using System.Net;
using System.Net.Http.Json;
using System.Text.Json;

namespace PluginXchange.Client;

public sealed class ClientOptions
{
    public required string BaseUrl { get; init; }

    public required string ApiKey { get; init; }

    /// <summary>How many times a transient failure (network error, 502/503/504) is retried.</summary>
    public int MaxRetries { get; init; } = 2;

    /// <summary>Wait before retry n is RetryDelay * n.</summary>
    public TimeSpan RetryDelay { get; init; } = TimeSpan.FromMilliseconds(200);
}

/// <summary>Typed wrapper over the PluginXchange HTTP API.</summary>
public sealed class PluginXchangeClient
{
    internal static readonly JsonSerializerOptions Json = new(JsonSerializerDefaults.Web)
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.WhenWritingNull,
    };

    private readonly HttpClient _http;
    private readonly ClientOptions _options;

    public PluginXchangeClient(HttpClient http, ClientOptions options)
    {
        _http = http;
        _options = options;
        _http.BaseAddress ??= new Uri(options.BaseUrl);
        _http.DefaultRequestHeaders.Remove("X-API-Key");
        _http.DefaultRequestHeaders.Add("X-API-Key", options.ApiKey);
    }

    public Task<Health> GetHealthAsync(CancellationToken ct = default) =>
        SendAsync<Health>(() => new HttpRequestMessage(HttpMethod.Get, "/health"), ct);

    public async Task<Product> GetProductAsync(int id, CancellationToken ct = default) =>
        (await SendAsync<Envelope<Product>>(() => new HttpRequestMessage(HttpMethod.Get, $"/api/v1/products/{id}"), ct))
            .Data;

    public async Task<ProductPage> ListProductsAsync(int page = 1, int perPage = 20, CancellationToken ct = default)
    {
        var list = await SendAsync<ListEnvelope<Product>>(
            () => new HttpRequestMessage(HttpMethod.Get, $"/api/v1/products?page={page}&per_page={perPage}"),
            ct);

        return new ProductPage(list.Data, list.Meta);
    }

    /// <summary>
    /// Places an order. The same idempotency key is sent on every retry, so a retried request can never buy twice.
    /// A key is generated when none is given.
    /// </summary>
    public async Task<Order> PlaceOrderAsync(
        int productId,
        int quantity,
        string? idempotencyKey = null,
        CancellationToken ct = default)
    {
        var body = new OrderRequest(productId, quantity, idempotencyKey ?? Guid.NewGuid().ToString("N"));

        return (await SendAsync<Envelope<Order>>(
            () => new HttpRequestMessage(HttpMethod.Post, "/api/v1/orders") { Content = JsonContent.Create(body, options: Json) },
            ct)).Data;
    }

    public async Task<Order> GetOrderAsync(long id, CancellationToken ct = default) =>
        (await SendAsync<Envelope<Order>>(() => new HttpRequestMessage(HttpMethod.Get, $"/api/v1/orders/{id}"), ct)).Data;

    /// <summary>Admin only: the order, its keys' status and every audit event.</summary>
    public async Task<OrderTrace> GetTraceAsync(long orderId, CancellationToken ct = default) =>
        (await SendAsync<Envelope<OrderTrace>>(
            () => new HttpRequestMessage(HttpMethod.Get, $"/api/v1/support/orders/{orderId}/trace"),
            ct)).Data;

    /// <summary>Admin only: bulk-load a vendor's catalog.</summary>
    public async Task<ImportResult> ImportCatalogAsync(
        string vendor,
        IReadOnlyList<ImportProduct> products,
        CancellationToken ct = default)
    {
        var body = new ImportRequest(vendor, products);

        return (await SendAsync<Envelope<ImportResult>>(
            () => new HttpRequestMessage(HttpMethod.Post, "/api/v1/catalog/import") { Content = JsonContent.Create(body, options: Json) },
            ct)).Data;
    }

    private async Task<T> SendAsync<T>(Func<HttpRequestMessage> build, CancellationToken ct)
    {
        for (var attempt = 0; ; attempt++)
        {
            var canRetry = attempt < _options.MaxRetries;
            try
            {
                // A request message can only be sent once, so each attempt builds a fresh one
                using var request = build();
                using var response = await _http.SendAsync(request, ct);
                if (canRetry && IsTransient(response.StatusCode))
                {
                    await Task.Delay(_options.RetryDelay * (attempt + 1), ct);
                    continue;
                }

                return await ReadAsync<T>(response, ct);
            }
            catch (HttpRequestException) when (canRetry)
            {
                await Task.Delay(_options.RetryDelay * (attempt + 1), ct);
            }
            catch (TaskCanceledException) when (canRetry && !ct.IsCancellationRequested)
            {
                // HttpClient timeout, not a caller cancellation
                await Task.Delay(_options.RetryDelay * (attempt + 1), ct);
            }
        }
    }

    private static bool IsTransient(HttpStatusCode status) =>
        status is HttpStatusCode.BadGateway or HttpStatusCode.ServiceUnavailable or HttpStatusCode.GatewayTimeout;

    private static async Task<T> ReadAsync<T>(HttpResponseMessage response, CancellationToken ct)
    {
        var text = await response.Content.ReadAsStringAsync(ct);
        if (response.IsSuccessStatusCode)
        {
            return JsonSerializer.Deserialize<T>(text, Json)
                ?? throw new InvalidOperationException("The API returned an empty body");
        }

        throw ToException(response.StatusCode, text);
    }

    internal static ApiException ToException(HttpStatusCode status, string body)
    {
        ErrorBody? error = null;
        try
        {
            error = JsonSerializer.Deserialize<ErrorEnvelope>(body, Json)?.Error;
        }
        catch (JsonException)
        {
            // not the API's error format, for example an HTML page from nginx
        }

        var code = error?.Code ?? $"HTTP_{(int)status}";
        var message = error?.Message ?? $"Unexpected response ({(int)status})";
        var requestId = error?.RequestId;

        return (int)status switch
        {
            401 => new UnauthorizedApiException(code, message, requestId),
            409 => new ConflictApiException(code, message, requestId),
            422 => new ValidationApiException(code, message, requestId),
            _ => new ApiException(status, code, message, requestId),
        };
    }
}
