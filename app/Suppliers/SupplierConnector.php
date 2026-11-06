<?php

namespace App\Suppliers;

use App\Models\Supplier;
use App\Models\SyncRun;

/**
 * A supplier API integration. One class per supplier (Amrod, Barron, Kevro ...).
 * Register new connectors in SupplierRegistry::DRIVERS.
 */
interface SupplierConnector
{
    public function __construct(Supplier $supplier);

    /** Human name, shown in the driver dropdown. */
    public static function label(): string;

    /**
     * Credential fields the supplier form should show, as key => label.
     * Values are stored encrypted on suppliers.credentials.
     *
     * @return array<string, array{label: string, password?: bool, help?: string}>
     */
    public static function credentialFields(): array;

    /** Modules this connector can sync, in the order a full sync should run them. */
    public static function modules(): array;

    /** Log in and return a short success message; throw on failure. */
    public function testConnection(): string;

    /** Sync one module. $mode is 'full' or 'changes'. Must update and return the SyncRun. */
    public function sync(string $module, string $mode, SyncRun $run): SyncRun;

    /** Raw sample records for a module, for checking field mapping. */
    public function sample(string $module, int $limit = 1): array;
}
