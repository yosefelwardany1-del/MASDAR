<?php

namespace App\Services\Claims;

use App\Models\Claim;
use App\Models\ClaimEvent;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\Hr\Money;
use App\Services\Numbering\NumberingService;
use App\Support\Access\Visibility;
use App\Support\Audit\AuditTrail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of claims and claim_events (spec §5.8). A claim is opened against a factory
 * (a supplier order, or the supplier alone) and/or with a client (a PI, or the client alone),
 * numbered from the claim series, with its first event "open". Its status moves by new events;
 * its facts are edited only while it is not rejected or closed (trigger).
 */
class ClaimService
{
    /** Where a claim may go from each status. Reopening a finished claim takes it back to open. */
    public const MOVES = [
        'open' => ['with_factory', 'agreed', 'rejected', 'closed'],
        'with_factory' => ['open', 'agreed', 'rejected', 'closed'],
        'agreed' => ['open', 'closed'],
        'rejected' => ['open'],
        'closed' => ['open'],
    ];

    public function __construct(private NumberingService $numbers) {}

    /** @param array<string, mixed> $data kind, supplier_order_id?, supplier_id?, proforma_invoice_id?, client_id?, opened_on?, description, cartons?, claimed_amount?, currency? */
    public function open(array $data, User $by): Claim
    {
        $this->authorize($by, 'claims.edit');
        $values = $this->values($data, $by);

        return $this->write(function () use ($values, $by) {
            $claim = Claim::create($values + ['number' => $this->numbers->next('claim', new \DateTimeImmutable($values['opened_on'])), 'created_by' => $by->id]);
            ClaimEvent::create(['claim_id' => $claim->id, 'status' => 'open', 'user_id' => $by->id]);
            AuditTrail::record('created', $claim, [], $values);

            return $claim;
        });
    }

    public function update(Claim $claim, array $data, User $by): Claim
    {
        $this->authorize($by, 'claims.edit');
        $this->assertVisible($claim, $by);
        $values = $this->values($data, $by);

        return $this->write(function () use ($claim, $values) {
            $claim = Claim::query()->lockForUpdate()->findOrFail($claim->id);
            if ($claim->isFinished()) {
                throw ValidationException::withMessages(['claim' => __('A rejected or closed claim does not change. Reopen it first.')]);
            }
            $old = $claim->only(array_keys($values));
            $claim->update($values);
            AuditTrail::record('updated', $claim, $old, $values);

            return $claim;
        });
    }

    /** A new status for the claim; "agreed" may carry the amount agreed with the factory or client. */
    public function move(Claim $claim, string $status, User $by, ?string $note = null, mixed $agreedAmount = null): ClaimEvent
    {
        $this->authorize($by, 'claims.edit');
        $this->assertVisible($claim, $by);

        return $this->write(function () use ($claim, $status, $by, $note, $agreedAmount) {
            $claim = Claim::query()->lockForUpdate()->with('latestEvent')->findOrFail($claim->id);
            $from = $claim->status();
            if (! in_array($status, self::MOVES[$from] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('A claim that is :from cannot become :to.', [
                    'from' => Claim::statusLabel($from), 'to' => Claim::statusLabel($status),
                ])]);
            }
            $amount = null;
            if ($status === 'agreed' && filled($agreedAmount)) {
                $amount = $this->amount($agreedAmount, 'agreed_amount', true);
            }
            $event = ClaimEvent::create([
                'claim_id' => $claim->id, 'status' => $status, 'agreed_amount' => $amount,
                'note' => filled($note) ? mb_substr(trim((string) $note), 0, 2000) : null, 'user_id' => $by->id,
            ]);
            AuditTrail::record('status_changed', $claim, ['status' => $from], ['status' => $status, 'agreed_amount' => $amount, 'note' => $event->note]);

            return $event;
        });
    }

    /** May $user open this claim at all (supplier identities; sales limited to their own clients)? */
    public function visibleTo(Claim $claim, User $user): bool
    {
        if (! $user->can('claims.view')) {
            return false;
        }
        // A claim against a factory names it: only for those who may see suppliers.
        if ($claim->supplier_id !== null && ! Visibility::canSeeSupplierPrices($user)) {
            return false;
        }

        return $claim->client_id === null || $this->clientVisible($claim->client, $user);
    }

    private function clientVisible(?Party $client, User $user): bool
    {
        return $client === null || ! Visibility::ownClientsOnly($user) || (int) $client->account_manager_id === (int) $user->id;
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function values(array $data, User $by): array
    {
        $errors = [];
        $kind = (string) ($data['kind'] ?? '');
        if (! Lookup::query()->where('list', 'claim_type')->where('code', $kind)->exists()) {
            $errors['kind'] = __('Choose what the claim is about.');
        }

        $order = filled($data['supplier_order_id'] ?? null) ? SupplierOrder::query()->find((int) $data['supplier_order_id']) : null;
        if (filled($data['supplier_order_id'] ?? null) && ! $order) {
            $errors['supplier_order_id'] = __('That purchase order does not exist.');
        }
        $supplierId = $order?->supplier_id ?? (filled($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null);
        if ($supplierId !== null) {
            if (! Visibility::canSeeSupplierPrices($by)) {
                throw new AuthorizationException(__('You may not see suppliers, so you cannot claim against one.'));
            }
            if (! Party::query()->whereKey($supplierId)->where('is_supplier', true)->exists()) {
                $errors['supplier_id'] = __('Choose a supplier.');
            }
        }

        $pi = filled($data['proforma_invoice_id'] ?? null) ? ProformaInvoice::query()->find((int) $data['proforma_invoice_id']) : null;
        if (filled($data['proforma_invoice_id'] ?? null) && ! $pi) {
            $errors['proforma_invoice_id'] = __('That PI does not exist.');
        }
        $clientId = $pi?->client_id ?? (filled($data['client_id'] ?? null) ? (int) $data['client_id'] : null);
        if ($clientId !== null) {
            $client = Party::query()->whereKey($clientId)->where('is_client', true)->first();
            if (! $client) {
                $errors['client_id'] = __('Choose a client.');
            } elseif (! $this->clientVisible($client, $by)) {
                throw new AuthorizationException(__('You may not do this.'));
            }
        }
        if ($supplierId === null && $clientId === null) {
            $errors['supplier_order_id'] = __('Name the purchase order or supplier the claim is against, the client it is with, or both.');
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            $errors['description'] = __('Say what is wrong.');
        }
        $openedOn = filled($data['opened_on'] ?? null) ? (string) $data['opened_on'] : now()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $openedOn)) {
            $errors['opened_on'] = __('Enter a valid date.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $amount = filled($data['claimed_amount'] ?? null) ? $this->amount($data['claimed_amount'], 'claimed_amount') : null;
        $currency = $amount !== null
            ? strtoupper((string) ($data['currency'] ?? '') ?: ($order?->currency ?? $pi?->currency ?? ''))
            : null;
        if ($amount !== null && ! preg_match('/^[A-Z]{3}$/', (string) $currency)) {
            throw ValidationException::withMessages(['currency' => __('Choose the currency of the amount claimed.')]);
        }

        return [
            'kind' => $kind,
            'supplier_order_id' => $order?->id,
            'supplier_id' => $supplierId,
            'proforma_invoice_id' => $pi?->id,
            'client_id' => $clientId,
            'opened_on' => $openedOn,
            'description' => mb_substr($description, 0, 5000),
            'cartons' => filled($data['cartons'] ?? null) ? $this->amount($data['cartons'], 'cartons') : null,
            'claimed_amount' => $amount,
            'currency' => $currency,
        ];
    }

    private function amount(mixed $value, string $field, bool $zeroAllowed = false): string
    {
        return Money::amount($value, $field, ! $zeroAllowed);
    }

    private function assertVisible(Claim $claim, User $by): void
    {
        if (! $this->visibleTo($claim, $by)) {
            throw new AuthorizationException(__('You may not do this.'));
        }
    }

    private function authorize(User $by, string $permission): void
    {
        if (! $by->can($permission)) {
            throw new AuthorizationException(__('You may not do this.'));
        }
    }

    private function write(callable $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23514', '23001', 'P0001'], true)) {
                throw ValidationException::withMessages(['claim' => __('The database refused this change: :reason', ['reason' => self::reason($e)])]);
            }
            throw $e;
        }
    }

    public static function reason(QueryException $e): string
    {
        $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();

        return preg_match('/ERROR:\s+(.+?)(?:\n|CONTEXT|$)/s', $message, $m) ? trim($m[1]) : $message;
    }
}
