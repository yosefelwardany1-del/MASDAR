{{-- One row of the lines table. $i is the index (or __INDEX__ in the template), $line the old values. --}}
@php $currency = $line['currency'] ?? $book; @endphp
<tr data-line>
    <td style="min-inline-size: 220px">
        <select name="lines[{{ $i }}][account_id]" aria-label="{{ __('Account') }}" class="form-select form-select-sm" data-account>
            <option value="">{{ __('— account —') }}</option>
            @foreach($accounts as $account)
                <option value="{{ $account->id }}" data-currency="{{ $account->currency }}" @selected((string) ($line['account_id'] ?? '') === (string) $account->id)>{{ $account->label() }}</option>
            @endforeach
        </select>
    </td>
    <td style="min-inline-size: 180px">
        <select name="lines[{{ $i }}][party_id]" aria-label="{{ __('Party') }}" class="form-select form-select-sm">
            <option value="">—</option>
            @foreach($parties as $party)
                <option value="{{ $party->id }}" @selected((string) ($line['party_id'] ?? '') === (string) $party->id)>{{ $party->name }}</option>
            @endforeach
        </select>
    </td>
    <td style="min-inline-size: 140px"><input name="lines[{{ $i }}][memo]" aria-label="{{ __('Memo') }}" class="form-control form-control-sm" value="{{ $line['memo'] ?? '' }}" maxlength="255"></td>
    <td style="min-inline-size: 90px">
        <select name="lines[{{ $i }}][currency]" aria-label="{{ __('Currency') }}" class="form-select form-select-sm" data-currency>
            @foreach($currencies as $code)
                <option value="{{ $code }}" @selected($currency === $code)>{{ $code }}</option>
            @endforeach
        </select>
    </td>
    <td style="min-inline-size: 110px"><input name="lines[{{ $i }}][debit]" aria-label="{{ __('Debit') }}" class="form-control form-control-sm app-num text-end" dir="ltr" inputmode="decimal" value="{{ $line['debit'] ?? '' }}" data-debit></td>
    <td style="min-inline-size: 110px"><input name="lines[{{ $i }}][credit]" aria-label="{{ __('Credit') }}" class="form-control form-control-sm app-num text-end" dir="ltr" inputmode="decimal" value="{{ $line['credit'] ?? '' }}" data-credit></td>
    <td style="min-inline-size: 100px"><input name="lines[{{ $i }}][rate]" aria-label="{{ __('Rate') }}" class="form-control form-control-sm app-num text-end" dir="ltr" inputmode="decimal" value="{{ $currency === $book ? '1' : ($line['rate'] ?? '') }}" @readonly($currency === $book) data-rate></td>
    <td class="app-num text-end text-nowrap" dir="ltr" data-book>—</td>
    <td><button type="button" class="btn btn-sm btn-light text-danger" data-remove title="{{ __('Remove line') }}" aria-label="{{ __('Remove line') }}"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>
</tr>
