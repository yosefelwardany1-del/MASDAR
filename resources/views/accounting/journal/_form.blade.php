{{-- Shared by create and edit: the header and the dynamic lines table with a live balance check. --}}
@include('configuration._form-errors')
<p class="app-form__legend"><span class="app-req" aria-hidden="true">*</span>{{ __('Required field') }}</p>

<x-section :title="__('Entry')" icon="journal-text">
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label" for="entry_date">{{ __('Date') }}<span class="app-req" aria-hidden="true">*</span></label>
            <input id="entry_date" name="entry_date" type="date" class="form-control @error('entry_date') is-invalid @enderror" value="{{ old('entry_date', $entry?->entry_date?->toDateString() ?? now()->toDateString()) }}" required>
            @error('entry_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-9">
            <label class="form-label" for="description">{{ __('Description') }}</label>
            <input id="description" name="description" class="form-control" value="{{ old('description', $entry?->description) }}" maxlength="2000">
        </div>
    </div>
</x-section>

@php $lineErrors = collect($errors->getMessages())->filter(fn ($m, $key) => str_starts_with($key, 'lines'))->flatten()->unique(); @endphp
<x-section :title="__('Lines')" icon="list-ul" flush>
    <p class="app-note mx-3 mt-3 mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('Enter each line\'s debit or credit in its currency. A line in another currency than :book takes the rate to :book; its :book amount is the amount × the rate.', ['book' => $book]) }}</span></p>
    @if($lineErrors->isNotEmpty())
        <div class="alert alert-danger mx-3 mb-2" role="alert">@foreach($lineErrors as $message)<div class="app-field-error text-reset">{{ $message }}</div>@endforeach</div>
    @endif
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 app-jlines" id="journal-lines">
            <thead><tr>
                <th scope="col">{{ __('Account') }}</th><th scope="col">{{ __('Party') }}</th><th scope="col">{{ __('Memo') }}</th><th scope="col">{{ __('Currency') }}</th>
                <th scope="col" class="text-end">{{ __('Debit') }}</th><th scope="col" class="text-end">{{ __('Credit') }}</th><th scope="col" class="text-end">{{ __('Rate') }}</th><th scope="col" class="text-end">{{ __('In :book', ['book' => $book]) }}</th><th scope="col"><span class="visually-hidden">{{ __('Remove line') }}</span></th>
            </tr></thead>
            <tbody>
                @foreach($lines as $i => $line)
                    @include('accounting.journal._line', ['i' => $i, 'line' => $line])
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4"><button type="button" class="btn btn-sm btn-light" id="add-line"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add line') }}</button></td>
                    <td class="text-end app-jlines__total"><span class="app-num" id="total-debit">0.00</span></td>
                    <td class="text-end app-jlines__total"><span class="app-num" id="total-credit">0.00</span></td>
                    <td colspan="3" class="text-end"><span id="balance-state" class="app-pill" role="status" aria-live="polite"></span></td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-section>

<template id="line-template">
    @include('accounting.journal._line', ['i' => '__INDEX__', 'line' => []])
</template>

@push('scripts')
<script>
(function () {
    const book = @js($book);
    const rates = @js($rates);
    const text = { balanced: @js(__('Balanced')), off: @js(__('Not balanced: difference :amount')) };
    const table = document.getElementById('journal-lines');
    const body = table.querySelector('tbody');
    let next = body.querySelectorAll('[data-line]').length;

    const num = (v) => { const n = parseFloat(String(v || '').replace(/,/g, '')); return isNaN(n) ? 0 : n; };
    const fmt = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const round4 = (n) => Math.round(n * 10000) / 10000;

    function syncRate(row, fromCurrencyChange) {
        const currency = row.querySelector('[data-currency]').value;
        const rate = row.querySelector('[data-rate]');
        if (currency === book) { rate.value = '1'; rate.readOnly = true; }
        else {
            rate.readOnly = false;
            if (fromCurrencyChange || rate.value === '' || rate.value === '1') rate.value = rates[currency] || '';
        }
    }

    function recalc() {
        let debit = 0, credit = 0;
        body.querySelectorAll('[data-line]').forEach((row) => {
            const rate = num(row.querySelector('[data-rate]').value);
            const d = round4(num(row.querySelector('[data-debit]').value) * rate);
            const c = round4(num(row.querySelector('[data-credit]').value) * rate);
            debit += d; credit += c;
            row.querySelector('[data-book]').textContent = d ? fmt(d) + ' Dr' : (c ? fmt(c) + ' Cr' : '—');
        });
        debit = round4(debit); credit = round4(credit);
        document.getElementById('total-debit').textContent = fmt(debit);
        document.getElementById('total-credit').textContent = fmt(credit);
        const state = document.getElementById('balance-state');
        const diff = round4(debit - credit);
        const ok = diff === 0 && debit > 0;
        state.className = 'app-pill ' + (ok ? 'app-pill--done' : 'app-pill--late');
        state.textContent = ok ? text.balanced : text.off.replace(':amount', fmt(Math.abs(diff)));
    }

    function wire(row) {
        row.querySelector('[data-currency]').addEventListener('change', () => { syncRate(row, true); recalc(); });
        row.querySelector('[data-account]').addEventListener('change', (e) => {
            const fixed = e.target.selectedOptions[0]?.dataset.currency;
            if (fixed) { row.querySelector('[data-currency]').value = fixed; syncRate(row, true); }
            recalc();
        });
        row.querySelectorAll('[data-debit], [data-credit], [data-rate]').forEach((input) => input.addEventListener('input', recalc));
        row.querySelector('[data-debit]').addEventListener('input', (e) => { if (e.target.value) row.querySelector('[data-credit]').value = ''; });
        row.querySelector('[data-credit]').addEventListener('input', (e) => { if (e.target.value) row.querySelector('[data-debit]').value = ''; });
        row.querySelector('[data-remove]').addEventListener('click', () => {
            if (body.querySelectorAll('[data-line]').length > 1) { row.remove(); } else { row.querySelectorAll('input:not([data-rate])').forEach((i) => i.value = ''); }
            recalc();
        });
        syncRate(row, false);
    }

    document.getElementById('add-line').addEventListener('click', () => {
        const html = document.getElementById('line-template').innerHTML.replaceAll('__INDEX__', String(next++));
        body.insertAdjacentHTML('beforeend', html);
        wire(body.lastElementChild);
        recalc();
    });

    body.querySelectorAll('[data-line]').forEach(wire);
    recalc();
})();
</script>
@endpush
