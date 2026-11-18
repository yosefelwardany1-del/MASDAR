<?php

use App\Models\Claim;
use App\Models\Container;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Payment;
use App\Models\ProformaInvoice;
use App\Models\Sample;
use App\Models\ServiceInvoice;
use App\Models\SourcingRequest;
use App\Models\SupplierOrder;
use App\Models\TradeNote;

return [

    /*
    | Attachments hold sensitive documents (passports, IDs, customs papers). They live on a
    | PRIVATE disk and are served only through the authorised download controller.
    | Production sets PRIVATE_DISK=s3 (Cloudflare R2): the container disk is wiped on
    | every deploy.
    */
    'disk' => env('ATTACHMENTS_DISK', env('PRIVATE_DISK', 'local')),

    'max_size_kb' => (int) env('ATTACHMENTS_MAX_SIZE_KB', 10240), // 10 MB

    'max_files_per_request' => (int) env('ATTACHMENTS_MAX_FILES', 20),

    // Whitelist, fail-closed: documents and images only.
    'allowed_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx', 'xlsx', 'ai', 'psd', 'zip'],

    /*
    | Attachable types (url segment => model class). A model becomes attachable by using
    | HasAttachments AND being listed here. Each sub-project registers its own.
    */
    'attachables' => [
        'parties' => Party::class,
        'proforma-invoices' => ProformaInvoice::class,
        'supplier-orders' => SupplierOrder::class,
        'containers' => Container::class,
        'payments' => Payment::class,
        'journal-entries' => JournalEntry::class,
        'employees' => Employee::class,
        'claims' => Claim::class,
        'trade-notes' => TradeNote::class,
        'sourcing' => SourcingRequest::class,
        'expense-claims' => ExpenseClaim::class,
        'fixed-assets' => FixedAsset::class,
        'samples' => Sample::class,
        'service-invoices' => ServiceInvoice::class,
    ],

];
