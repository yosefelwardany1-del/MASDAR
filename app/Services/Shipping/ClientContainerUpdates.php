<?php

namespace App\Services\Shipping;

use App\Models\BusinessSetting;
use App\Models\ContainerEvent;
use App\Models\Lookup;
use App\Models\PartyContact;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Support\Facades\DB;

/**
 * Tells each client with goods in a container that it reached a status MASDAR chose (spec
 * Q-C4: Lists & options → "WhatsApp updates to clients", off by default), through the approved
 * container template. Runs after the status is saved (SendContainerUpdates, a queued job), so a
 * slow or failed send never touches the status. A correction never notifies. The only writer of
 * whatsapp_messages: every attempt is logged, sent or not.
 */
class ClientContainerUpdates
{
    public function __construct(private WhatsAppClient $whatsapp) {}

    /** @return int how many clients were sent the update */
    public function notify(ContainerEvent $event): int
    {
        $setting = BusinessSetting::current();
        if (! $setting->whatsapp_container_updates || $event->corrects_event_id !== null
            || ! in_array($event->status, $setting->whatsappUpdateStatuses(), true)) {
            return 0;
        }

        $container = $event->container()->with('shipment')->first();
        $shipment = $container?->shipment;
        $template = (string) config('whatsapp.container_template');
        $language = (string) config('whatsapp.container_template_language', 'en');
        $status = (string) (Lookup::query()->where('list', 'container_status')->where('code', $event->status)->value('name') ?? $event->status);
        $port = $shipment?->port_of_discharge
            ? (string) (Lookup::query()->where('list', 'port')->where('code', $shipment->port_of_discharge)->value('name') ?? $shipment->port_of_discharge)
            : '';
        $eta = trim(($shipment?->eta?->format('Y-m-d') ?? '').($port !== '' ? ', '.$port : '')) ?: '—';

        $sent = 0;
        foreach ($this->clientsIn($container->id) as $client) {
            $number = $this->numberFor($client->id);
            $ok = $number !== null && $this->whatsapp->sendTemplate($number, $template, $language, [
                $client->name, $container->container_no ?: $shipment?->number ?? '', $status, $eta,
            ]);
            DB::transaction(fn () => WhatsAppMessage::create([
                'purpose' => 'container_update',
                'container_id' => $container->id,
                'container_event_id' => $event->id,
                'party_id' => $client->id,
                'to_last4' => $number ? substr($number, -4) : null,
                'template' => $template,
                'sent' => $ok,
                'error' => $ok ? null : ($number === null ? 'The client has no WhatsApp or phone number on a contact.' : $this->whatsapp->lastError()),
            ]));
            $sent += $ok ? 1 : 0;
        }

        return $sent;
    }

    /** Distinct clients with goods (net cartons > 0) in the container. */
    private function clientsIn(int $containerId)
    {
        return DB::table('container_loads as cl')
            ->join('supplier_order_lines as sol', 'sol.id', '=', 'cl.supplier_order_line_id')
            ->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
            ->join('proforma_invoices as pi', 'pi.id', '=', 'pl.proforma_invoice_id')
            ->join('parties as p', 'p.id', '=', 'pi.client_id')
            ->where('cl.container_id', $containerId)
            ->groupBy('p.id', 'p.name')
            ->havingRaw('SUM(cl.cartons) > 0')
            ->orderBy('p.name')
            ->get(['p.id', 'p.name']);
    }

    /** The client's WhatsApp number: the primary contact's, else any contact's (WhatsApp before phone). */
    private function numberFor(int $partyId): ?string
    {
        $contacts = PartyContact::query()->where('party_id', $partyId)->orderByDesc('is_primary')->orderBy('id')->get();
        foreach (['whatsapp', 'phone'] as $field) {
            foreach ($contacts as $contact) {
                $digits = preg_replace('/[^\d+]/', '', (string) $contact->{$field});
                if ($digits !== '' && str_starts_with($digits, '+')) {
                    return $digits;
                }
            }
        }

        return null;
    }
}
