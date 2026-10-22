<?php

namespace App\Services;

use App\Models\Product;
use InvalidArgumentException;

final class PricingService
{
    /**
     * @param  array<int, array{product_id: int, weight_kg: string}>  $items
     * @return array{
     *     items: array<int, array<string, int|string>>,
     *     subtotal: int,
     *     discount: int,
     *     total: int
     * }
     */
    public function quoteCart(string $customerCategory, array $items, ?int $finalTotal = null): array
    {
        if (! in_array($customerCategory, ['ecer', 'grosir'], true)) {
            throw new InvalidArgumentException('Kategori pelanggan tidak valid.');
        }

        if ($items === []) {
            throw new InvalidArgumentException('Keranjang tidak boleh kosong.');
        }

        $productIds = array_values(array_unique(array_map(
            static fn (array $item): int => (int) ($item['product_id'] ?? 0),
            $items
        )));
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $weights = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $weightMilli = $this->parseWeight($item['weight_kg'] ?? null);

            if (! $products->has($productId)) {
                throw new InvalidArgumentException('Produk tidak ditemukan.');
            }

            $weights[$productId] = ($weights[$productId] ?? 0) + $weightMilli;
        }

        $quotedItems = [];
        $subtotal = 0;

        foreach ($weights as $productId => $weightMilli) {
            $product = $products->get($productId);
            $tier = $this->selectTier($customerCategory, $weightMilli);
            $price = $this->priceForTier($product, $tier);
            $lineTotal = $this->roundToHundred($weightMilli, $price);

            $quotedItems[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'weight_kg' => $this->formatWeight($weightMilli),
                'price_tier' => $tier,
                'price_per_kg' => $price,
                'line_total' => $lineTotal,
            ];
            $subtotal += $lineTotal;
        }

        $total = $finalTotal ?? $subtotal;

        if ($total < 0 || $total > $subtotal) {
            throw new InvalidArgumentException('Total akhir harus di antara nol dan subtotal.');
        }

        return [
            'items' => $quotedItems,
            'subtotal' => $subtotal,
            'discount' => $subtotal - $total,
            'total' => $total,
        ];
    }

    private function parseWeight(mixed $weight): int
    {
        if (! is_string($weight) && ! is_int($weight)) {
            throw new InvalidArgumentException('Berat harus berupa desimal.');
        }

        $value = (string) $weight;

        if (! preg_match('/^(?:0|[1-9]\d{0,3})(?:\.(\d{1,3}))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Berat harus memiliki maksimal tiga angka desimal.');
        }

        $whole = (int) strtok($value, '.');
        $fraction = str_pad($matches[1] ?? '', 3, '0');
        $weightMilli = ($whole * 1000) + (int) $fraction;

        if ($weightMilli < 50 || $weightMilli > 9_999_999) {
            throw new InvalidArgumentException('Berat harus antara 0,050 kg dan 9999,999 kg.');
        }

        return $weightMilli;
    }

    private function selectTier(string $customerCategory, int $weightMilli): string
    {
        if ($customerCategory === 'grosir' || $weightMilli >= 5000) {
            return '5kg';
        }

        return $weightMilli >= 3000 ? '3kg' : 'retail';
    }

    private function priceForTier(Product $product, string $tier): int
    {
        return match ($tier) {
            'retail' => (int) $product->price_retail,
            '3kg' => (int) $product->price_3kg,
            '5kg' => (int) $product->price_5kg,
        };
    }

    private function roundToHundred(int $weightMilli, int $price): int
    {
        return intdiv(($weightMilli * $price) + 50_000, 100_000) * 100;
    }

    private function formatWeight(int $weightMilli): string
    {
        return sprintf('%d.%03d', intdiv($weightMilli, 1000), $weightMilli % 1000);
    }
}
