<?php

namespace App\Console\Commands;

use App\Jobs\SyncSupplier;
use App\Models\Supplier;
use App\Services\SupplierSyncService;
use Illuminate\Console\Command;

class SyncSuppliers extends Command
{
    protected $signature = 'suppliers:sync
        {supplier? : Supplier code, e.g. AMROD. Omit for all suppliers with sync enabled}
        {--module=* : Only these modules (categories, brands, branding_prices, inclusive_brandings, products, prices, stock)}
        {--full : Full sync instead of the last-24h changes}
        {--queue : Dispatch to the queue instead of running now}';

    protected $description = 'Pull catalogue, price, branding and stock data from supplier APIs';

    public function handle(SupplierSyncService $sync): int
    {
        $suppliers = Supplier::query()
            ->whereNotNull('driver')
            ->where('is_active', true)
            ->when($this->argument('supplier'),
                fn ($q, $code) => $q->where('code', strtoupper($code)),
                fn ($q) => $q->where('sync_enabled', true))
            ->get();

        if ($suppliers->isEmpty()) {
            $this->warn('No matching suppliers with an API connector.');

            return self::SUCCESS;
        }

        $mode = $this->option('full') ? 'full' : 'changes';
        $modules = $this->option('module') ?: null;

        foreach ($suppliers as $supplier) {
            if ($this->option('queue')) {
                SyncSupplier::dispatch($supplier, $mode, $modules);
                $this->info("Queued {$mode} sync for {$supplier->name}.");

                continue;
            }

            $this->info("Syncing {$supplier->name} ({$mode})...");
            foreach ($sync->runAll($supplier, $mode, $modules) as $run) {
                $this->line(sprintf('  %-20s %-8s received %6d  created %6d  updated %6d  removed %6d  %s',
                    $run->module, $run->status, $run->records_received, $run->records_created,
                    $run->records_updated, $run->records_deleted, $run->message));
            }
        }

        return self::SUCCESS;
    }
}
