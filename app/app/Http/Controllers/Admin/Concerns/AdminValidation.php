<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use App\Domain\Commerce\Inventory\InventoryService;

/** 後臺表單驗證規則與正體中文訊息（金額只接受十進位字串，不經 float）。 */
trait AdminValidation
{
    protected const MONEY_REGEX = '/^\d{1,10}(\.\d{1,2})?$/';

    protected const SLUG_REGEX = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    protected const SKU_REGEX = '/^[A-Z0-9][A-Z0-9-]{0,63}$/';

    /** 封面：https 網址或站內絕對路徑（不允許 // 開頭、空白、引號與角括號）。 */
    protected const COVER_REGEX = '#^(https://[^\s"\'<>]+|/(?!/)[^\s"\'<>]*)$#';

    /** @return array<string, string> */
    protected function adminMessages(): array
    {
        return [
            'required' => '「:attribute」為必填。',
            'string' => '「:attribute」格式不合法。',
            'integer' => '「:attribute」必須是整數。',
            'boolean' => '「:attribute」格式不合法。',
            'max' => '「:attribute」超過長度或數值上限。',
            'min' => '「:attribute」低於下限。',
            'between' => '「:attribute」超出允許範圍。',
            'in' => '「:attribute」的值不合法。',
            'unique' => '「:attribute」已被使用。',
            'not_in' => '「:attribute」的值不合法。',
            'slug.regex' => '代稱只能使用小寫英數字與連字號（例如 sample-mug）。',
            'sku.regex' => 'SKU 只能使用大寫英數字與連字號，且以英數字開頭。',
            'price.regex' => '價格必須是非負數，最多 10 位整數與 2 位小數（例如 350 或 350.50）。',
            'cover_url.regex' => '封面必須是 https 網址或以 / 開頭的站內路徑。',
        ];
    }

    /** @return array<string, string> */
    protected function adminAttributes(): array
    {
        return [
            'slug' => '代稱',
            'title' => '標題',
            'description' => '描述',
            'price' => '價格',
            'status' => '狀態',
            'cover_url' => '封面',
            'sku' => 'SKU',
            'name' => '規格名稱',
            'initial_stock' => '初始庫存',
            'delta' => '調整數量',
            'note' => '備註',
            'is_active' => '啟用',
        ];
    }

    protected function maxStock(): int
    {
        return InventoryService::MAX_STOCK;
    }
}
