<?php

namespace App\Livewire\Monitoring;

use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    /**
     * Time ranges offered by the range listbox, in minutes.
     *
     * @var list<int>
     */
    public const INTERVALS = [5, 10, 30, 60, 720, 10080];

    /**
     * Database models that expose Sentinel container metrics.
     *
     * @var list<class-string<Model>>
     */
    private const DATABASE_MODELS = [
        StandalonePostgresql::class,
        StandaloneMysql::class,
        StandaloneMariadb::class,
        StandaloneMongodb::class,
        StandaloneRedis::class,
        StandaloneKeydb::class,
        StandaloneDragonfly::class,
        StandaloneClickhouse::class,
    ];

    /**
     * One card per server, each carrying its own metrics and container rows.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $serverCards = [];

    /** @var array<int, array{value: string, label: string}> */
    public array $serverOptions = [];

    /** @var array<int, array{value: string, label: string}> */
    public array $projectOptions = [];

    #[Url(as: 'server')]
    public string $serverUuid = '';

    #[Url(as: 'project')]
    public string $projectUuid = '';

    #[Url(as: 'range')]
    public int $interval = 5;

    /**
     * False until the first (deferred) metrics pass finished, so the first paint
     * never waits on Sentinel round trips.
     */
    public bool $metricsLoaded = false;

    /** @var array<string, mixed> */
    public array $summary = [
        'servers' => 0,
        'online_servers' => 0,
        'monitored_servers' => 0,
        'containers' => 0,
        'running_containers' => 0,
        'average_cpu' => null,
        'average_memory' => null,
    ];

    public function mount(): void
    {
        $this->normalizeFilters();
        $this->loadFilterOptions();
        $this->buildServerCards(withMetrics: false);
    }

    /**
     * Deferred first metrics pass, triggered from the view once the shell painted.
     */
    public function loadMetrics(): void
    {
        $this->buildServerCards(withMetrics: true);
    }

    public function refresh(): void
    {
        $this->loadFilterOptions();
        $this->buildServerCards(withMetrics: true);
    }

    public function applyFilters(): void
    {
        $this->normalizeFilters();
        $this->buildServerCards(withMetrics: true);
    }

    public function clearFilters(): void
    {
        $this->serverUuid = '';
        $this->projectUuid = '';

        $this->buildServerCards(withMetrics: true);
    }

    public function setInterval(): void
    {
        $this->normalizeFilters();
        $this->buildServerCards(withMetrics: true);
    }

    public function rangeOptions(): array
    {
        return [
            ['value' => 5, 'label' => 'Last 5 minutes'],
            ['value' => 10, 'label' => 'Last 10 minutes'],
            ['value' => 30, 'label' => 'Last 30 minutes'],
            ['value' => 60, 'label' => 'Last hour'],
            ['value' => 720, 'label' => 'Last 12 hours'],
            ['value' => 10080, 'label' => 'Last week'],
        ];
    }

    private function normalizeFilters(): void
    {
        if (! in_array($this->interval, self::INTERVALS, true)) {
            $this->interval = 5;
        }
    }

    private function loadFilterOptions(): void
    {
        $servers = Server::ownedByCurrentTeam()->orderBy('name')->get(['uuid', 'name']);
        $projects = Project::ownedByCurrentTeam()->orderBy('name')->get(['uuid', 'name']);

        $this->serverOptions = $servers
            ->map(fn (Server $server): array => ['value' => $server->uuid, 'label' => $server->name])
            ->prepend(['value' => '', 'label' => 'All servers'])
            ->values()
            ->all();

        $this->projectOptions = $projects
            ->map(fn (Project $project): array => ['value' => $project->uuid, 'label' => $project->name])
            ->prepend(['value' => '', 'label' => 'All projects'])
            ->values()
            ->all();

        if ($this->serverUuid !== '' && ! $servers->contains('uuid', $this->serverUuid)) {
            $this->serverUuid = '';
        }

        if ($this->projectUuid !== '' && ! $projects->contains('uuid', $this->projectUuid)) {
            $this->projectUuid = '';
        }
    }

    /**
     * Rebuild every server card. Metrics are optional because each Sentinel read
     * is an SSH round trip and the shell must render without them.
     */
    private function buildServerCards(bool $withMetrics): void
    {
        $servers = Server::ownedByCurrentTeam()
            ->with('settings')
            ->orderBy('name')
            ->get()
            ->when($this->serverUuid !== '', fn (Collection $servers): Collection => $servers
                ->where('uuid', $this->serverUuid)
                ->values());

        $containersByServer = $this->containersByServer();
        $chartSeries = [];
        $cards = [];

        foreach ($servers as $server) {
            $containers = $containersByServer->get($server->id, collect());

            if ($this->projectUuid !== '' && $containers->isEmpty()) {
                continue;
            }

            $metricsEnabled = $server->isMetricsEnabled();
            $collectMetrics = $withMetrics && $metricsEnabled;

            $cpuSeries = $collectMetrics ? $this->safeMetrics(fn (): ?array => $server->getCpuMetrics($this->interval)) : null;
            $memorySeries = $collectMetrics ? $this->safeMetrics(fn (): ?array => $server->getMemoryMetrics($this->interval)) : null;

            $rows = $containers
                ->map(fn (array $container): array => $this->containerRow($container, $collectMetrics))
                ->values();

            $memoryScale = max(
                1.0,
                (float) $rows->pluck('memory.max')->filter(fn ($value): bool => is_numeric($value))->max()
            );

            $chartSeries[$server->uuid] = [
                'cpu' => $this->chartSeries($cpuSeries),
                'memory' => $this->chartSeries($memorySeries),
            ];

            $cards[] = [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'ip' => $server->ip,
                'href' => route('server.show', ['server_uuid' => $server->uuid]),
                'metrics_href' => route('server.metrics', ['server_uuid' => $server->uuid]),
                'is_reachable' => (bool) $server->settings?->is_reachable,
                'is_functional' => $server->isFunctional(),
                'metrics_enabled' => $metricsEnabled,
                'sentinel_enabled' => $server->isSentinelEnabled(),
                'proxy_status' => $server->proxy?->status ?? 'unknown',
                'cpu' => $this->metricPayload($cpuSeries, '%'),
                'memory' => $this->metricPayload($memorySeries, '%'),
                'containers' => $rows->all(),
                'container_count' => $rows->count(),
                'running_container_count' => $rows->filter(fn (array $row): bool => $row['status']['running'])->count(),
                'memory_scale' => $memoryScale,
            ];
        }

        $this->serverCards = $cards;
        $this->buildSummary();

        if ($withMetrics) {
            $this->metricsLoaded = true;
            $this->dispatch('monitoringChartsUpdated', $chartSeries);
        }
    }

    /**
     * Every team container grouped by server id, filtered by the project scope.
     *
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    private function containersByServer(): Collection
    {
        $containers = collect();

        Application::ownedByCurrentTeam()
            ->with(['environment.project', 'destination'])
            ->get()
            ->each(function (Application $application) use ($containers): void {
                $containers->push([
                    'model' => $application,
                    'server_id' => $application->destination?->server_id,
                    'type' => 'application',
                    'type_label' => 'Application',
                    'name' => $application->name,
                    'status' => $application->status,
                    'detail' => $this->applicationImage($application) ?? str($application->build_pack)->headline()->value(),
                    'href' => $application->link(),
                    'project' => $application->environment?->project,
                    'environment' => $application->environment?->name,
                    'has_metrics' => true,
                ]);
            });

        foreach (self::DATABASE_MODELS as $model) {
            $model::ownedByCurrentTeam()
                ->with(['environment.project', 'destination'])
                ->get()
                ->each(function (Model $database) use ($containers): void {
                    $containers->push([
                        'model' => $database,
                        'server_id' => $database->destination?->server_id,
                        'type' => 'database',
                        'type_label' => 'Database',
                        'name' => $database->name,
                        'status' => $database->status,
                        'detail' => $database->image,
                        'href' => $database->link(),
                        'project' => $database->environment?->project,
                        'environment' => $database->environment?->name,
                        'has_metrics' => true,
                    ]);
                });
        }

        // Compose sub-containers get no metrics: Sentinel keys container history by
        // Docker container name ("{subName}-{serviceUuid}") and its
        // /api/container/{id}/{cpu,memory}/history route only accepts ids matching
        // ^[a-zA-Z0-9]+$. The hyphen in a compose container name makes every such
        // lookup return an empty series even though Sentinel did record the samples.
        Service::ownedByCurrentTeam()
            ->with(['environment.project', 'destination', 'applications', 'databases'])
            ->get()
            ->each(function (Service $service) use ($containers): void {
                $serverId = $service->server_id ?? $service->destination?->server_id;
                $subContainers = $service->applications->concat($service->databases);

                foreach ($subContainers as $subContainer) {
                    $containers->push([
                        'model' => null,
                        'server_id' => $serverId,
                        'type' => 'service',
                        'type_label' => 'Service',
                        'name' => $service->name.' / '.($subContainer->human_name ?: $subContainer->name),
                        'status' => $subContainer->status,
                        'detail' => $subContainer->image,
                        'href' => $service->link(),
                        'project' => $service->environment?->project,
                        'environment' => $service->environment?->name,
                        'has_metrics' => false,
                    ]);
                }
            });

        return $containers
            ->filter(fn (array $container): bool => filled($container['server_id']))
            ->when($this->projectUuid !== '', fn (Collection $rows): Collection => $rows
                ->filter(fn (array $container): bool => $container['project']?->uuid === $this->projectUuid))
            ->sortBy(fn (array $container): string => strtolower($container['name']))
            ->groupBy('server_id');
    }

    /**
     * @param  array<string, mixed>  $container
     * @return array<string, mixed>
     */
    private function containerRow(array $container, bool $collectMetrics): array
    {
        $model = $container['model'];
        $readMetrics = $collectMetrics && $container['has_metrics'] && $model instanceof Model;

        $cpuSeries = $readMetrics ? $this->safeMetrics(fn (): ?array => $model->getCpuMetrics($this->interval)) : null;
        $memorySeries = $readMetrics ? $this->safeMetrics(fn (): ?array => $model->getMemoryMetrics($this->interval)) : null;

        return [
            'key' => $container['type'].'-'.($model?->uuid ?? md5($container['name'].$container['server_id'])),
            'name' => $container['name'],
            'type' => $container['type'],
            'type_label' => $container['type_label'],
            'detail' => $container['detail'],
            'project' => $container['project']?->name,
            'environment' => $container['environment'],
            'href' => $container['href'],
            'has_metrics' => $container['has_metrics'],
            'status' => $this->statusPayload($container['status']),
            'cpu' => $this->metricPayload($cpuSeries, '%'),
            'memory' => $this->metricPayload($memorySeries, 'MB'),
        ];
    }

    private function buildSummary(): void
    {
        $cards = collect($this->serverCards);

        $cpuValues = $cards->pluck('cpu.current')->filter(fn ($value): bool => is_numeric($value));
        $memoryValues = $cards->pluck('memory.current')->filter(fn ($value): bool => is_numeric($value));

        $this->summary = [
            'servers' => $cards->count(),
            'online_servers' => $cards->where('is_functional', true)->count(),
            'monitored_servers' => $cards->where('metrics_enabled', true)->count(),
            'containers' => (int) $cards->sum('container_count'),
            'running_containers' => (int) $cards->sum('running_container_count'),
            'average_cpu' => $cpuValues->isNotEmpty() ? round($cpuValues->avg(), 1) : null,
            'average_memory' => $memoryValues->isNotEmpty() ? round($memoryValues->avg(), 1) : null,
        ];
    }

    private function applicationImage(Application $application): ?string
    {
        if ($application->build_pack !== 'dockerimage') {
            return null;
        }

        return trim(($application->docker_registry_image_name ?? '').':'.($application->docker_registry_image_tag ?? ''), ':') ?: null;
    }

    /**
     * @return array{raw: string, label: string, health: ?string, type: string, running: bool}
     */
    private function statusPayload(?string $status): array
    {
        $raw = filled($status) ? strtolower($status) : 'unknown';
        $state = str($raw)->before(':')->value();
        $health = str($raw)->contains(':') ? str($raw)->after(':')->value() : null;

        $type = match (true) {
            str_starts_with($state, 'running') => $health === 'unhealthy' ? 'warning' : 'success',
            str_starts_with($state, 'degraded'),
            str_starts_with($state, 'restarting'),
            str_starts_with($state, 'starting') => 'warning',
            default => 'neutral',
        };

        return [
            'raw' => $raw,
            'label' => str($state)->headline()->value(),
            'health' => $health,
            'type' => $type,
            'running' => str_starts_with($state, 'running'),
        ];
    }

    /**
     * Summary values plus a short tail used for the inline sparkline.
     *
     * @return array<string, mixed>
     */
    private function metricPayload(?array $metrics, string $unit): array
    {
        $points = collect($metrics ?? [])
            ->map(fn (array $point): float => round((float) ($point[1] ?? 0), 1))
            ->values();

        return [
            'available' => $points->isNotEmpty(),
            'current' => $points->last(),
            'average' => $points->isNotEmpty() ? round($points->avg(), 1) : null,
            'max' => $points->isNotEmpty() ? round($points->max(), 1) : null,
            'unit' => $unit,
            'points' => $points->take(-20)->values()->all(),
        ];
    }

    /**
     * @return array<int, array{0: int, 1: float}>
     */
    private function chartSeries(?array $metrics): array
    {
        return collect($metrics ?? [])
            ->map(fn (array $point): array => [(int) ($point[0] ?? 0), round((float) ($point[1] ?? 0), 2)])
            ->values()
            ->all();
    }

    private function safeMetrics(callable $callback): ?array
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return null;
        }
    }

    public function render(): View
    {
        return view('livewire.monitoring.index');
    }
}
