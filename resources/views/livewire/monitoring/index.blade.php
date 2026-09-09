<div class="application-settings-form w-full" x-data x-init="$wire.loadMetrics()" wire:poll.30s="refresh">
    <x-slot:title>
        Monitoring | Coolify
    </x-slot>

    <div class="flex min-w-0 flex-col gap-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div class="min-w-0">
                <h1>Monitoring</h1>
                <p class="mt-1 text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                    CPU and memory history per server, with every container running on it.
                </p>
                <p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                    <span>{{ $summary['servers'] }} {{ Str::plural('server', $summary['servers']) }}</span>
                    <span>·</span>
                    <span>{{ $summary['online_servers'] }} online</span>
                    <span>·</span>
                    <span>{{ $summary['containers'] }} {{ Str::plural('container', $summary['containers']) }}</span>
                    <span>·</span>
                    <span>{{ $summary['running_containers'] }} running</span>
                    @if ($summary['average_cpu'] !== null)
                        <span>·</span>
                        <span>Avg CPU {{ $summary['average_cpu'] }}%</span>
                    @endif
                    @if ($summary['average_memory'] !== null)
                        <span>·</span>
                        <span>Avg RAM {{ $summary['average_memory'] }}%</span>
                    @endif
                </p>
            </div>

            <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-end xl:w-auto">
                <div class="w-full sm:w-48">
                    <x-forms.listbox id="serverUuid" label="Server" onChange="applyFilters"
                        :options="$serverOptions" />
                </div>
                <div class="w-full sm:w-48">
                    <x-forms.listbox id="projectUuid" label="Project" onChange="applyFilters"
                        :options="$projectOptions" />
                </div>
                <div class="w-full sm:w-48">
                    <x-forms.listbox id="interval" label="Time range" onChange="setInterval"
                        :options="$this->rangeOptions()" />
                </div>
                <x-forms.button wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh">
                    <x-reicon name="refresh" class="size-3.5" wire:loading.remove wire:target="refresh" />
                    <x-loading-on-button wire:loading.delay wire:target="refresh" />
                    Refresh
                </x-forms.button>
            </div>
        </div>

        @forelse ($serverCards as $server)
            @include('livewire.monitoring.server-card', ['server' => $server])
        @empty
            <x-empty title="No servers to monitor"
                description="Add a server, or widen the current server and project filters."
                icon-name="servers">
                @if ($serverUuid !== '' || $projectUuid !== '')
                    <x-slot:contents>
                        <x-forms.button wire:click="clearFilters">Clear filters</x-forms.button>
                    </x-slot:contents>
                @endif
            </x-empty>
        @endforelse
    </div>

    @script
        <script>
            (() => {
                const charts = new Map();
                let latestSeries = {};

                const formatPercent = (value) => {
                    const number = Number(value);
                    const precision = Math.abs(number) < 1 ? 2 : 1;

                    return `${Number(number.toFixed(precision))}%`;
                };

                const formatLocalTimestamp = (timestamp) => new Date(timestamp).toLocaleString(undefined, {
                    hour12: false,
                    timeZoneName: 'short',
                });
                const formatUtcTimestamp = (timestamp) => new Date(timestamp).toLocaleString(undefined, {
                    hour12: false,
                    timeZone: 'UTC',
                    timeZoneName: 'short',
                });

                const axisOptions = () => ({
                    xaxis: {
                        type: 'datetime',
                        labels: {
                            datetimeUTC: false,
                            style: {
                                colors: textColor,
                            },
                        },
                    },
                    yaxis: {
                        min: 0,
                        max: (max) => (max > 0 ? max * 1.2 : 1),
                        forceNiceScale: true,
                        tickAmount: 4,
                        labels: {
                            style: {
                                colors: textColor,
                            },
                            formatter: formatPercent,
                        },
                    },
                });

                const chartOptions = (name, color) => ({
                    chart: {
                        height: 200,
                        type: 'area',
                        toolbar: {
                            show: false
                        },
                        zoom: {
                            enabled: false
                        },
                        animations: {
                            enabled: false
                        },
                        background: 'transparent',
                    },
                    series: [{
                        name,
                        data: [],
                    }],
                    colors: [color],
                    stroke: {
                        curve: 'smooth',
                        width: 2,
                    },
                    fill: {
                        type: 'gradient',
                        gradient: {
                            opacityFrom: 0.28,
                            opacityTo: 0.02,
                            stops: [0, 90, 100],
                        },
                    },
                    dataLabels: {
                        enabled: false,
                    },
                    grid: {
                        borderColor: 'rgba(128, 128, 128, 0.14)',
                        strokeDashArray: 4,
                    },
                    legend: {
                        show: false,
                    },
                    ...axisOptions(),
                    noData: {
                        text: `Loading ${name.toLowerCase()} metrics…`,
                        style: {
                            color: textColor,
                        },
                    },
                    tooltip: {
                        shared: false,
                        intersect: false,
                        followCursor: false,
                        fixed: {
                            enabled: false,
                        },
                        marker: {
                            show: false,
                        },
                        custom: ({
                            series,
                            seriesIndex,
                            dataPointIndex,
                            w
                        }) => {
                            const value = series[seriesIndex][dataPointIndex];
                            const timestamp = w.globals.seriesX[seriesIndex][dataPointIndex];

                            return `<div class="apexcharts-tooltip-custom">
                                <div class="apexcharts-tooltip-custom-value">${name}: <span class="apexcharts-tooltip-value-bold">${formatPercent(value)}</span></div>
                                <div class="apexcharts-tooltip-custom-title">Your time: ${formatLocalTimestamp(timestamp)}</div>
                                <div class="apexcharts-tooltip-custom-title">UTC: ${formatUtcTimestamp(timestamp)}</div>
                            </div>`;
                        },
                    },
                });

                const syncCharts = () => {
                    checkTheme();

                    document.querySelectorAll('[data-monitoring-chart]').forEach((element) => {
                        const metric = element.dataset.metric;
                        const name = metric === 'cpu' ? 'CPU' : 'Memory';
                        const color = metric === 'cpu' ? cpuColor : ramColor;
                        const data = latestSeries?.[element.dataset.server]?.[metric] ?? [];

                        let chart = charts.get(element.id);
                        if (!chart) {
                            chart = new ApexCharts(element, chartOptions(name, color));
                            chart.render();
                            charts.set(element.id, chart);
                        }

                        chart.updateOptions({
                            colors: [color],
                            series: [{
                                name,
                                data,
                            }],
                            ...axisOptions(),
                            noData: {
                                text: `No ${name.toLowerCase()} metrics available`,
                                style: {
                                    color: textColor,
                                },
                            },
                        });
                    });

                    charts.forEach((chart, id) => {
                        if (!document.getElementById(id)) {
                            chart.destroy();
                            charts.delete(id);
                        }
                    });
                };

                let scheduled = false;
                const scheduleSync = () => {
                    if (scheduled) return;
                    scheduled = true;
                    queueMicrotask(() => {
                        scheduled = false;
                        syncCharts();
                    });
                };

                Livewire.on('monitoringChartsUpdated', (payload) => {
                    latestSeries = Array.isArray(payload) ? payload[0] : payload;
                    scheduleSync();
                });

                // Filter changes morph new (empty) chart nodes into the DOM.
                Livewire.hook('morphed', scheduleSync);
            })();
        </script>
    @endscript
</div>
