<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Commerce\Money;
use App\Models\Product;
use App\Models\ProductStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'slug' => 'test-'.Str::lower(Str::random(10)),
            'title' => '測試商品 '.Str::random(4),
            'description' => '測試用合成商品。',
            'price' => Money::fromDecimal('100.00'),
            'status' => ProductStatus::Active,
            'cover_url' => null,
            'is_sample' => true,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => ProductStatus::Draft]);
    }

    public function archived(): static
    {
        return $this->state(['status' => ProductStatus::Archived]);
    }
}
