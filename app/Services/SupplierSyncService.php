<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SyncRun;
use App\Suppliers\SupplierUnavailableException;
use Illuminate\Support\Facades\Log;
use Throwable;

class SupplierSyncService
{
    /**
     * Run one module sync and record it in sync_runs.
     * A failed 'changes' sync flags the supplier so the next run does a full sync instead,
     * because Amrod only keeps 24 hours of changes.
     */
    public function run(Supplier $supplier, string $module, string $mode = 'changes'): SyncRun
    {
        // Stock / price feeds are decoded in memory; products are streamed. 1 GB is ample for both.
        if ((int) ini_get('memory_limit') !== -1) {
            @ini_set('memory_limit', config('services.amrod.memory_limit', '1024M'));
        }

        $connector = $supplier->connector() ?? throw new \RuntimeException("{$supplier->name} has no API connector.");

        if ($mode === 'changes' && data_get($supplier->settings, "needs_full_sync.{$module}")) {
            $mode = 'full';
        }

        $run = SyncRun::create([
            'supplier_id' => $supplier->id,
            'module' => $module,
            'mode' => $mode,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $connector->sync($module, $mode, $run);
            $run->finish('success');
            $this->flagFullSync($supplier, $module, false);
        } catch (SupplierUnavailableException $e) {
            $run->finish('skipped', $e->getMessage());
        } catch (Throwable $e) {
            $run->finish('failed', str($e->getMessage())->limit(2000));
            if ($mode === 'changes') {
                $this->flagFullSync($supplier, $module, true);
            }
            Log::error("Supplier sync failed: {$supplier->code} {$module} {$mode}", ['exception' => $e]);
        }

        return $run->fresh();
    }

    /** Run every module the connector supports, in dependency order. */
    public function runAll(Supplier $supplier, string $mode = 'changes', ?array $only = null): array
    {
        $modules = $supplier->connector()::modules();
        if ($only) {
            $modules = array_values(array_intersect($modules, $only));
        }

        return array_map(fn ($module) => $this->run($supplier, $module, $mode), $modules);
    }

    private function flagFullSync(Supplier $supplier, string $module, bool $needed): void
    {
        $settings = $supplier->settings ?? [];
        if ((bool) data_get($settings, "needs_full_sync.{$module}") === $needed) {
            return;
        }
        data_set($settings, "needs_full_sync.{$module}", $needed);
        $supplier->forceFill(['settings' => $settings])->save();
    }
}
