<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Container;
use App\Models\Lookup;
use App\Models\Payment;
use App\Models\ProformaInvoice;
use App\Models\Setting;
use App\Services\Accounting\LedgerReports;
use App\Services\Claims\NoteQueries;
use App\Services\ProformaInvoices\PiAmounts;
use App\Services\Shipping\ShippingQueries;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The client portal (spec §5.8, Q-R2): a client's own orders with what is paid and due, their
 * containers and where they are, the PI as printed, and their statement. Never a supplier, a
 * factory price or another client: every query is bound to the account's own party.
 */
class PortalController extends Controller
{
    public function home(Request $request, ShippingQueries $shipping)
    {
        $party = $request->user()->portalParty;
        $pis = ProformaInvoice::query()->current()->where('client_id', $party->id)
            ->whereIn('status', ['issued', 'accepted', 'confirmed'])->orderByDesc('issued_at')->get();
        $notes = app(NoteQueries::class);
        $orders = $pis->map(function (ProformaInvoice $pi) use ($notes) {
            $total = (string) $pi->lineTotals()->amount;
            $paid = $pi->paidAmount();
            $credited = $notes->creditedForPiNumber($pi->pi_number, $pi->currency);

            return ['pi' => $pi, 'total' => $total, 'paid' => $paid, 'due' => PiAmounts::subtract(PiAmounts::subtract($total, $paid), $credited)];
        });

        $containerIds = $shipping->contents(Container::query()->pluck('id')->all(), [$party->id])->pluck('container_id')->unique()->values()->all();
        $containers = Container::query()->with(['shipment', 'latestEvent'])->whereKey($containerIds)->orderByDesc('id')->get();

        return view('portal.home', [
            'party' => $party, 'orders' => $orders, 'containers' => $containers,
            'statuses' => Lookup::options('container_status'),
            'payments' => Payment::query()->where('party_id', $party->id)->where('kind', 'client_receipt')->with('proformaInvoice')->orderByDesc('paid_on')->limit(20)->get(),
        ]);
    }

    public function pi(Request $request, ProformaInvoice $pi)
    {
        abort_unless($pi->client_id === $request->user()->party_id && ! in_array($pi->status, ['draft', 'cancelled'], true), 404);
        $pi->load(['client', 'lines.product']);
        $setting = Setting::current();
        $business = BusinessSetting::current();

        return view('proforma-invoices.print', [
            'pi' => $pi, 'totals' => $pi->lineTotals(),
            'logoUrl' => $setting->logo ? route('settings.logo') : asset('images/masdar-logo.png'),
            'companyName' => $setting->company_name, 'address' => $business->company_address, 'phone' => $business->company_phone,
            'unitNames' => Lookup::query()->where('list', 'unit')->pluck('name', 'code'),
            'portal' => true,
        ]);
    }

    public function container(Request $request, Container $container, ShippingQueries $shipping)
    {
        $party = $request->user()->portalParty;
        $lines = $shipping->contents([$container->id], [$party->id]);
        abort_if($lines->isEmpty(), 404);
        $container->load(['shipment.shippingCompany', 'events' => fn ($q) => $q->orderBy('happened_at')]);

        return view('portal.container', [
            'party' => $party, 'container' => $container, 'lines' => $lines,
            'statuses' => Lookup::options('container_status'),
            'tracking' => $this->trackingUrl($container),
        ]);
    }

    public function statement(Request $request, LedgerReports $reports)
    {
        $party = $request->user()->portalParty;
        $to = now()->toDateString();
        $from = Carbon::parse($to)->subYear()->toDateString();

        return view('portal.statement', ['party' => $party, 'report' => $reports->partyStatement($party, $from, $to), 'from' => $from, 'to' => $to,
            'book' => BusinessSetting::current()->book_currency]);
    }

    private function trackingUrl(Container $container): ?string
    {
        $template = $container->shipment?->shippingCompany?->tracking_url_template;
        if (! $template || ! $container->container_no) {
            return null;
        }

        return strtr($template, [
            '{container}' => rawurlencode($container->container_no),
            '{bl}' => rawurlencode((string) $container->shipment?->bl_number),
            '{booking}' => rawurlencode((string) $container->shipment?->booking_no),
        ]);
    }
}
