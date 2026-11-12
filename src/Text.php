<?php

namespace ErnestDefoe\StoryChain;

/** Member text as one clean line: no control characters, no line breaks, single spaces. */
final class Text
{
    public static function clean(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    public static function length(string $value): int
    {
        return mb_strlen($value);
    }
}
