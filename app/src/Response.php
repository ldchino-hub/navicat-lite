<?php
declare(strict_types=1);

namespace Navicat;

final class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(self::sanitize($data), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function error(string $message, int $status = 400): void
    {
        self::json(['error' => $message], $status);
    }

    public static function sseStart(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) ob_end_flush();
        ob_implicit_flush(true);
    }

    public static function sse(array $event): void
    {
        echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    private static function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) $out[$k] = self::sanitize($v);
            return $out;
        }
        if ($value instanceof \DateTimeInterface) return $value->format(DATE_ATOM);
        if (is_object($value)) return self::sanitize(get_object_vars($value));
        return $value;
    }
}
