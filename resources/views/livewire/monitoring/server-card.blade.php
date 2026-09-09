@php
    $chartId = 'monitoring-chart-' . $server['uuid'];
@endphp

<x-application.settings-section :title="$server['name']" flush wire:key="monitoring-server-{{ $server['uuid'] }}">
    <x-slot:actions>
        @if ($server['is_functional'])
            <x-status-badge status="Online" type="success" />
        @elseif ($server['is_reachable'])
            <x-status-badge status="Reachable" type="warning" />
        @else
            <x-status-badge status="Offline" type="neutral" />
        @endif

        <x-status-badge label="Proxy" :status="str($server['proxy_status'])->headline()" :type="str($server['proxy_status'])->startsWith('running') ? 'success' : 'neutral'" />

        <a class="button" href="{{ $server['href'] }}" {{ wireNavigate() }}>Open server</a>
    </x-slot:actions>

    <div class="flex min-w-0 flex-col">
        <div class="flex flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                <span>{{ $server['ip'] ?: 'No IP address' }}</span>
                <span>·</span>
                <span>{{ $server['container_count'] }} {{ Str::plural('container', $server['container_count']) }}</span>
                <span>·</span>
                <span>{{ $server['running_container_count'] }} running</span>
            </div>

            <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                @include('livewire.monitoring.metric-card', [
                    'label' => 'CPU',
                    'metric' => $server['cpu'],
                ])

                @include('livewire.monitoring.metric-card', [
                    'label' => 'Memory',
                    'metric' => $server['memory'],
                ])

                <div class="monitoring-stat">
                    <p class="monitoring-stat-label">Containers</p>
                    <p class="monitoring-stat-value">{{ $server['running_container_count'] }}/{{ $server['container_count'] }}</p>
                    <p class="monitoring-stat-meta">Running of total</p>
                </div>

                <div class="monitoring-stat">
                    <p class="monitoring-stat-label">Metrics</p>
                    <p class="monitoring-stat-value">{{ $server['metrics_enabled'] ? 'On' : 'Off' }}</p>
                    <p class="monitoring-stat-meta">
                        @if ($server['metrics_enabled'])
                            <a class="underline underline-offset-4" href="{{ $server['metrics_href'] }}"
                                {{ wireNavigate() }}>Sentinel settings</a>
                        @else
                            Sentinel is not collecting
                        @endif
                    </p>
                </div>
            </div>

            @if ($server['metrics_enabled'])
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="monitoring-chart-shell">
                        <p class="monitoring-stat-label">CPU usage</p>
                        <div wire:ignore id="{{ $chartId }}-cpu" data-monitoring-chart data-metric="cpu"
                            data-server="{{ $server['uuid'] }}" class="mt-2 min-h-[200px] w-full"></div>
                    </div>

                    <div class="monitoring-chart-shell">
                        <p class="monitoring-stat-label">Memory usage</p>
                        <div wire:ignore id="{{ $chartId }}-memory" data-monitoring-chart data-metric="memory"
                            data-server="{{ $server['uuid'] }}" class="mt-2 min-h-[200px] w-full"></div>
                    </div>
                </div>
            @else
                <x-empty size="sm" title="Metrics are disabled"
                    :description="$server['sentinel_enabled']
                        ? 'Enable metrics on this server to collect CPU and memory history.'
                        : 'Enable Sentinel on this server before collecting CPU and memory history.'"
                    icon-name="graph">
                    <x-slot:contents>
                        <a class="button" href="{{ $server['metrics_href'] }}" {{ wireNavigate() }}>
                            Configure metrics
                        </a>
                    </x-slot:contents>
                </x-empty>
            @endif
        </div>

        <div class="monitoring-table-section">
            @if (empty($server['containers']))
                <div class="p-4">
                    <x-empty size="sm" title="No containers on this server"
                        description="Applications, databases and services deployed here appear in this table."
                        icon-name="layers" />
                </div>
            @else
                <div class="data-table monitoring-table-scroll w-full">
                    <div class="data-table-header monitoring-container-grid">
                        <span>Container</span>
                        <span>Type</span>
                        <span>Project</span>
                        <span>Status</span>
                        <span>CPU</span>
                        <span>Memory</span>
                    </div>

                    @foreach ($server['containers'] as $container)
                        <div class="data-table-row monitoring-container-grid"
                            wire:key="monitoring-container-{{ $server['uuid'] }}-{{ $container['key'] }}">
                            <div class="min-w-0">
                                @if ($container['href'])
                                    <a href="{{ $container['href'] }}" {{ wireNavigate() }}
                                        class="block truncate text-[13px] font-medium text-black hover:underline dark:text-fg">
                                        {{ $container['name'] }}
                                    </a>
                                @else
                                    <span
                                        class="block truncate text-[13px] font-medium text-black dark:text-fg">{{ $container['name'] }}</span>
                                @endif
                                @if ($container['detail'])
                                    <span class="block truncate font-mono text-[11px] text-neutral-500 dark:text-fg-dim"
                                        title="{{ $container['detail'] }}">{{ $container['detail'] }}</span>
                                @endif
                            </div>

                            <span class="truncate text-[13px] text-neutral-500 dark:text-fg-dim">
                                {{ $container['type_label'] }}
                            </span>

                            <span class="truncate text-[13px] text-neutral-500 dark:text-fg-dim"
                                title="{{ collect([$container['project'], $container['environment']])->filter()->join(' / ') }}">
                                {{ collect([$container['project'], $container['environment']])->filter()->join(' / ') ?: '-' }}
                            </span>

                            <span class="min-w-0">
                                <x-status-badge :status="$container['status']['label']" :type="$container['status']['type']"
                                    :title="$container['status']['raw']" />
                            </span>

                            @include('livewire.monitoring.metric-cell', [
                                'metric' => $container['cpu'],
                                'scale' => 100,
                                'hasMetrics' => $container['has_metrics'],
                                'metricsEnabled' => $server['metrics_enabled'],
                            ])

                            @include('livewire.monitoring.metric-cell', [
                                'metric' => $container['memory'],
                                'scale' => $server['memory_scale'],
                                'hasMetrics' => $container['has_metrics'],
                                'metricsEnabled' => $server['metrics_enabled'],
                            ])
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-application.settings-section>
