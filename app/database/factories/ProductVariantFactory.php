<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Commerce\Money;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * 測試用規格。stock 不在 fillable，請用 ->withStock(n)（直接寫欄位，僅限測試）。
 *
 * @extends Factory<ProductVariant>
 */
final class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => 'T-'.Str::upper(Str::random(12)),
            'name' => '標準款',
            'price' => Money::fromDecimal('100.00'),
            'is_active' => true,
            'is_default' => true,
        ];
    }

    public function price(string $decimal): static
    {
        return $this->state(['price' => Money::fromDecimal($decimal)]);
    }

    public function withStock(int $stock): static
    {
        return $this->afterCreating(static function (ProductVariant $variant) use ($stock): void {
            $variant->forceFill(['stock' => $stock])->save();
        });
    }
}
