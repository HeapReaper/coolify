@php
    $available = $metric['available'] ?? false;
    $unit = $metric['unit'] ?? '';
@endphp

<div class="monitoring-stat">
    <p class="monitoring-stat-label">{{ $label }}</p>
    <p class="monitoring-stat-value">
        {{ $available ? $metric['current'] . $unit : '-' }}
    </p>
    <p class="monitoring-stat-meta">
        @if ($available)
            Avg {{ $metric['average'] }}{{ $unit }} · Max {{ $metric['max'] }}{{ $unit }}
        @else
            No data yet
        @endif
    </p>
</div>
