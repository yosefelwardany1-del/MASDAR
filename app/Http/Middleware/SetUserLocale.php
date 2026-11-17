<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Each user picks their screen language (Arabic, English or Chinese — spec Q-B2): the signed-in
 * user's own choice wins over APP_LOCALE for the whole request.
 */
class SetUserLocale
{
    public const LOCALES = ['ar' => 'العربية', 'en' => 'English', 'zh_CN' => '中文'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;
        if ($locale && array_key_exists($locale, self::LOCALES)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    public static function isRtl(?string $locale = null): bool
    {
        return ($locale ?? app()->getLocale()) === 'ar';
    }
}
