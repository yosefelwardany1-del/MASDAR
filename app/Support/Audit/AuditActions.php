<?php

namespace App\Support\Audit;

/**
 * B25: what each audit action is called on the audit log, in the reader's language. An action not
 * listed here (a new one) still reads as words — "lines_taken_back" as "Lines taken back" — never
 * as a code.
 */
final class AuditActions
{
    public static function label(string $action): string
    {
        return match ($action) {
            'created' => __('Created'), 'updated' => __('Updated'), 'deleted' => __('Deleted'),
            'approved' => __('Approved'), 'rejected' => __('Rejected'), 'submitted' => __('Submitted'), 'withdrawn' => __('Withdrawn'),
            'issued' => __('Issued'), 'sent' => __('Sent'), 'accepted' => __('Accepted'), 'declined' => __('Declined'),
            'confirmed' => __('Confirmed'), 'cancelled' => __('Cancelled'), 'superseded' => __('Superseded'), 'completed' => __('Completed'),
            'reopened' => __('Reopened'), 'closed' => __('Closed'), 'voided' => __('Voided'), 'posted' => __('Posted'),
            'reversed' => __('Reversed'), 'corrected' => __('Corrected'), 'matched' => __('Matched'), 'unmatched' => __('Unmatched'),
            'imported' => __('Imported'), 'converted' => __('Converted'), 'copied' => __('Copied'), 'chosen' => __('Chosen'),
            'quoted' => __('Quoted'), 'disposed' => __('Disposed of'), 'recalculated' => __('Recalculated'),
            'deactivated' => __('Deactivated'), 'reactivated' => __('Reactivated'),
            'line_added' => __('Line added'), 'line_updated' => __('Line changed'), 'line_removed' => __('Line removed'),
            'lines_added' => __('Lines added'), 'lines_released' => __('Lines released'), 'lines_taken_back' => __('Lines taken back'),
            'loaded' => __('Goods loaded'), 'unloaded' => __('Goods unloaded'), 'loading_exception' => __('Loaded before payment'),
            'order_booked' => __('Order booked'), 'order_unbooked' => __('Order unbooked'),
            'status_changed' => __('Status changed'), 'status_recorded' => __('Status recorded'), 'status_corrected' => __('Status corrected'),
            'step_done' => __('Step done'), 'step_skipped' => __('Step not applicable'), 'step_reopened' => __('Step reopened'),
            'delivery_date_changed' => __('Delivery date changed'), 'merchandiser_assigned' => __('Merchandiser assigned'),
            'revision_created' => __('Revision made'), 'document_added' => __('Document added'), 'salary_added' => __('Salary added'),
            'leave_allowance_set' => __('Leave allowance set'), 'attendance_recorded' => __('Attendance recorded'),
            'quote_added' => __('Quote added'), 'quote_updated' => __('Quote changed'), 'quote_removed' => __('Quote removed'),
            'document_sent' => __('Document sent'), 'document_not_sent' => __('Document not sent'),
            'portal_access_granted' => __('Portal access given'), 'portal_access_revoked' => __('Portal access taken away'),
            'password_changed' => __('Password changed'),
            default => ucfirst(str_replace('_', ' ', $action)),
        };
    }

    /** A value as the log shows it: empty is "—", true/false are Yes/No, lists are joined. */
    public static function value(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '—',
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
