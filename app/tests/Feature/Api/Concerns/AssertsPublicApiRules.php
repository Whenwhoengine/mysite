<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Concerns;

/**
 * 契約說明裡、OpenAPI 3.0 語法表達不了的跨欄位規則（docs/公開API契約.md 第 4 節）。
 * PublicApiTest 對每一個 200 回應、PublicApiSamplesTest 對每一個有效樣本都檢查一次。
 */
trait AssertsPublicApiRules
{
    /** @param array<string, mixed> $json */
    protected function assertPublicApiRules(array $json, string $operation, string $label): void
    {
        $data = $json['data'] ?? null;
        if (str_ends_with($operation, '/{slug}') && ! str_contains($operation, '/categories/') && ! str_contains($operation, '/tags/')) {
            $this->assertIsArray($data, $label);
            $type = $data['type'];
            $this->assertSame($type === 'video', $data['video'] !== null, "{$label}：type 為 video 時 video 才不為 null");
            $this->assertSame($type === 'podcast', $data['podcast'] !== null, "{$label}：type 為 podcast 時 podcast 才不為 null");
            $expectedType = ['articles' => 'post', 'videos' => 'video', 'podcasts' => 'podcast', 'pages' => 'page'][explode('/', $operation)[3]];
            $this->assertSame($expectedType, $type, "{$label}：端點與內容型別一致");
            if ($data['video'] !== null) {
                $this->assertSame($data['video']['youtube_id'] === null, $data['video']['video_unavailable'], "{$label}：video_unavailable 與 youtube_id 一致");
            }
            if ($data['podcast'] !== null) {
                $this->assertSame($data['podcast']['audio_url'] === null, $data['podcast']['audio_unavailable'], "{$label}：audio_unavailable 與 audio_url 一致");
            }
        }
        if (str_contains($operation, '/categories/') || str_contains($operation, '/tags/')) {
            $overview = $data['mode'] === 'overview';
            $this->assertSame($overview, $data['overview'] !== null, "{$label}：overview 只在總覽");
            $this->assertSame($overview, $data['items'] === null, "{$label}：items 只在列表");
            $this->assertSame($overview, $json['meta'] === null, "{$label}：meta 只在列表");
            $this->assertSame($overview, $data['type'] === null, "{$label}：type 只在列表");
            $this->assertSame(str_contains($operation, '/categories/') ? 'category' : 'tag', $data['kind'], "{$label}：kind 與端點一致");
        }
        if ($operation === '/api/v1/search') {
            $this->assertSame($data['searched'], $json['meta'] !== null, "{$label}：meta 只在已搜尋時出現");
            $this->assertSame($data['searched'], $data['q'] !== '', "{$label}：q 不為空才算已搜尋");
            if (! $data['searched']) {
                $this->assertSame([], $data['items'], "{$label}：未搜尋時沒有結果");
            }
        }
        if (isset($json['meta']) && is_array($json['meta']) && isset($json['meta']['total'])) {
            $m = $json['meta'];
            $this->assertSame((int) ceil($m['total'] / max(1, $m['per_page'])), $m['total_pages'], "{$label}：total_pages 與 total、per_page 一致");
        }
    }
}
