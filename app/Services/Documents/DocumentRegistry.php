<?php

namespace App\Services\Documents;

use App\Http\Controllers\Claims\TradeNoteController;
use App\Http\Controllers\ProformaInvoices\ProformaInvoiceController;
use App\Http\Controllers\Quotations\QuotationController;
use App\Http\Controllers\Samples\ServiceInvoiceController;
use App\Http\Controllers\Shipping\ContainerController;
use App\Http\Controllers\SupplierOrders\SupplierOrderController;
use App\Models\Container;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\Quotation;
use App\Models\ServiceInvoice;
use App\Models\SupplierOrder;
use App\Models\TradeNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The documents that go out as PDF (spec §5.8). Each type renders through its own print page —
 * so the PDF is exactly what prints, and the page's own checks decide who may see it — and
 * names who may send it (the edit level of its screen), to whom it usually goes, and when it is
 * ready to send (a draft never leaves).
 */
class DocumentRegistry
{
    /** type => [model, title, screen] */
    public const TYPES = [
        'quotation' => [Quotation::class, 'Quotation', 'quotations'],
        'proforma-invoice' => [ProformaInvoice::class, 'Proforma Invoice', 'proforma_invoices'],
        'purchase-contract' => [SupplierOrder::class, 'Purchase Contract', 'supplier_orders'],
        'packing-list' => [Container::class, 'Packing List', 'containers'],
        'commercial-invoice' => [Container::class, 'Commercial Invoice', 'containers'],
        'credit-note' => [TradeNote::class, 'Credit Note', 'claims'],
        'debit-note' => [TradeNote::class, 'Debit Note', 'claims'],
        'service-invoice' => [ServiceInvoice::class, 'Invoice', 'service_invoices'],
    ];

    public function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public function find(string $type, int $id): Model
    {
        abort_unless($this->exists($type), 404);
        $model = self::TYPES[$type][0]::query()->findOrFail($id);
        if ($model instanceof TradeNote) {
            abort_unless($model->kind === ($type === 'credit-note' ? 'credit' : 'debit'), 404);
        }

        return $model;
    }

    /** The English title printed on it, e.g. "Proforma Invoice". */
    public function title(string $type): string
    {
        return self::TYPES[$type][1];
    }

    public function maySend(string $type, User $user): bool
    {
        return $user->can(self::TYPES[$type][2].'.edit');
    }

    /** The print page, rendered with the page's own checks (a 404 or 403 when not allowed). */
    public function render(string $type, Model $model, Request $request): string
    {
        $view = match ($type) {
            'quotation' => app(QuotationController::class)->print($request, $model),
            'proforma-invoice' => app(ProformaInvoiceController::class)->print($request, $model),
            'purchase-contract' => app(SupplierOrderController::class)->contract($request, $model),
            'packing-list' => app(ContainerController::class)->packingList($request, $model),
            'commercial-invoice' => app(ContainerController::class)->commercialInvoice($request, $model),
            'credit-note', 'debit-note' => app(TradeNoteController::class)->print($request, $model),
            'service-invoice' => app(ServiceInvoiceController::class)->print($request, $model),
        };

        return $view instanceof View ? $view->render() : (string) $view;
    }

    /** Its number as people know it: "PI-2026-0007 Rev 2", "QT-2026-0003", the container no … */
    public function reference(string $type, Model $model, Request $request): string
    {
        return match ($type) {
            'quotation' => $model->number,
            'proforma-invoice' => $model->reference(),
            'purchase-contract' => $model->number,
            'packing-list' => 'PL-'.($model->container_no ?: $model->id),
            'commercial-invoice' => 'CI-'.$model->shipment?->number.'-'.(Party::query()->find($request->integer('client'))?->code ?? ''),
            'credit-note', 'debit-note', 'service-invoice' => $model->number,
        };
    }

    /** Null when it may be sent; otherwise why not (a draft never leaves the office). */
    public function notReady(string $type, Model $model): ?string
    {
        return match (true) {
            $model instanceof Quotation && $model->isDraft() => __('Mark the quotation as sent first: a draft is not sent to the client.'),
            $model instanceof ProformaInvoice && in_array($model->status, ['draft', 'cancelled', 'superseded'], true) => __('Only an issued PI is sent to the client.'),
            $model instanceof TradeNote && $model->status !== 'issued' => __('Only an issued note is sent.'),
            $model instanceof ServiceInvoice && $model->status !== 'issued' => __('Only an issued invoice is sent.'),
            $model instanceof SupplierOrder && $model->status === 'cancelled' => __('This order is cancelled.'),
            default => null,
        };
    }

    /** The party it goes to, when there is one: the client, or the factory. */
    public function party(string $type, Model $model, Request $request): ?Party
    {
        return match ($type) {
            'quotation', 'proforma-invoice' => $model->client,
            'purchase-contract' => $model->supplier,
            'commercial-invoice' => Party::query()->find($request->integer('client')),
            'credit-note', 'debit-note' => $model->party,
            'service-invoice' => $model->client,
            default => null,
        };
    }

    /** The party's contacts with an email or a WhatsApp number, primary first. */
    public function contacts(?Party $party): Collection
    {
        if (! $party) {
            return collect();
        }

        return $party->contacts()->orderByDesc('is_primary')->orderBy('name')->get(['id', 'name', 'role', 'email', 'whatsapp', 'phone']);
    }

    /** Where "Back" goes from the send page. */
    public function backUrl(string $type, Model $model): string
    {
        return match ($type) {
            'quotation' => route('quotations.show', $model),
            'proforma-invoice' => route('proforma-invoices.show', $model),
            'purchase-contract' => route('supplier-orders.show', $model),
            'packing-list', 'commercial-invoice' => route('containers.show', $model),
            'credit-note', 'debit-note' => route('trade-notes.show', $model),
            'service-invoice' => route('service-invoices.show', $model),
        };
    }

    public function filename(string $type, string $reference): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $this->title($type).' '.$reference).'.pdf';
    }
}
