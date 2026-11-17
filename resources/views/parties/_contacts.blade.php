{{-- Contacts of a party, added, edited and deleted in place. Each form has its own error bag
     ("contact" for the new one, "contact_{id}" for an existing one) so a mistake in one form
     does not light up the others. Each way to reach someone is a link that opens it. --}}
@php
    $canEdit = auth()->user()->can($screen.'.edit');
    $newErrors = $errors->getBag('contact');
    $initials = function (string $name) {
        $words = preg_split('/\s+/u', trim($name)) ?: [];

        return mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    };
@endphp
<div class="app-card">
    <div class="app-card__head border-bottom" id="contacts-head">
        <i class="bi bi-person-lines-fill" aria-hidden="true"></i>{{ __('Contacts') }}
        <span class="app-ptabs__count app-num">{{ $party->contacts->count() }}</span>
    </div>

    <ul class="app-contacts">
        @forelse($party->contacts as $c)
            @php $bag = $errors->getBag('contact_'.$c->id); $editing = $bag->any(); @endphp
            <li class="app-contact {{ $c->is_primary ? 'is-primary' : '' }}">
                <div class="app-contact__row">
                    <span class="app-contact__mark" aria-hidden="true">{{ $initials($c->name) }}</span>
                    <div class="app-contact__who">
                        <div class="app-contact__name"><bdi>{{ $c->name }}</bdi>
                            @if($c->is_primary)<span class="app-pill app-pill--done"><i class="bi bi-star-fill" aria-hidden="true"></i>{{ __('Primary') }}</span>@endif
                        </div>
                        <div class="app-contact__role"><bdi>{{ $c->role ?: '—' }}</bdi></div>
                        @if($c->notes)<div class="app-contact__notes"><bdi>{{ $c->notes }}</bdi></div>@endif
                    </div>
                    <ul class="app-contact__ways">
                        @if($c->phone)
                            <li><a href="tel:{{ $c->phone }}" class="app-way"><i class="bi bi-telephone" aria-hidden="true"></i><span class="visually-hidden">{{ __('Phone') }}</span><span class="app-num">{{ $c->phone }}</span></a></li>
                        @endif
                        @if($c->whatsapp)
                            <li><a href="https://wa.me/{{ ltrim($c->whatsapp, '+') }}" target="_blank" rel="noopener" class="app-way app-way--whatsapp"><i class="bi bi-whatsapp" aria-hidden="true"></i><span class="visually-hidden">{{ __('WhatsApp') }}</span><span class="app-num">{{ $c->whatsapp }}</span></a></li>
                        @endif
                        @if($c->wechat)
                            <li><span class="app-way app-way--wechat"><i class="bi bi-wechat" aria-hidden="true"></i><span class="visually-hidden">{{ __('WeChat') }}</span><span class="app-ltr">{{ $c->wechat }}</span></span></li>
                        @endif
                        @if($c->email)
                            <li><a href="mailto:{{ $c->email }}" class="app-way"><i class="bi bi-envelope" aria-hidden="true"></i><span class="visually-hidden">{{ __('E-mail') }}</span><span class="app-ltr">{{ $c->email }}</span></a></li>
                        @endif
                        @if(! $c->phone && ! $c->whatsapp && ! $c->wechat && ! $c->email)
                            <li class="app-contact__none">{{ __('No phone, WhatsApp, WeChat or e-mail yet.') }}</li>
                        @endif
                    </ul>
                    @if($canEdit)
                        <div class="app-contact__actions">
                            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#contact-edit-{{ $c->id }}" aria-controls="contact-edit-{{ $c->id }}" aria-expanded="{{ $editing ? 'true' : 'false' }}" title="{{ __('Edit') }}"><i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">{{ __('Edit') }} {{ $c->name }}</span></button>
                            <form method="POST" action="{{ route($prefix.'.contacts.destroy', [$party, $c]) }}" class="d-inline" onsubmit="return confirm(@js(__('Delete this contact?')));">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-light text-danger" title="{{ __('Delete') }}"><i class="bi bi-trash" aria-hidden="true"></i><span class="visually-hidden">{{ __('Delete') }} {{ $c->name }}</span></button>
                            </form>
                        </div>
                    @endif
                </div>
                @if($canEdit)
                    <div class="collapse {{ $editing ? 'show' : '' }}" id="contact-edit-{{ $c->id }}">
                        <form method="POST" action="{{ route($prefix.'.contacts.update', [$party, $c]) }}" class="app-contact__form">
                            @csrf @method('PUT')
                            @include('parties._contact_fields', ['contact' => $c, 'bag' => $bag, 'useOld' => $editing, 'idPrefix' => 'c'.$c->id])
                            <div class="d-flex gap-2 mt-3">
                                <button class="btn btn-sm btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
                                <button type="button" class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#contact-edit-{{ $c->id }}">{{ __('Close') }}</button>
                            </div>
                        </form>
                    </div>
                @endif
            </li>
        @empty
            <li class="app-contacts__empty">
                <x-empty icon="person-lines-fill" :title="__('No contacts yet.')" :text="__('Add the people you deal with, with their phone, WhatsApp, WeChat and e-mail.')" />
            </li>
        @endforelse
    </ul>

    @if($canEdit)
        <details class="app-adder" @if($newErrors->any()) open @endif>
            <summary class="app-adder__summary"><i class="bi bi-person-plus" aria-hidden="true"></i>{{ __('Add a contact') }}</summary>
            <form method="POST" action="{{ route($prefix.'.contacts.store', $party) }}" class="app-adder__body">
                @csrf
                @include('parties._contact_fields', ['contact' => null, 'bag' => $newErrors, 'useOld' => $newErrors->any(), 'idPrefix' => 'new'])
                <button class="btn btn-sm btn-primary mt-3"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add') }}</button>
            </form>
        </details>
    @endif
</div>
