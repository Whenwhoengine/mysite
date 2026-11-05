<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Support\Html\SafeContent;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * 富文字唯一輸出點（ADR-010）：只接受 SafeContent（經 Sanitizer 產生），型別保證不會輸出未清理的 HTML。
 */
final class RichContent extends Component
{
    public function __construct(public SafeContent $content) {}

    public function render(): View
    {
        return view('components.rich-content');
    }
}
