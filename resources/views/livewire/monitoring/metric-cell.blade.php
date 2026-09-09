@php
    $available = $metric['available'] ?? false;
    $unit = $metric['unit'] ?? '';
    $scale = max((float) ($scale ?? 100), 0.01);
    $points = $metric['points'] ?? [];
    $barWidth = min((float) ($metric['current'] ?? 0) / $scale * 100, 100);
@endphp

<div class="min-w-0">
    @if ($available)
        <div class="flex items-baseline justify-between gap-2">
            <span class="text-[13px] font-medium text-black dark:text-fg">{{ $metric['current'] }}{{ $unit }}</span>
            <span class="text-[11px] text-neutral-500 dark:text-fg-dim">max {{ $metric['max'] }}</span>
        </div>
        <div class="monitoring-sparkline" aria-hidden="true">
            @foreach ($points as $point)
                <span class="monitoring-sparkline-bar"
                    style="height: {{ max(min((float) $point / $scale * 100, 100), 6) }}%"></span>
            @endforeach
        </div>
        <div class="monitoring-meter" aria-hidden="true">
            <span style="width: {{ $barWidth }}%"></span>
        </div>
    @else
        @php
            $emptyLabel = ! $hasMetrics ? 'Unsupported' : (! $metricsEnabled ? 'Metrics off' : '-');
            $emptyTitle = ! $hasMetrics
                ? 'Sentinel stores container metrics under the Docker container name, and its history endpoint only accepts names made of letters and digits. Compose container names contain a hyphen, so no history can be read for them.'
                : (! $metricsEnabled ? 'Enable metrics on this server to collect container history.' : 'No samples in the selected time range.');
        @endphp
        <span class="text-[13px] text-neutral-400 dark:text-fg-faint" title="{{ $emptyTitle }}">
            {{ $emptyLabel }}
        </span>
    @endif
</div>
