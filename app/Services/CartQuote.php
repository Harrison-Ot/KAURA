<?php

namespace App\Services;

use App\Models\Product;
use App\Support\CatalogueValues;
use App\Support\StockArithmetic;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class CartQuote
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{items: list<array{product: Product, position: int, quantity: int, amount: int, cost: int|null, remaining: int}>, total: int, cost_total: int, cost_complete: bool}
     */
    public static function calculate(array $rows): array
    {
        $ids = array_column($rows, 'product_id');
        if (count($ids) === 0 || count($ids) > 100 || count($ids) !== count(array_unique(array_map('strval', $ids)))) {
            throw ValidationException::withMessages(['items' => 'Use 1–100 distinct product listings.']);
        }
        $products = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $items = [];
        $total = $costTotal = 0;
        $complete = true;
        foreach ($rows as $position => $row) {
            $product = $products->get((int) $row['product_id']);
            if (! $product || ! $product->is_active) {
                throw ValidationException::withMessages(['items.'.$position.'.product_id' => 'This product is unavailable. Refresh the catalogue.']);
            }
            if (CatalogueValues::minorUnits((string) $row['quoted_price']) !== $product->selling_price_minor) {
                throw ValidationException::withMessages(['items.'.$position.'.quoted_price' => 'The price of '.$product->sku.' changed. Refresh and reconfirm before posting.']);
            }
            $quantity = StockArithmetic::quantity((string) $row['quantity'], $product->quantity_precision, 'items.'.$position.'.quantity');
            $available = StockArithmetic::scaled($product->quantity_on_hand);
            if ($quantity === 0 || $quantity > $available) {
                throw ValidationException::withMessages(['items.'.$position.'.quantity' => 'Enter a positive quantity within available stock for '.$product->sku.'.']);
            }
            $amount = StockArithmetic::lineValue($quantity, $product->selling_price_minor, 'items');
            $cost = $product->inventory_value_minor === null ? null : ($quantity === $available ? $product->inventory_value_minor : BigDecimal::of($product->inventory_value_minor)->multipliedBy($quantity)->dividedBy($available, 0, RoundingMode::HalfUp)->toInt());
            $items[] = ['product' => $product, 'position' => $position + 1, 'quantity' => $quantity, 'amount' => $amount, 'cost' => $cost, 'remaining' => $available - $quantity];
            $total = StockArithmetic::total($total, $amount, 'items');
            $costTotal = StockArithmetic::total($costTotal, $cost ?? 0, 'items');
            $complete = $complete && $cost !== null;
        }

        return ['items' => $items, 'total' => $total, 'cost_total' => $costTotal, 'cost_complete' => $complete];
    }

    /** @param array<string, mixed> $data */
    public static function signature(array $data): string
    {
        $items = array_map(fn (array $row): array => [(int) $row['product_id'], StockArithmetic::scaled((string) $row['quantity']), CatalogueValues::minorUnits((string) $row['quoted_price'])], $data['items']);

        return hash('sha256', json_encode([$items, empty($data['customer_id']) ? null : (int) $data['customer_id'], $data['payment_method']], JSON_THROW_ON_ERROR));
    }
}
