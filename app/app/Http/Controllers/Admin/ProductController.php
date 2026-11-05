<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Commerce\Catalog\CatalogService;
use App\Http\Controllers\Admin\Concerns\AdminValidation;
use App\Http\Controllers\Controller;
use App\Models\InventoryMutation;
use App\Models\Product;
use App\Models\ProductStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** 後臺商品管理（admin 可寫、viewer 唯讀；寫入皆留稽核）。 */
final class ProductController extends Controller
{
    use AdminValidation;

    private const PER_PAGE = 20;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);
        $products = Product::query()
            ->withCount('variants')
            ->withSum('variants as stock_total', 'stock')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return view('admin.products.index', ['products' => $products]);
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('admin.products.create', ['statuses' => ProductStatus::cases()]);
    }

    public function store(Request $request, CatalogService $catalog): RedirectResponse
    {
        Gate::authorize('create', Product::class);
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:120', 'regex:'.self::SLUG_REGEX, Rule::unique('products', 'slug')],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'string', 'regex:'.self::MONEY_REGEX],
            'status' => ['required', Rule::in(array_column(ProductStatus::cases(), 'value'))],
            'cover_url' => ['nullable', 'string', 'max:500', 'regex:'.self::COVER_REGEX],
            'sku' => ['required', 'string', 'max:64', 'regex:'.self::SKU_REGEX, Rule::unique('product_variants', 'sku')],
            'initial_stock' => ['required', 'integer', 'min:0', 'max:'.$this->maxStock()],
        ], $this->adminMessages(), $this->adminAttributes());

        $product = $catalog->createProduct([
            'slug' => $data['slug'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'status' => $data['status'],
            'cover_url' => $data['cover_url'] ?? null,
            'sku' => $data['sku'],
            'initial_stock' => (int) $data['initial_stock'],
        ], $request->user());

        return redirect()->route('admin.products.edit', $product)->with('status', '商品已建立。');
    }

    public function edit(Product $product): View
    {
        Gate::authorize('view', $product);
        $product->load('variants');
        $mutations = InventoryMutation::query()
            ->whereIn('product_variant_id', $product->variants->pluck('id'))
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return view('admin.products.edit', [
            'product' => $product,
            'statuses' => ProductStatus::cases(),
            'mutations' => $mutations,
            'canWrite' => Gate::allows('update', $product),
        ]);
    }

    public function update(Request $request, Product $product, CatalogService $catalog): RedirectResponse
    {
        Gate::authorize('update', $product);
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:120', 'regex:'.self::SLUG_REGEX, Rule::unique('products', 'slug')->ignore($product->id)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'string', 'regex:'.self::MONEY_REGEX],
            'status' => ['required', Rule::in(array_column(ProductStatus::cases(), 'value'))],
            'cover_url' => ['nullable', 'string', 'max:500', 'regex:'.self::COVER_REGEX],
        ], $this->adminMessages(), $this->adminAttributes());

        $catalog->updateProduct($product, [
            'slug' => $data['slug'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'status' => $data['status'],
            'cover_url' => $data['cover_url'] ?? null,
        ], $request->user());

        return redirect()->route('admin.products.edit', $product)->with('status', '商品已更新。既有訂單明細不受影響。');
    }
}
