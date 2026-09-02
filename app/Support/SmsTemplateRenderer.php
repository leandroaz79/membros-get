<?php

namespace App\Support;

final class SmsTemplateRenderer
{
    public const MAX_LENGTH = 160;

    /**
     * @param  array<string, string>  $variables
     */
    public static function render(string $template, array $variables): string
    {
        $message = str_replace(array_keys($variables), array_values($variables), $template);
        $message = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);

        return $message;
    }

    public static function length(string $message): int
    {
        return mb_strlen($message);
    }

    public static function exceedsLimit(string $message): bool
    {
        return self::length($message) > self::MAX_LENGTH;
    }

    /**
     * @return array{ok: bool, message: string, length: int}
     */
    public static function prepareForSend(string $template, array $variables): array
    {
        $message = self::render($template, $variables);
        $length = self::length($message);

        return [
            'ok' => $length <= self::MAX_LENGTH && $length > 0,
            'message' => $message,
            'length' => $length,
        ];
    }
}
