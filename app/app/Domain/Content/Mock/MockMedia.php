<?php

declare(strict_types=1);

namespace App\Domain\Content\Mock;

/**
 * 假資料用的合成媒體（MySite React 前端專案 v1 §4.4：public/mock-media/ 放合成素材、無授權疑慮）。
 *
 * 全部由程式現場產生，不下載、不複製任何外部或 WordPress 上的檔案：
 * - PNG：單色、極小尺寸，手寫 PNG 結構（簽章＋IHDR＋IDAT〔zlib〕＋IEND），不依賴 GD。
 * - WAV：PCM 16 位元單聲道 8000 Hz、0.5 秒靜音。
 * 圖片的版面尺寸由 API 的 width／height 決定，前端以這些屬性排版，所以合成圖只要是合法圖檔即可。
 */
final class MockMedia
{
    public const COVER = 'mock-cover.png';

    public const LOGO = 'mock-logo.png';

    public const INLINE = 'mock-inline.png';

    public const AUDIO = 'mock-audio.wav';

    /** 合成音訊長度（毫秒）；任務要求不超過 1 秒。 */
    public const AUDIO_MILLISECONDS = 500;

    private const AUDIO_SAMPLE_RATE = 8000;

    /**
     * @return array<string, array{bytes: string, mime: string, description: string}> 檔名 → 內容
     */
    public static function files(): array
    {
        return [
            self::COVER => ['bytes' => self::png(16, 9, [0x5B, 0x6B, 0x82]), 'mime' => 'image/png', 'description' => '封面與 og_image 共用的合成單色圖（16×9）'],
            self::LOGO => ['bytes' => self::png(8, 8, [0x2F, 0x3E, 0x4F]), 'mime' => 'image/png', 'description' => '網站標誌的合成單色圖（8×8）'],
            self::INLINE => ['bytes' => self::png(16, 9, [0x8A, 0x9B, 0xB0]), 'mime' => 'image/png', 'description' => '正文 content_html 內圖片的合成單色圖（16×9）'],
            self::AUDIO => ['bytes' => self::wav(self::AUDIO_MILLISECONDS), 'mime' => 'audio/wav', 'description' => 'Podcast 音訊的合成靜音（PCM 16 位元、單聲道、8000 Hz、0.5 秒）'],
        ];
    }

    /** 公開網址（相對路徑；前端 CI 不向外部發請求）。 */
    public static function url(string $file): string
    {
        return '/mock-media/'.$file;
    }

    /** @return list<string> 所有合成檔的公開網址 */
    public static function urls(): array
    {
        return array_map(self::url(...), array_keys(self::files()));
    }

    /**
     * 單色 RGB PNG（色彩型態 2、位元深度 8、無交錯）。
     *
     * @param  array{int, int, int}  $rgb
     */
    public static function png(int $width, int $height, array $rgb): string
    {
        $row = "\x00".str_repeat(chr($rgb[0]).chr($rgb[1]).chr($rgb[2]), $width); // 每列開頭的濾波型態 0
        $idat = gzcompress(str_repeat($row, $height), 9);

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .self::chunk('IDAT', (string) $idat)
            .self::chunk('IEND', '');
    }

    /** PCM 16 位元單聲道靜音 WAV（RIFF／WAVE 標準 44 位元組標頭）。 */
    public static function wav(int $milliseconds): string
    {
        $channels = 1;
        $bitsPerSample = 16;
        $blockAlign = $channels * intdiv($bitsPerSample, 8);
        $samples = intdiv(self::AUDIO_SAMPLE_RATE * $milliseconds, 1000);
        $data = str_repeat("\x00", $samples * $blockAlign);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
            .'fmt '.pack('V', 16).pack('vvVVvv', 1, $channels, self::AUDIO_SAMPLE_RATE, self::AUDIO_SAMPLE_RATE * $blockAlign, $blockAlign, $bitsPerSample)
            .'data'.pack('V', strlen($data)).$data;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
