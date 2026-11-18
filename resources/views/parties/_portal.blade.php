{{-- The client's portal accounts (spec §5.8, Q-R2): give one to a contact, take it away. --}}
@php
    $accounts = \App\Models\User::query()->where('party_id', $party->id)->orderBy('email')->get();
    $open = \App\Services\Portal\ClientPortalService::enabled();
@endphp
<div class="app-card p-3 mt-3">
    <div class="fw-semibold mb-2"><i class="bi bi-door-open me-1" aria-hidden="true"></i>{{ __('Client portal') }}</div>
    @unless($open)
        <p class="app-note mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('The portal is off: switch it on under Lists & options → Business options for these accounts to sign in.') }}</span></p>
    @endunless
    <p class="small text-muted">{{ __('A portal account sees only this client\'s orders, payments, containers and statement — never a factory or a price we pay.') }}</p>
    @foreach($accounts as $a)
        <div class="d-flex align-items-center gap-2 py-1 border-bottom">
            <span class="app-ltr">{{ $a->email }}</span>
            <x-status :tone="$a->status === 'active' ? 'done' : 'skip'">{{ $a->status === 'active' ? __('Active') : __('Inactive') }}</x-status>
            @if($a->status === 'active')
                <form method="POST" action="{{ route('clients.portal.destroy', [$party, $a]) }}" class="ms-auto" onsubmit="return confirm(@js(__('Take away this portal access?')));">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger">{{ __('Take away') }}</button></form>
            @endif
        </div>
    @endforeach
    <form method="POST" action="{{ route('clients.portal.store', $party) }}" class="row g-2 align-items-end mt-2">
        @csrf
        <div class="col-md-5"><label class="form-label small" for="portal-email">{{ __('Email') }}</label><input id="portal-email" name="email" type="email" class="form-control form-control-sm @error('email') is-invalid @enderror" dir="ltr" required></div>
        <div class="col-md-4"><label class="form-label small" for="portal-name">{{ __('Name') }}</label><input id="portal-name" name="name" class="form-control form-control-sm" dir="auto"></div>
        <div class="col-md-3"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-person-plus" aria-hidden="true"></i>{{ __('Give portal access') }}</button></div>
        @error('email')<div class="col-12 text-danger small">{{ $message }}</div>@enderror
    </form>
</div>
