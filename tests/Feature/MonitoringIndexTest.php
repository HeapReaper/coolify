<?php

use App\Livewire\Monitoring\Index;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function monitoringPrivateKey(Team $team): PrivateKey
{
    return PrivateKey::create([
        'name' => 'Monitoring key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
        'team_id' => $team->id,
    ]);
}

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], []));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    // A single key: fingerprints are globally unique, so every server reuses it.
    $this->privateKey = monitoringPrivateKey($this->team);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Monitoring server',
        'private_key_id' => $this->privateKey->id,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id, 'network' => 'coolify-test']);

    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Monitoring project']);
    $this->environment = $this->project->environments()->firstOrFail();
});

function monitoringApplication(array $overrides = []): Application
{
    return Application::factory()->create(array_merge([
        'name' => 'Monitored app',
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
        'ports_exposes' => '3000',
        'status' => 'running:healthy',
    ], $overrides));
}

it('renders one card per server with its containers in a table', function () {
    monitoringApplication();

    StandalonePostgresql::create([
        'name' => 'Monitored postgres',
        'postgres_password' => 'secret',
        'image' => 'postgres:16-alpine',
        'status' => 'exited:unhealthy',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $service = Service::factory()->create([
        'name' => 'Monitored service',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
    ]);
    ServiceApplication::create([
        'name' => 'worker',
        'human_name' => 'Worker',
        'image' => 'redis:7',
        'status' => 'running',
        'service_id' => $service->id,
    ]);

    Livewire::test(Index::class)
        ->assertSee('Monitoring server')
        ->assertSee('Monitored app')
        ->assertSee('Monitored postgres')
        ->assertSee('Monitored service / Worker')
        ->assertSeeHtml('data-table-header monitoring-container-grid')
        ->assertSee('Container')
        ->assertSee('Memory');
});

it('groups containers under the server they run on', function () {
    monitoringApplication();

    $component = Livewire::test(Index::class);
    $cards = $component->get('serverCards');

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['name'])->toBe('Monitoring server')
        ->and($cards[0]['container_count'])->toBe(1)
        ->and($cards[0]['running_container_count'])->toBe(1)
        ->and($cards[0]['containers'][0]['name'])->toBe('Monitored app')
        ->and($cards[0]['containers'][0]['type_label'])->toBe('Application')
        ->and($cards[0]['containers'][0]['status']['label'])->toBe('Running')
        ->and($cards[0]['containers'][0]['status']['type'])->toBe('success');
});

it('never exposes servers or containers from another team', function () {
    monitoringApplication();

    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create([
        'team_id' => $otherTeam->id,
        'name' => 'Other team server',
        'private_key_id' => $this->privateKey->id,
    ]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $otherServer->id, 'network' => 'coolify-other']);
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = $otherProject->environments()->firstOrFail();

    Application::factory()->create([
        'name' => 'Other team app',
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => StandaloneDocker::class,
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
        'ports_exposes' => '3000',
    ]);

    Livewire::test(Index::class)
        ->assertSee('Monitoring server')
        ->assertDontSee('Other team server')
        ->assertDontSee('Other team app');
});

it('filters the cards by project', function () {
    monitoringApplication();

    $otherProject = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Empty project']);

    Livewire::test(Index::class)
        ->set('projectUuid', $otherProject->uuid)
        ->call('applyFilters')
        ->assertSet('serverCards', [])
        ->assertSee('No servers to monitor');
});

it('filters the cards by server', function () {
    monitoringApplication();

    $secondServer = Server::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Second server',
        'private_key_id' => $this->privateKey->id,
    ]);

    $unfiltered = Livewire::test(Index::class);
    expect(collect($unfiltered->get('serverCards'))->pluck('name')->all())
        ->toBe(['Monitoring server', 'Second server']);

    $filtered = $unfiltered
        ->set('serverUuid', $this->server->uuid)
        ->call('applyFilters');

    expect(collect($filtered->get('serverCards'))->pluck('name')->all())->toBe(['Monitoring server']);
});

it('renders the metrics empty state instead of charts when metrics are disabled', function () {
    monitoringApplication();

    Livewire::test(Index::class)
        ->assertSee('Metrics are disabled')
        ->assertDontSeeHtml('id="monitoring-chart-'.$this->server->uuid.'-cpu"');
});

it('renders chart placeholders and pushes series to the browser when metrics are enabled', function () {
    monitoringApplication();
    $this->server->settings->update(['is_metrics_enabled' => true]);

    Livewire::test(Index::class)
        ->assertSeeHtml('id="monitoring-chart-'.$this->server->uuid.'-cpu"')
        ->assertSeeHtml('id="monitoring-chart-'.$this->server->uuid.'-memory"')
        ->assertSee('CPU usage')
        ->assertSee('Memory usage');
});

it('dispatches chart series only once metrics have been loaded', function () {
    monitoringApplication();

    Livewire::test(Index::class)
        ->assertSet('metricsLoaded', false)
        ->assertNotDispatched('monitoringChartsUpdated')
        ->call('loadMetrics')
        ->assertSet('metricsLoaded', true)
        ->assertDispatched('monitoringChartsUpdated');
});

it('falls back to the default range for an unsupported url range', function () {
    Livewire::withUrlParams(['range' => 4242])
        ->test(Index::class)
        ->assertSet('interval', 5);
});

it('summarises servers and containers for the page header', function () {
    monitoringApplication();
    monitoringApplication(['name' => 'Stopped app', 'status' => 'exited:unhealthy']);

    $summary = Livewire::test(Index::class)->get('summary');

    expect($summary['servers'])->toBe(1)
        ->and($summary['containers'])->toBe(2)
        ->and($summary['running_containers'])->toBe(1);
});

it('marks compose sub-containers as unsupported instead of showing empty metrics', function () {
    $service = Service::factory()->create([
        'name' => 'Monitored service',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
    ]);
    ServiceApplication::create([
        'name' => 'worker',
        'human_name' => 'Worker',
        'image' => 'redis:7',
        'status' => 'running',
        'service_id' => $service->id,
    ]);

    $component = Livewire::test(Index::class);
    $row = collect($component->get('serverCards')[0]['containers'])
        ->firstWhere('type', 'service');

    // Sentinel keys history by container name and rejects ids containing a hyphen,
    // so a compose container can never resolve through its history endpoint.
    expect($row['has_metrics'])->toBeFalse();

    $component->assertSee('Unsupported');
});
