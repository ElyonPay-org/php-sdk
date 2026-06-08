<?php

declare(strict_types=1);

namespace ElyonPay\Exception;

/**
 * Thrown when the API returns an error payload or a non-2xx status.
 * Carries the machine-readable error code returned by ElyonPay when available.
 */
class ApiException extends ElyonPayException
{
    private ?string $errorCode;
    private int $httpStatus;

    public function __construct(string $message, ?string $errorCode = null, int $httpStatus = 0)
    {
        parent::__construct($message);
        $this->errorCode  = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
