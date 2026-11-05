<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Domain\Content\Dto\Image as ImageDto;
use App\Support\Html\Sanitizer;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * 封面圖（UI-03）：預留比例避免版面跳動、延後載入；無封面或來源不合政策時顯示佔位；
 * 載入失敗由 app.js 切換為「圖片無法載入」狀態（無 JavaScript 時瀏覽器顯示替代文字）。
 */
final class Image extends Component
{
    public readonly ?string $src;

    /**
     * @param  'wide'|'square'  $ratio
     */
    public function __construct(
        public ?ImageDto $image = null,
        public string $ratio = 'wide',
        public string $placeholder = '尚無封面',
        public bool $eager = false,
        public bool $preview = false,
        public string $sizes = '(min-width: 1200px) 400px, (min-width: 768px) 50vw, 100vw',
    ) {
        $this->src = $image !== null && app(Sanitizer::class)->isAllowedMediaUrl($image->url, $preview) ? $image->url : null;
    }

    public function render(): View
    {
        return view('components.image');
    }
}
