<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\SupplierSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSupplier implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    /** @param  string[]|null  $modules  null = all modules */
    public function __construct(
        public Supplier $supplier,
        public string $mode = 'changes',
        public ?array $modules = null,
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return $this->supplier->id.':'.$this->mode.':'.implode(',', $this->modules ?? ['all']);
    }

    public function handle(SupplierSyncService $sync): void
    {
        $sync->runAll($this->supplier, $this->mode, $this->modules);
    }
}
