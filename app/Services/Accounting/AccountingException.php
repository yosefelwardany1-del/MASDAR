<?php

namespace App\Services\Accounting;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * A posting, period or chart rule refused what was asked, with a message that says what to do
 * ("Set the credit account of posting rule client_receipt on the Accounting screen"). Shown as
 * a flash error, never a 500: the controllers catch it, and render() is the safety net for any
 * that escapes.
 */
class AccountingException extends \RuntimeException
{
    /**
     * Turn a refusal from the ledger's triggers and constraints (I1, I2, I3) into a message a
     * person can act on. Anything unrecognised is rethrown: it is a bug, not a rule.
     */
    public static function fromQuery(QueryException $e): self
    {
        $text = $e->getMessage();

        return match (true) {
            (bool) preg_match('/journal entry (\S+) does not balance \(debit ([\d.]+), credit ([\d.]+)\)/', $text, $m) => new self(
                __('The entry does not balance: debits :debit, credits :credit. It needs at least two lines and equal debits and credits.', [
                    'debit' => Decimal::format($m[2]), 'credit' => Decimal::format($m[3]),
                ]), 0, $e),
            (bool) preg_match('/the period containing (\S+) is closed/', $text, $m) => new self(
                __('The period containing :date is closed. Reopen it or choose another date.', ['date' => $m[1]]), 0, $e),
            str_contains($text, 'is a heading or inactive') => new self(
                __('One of the accounts is a heading or inactive; post to an active sub-account.'), 0, $e),
            str_contains($text, 'journal_entries_one_posting_per_source') => new self(
                __('This document has already been posted.'), 0, $e),
            str_contains($text, 'journal_entries_reverses_entry_id_unique') => new self(
                __('This entry has already been reversed.'), 0, $e),
            str_contains($text, 'posted journal entry') || str_contains($text, 'lines of a posted journal entry') => new self(
                __('A posted entry never changes; reverse it instead.'), 0, $e),
            default => throw $e,
        };
    }

    /** The safety net: an escaped refusal goes back to the form with its message. */
    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->withInput()->with('error', $this->getMessage());
    }
}
