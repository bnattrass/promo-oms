<?php

namespace App\Suppliers;

use App\Models\Supplier;
use App\Suppliers\Amrod\AmrodConnector;
use InvalidArgumentException;

class SupplierRegistry
{
    /** driver key => connector class. Add Barron, Kevro etc. here. */
    public const DRIVERS = [
        'amrod' => AmrodConnector::class,
    ];

    public function make(Supplier $supplier): SupplierConnector
    {
        $class = self::DRIVERS[$supplier->driver] ?? throw new InvalidArgumentException("Unknown supplier driver [{$supplier->driver}]");

        return new $class($supplier);
    }

    /** @return array<string, string> driver => label, for select fields */
    public static function options(): array
    {
        return collect(self::DRIVERS)->map(fn (string $class) => $class::label())->all();
    }

    public static function classFor(?string $driver): ?string
    {
        return $driver ? (self::DRIVERS[$driver] ?? null) : null;
    }
}
