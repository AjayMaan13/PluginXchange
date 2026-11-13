using System.Text.Json;

namespace PluginXchange.Client;

// These records mirror the API's JSON. Property names are snake_case on the wire (see PluginXchangeClient.Json).

public sealed record Product(
    int Id,
    int VendorId,
    string Sku,
    string Name,
    string Category,
    int PriceCents,
    bool Active,
    int? AvailableKeys);

public sealed record Order(
    long Id,
    string Status,
    int ProductId,
    int Quantity,
    int UnitPriceCents,
    int TotalCents,
    IReadOnlyList<string> Keys,
    string? FailureReason,
    string CreatedAt);

public sealed record PageMeta(int Page, int PerPage, int Total);

public sealed record ProductPage(IReadOnlyList<Product> Items, PageMeta Meta);

public sealed record Health(string Status, string? Db);

public sealed record TraceOrder(
    long Id,
    int ResellerId,
    string Status,
    int ProductId,
    int Quantity,
    int UnitPriceCents,
    int TotalCents,
    string? FailureReason,
    string CreatedAt);

public sealed record TraceKey(long Id, string KeyValue, string Status);

public sealed record TraceEvent(
    string At,
    string Event,
    string EntityType,
    long EntityId,
    JsonElement? Detail,
    string? RequestId);

public sealed record OrderTrace(TraceOrder Order, IReadOnlyList<TraceKey> Keys, IReadOnlyList<TraceEvent> Events);

public sealed record ImportProduct(string Sku, string Name, string Category, int PriceCents, IReadOnlyList<string> Keys);

public sealed record ImportRejection(int Index, string? Sku, IReadOnlyList<string> Errors);

public sealed record ImportResult(
    int ProductsCreated,
    int ProductsUpdated,
    int KeysAdded,
    IReadOnlyList<ImportRejection> Rejected);

// Wire envelopes: {"data": ...}, {"data": [...], "meta": {...}} and {"error": {...}}
internal sealed record Envelope<T>(T Data);

internal sealed record ListEnvelope<T>(IReadOnlyList<T> Data, PageMeta Meta);

internal sealed record ErrorEnvelope(ErrorBody Error);

internal sealed record ErrorBody(string Code, string Message, string? RequestId);

internal sealed record OrderRequest(int ProductId, int Quantity, string? IdempotencyKey);

internal sealed record ImportRequest(string Vendor, IReadOnlyList<ImportProduct> Products);
