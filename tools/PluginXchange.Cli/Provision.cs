using PluginXchange.Client;

namespace PluginXchange.Cli;

/// <summary>Creates throwaway test data through the admin API (a product with fresh keys, a reseller).</summary>
internal static class Provision
{
    /// <summary>Imports a one-product vendor with fresh keys, then finds the product's id in the catalog.</summary>
    public static async Task<int> CreateProductAsync(
        PluginXchangeClient admin,
        int keyCount,
        string label = "Load Test",
        string skuPrefix = "LT")
    {
        var tag = Guid.NewGuid().ToString("N")[..8];
        var sku = $"{skuPrefix}-{tag}";
        var keys = Enumerable.Range(1, keyCount).Select(n => $"{skuPrefix}{tag}-{n:D5}").ToList();

        var result = await admin.ImportCatalogAsync(
            $"{label} {tag}",
            [new ImportProduct(sku, $"{label} product", "other", 100, keys)]);
        if (result.ProductsCreated != 1 || result.KeysAdded != keyCount)
        {
            throw new InvalidOperationException(
                $"Could not create the test product: {result.ProductsCreated} created, {result.KeysAdded} keys, "
                + $"{string.Join("; ", result.Rejected.SelectMany(r => r.Errors))}");
        }

        for (var page = 1; ; page++)
        {
            var products = await admin.ListProductsAsync(page, 100);
            if (products.Items.FirstOrDefault(p => p.Sku == sku) is { } found)
            {
                return found.Id;
            }

            if (page * 100 >= products.Meta.Total)
            {
                throw new InvalidOperationException($"Imported product {sku} is not in the catalog");
            }
        }
    }
}
