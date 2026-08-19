<?php
declare(strict_types=1);

namespace Services;

final class ProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 502,
        public readonly ?int $providerStatus = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
