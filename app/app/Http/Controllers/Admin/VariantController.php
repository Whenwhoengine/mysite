<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Commerce\Catalog\CatalogService;
use App\Domain\Commerce\Exceptions\InventoryException;
use App\Domain\Commerce\Inventory\InventoryService;
use App\Http\Controllers\Admin\Concerns\AdminValidation;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** 後臺規格與庫存調整。 */
final class VariantController extends Controller
{
    use AdminValidation;

    public function store(Request $request, Product $product, CatalogService $catalog): RedirectResponse
    {
        Gate::authorize('update', $product);
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:64', 'regex:'.self::SKU_REGEX, Rule::unique('product_variants', 'sku')],
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'string', 'regex:'.self::MONEY_REGEX],
            'initial_stock' => ['required', 'integer', 'min:0', 'max:'.$this->maxStock()],
        ], $this->adminMessages(), $this->adminAttributes());

        $catalog->addVariant($product, [
            'sku' => $data['sku'],
            'name' => $data['name'],
            'price' => $data['price'],
            'initial_stock' => (int) $data['initial_stock'],
        ], $request->user());

        return redirect()->route('admin.products.edit', $product)->with('status', '規格已新增。');
    }

    public function update(Request $request, ProductVariant $variant, CatalogService $catalog): RedirectResponse
    {
        Gate::authorize('update', $variant->product);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'string', 'regex:'.self::MONEY_REGEX],
            'is_active' => ['required', 'boolean'],
        ], $this->adminMessages(), $this->adminAttributes());

        $catalog->updateVariant($variant, [
            'name' => $data['name'],
            'price' => $data['price'],
            'is_active' => (bool) $data['is_active'],
        ], $request->user());

        return redirect()->route('admin.products.edit', $variant->product_id)->with('status', '規格已更新。既有訂單明細不受影響。');
    }

    public function adjustStock(Request $request, ProductVariant $variant, InventoryService $inventory): RedirectResponse
    {
        Gate::authorize('adjustStock', $variant->product);
        $max = $this->maxStock();
        $data = $request->validate([
            'delta' => ['required', 'integer', 'between:-'.$max.','.$max, 'not_in:0'],
            'note' => ['nullable', 'string', 'max:200'],
        ], $this->adminMessages(), $this->adminAttributes());

        try {
            $inventory->adjust($variant, (int) $data['delta'], $request->user(), $data['note'] ?? null);
        } catch (InventoryException $e) {
            return back()->withErrors(['delta' => $e->getMessage()]);
        }

        return redirect()->route('admin.products.edit', $variant->product_id)->with('status', '庫存已調整。');
    }
}
