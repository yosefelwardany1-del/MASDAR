{{-- One row per screen, one column per level. Read-only unless $editable. --}}
@php $levelNames = ['none' => __('None'), 'view' => __('View'), 'edit' => __('Edit'), 'approve' => __('Approve')]; @endphp
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0 app-matrix">
        <thead><tr><th scope="col">{{ __('Screen') }}</th>@foreach($levelNames as $name)<th scope="col" class="text-center">{{ $name }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach($screens as $key => $screen)
            <tr data-screen="{{ $key }}" data-level="{{ $levels[$key] }}">
                <th scope="row" class="fw-normal"><span class="app-matrix__label"><i class="bi {{ $screen['icon'] }}" aria-hidden="true"></i>{{ __($screen['label']) }}</span></th>
                @foreach(array_keys($levelNames) as $level)
                    <td class="app-matrix__cell">
                        @if($level === 'none' || in_array($level, $screen['levels'], true))
                            @if($editable)
                                <input class="form-check-input" type="radio" name="levels[{{ $key }}]" value="{{ $level }}" @checked($levels[$key] === $level) aria-label="{{ __($screen['label']) }}: {{ $levelNames[$level] }}">
                            @elseif($levels[$key] === $level)
                                <i class="bi bi-check-circle-fill app-matrix__on" aria-hidden="true"></i><span class="visually-hidden">{{ $levelNames[$level] }}</span>
                            @else
                                <span class="app-matrix__off" aria-hidden="true">·</span>
                            @endif
                        @endif
                    </td>
                @endforeach
            </tr>
            @error("levels.$key")<tr><td colspan="5"><span class="app-field-error">{{ $message }}</span></td></tr>@enderror
        @endforeach
        </tbody>
    </table>
</div>
