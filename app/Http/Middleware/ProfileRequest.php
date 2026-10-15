<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * How long did this request take, and where did the time go? — measured in PRODUCTION.
 *
 * "The ERP is slow" cannot be answered from a laptop. Locally every query costs
 * microseconds against sqlite on an SSD; in production each one is a network round trip
 * to Neon, and the app may have been asleep. The same page is cheap here and expensive
 * there, so the only honest instrument is one that reports from where it hurts.
 *
 * TWO READERS, DELIBERATELY:
 *
 *   `Server-Timing` on every response. Visible to anyone in their browser's devtools
 *   Network tab, with no access to Render and nothing to install. It separates the
 *   three things that feel identical to a user — the server was asleep (huge wait,
 *   tiny app time), the database is far away (many queries, most of the time in db),
 *   and the page itself is heavy (few queries, large app time).
 *
 *   A log line when a request crosses PROFILE_SLOW_MS, for whoever can read the logs.
 *   Default 1500ms, and it logs at WARNING because production runs at that level.
 *
 * COSTS ALMOST NOTHING. One DB::listen closure summing floats, and two header writes.
 * It is not a debug bar: no payloads, no bindings, no bodies — the slowest statement is
 * recorded as SQL with placeholders intact, which names the query without carrying data.
 */
class ProfileRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $queries = 0;
        $dbMs = 0.0;
        $slowestMs = 0.0;
        $slowestSql = '';

        DB::listen(function ($query) use (&$queries, &$dbMs, &$slowestMs, &$slowestSql) {
            $queries++;
            $dbMs += $query->time;

            if ($query->time > $slowestMs) {
                $slowestMs = $query->time;
                // The statement, never the bindings: this says WHICH query is slow
                // without putting a salary or an email address in a log line.
                $slowestSql = mb_substr((string) preg_replace('/\s+/', ' ', $query->sql), 0, 200);
            }
        });

        $response = $next($request);

        $totalMs = round((microtime(true) - $started) * 1000, 1);
        $dbMs = round($dbMs, 1);
        $appMs = round(max(0, $totalMs - $dbMs), 1);

        // `headers` is a PROPERTY on a Symfony response, not a method — guarding with
        // method_exists() here silently produced no header at all, and the tests are
        // what caught it. Every Symfony response has the bag; anything else is skipped.
        if (isset($response->headers)) {
            $response->headers->set('Server-Timing', implode(', ', [
                sprintf('app;desc="PHP";dur=%s', $appMs),
                sprintf('db;desc="%d queries";dur=%s', $queries, $dbMs),
            ]));
        }

        $threshold = (int) env('PROFILE_SLOW_MS', 1500);

        if ($threshold > 0 && $totalMs >= $threshold) {
            Log::warning('Slow request', [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'total_ms' => $totalMs,
                'db_ms' => $dbMs,
                'queries' => $queries,
                'slowest_ms' => round($slowestMs, 1),
                'slowest_sql' => $slowestSql,
            ]);
        }

        return $response;
    }
}
