{{-- Shared by create and edit: the header and the dynamic lines table with a live balance check. --}}
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <label class="form-label" for="entry_date">{{ __('Date') }} <span class="text-danger">*</span></label>
        <input id="entry_date" name="entry_date" type="date" class="form-control @error('entry_date') is-invalid @enderror" value="{{ old('entry_date', $entry?->entry_date?->toDateString() ?? now()->toDateString()) }}" required>
        @error('entry_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-9">
        <label class="form-label" for="description">{{ __('Description') }}</label>
        <input id="description" name="description" class="form-control" value="{{ old('description', $entry?->description) }}" maxlength="2000">
    </div>
</div>

<p class="form-text mb-2">{{ __('Enter each line\'s debit or credit in its currency. A line in another currency than :book takes the rate to :book; its :book amount is the amount × the rate.', ['book' => $book]) }}</p>
@php $lineErrors = collect($errors->getMessages())->filter(fn ($m, $key) => str_starts_with($key, 'lines'))->flatten()->unique(); @endphp
@if($lineErrors->isNotEmpty())
    <div class="alert alert-danger py-2">@foreach($lineErrors as $message)<div>{{ $message }}</div>@endforeach</div>
@endif

<div class="table-responsive app-board">
    <table class="table table-sm align-middle mb-2" id="journal-lines">
        <thead><tr>
            <th>{{ __('Account') }}</th><th>{{ __('Party') }}</th><th>{{ __('Memo') }}</th><th>{{ __('Currency') }}</th>
            <th>{{ __('Debit') }}</th><th>{{ __('Credit') }}</th><th>{{ __('Rate') }}</th><th class="text-end">{{ __('In :book', ['book' => $book]) }}</th><th></th>
        </tr></thead>
        <tbody>
            @foreach($lines as $i => $line)
                @include('accounting.journal._line', ['i' => $i, 'line' => $line])
            @endforeach
        </tbody>
        <tfoot>
            <tr class="fw-semibold">
                <td colspan="4"><button type="button" class="btn btn-sm btn-light" id="add-line"><i class="bi bi-plus-lg me-1"></i>{{ __('Add line') }}</button></td>
                <td class="app-num" id="total-debit">0.00</td>
                <td class="app-num" id="total-credit">0.00</td>
                <td colspan="3" class="text-end"><span id="balance-state" class="app-pill"></span></td>
            </tr>
        </tfoot>
    </table>
</div>

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
