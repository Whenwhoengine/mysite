<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * 結構化 log：timestamp、service、environment、level、event、request_id、error_code。
 * 遮罩 Authorization、Cookie、票券、密碼與金鑰類欄位（DAT-02）。
 */
final class JsonStderrFormatter extends JsonFormatter
{
    private const SENSITIVE = '/(authorization|cookie|password|passwd|secret|token|ticket|signature|api[_-]?key|app_key|hmac|salt|session)/i';

    public function format(LogRecord $record): string
    {
        $context = self::mask($record->context);
        $data = [
            'timestamp' => $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'service' => getenv('MYSITE_ROLE') ?: 'app-php',
            'environment' => getenv('APP_ENV') ?: 'unknown',
            'level' => strtolower($record->level->getName()),
            'event' => self::maskString($record->message),
            'request_id' => $record->extra['request_id'] ?? ($context['request_id'] ?? null),
            'error_code' => $context['error_code'] ?? null,
        ];
        unset($context['request_id'], $context['error_code']);
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e = $context['exception'];
            $context['exception'] = ['class' => $e::class, 'message' => self::maskString($e->getMessage()), 'file' => basename($e->getFile()), 'line' => $e->getLine()];
        }
        if ($context !== []) {
            $data['context'] = $context;
        }

        return $this->toJson($data, true)."\n";
    }

    /**
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    public static function mask(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key)) {
                $context[$key] = '***';
            } elseif (is_array($value)) {
                $context[$key] = self::mask($value);
            } elseif (is_string($value)) {
                $context[$key] = self::maskString($value);
            }
        }

        return $context;
    }

    public static function maskString(string $value): string
    {
        $value = (string) preg_replace('/(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/', '$1 ***', $value);
        $value = (string) preg_replace('/(ticket|token|signature|password)=([^&\s"]+)/i', '$1=***', $value);
        $value = (string) preg_replace('/base64:[A-Za-z0-9+\/=]{20,}/', 'base64:***', $value);

        return (string) preg_replace('/[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/', '***.***.***', $value);
    }
}
