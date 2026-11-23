<?php

namespace App\Support\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * B10: a refusal from PostgreSQL (a trigger, a CHECK, a unique index) said the way a person
 * reads it. The writers check their rules first, so this is rarely reached; when it is, the
 * details — table, constraint, SQL — go to the log and the person gets plain words, never the
 * database's. A refusal it does not know gets a general sentence, never the raw text.
 */
final class Refusal
{
    /** SQLSTATE classes a person can cause: integrity rules (23…) and the triggers' RAISE (P0001). */
    public static function isRefusal(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        return str_starts_with($state, '23') || $state === 'P0001';
    }

    public static function message(QueryException $e): string
    {
        $text = $e->getPrevious()?->getMessage() ?? $e->getMessage();
        Log::warning('The database refused a change.', ['sqlstate' => $e->errorInfo[0] ?? $e->getCode(), 'error' => self::detail($text)]);

        return match (true) {
            str_contains($text, 'service_invoices_check') => __('Check the dates: the due date can\'t be before the invoice date.'),
            str_contains($text, 'reverse its journal entry before voiding it') => __('This document is posted. Reverse its journal entry on the Accounting screen first.'),
            str_contains($text, 'a statement line matches a payment of its account and amount') => __('This payment\'s amount is different from the statement line.'),
            str_contains($text, 'below zero') => __('There is not enough money in the account for this.'),
            str_contains($text, 'would exceed') => __('This would come to more than the document allows.'),
            str_contains($text, 'is closed') => __('The period of this date is closed. Reopen it or choose another date.'),
            str_contains($text, 'never changes') || str_contains($text, 'never change') || str_contains($text, 'append-only') => __('This record is final and never changes. Make a correction instead.'),
            (string) ($e->errorInfo[0] ?? '') === '23505' => __('This already exists, so it was not saved again.'),
            default => __('This change was refused because it breaks a rule of the system, so nothing was saved. Check what you entered, or ask your administrator.'),
        };
    }

    /** The database's own sentence, for the log. */
    private static function detail(string $text): string
    {
        return preg_match('/ERROR:\s+(.+?)(?:\n|CONTEXT|$)/s', $text, $m) ? trim($m[1]) : mb_substr($text, 0, 500);
    }
}
