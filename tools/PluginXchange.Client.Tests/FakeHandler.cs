using System.Net;
using System.Text;

namespace PluginXchange.Client.Tests;

/// <summary>Plays back queued responses (or exceptions) and records every request it receives.</summary>
internal sealed class FakeHandler : HttpMessageHandler
{
    private readonly Queue<Func<HttpResponseMessage>> _queue = new();

    public List<RecordedRequest> Requests { get; } = [];

    public FakeHandler Respond(HttpStatusCode status, string json)
    {
        _queue.Enqueue(() => new HttpResponseMessage(status)
        {
            Content = new StringContent(json, Encoding.UTF8, "application/json"),
        });

        return this;
    }

    public FakeHandler Throw(Exception exception)
    {
        _queue.Enqueue(() => throw exception);

        return this;
    }

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        var body = request.Content is null ? string.Empty : await request.Content.ReadAsStringAsync(cancellationToken);
        var apiKey = request.Headers.TryGetValues("X-API-Key", out var values) ? values.Single() : null;
        Requests.Add(new RecordedRequest(request.Method, request.RequestUri!.PathAndQuery, body, apiKey));

        return _queue.Dequeue()();
    }
}

internal sealed record RecordedRequest(HttpMethod Method, string Path, string Body, string? ApiKey);
