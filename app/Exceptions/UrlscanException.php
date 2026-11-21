<?php

namespace App\Exceptions;

use App\Models\BrandScanResult;
use RuntimeException;

class UrlscanException extends RuntimeException
{
    public const STATUS_UNRESOLVABLE = 'unresolvable';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_ERROR = 'error';

    public readonly int $httpStatus;

    public readonly string $scanStatus;

    public readonly ?int $retryAfter;

    public ?BrandScanResult $result;

    public function __construct(
        string $message,
        int $httpStatus = 422,
        string $scanStatus = self::STATUS_ERROR,
        ?int $retryAfter = null,
        ?BrandScanResult $result = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
        $this->httpStatus = $httpStatus;
        $this->scanStatus = $scanStatus;
        $this->retryAfter = $retryAfter;
        $this->result = $result;
    }

    public function withResult(?BrandScanResult $result): self
    {
        $this->result = $result;

        return $this;
    }
}
