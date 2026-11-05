{{-- 富文字唯一輸出點（ADR-010）：$content 型別為 App\Support\Html\SafeContent，只能由 Sanitizer 產生。
     scripts/ci/check-blade-raw.sh 只允許本檔使用不跳脫輸出。 --}}
<div {{ $attributes->class(['prose']) }}>{!! $content->html() !!}</div>
