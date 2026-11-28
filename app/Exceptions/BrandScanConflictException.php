<?php

namespace App\Exceptions;

use App\Models\BrandScan;
use RuntimeException;

class BrandScanConflictException extends RuntimeException
{
    public BrandScan $brandScan;

    public function __construct(BrandScan $brandScan, string $message = 'A scan for this domain is already in progress.')
    {
        parent::__construct($message);
        $this->brandScan = $brandScan;
    }
}
