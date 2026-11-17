<?php

namespace App\Http\Controllers\ProformaInvoices;

use App\Http\Controllers\Controller;
use App\Models\ProformaInvoice;
use App\Services\ProformaInvoices\ProformaInvoiceService;
use Illuminate\Http\Request;

/**
 * A PI's workflow (spec §5.2): issue, accept, confirm, cancel with a reason, and a new
 * revision. Each is one call to ProformaInvoiceService, which holds the rules.
 */
class ProformaInvoiceStatusController extends Controller
{
    use ScopesToVisibleClients;

    public function __construct(private ProformaInvoiceService $service) {}

    public function issue(Request $request, ProformaInvoice $pi)
    {
        $this->abortUnlessVisible($request->user(), $pi);
        $pi = $this->service->issue($pi, $request->user());

        return redirect()->route('proforma-invoices.show', $pi)->with('success', __(':reference issued.', ['reference' => $pi->reference()]));
    }

    public function accept(Request $request, ProformaInvoice $pi)
    {
        $this->abortUnlessVisible($request->user(), $pi);
        $pi = $this->service->accept($pi, $request->user());

        return redirect()->route('proforma-invoices.show', $pi)->with('success', __('Marked as accepted by the client.'));
    }

    public function confirm(Request $request, ProformaInvoice $pi)
    {
        $this->abortUnlessVisible($request->user(), $pi);
        $pi = $this->service->confirm($pi, $request->user());

        return redirect()->route('proforma-invoices.show', $pi)->with('success', __('Confirmed: the client\'s deposit has arrived.'));
    }

    public function cancel(Request $request, ProformaInvoice $pi)
    {
        $this->abortUnlessVisible($request->user(), $pi);
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:2000']]);
        $pi = $this->service->cancel($pi, $request->user(), $data['cancel_reason']);

        return redirect()->route('proforma-invoices.show', $pi)->with('success', __(':reference cancelled.', ['reference' => $pi->reference()]));
    }

    public function revise(Request $request, ProformaInvoice $pi)
    {
        $this->abortUnlessVisible($request->user(), $pi);
        $revision = $this->service->createRevision($pi, $request->user());

        return redirect()->route('proforma-invoices.show', $revision)
            ->with('success', __('Revision :revision created as a draft. Change it, then issue it to replace revision :previous.', [
                'revision' => $revision->revision, 'previous' => $pi->revision,
            ]));
    }
}
