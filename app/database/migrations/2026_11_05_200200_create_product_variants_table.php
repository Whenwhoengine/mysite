<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 商品規格（variant）。無規格商品以單一預設 variant（is_default=1）表示。
 * 庫存為 unsigned，另加 CHECK；結帳扣庫存在交易內以 lockForUpdate 取得列鎖。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('name', 120);
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT chk_variants_price_nonnegative CHECK (price >= 0)');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT chk_variants_stock_nonnegative CHECK (stock >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
