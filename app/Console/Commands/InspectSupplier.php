<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use Illuminate\Console\Command;

class InspectSupplier extends Command
{
    protected $signature = 'suppliers:inspect
        {supplier : Supplier code, e.g. AMROD}
        {module : Endpoint key, e.g. products, stock, prices, categories, inclusive_brandings}
        {--limit=1 : Number of records}';

    protected $description = 'Print raw sample records from a supplier API, to check the field mapping';

    public function handle(): int
    {
        $supplier = Supplier::where('code', strtoupper($this->argument('supplier')))->firstOrFail();
        $records = $supplier->connector()->sample($this->argument('module'), (int) $this->option('limit'));

        $this->line(json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
