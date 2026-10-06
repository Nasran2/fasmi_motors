<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\StoreStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockAdjustmentService
{
    public function create(int $storeId, array $item, string $direction, ?string $note): ?StockMovement
    {
        return DB::transaction(function () use ($storeId, $item, $direction, $note) {
            $product = Product::whereKey($item['id'])->lockForUpdate()->firstOrFail();
            Store::findOrFail($storeId);
            $stock = $this->stock($storeId, $product->id);
            $base = (float) $stock->quantity;
            $quantity = round((float) $item['quantity'], 2);
            $effect = $this->effect($direction, $quantity, $base);
            $priceChanges = $this->prices($product, $item);
            if ($effect === 0.0 && ! $priceChanges) {
                return null;
            }

            return $this->write($product, $stock, $effect, $effect, $direction, $quantity, $base, $note);
        });
    }

    public function update(StockMovement $movement, array $data): StockMovement
    {
        return DB::transaction(function () use ($movement, $data) {
            [$movement, $product, $stock] = $this->lock($movement);
            $base = (float) ($movement->adjustment_base_quantity ?? ((float) $movement->balance_after - $movement->adjustmentEffect()));
            $quantity = round((float) $data['quantity'], 2);
            $effect = $this->effect($data['direction'], $quantity, $base);
            $this->prices($product, $data);
            // The ledger records only the correction; the visible revision retains the full adjustment effect.
            $replacement = $this->write($product, $stock, round($effect - $movement->adjustmentEffect(), 2), $effect, $data['direction'], $quantity, $base, $data['note'] ?? null, $movement);
            $this->void($movement, 'Edited: '.$data['reason']);

            return $replacement;
        });
    }

    public function delete(StockMovement $movement, string $reason): void
    {
        DB::transaction(function () use ($movement, $reason) {
            [$movement, $product, $stock] = $this->lock($movement);
            $change = -$movement->adjustmentEffect();
            $this->apply($stock, $change);
            StockMovement::create([
                'product_id' => $product->id, 'store_id' => $stock->store_id,
                'type' => 'adjustment_delete', 'quantity' => $change, 'balance_after' => $stock->quantity,
                'cost_price' => $product->cost_price, 'selling_price' => $product->selling_price,
                'reference_type' => $movement->getMorphClass(), 'reference_id' => $movement->id,
                'note' => $reason, 'created_by' => auth()->id(),
            ]);
            $this->void($movement, 'Deleted: '.$reason);
        });
    }

    private function lock(StockMovement $movement): array
    {
        $product = Product::whereKey($movement->product_id)->lockForUpdate()->firstOrFail();
        Store::findOrFail($movement->store_id);
        $movement = StockMovement::whereKey($movement->id)->lockForUpdate()->firstOrFail();
        abort_unless($movement->isAdjustment(), 404);
        if ($movement->voided_at) {
            throw ValidationException::withMessages(['adjustment' => 'This adjustment has already been edited or deleted. Refresh the list.']);
        }

        return [$movement, $product, $this->stock($movement->store_id, $product->id)];
    }

    private function stock(int $storeId, int $productId): StoreStock
    {
        return StoreStock::where('store_id', $storeId)->where('product_id', $productId)->lockForUpdate()
            ->firstOrCreate(['store_id' => $storeId, 'product_id' => $productId], ['quantity' => 0]);
    }

    private function prices(Product $product, array $data): bool
    {
        $prices = [];
        foreach (['cost_price', 'selling_price'] as $field) {
            if (isset($data[$field])) {
                $value = round((float) $data[$field], 2);
                if (round((float) $product->$field, 2) !== $value) {
                    $prices[$field] = $value;
                }
            }
        }
        if ($prices) {
            $product->update($prices);
        }

        return (bool) $prices;
    }

    private function effect(string $direction, float $quantity, float $base): float
    {
        return round(match ($direction) {
            'override' => $quantity - $base,
            'decrease' => -$quantity,
            default => $quantity,
        }, 2);
    }

    private function apply(StoreStock $stock, float $change): void
    {
        $balance = round((float) $stock->quantity + $change, 2);
        if ($change !== 0.0 && $balance < 0 && ! filter_var(Setting::valueFor('negative_stock', false), FILTER_VALIDATE_BOOL)) {
            throw ValidationException::withMessages(['quantity' => 'This change would leave insufficient stock. Reduce the correction or enable negative stock in Stock Settings.']);
        }
        if ($change !== 0.0) {
            $stock->update(['quantity' => $balance]);
        }
    }

    private function write(Product $product, StoreStock $stock, float $change, float $effect, string $direction, float $quantity, float $base, ?string $note, ?StockMovement $previous = null): StockMovement
    {
        $this->apply($stock, $change);

        return StockMovement::create([
            'product_id' => $product->id, 'store_id' => $stock->store_id,
            'type' => $effect > 0 ? 'adjustment_in' : ($effect < 0 ? 'adjustment_out' : 'adjustment_price'),
            'quantity' => $change, 'balance_after' => $stock->quantity,
            'cost_price' => $product->cost_price, 'selling_price' => $product->selling_price,
            'adjustment_direction' => $direction, 'adjustment_quantity' => $quantity,
            'adjustment_base_quantity' => $base, 'adjustment_effect_quantity' => $effect,
            'reference_type' => $previous?->getMorphClass(), 'reference_id' => $previous?->id,
            'note' => $note, 'created_by' => auth()->id(),
        ]);
    }

    private function void(StockMovement $movement, string $reason): void
    {
        $movement->update(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => mb_substr($reason, 0, 500)]);
    }
}
