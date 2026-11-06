<?php

namespace App\Suppliers;

use RuntimeException;

/** Thrown when a supplier API is in a maintenance window or returns no content. */
class SupplierUnavailableException extends RuntimeException {}
