<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

trait ValidatesSellingPrice
{
    /**
     * Total cost of an item (purchase + material + labour + overhead).
     */
    protected function getItemCost(string $stockId): float
    {
        return (float) (DB::table('items')
            ->where('stock_id', $stockId)
            ->selectRaw('COALESCE(purchase_cost,0)+COALESCE(material_cost,0)+COALESCE(labour_cost,0)+COALESCE(overhead_cost,0) as total_cost')
            ->value('total_cost') ?? 0);
    }

    /**
     * Check every item in a submitted items array.
     * Returns a map of "items.N.price" => message for any violations.
     */
    protected function checkItemPrices(array $items): array
    {
        $errors = [];
        foreach ($items as $i => $itemData) {
            $stockId = $itemData['stock_id'] ?? '';
            $price   = (float) ($itemData['price'] ?? 0);
            if ($stockId === '') continue;

            $cost = $this->getItemCost($stockId);
            if ($cost > 0 && $price < $cost) {
                $errors["items.{$i}.price"] =
                    "Selling price (KES {$price}) is below cost price (KES {$cost}) for item {$stockId}.";
            }
        }
        return $errors;
    }

    /**
     * Validate a single item price against cost. Returns error string or null.
     */
    protected function checkSingleItemPrice(string $stockId, float $price): ?string
    {
        $cost = $this->getItemCost($stockId);
        if ($cost > 0 && $price < $cost) {
            return "Selling price (KES {$price}) is below cost price (KES {$cost}) for item {$stockId}.";
        }
        return null;
    }
}
