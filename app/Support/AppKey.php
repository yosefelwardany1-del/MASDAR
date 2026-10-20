<?php

namespace App\Support;

/**
 * The application key as Laravel reads it. Render's `generateValue` (render.yaml) makes a
 * bare base64 string of 32 random bytes, while Laravel decodes a key only behind "base64:";
 * read as it is, that string is no AES-256 key and every encrypted cookie fails. Such a key
 * gains the prefix; any other key is left exactly as it is.
 */
final class AppKey
{
    public static function normalise(?string $key): ?string
    {
        if ($key === null || $key === '' || str_starts_with($key, 'base64:')) {
            return $key;
        }
        $bytes = base64_decode($key, true);

        return $bytes !== false && strlen($bytes) === 32 ? 'base64:'.$key : $key;
    }
}
