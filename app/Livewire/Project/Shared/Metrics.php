<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use Illuminate\Support\Collection;
use Livewire\Component;

class Metrics extends Component
{
    public $resource;

    public $chartId = 'metrics';

    public $data;

    public $categories;

    public int $interval = 5;

    public bool $poll = true;

    public bool $isDockerCompose = false;

    public Collection $containers;

    public ?string $selectedContainer = null;

    public function mount($resource)
    {
        $this->resource = $resource;
        $this->containers = collect();

        if ($this->resource instanceof Application && $this->resource->build_pack === 'dockercompose') {
            $this->isDockerCompose = true;
            $this->loadContainers();
        }
    }

    public function loadContainers()
    {
        try {
            $this->containers = $this->resource->getMetricsContainers();
            if ($this->containers->isNotEmpty() && (! $this->selectedContainer || ! $this->containers->contains('name', $this->selectedContainer))) {
                $this->selectedContainer = data_get($this->containers->first(), 'name');
            }
        } catch (\Throwable $e) {
            $this->containers = collect();
        }
    }

    public function pollData()
    {
        if ($this->poll || $this->interval <= 10) {
            $this->loadData();
            if ($this->interval > 10) {
                $this->poll = false;
            }
        }
    }

    public function loadData()
    {
        try {
            if ($this->isDockerCompose && ! $this->selectedContainer) {
                return;
            }
            $container = $this->isDockerCompose ? $this->selectedContainer : null;
            $cpuMetrics = $this->resource->getCpuMetrics($this->interval, $container);
            $memoryMetrics = $this->resource->getMemoryMetrics($this->interval, $container);
            $this->dispatch("refreshChartData-{$this->chartId}-cpu", [
                'seriesData' => $cpuMetrics,
            ]);
            $this->dispatch("refreshChartData-{$this->chartId}-memory", [
                'seriesData' => $memoryMetrics,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function setInterval()
    {
        if ($this->interval <= 10) {
            $this->poll = true;
        }
        $this->loadData();
    }

    public function setContainer()
    {
        $this->loadData();
    }

    public function render()
    {
        return view('livewire.project.shared.metrics');
    }
}
