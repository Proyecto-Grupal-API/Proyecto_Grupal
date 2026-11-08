<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductVariantService
{
    public function sync(Product $product): void
    {
        $businessId = (string) $product->business_id;
        $productId = (string) $product->_id;
        $sizes = array_values(array_unique(array_filter((array) $product->sizes)));
        $colors = array_values(array_unique(array_filter((array) $product->colors)));
        $combinations = [];
        if ($sizes !== [] || $colors !== []) {
            $sizeValues = $sizes ?: [null];
            $colorValues = $colors ?: [null];
            foreach ($sizeValues as $size) {
                foreach ($colorValues as $color) {
                    if ($size === null && $color === null) {
                        continue;
                    }
                    $variantId = substr(hash('sha256', implode("\0", [
                        $businessId,
                        $productId,
                        (string) $size,
                        (string) $color,
                    ])), 0, 24);
                    $combinations[$variantId] = [
                        'business_id' => $businessId,
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'sku' => (string) $product->sku . '-' . strtoupper(substr($variantId, 0, 8)),
                        'size' => $size,
                        'color' => $color,
                        'active' => (bool) $product->active,
                    ];
                }
            }
        }

        $variants = DB::connection('mongodb')->getCollection('product_variants');
        $variants->updateMany(
            ['business_id' => $businessId, 'product_id' => $productId, 'active' => true],
            ['$set' => ['active' => false, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())]]
        );

        foreach ($combinations as $variantId => $variant) {
            $variants->updateOne(
                ['business_id' => $businessId, 'product_id' => $productId, 'variant_id' => $variantId],
                ['$set' => [...$variant, 'updated_at' => new \MongoDB\BSON\UTCDateTime(now())],
                    '$setOnInsert' => ['created_at' => new \MongoDB\BSON\UTCDateTime(now())]],
                ['upsert' => true]
            );
        }
    }
}
