{{-- A sourcing request's facts. $sourcing (null when new), $units, $currencies, $products, $clients. --}}
@php $v = fn (string $f) => old($f, $sourcing?->{$f}); $plain = fn ($x) => $x === null ? '' : (rtrim(rtrim((string) $x, '0'), '.') ?: '0'); @endphp
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="product_id">{{ __('Product') }}</label>
        <select id="product_id" name="product_id" class="form-select">
            <option value="">{{ __('A new item') }}</option>
            @foreach($products as $p)<option value="{{ $p->id }}" @selected((int) $v('product_id') === $p->id)>{{ $p->name }} ({{ $p->code }})</option>@endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="client_id">{{ __('For the client') }}</label>
        <select id="client_id" name="client_id" class="form-select @error('client_id') is-invalid @enderror">
            <option value="">{{ __('None yet') }}</option>
            @foreach($clients as $c)<option value="{{ $c->id }}" @selected((int) $v('client_id') === $c->id)>{{ $c->name }} ({{ $c->code }})</option>@endforeach
        </select>
        @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12">
        <label class="form-label" for="description">{{ __('What is wanted') }}</label>
        <textarea id="description" name="description" rows="2" class="form-control @error('description') is-invalid @enderror" dir="auto">{{ $v('description') }}</textarea>
        <div class="form-text">{{ __('Size, material, colour, packing — what the factories quote on. It becomes the quotation line\'s description.') }}</div>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label" for="quantity">{{ __('Quantity') }}</label>
        <input id="quantity" name="quantity" inputmode="decimal" value="{{ $plain($v('quantity')) }}" class="form-control @error('quantity') is-invalid @enderror">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label" for="unit">{{ __('Unit') }}</label>
        <select id="unit" name="unit" class="form-select @error('unit') is-invalid @enderror">
            <option value="">—</option>
            @foreach($units as $code => $label)<option value="{{ $code }}" @selected($v('unit') === $code)>{{ $label }}</option>@endforeach
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label" for="target_price">{{ __('Target factory price') }}</label>
        <input id="target_price" name="target_price" inputmode="decimal" value="{{ $plain($v('target_price')) }}" class="form-control @error('target_price') is-invalid @enderror">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label" for="target_currency">{{ __('Currency') }}</label>
        <select id="target_currency" name="target_currency" class="form-select @error('target_currency') is-invalid @enderror">
            <option value="">—</option>
            @foreach($currencies as $code => $label)<option value="{{ $code }}" @selected(($v('target_currency') ?? 'CNY') === $code)>{{ $code }}</option>@endforeach
        </select>
        @error('target_currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="needed_by">{{ __('Needed by') }}</label>
        <input id="needed_by" type="date" name="needed_by" value="{{ $v('needed_by') instanceof \DateTimeInterface ? $v('needed_by')->format('Y-m-d') : $v('needed_by') }}" class="form-control">
    </div>
    <div class="col-md-8">
        <label class="form-label" for="notes">{{ __('Notes') }}</label>
        <input id="notes" name="notes" value="{{ $v('notes') }}" class="form-control" dir="auto">
    </div>
</div>
