{{-- 統一錯誤頁（ErrorRenderer）：固定正體中文訊息與 request ID；不顯示 stack trace、路徑或上游細節。 --}}
@extends('layouts.app')

@php
    $status = (int) ($status ?? 500);
    $code = (string) ($code ?? 'HTTP_'.$status);
    $isPreview = $code === 'PREVIEW_INVALID';
    $heading = match (true) {
        $isPreview => '預覽已失效',
        $status === 404 => '找不到這個頁面',
        $status === 503 => '內容暫時無法讀取',
        $status === 419 => '頁面已逾時',
        $status === 429 => '操作過於頻繁',
        $status === 403 => '無法檢視這個頁面',
        $status >= 500 => '系統發生錯誤',
        default => '無法完成請求',
    };
@endphp

@section('title', $heading)
@section('robots', 'noindex, nofollow')
@section('body_class', 'page-error page-error--'.$status)

@section('content')
    <section class="state" aria-labelledby="state-title">
        <p class="state__code" aria-hidden="true">{{ $status }}</p>
        <h1 id="state-title" class="state__title">{{ $heading }}</h1>
        <p class="state__message">{{ $message ?? '' }}</p>

        @if ($isPreview)
            <p>預覽連結只能使用一次，且會在短時間後失效。請回到 WordPress 編輯器，儲存後再按一次「主站預覽」。</p>
        @elseif ($status === 404)
            <p>連結可能已變更，或內容已不再公開。</p>
            <form class="search-form state__search" role="search" action="/search" method="get">
                <label class="visually-hidden" for="state-q">搜尋站內內容</label>
                <input id="state-q" class="input" type="search" name="q" maxlength="100" placeholder="搜尋文章、影片、Podcast">
                <button class="button" type="submit">搜尋</button>
            </form>
        @elseif ($status === 503)
            <p>內容系統目前沒有回應。這不代表內容已被刪除，請稍候一分鐘再重新整理。</p>
        @elseif ($status === 419)
            <p>為了安全，表單在一段時間後會失效。請重新整理頁面，再送出一次。</p>
        @elseif ($status === 429)
            <p>請稍候片刻再試。@if (! empty($retryAfter))大約 {{ $retryAfter }} 秒後可以再試一次。@endif</p>
        @endif

        <p class="state__actions">
            <a class="button button--primary" href="/">回首頁</a>
            @if (in_array($status, [503, 419, 429], true) && ! request()->is('preview', 'preview/*'))
                <a class="button" href="{{ request()->getRequestUri() }}">重新整理</a>
            @endif
        </p>
        <p class="state__ref">請求編號：<code>{{ $requestId ?? '' }}</code></p>
    </section>
@endsection
