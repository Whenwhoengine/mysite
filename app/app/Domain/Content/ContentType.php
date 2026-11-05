<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * 核准內容型別（docs/API契約.md）：WP REST 端點、主站路徑前綴與顯示名稱的唯一對照。
 */
enum ContentType: string
{
    case Post = 'post';
    case Page = 'page';
    case Video = 'video';
    case Podcast = 'podcast';

    /** WP REST 列表端點（/wp-json/site-content/v1/{endpoint}）。 */
    public function endpoint(): string
    {
        return match ($this) {
            self::Post => 'posts',
            self::Page => 'pages',
            self::Video => 'videos',
            self::Podcast => 'podcasts',
        };
    }

    /** 主站公開路徑前綴。 */
    public function pathPrefix(): string
    {
        return match ($this) {
            self::Post => 'articles',
            self::Page => 'pages',
            self::Video => 'videos',
            self::Podcast => 'podcasts',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Post => '文章',
            self::Page => '頁面',
            self::Video => '影片',
            self::Podcast => 'Podcast',
        };
    }

    /** 列表頁標題。 */
    public function collectionTitle(): string
    {
        return match ($this) {
            self::Post => '文章',
            self::Page => '頁面',
            self::Video => '影片',
            self::Podcast => 'Podcast 節目',
        };
    }

    public function collectionLede(): string
    {
        return match ($this) {
            self::Post => '長文、筆記與幕後紀錄。',
            self::Page => '',
            self::Video => '影片作品與完整說明；點擊播放後才會連線到 YouTube。',
            self::Podcast => '可直接收聽的音訊節目，附節目簡介。',
        };
    }

    /** 有列表頁的型別（頁面只有詳情）。 @return list<self> */
    public static function listable(): array
    {
        return [self::Post, self::Video, self::Podcast];
    }

    /** 主站文章網址的相對路徑。 */
    public function path(string $slug): string
    {
        return '/'.$this->pathPrefix().'/'.rawurlencode($slug);
    }
}
