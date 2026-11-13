namespace PluginXchange.Cli;

/// <summary>Tiny `--name value` parser, enough for two commands.</summary>
internal static class Args
{
    public static Dictionary<string, string> Parse(string[] args)
    {
        var result = new Dictionary<string, string>();
        for (var i = 0; i < args.Length; i++)
        {
            if (!args[i].StartsWith("--") || i + 1 >= args.Length)
            {
                throw new ArgumentException($"Bad argument '{args[i]}': options look like --name value");
            }

            result[args[i][2..]] = args[++i];
        }

        return result;
    }

    public static string Get(Dictionary<string, string> options, string name, string envVar, string fallback) =>
        options.TryGetValue(name, out var value) ? value
        : Environment.GetEnvironmentVariable(envVar) is { Length: > 0 } env ? env
        : fallback;

    public static int GetInt(Dictionary<string, string> options, string name, int fallback)
    {
        if (!options.TryGetValue(name, out var value))
        {
            return fallback;
        }

        return int.TryParse(value, out var n) && n > 0
            ? n
            : throw new ArgumentException($"--{name} must be a positive integer");
    }
}
