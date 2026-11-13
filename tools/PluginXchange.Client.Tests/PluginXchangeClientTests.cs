using System.Net;
using System.Text.Json;
using Xunit;

namespace PluginXchange.Client.Tests;

public class PluginXchangeClientTests
{
    private const string OrderJson = """
        {"data":{"id":7,"status":"fulfilled","product_id":12,"quantity":2,"unit_price_cents":1999,
        "total_cents":3998,"keys":["K-1","K-2"],"failure_reason":null,"created_at":"2026-01-01 10:00:00"}}
        """;

    private static PluginXchangeClient ClientFor(FakeHandler handler, int maxRetries = 2) =>
        new(
            new HttpClient(handler),
            new ClientOptions
            {
                BaseUrl = "http://api.test",
                ApiKey = "test-key",
                MaxRetries = maxRetries,
                RetryDelay = TimeSpan.Zero,
            });

    [Fact]
    public async Task ParsesAFulfilledOrder()
    {
        var order = await ClientFor(new FakeHandler().Respond(HttpStatusCode.Created, OrderJson))
            .PlaceOrderAsync(12, 2, "idem-1");

        Assert.Equal(7, order.Id);
        Assert.Equal("fulfilled", order.Status);
        Assert.Equal(12, order.ProductId);
        Assert.Equal(3998, order.TotalCents);
        Assert.Equal(["K-1", "K-2"], order.Keys);
        Assert.Null(order.FailureReason);
    }

    [Fact]
    public async Task ParsesAProductWithAvailableKeys()
    {
        var json = """
            {"data":{"id":12,"vendor_id":3,"sku":"CBL-OTH-001","name":"MIDI Toolkit","category":"other",
            "price_cents":1999,"active":true,"available_keys":100}}
            """;

        var product = await ClientFor(new FakeHandler().Respond(HttpStatusCode.OK, json)).GetProductAsync(12);

        Assert.Equal("CBL-OTH-001", product.Sku);
        Assert.Equal(100, product.AvailableKeys);
        Assert.True(product.Active);
    }

    [Fact]
    public async Task ParsesAProductPageWithMeta()
    {
        var json = """
            {"data":[{"id":1,"vendor_id":1,"sku":"A","name":"N","category":"synth","price_cents":100,"active":true}],
            "meta":{"page":1,"per_page":20,"total":12}}
            """;

        var page = await ClientFor(new FakeHandler().Respond(HttpStatusCode.OK, json)).ListProductsAsync();

        Assert.Single(page.Items);
        Assert.Null(page.Items[0].AvailableKeys);
        Assert.Equal(12, page.Meta.Total);
    }

    [Fact]
    public async Task ParsesATrace()
    {
        var json = """
            {"data":{"order":{"id":7,"reseller_id":1,"status":"fulfilled","product_id":12,"quantity":1,
            "unit_price_cents":1999,"total_cents":1999,"failure_reason":null,"created_at":"2026-01-01 10:00:00"},
            "keys":[{"id":5,"key_value":"K-1","status":"sold"}],
            "events":[{"at":"2026-01-01T10:00:00Z","event":"order.fulfilled","entity_type":"order","entity_id":7,
            "detail":{"quantity":1},"request_id":"abcdef0123456789"}]}}
            """;

        var trace = await ClientFor(new FakeHandler().Respond(HttpStatusCode.OK, json)).GetTraceAsync(7);

        Assert.Equal("sold", trace.Keys.Single().Status);
        Assert.Equal("order.fulfilled", trace.Events.Single().Event);
        Assert.Equal(1, trace.Events.Single().Detail!.Value.GetProperty("quantity").GetInt32());
    }

    [Fact]
    public async Task SendsTheApiKeyHeaderOnEveryRequest()
    {
        var handler = new FakeHandler().Respond(HttpStatusCode.OK, OrderJson).Respond(HttpStatusCode.OK, OrderJson);
        var client = ClientFor(handler);

        await client.GetOrderAsync(7);
        await client.GetOrderAsync(7);

        Assert.All(handler.Requests, r => Assert.Equal("test-key", r.ApiKey));
    }

    [Fact]
    public async Task Maps401ToUnauthorizedWithCodeAndRequestId()
    {
        var json = """{"error":{"code":"UNAUTHORIZED","message":"Invalid API key","request_id":"aaaabbbbccccdddd"}}""";

        var e = await Assert.ThrowsAsync<UnauthorizedApiException>(() =>
            ClientFor(new FakeHandler().Respond(HttpStatusCode.Unauthorized, json)).GetProductAsync(1));

        Assert.Equal("UNAUTHORIZED", e.Code);
        Assert.Equal("aaaabbbbccccdddd", e.RequestId);
        Assert.Equal(HttpStatusCode.Unauthorized, e.Status);
    }

    [Fact]
    public async Task Maps409ToConflictAndDoesNotRetry()
    {
        var json = """{"error":{"code":"INSUFFICIENT_STOCK","message":"Only 1 available","request_id":"r1"}}""";
        var handler = new FakeHandler().Respond(HttpStatusCode.Conflict, json);

        var e = await Assert.ThrowsAsync<ConflictApiException>(() => ClientFor(handler).PlaceOrderAsync(12, 3));

        Assert.Equal("INSUFFICIENT_STOCK", e.Code);
        Assert.Single(handler.Requests);
    }

    [Fact]
    public async Task Maps422ToValidationAndDoesNotRetry()
    {
        var json = """{"error":{"code":"VALIDATION_FAILED","message":"quantity is invalid","request_id":"r2"}}""";
        var handler = new FakeHandler().Respond((HttpStatusCode)422, json);

        var e = await Assert.ThrowsAsync<ValidationApiException>(() => ClientFor(handler).PlaceOrderAsync(12, 0));

        Assert.Equal("VALIDATION_FAILED", e.Code);
        Assert.Single(handler.Requests);
    }

    [Fact]
    public async Task ABodyThatIsNotTheApiErrorFormatStillBecomesAnApiException()
    {
        var handler = new FakeHandler().Respond(HttpStatusCode.RequestEntityTooLarge, "<html>413</html>");

        var e = await Assert.ThrowsAsync<ApiException>(() => ClientFor(handler).GetProductAsync(1));

        Assert.Equal("HTTP_413", e.Code);
        Assert.Null(e.RequestId);
    }

    [Fact]
    public async Task RetriedOrderReusesTheSameIdempotencyKey()
    {
        var handler = new FakeHandler()
            .Respond(HttpStatusCode.ServiceUnavailable, "{}")
            .Throw(new HttpRequestException("connection reset"))
            .Respond(HttpStatusCode.Created, OrderJson);

        var order = await ClientFor(handler).PlaceOrderAsync(12, 2, "idem-42");

        Assert.Equal(7, order.Id);
        Assert.Equal(3, handler.Requests.Count);
        Assert.All(handler.Requests, r => Assert.Equal("idem-42", IdempotencyKeyOf(r)));
        Assert.Single(handler.Requests.Select(r => r.Body).Distinct());
    }

    [Fact]
    public async Task GeneratesAnIdempotencyKeyWhenNoneIsGiven()
    {
        var handler = new FakeHandler().Respond(HttpStatusCode.Created, OrderJson);

        await ClientFor(handler).PlaceOrderAsync(12, 2);

        var key = IdempotencyKeyOf(handler.Requests.Single());
        Assert.NotNull(key);
        Assert.Equal(32, key!.Length);
    }

    [Fact]
    public async Task GivesUpAfterMaxRetriesAndThrowsTheLastError()
    {
        var handler = new FakeHandler()
            .Respond(HttpStatusCode.BadGateway, "{}")
            .Respond(HttpStatusCode.BadGateway, "{}")
            .Respond(HttpStatusCode.BadGateway, "{}");

        var e = await Assert.ThrowsAsync<ApiException>(() => ClientFor(handler, maxRetries: 2).GetProductAsync(1));

        Assert.Equal(HttpStatusCode.BadGateway, e.Status);
        Assert.Equal(3, handler.Requests.Count);
    }

    private static string? IdempotencyKeyOf(RecordedRequest request) =>
        JsonDocument.Parse(request.Body).RootElement.GetProperty("idempotency_key").GetString();
}
