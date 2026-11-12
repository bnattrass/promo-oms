<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Pricing\BrandingCoverage;
use Illuminate\Console\Command;

class CheckBrandingCoverage extends Command
{
    protected $signature = 'suppliers:check-branding {supplier=AMROD : Supplier code}';

    protected $description = 'List branding methods used on products that have no branding prices on file';

    public function handle(BrandingCoverage $coverage): int
    {
        $supplier = Supplier::where('code', strtoupper($this->argument('supplier')))->firstOrFail();
        $report = $coverage->report($supplier);

        $this->info("Branding codes used on products: {$report['codes_used']}");
        $this->info("Codes with prices on file:       {$report['codes_priced']}");

        if ($report['missing'] === []) {
            $this->info('All branding methods on active products have prices.');

            return self::SUCCESS;
        }

        $this->warn(count($report['missing']).' branding code(s) have no prices:');
        $this->table(['Code', 'Method', 'Products using it', 'Example product'], $report['missing']);
        $this->line('Fix: php artisan suppliers:sync '.$supplier->code.' --full --module=branding_prices');

        return self::FAILURE;
    }
}
