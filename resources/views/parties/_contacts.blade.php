{{-- Contacts of a party, added, edited and deleted in place. Each form has its own error bag
     ("contact" for the new one, "contact_{id}" for an existing one) so a mistake in one form
     does not light up the others. --}}
@php
    $canEdit = auth()->user()->can($screen.'.edit');
    $newErrors = $errors->getBag('contact');
@endphp
<div class="app-card p-4 mt-3" id="contacts">
    <h2 class="h6 mb-3">{{ __('Contacts') }} <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $party->contacts->count() }}</span></h2>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Name') }}</th><th>{{ __('Role') }}</th><th>{{ __('Phone') }}</th><th>{{ __('WhatsApp') }}</th>
                <th>{{ __('WeChat') }}</th><th>{{ __('E-mail') }}</th>@if($canEdit)<th></th>@endif
            </tr></thead>
            <tbody>
            @forelse($party->contacts as $c)
                @php $bag = $errors->getBag('contact_'.$c->id); $editing = $bag->any(); @endphp
                <tr>
                    <td>{{ $c->name }} @if($c->is_primary)<span class="app-pill app-pill--done ms-1">{{ __('Primary') }}</span>@endif</td>
                    <td>{{ $c->role ?: '—' }}</td>
                    <td>@if($c->phone)<a href="tel:{{ $c->phone }}" class="app-num">{{ $c->phone }}</a>@else — @endif</td>
                    <td>@if($c->whatsapp)<a href="https://wa.me/{{ ltrim($c->whatsapp, '+') }}" target="_blank" rel="noopener" class="app-num">{{ $c->whatsapp }}</a>@else — @endif</td>
                    <td><span class="app-ltr">{{ $c->wechat ?: '—' }}</span></td>
                    <td>@if($c->email)<a href="mailto:{{ $c->email }}" class="app-ltr">{{ $c->email }}</a>@else — @endif</td>
                    @if($canEdit)
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#contact-edit-{{ $c->id }}" title="{{ __('Edit') }}"><i class="bi bi-pencil"></i></button>
                            <form method="POST" action="{{ route($prefix.'.contacts.destroy', [$party, $c]) }}" class="d-inline" onsubmit="return confirm(@js(__('Delete this contact?')));">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-light text-danger" title="{{ __('Delete') }}"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    @endif
                </tr>
                @if($canEdit)
                    <tr class="collapse {{ $editing ? 'show' : '' }}" id="contact-edit-{{ $c->id }}">
                        <td colspan="7" class="bg-body-tertiary">
                            <form method="POST" action="{{ route($prefix.'.contacts.update', [$party, $c]) }}">
                                @csrf @method('PUT')
                                @include('parties._contact_fields', ['contact' => $c, 'bag' => $bag, 'useOld' => $editing, 'idPrefix' => 'c'.$c->id])
                                <button class="btn btn-sm btn-primary mt-2">{{ __('Save') }}</button>
                            </form>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="7" class="text-muted small">{{ __('No contacts yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($canEdit)
        <details class="mt-3" @if($newErrors->any()) open @endif>
            <summary class="btn btn-sm btn-light"><i class="bi bi-person-plus me-1"></i>{{ __('Add a contact') }}</summary>
            <form method="POST" action="{{ route($prefix.'.contacts.store', $party) }}" class="mt-3">
                @csrf
                @include('parties._contact_fields', ['contact' => null, 'bag' => $newErrors, 'useOld' => $newErrors->any(), 'idPrefix' => 'new'])
                <button class="btn btn-sm btn-primary mt-2">{{ __('Add') }}</button>
            </form>
        </details>
    @endif
</div>
