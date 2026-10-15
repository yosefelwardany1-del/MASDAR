{{-- One row per screen, one column per level. Read-only unless $editable. --}}
@php $levelNames = ['none' => __('None'), 'view' => __('View'), 'edit' => __('Edit'), 'approve' => __('Approve')]; @endphp
<div class="table-responsive">
    <table class="table align-middle mb-0">
        <thead><tr><th>{{ __('Screen') }}</th>@foreach($levelNames as $name)<th class="text-center">{{ $name }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach($screens as $key => $screen)
            <tr data-screen="{{ $key }}" data-level="{{ $levels[$key] }}">
                <td><i class="bi {{ $screen['icon'] }} me-2 text-muted"></i>{{ $screen['label'] }}</td>
                @foreach(array_keys($levelNames) as $level)
                    <td class="text-center">
                        @if($level === 'none' || in_array($level, $screen['levels'], true))
                            @if($editable)
                                <input class="form-check-input" type="radio" name="levels[{{ $key }}]" value="{{ $level }}" @checked($levels[$key] === $level) aria-label="{{ $screen['label'] }}: {{ $levelNames[$level] }}">
                            @elseif($levels[$key] === $level)
                                <i class="bi bi-check-circle-fill text-success"></i>
                            @else
                                <span class="text-muted">·</span>
                            @endif
                        @endif
                    </td>
                @endforeach
            </tr>
            @error("levels.$key")<tr><td colspan="5" class="text-danger small">{{ $message }}</td></tr>@enderror
        @endforeach
        </tbody>
    </table>
</div>
