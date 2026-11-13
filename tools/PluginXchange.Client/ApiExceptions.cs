using System.Net;

namespace PluginXchange.Client;

/// <summary>An error response from the API. Carries the machine-readable code and the request id for support.</summary>
public class ApiException : Exception
{
    public ApiException(HttpStatusCode status, string code, string message, string? requestId)
        : base($"{(int)status} {code}: {message}")
    {
        Status = status;
        Code = code;
        RequestId = requestId;
    }

    public HttpStatusCode Status { get; }

    public string Code { get; }

    public string? RequestId { get; }
}

/// <summary>401: missing or unknown API key.</summary>
public sealed class UnauthorizedApiException(string code, string message, string? requestId)
    : ApiException(HttpStatusCode.Unauthorized, code, message, requestId);

/// <summary>409: for example INSUFFICIENT_STOCK or ALREADY_CANCELLED.</summary>
public sealed class ConflictApiException(string code, string message, string? requestId)
    : ApiException(HttpStatusCode.Conflict, code, message, requestId);

/// <summary>422: the request body or query failed validation.</summary>
public sealed class ValidationApiException(string code, string message, string? requestId)
    : ApiException((HttpStatusCode)422, code, message, requestId);
