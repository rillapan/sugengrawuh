<?php

namespace App\Exceptions;

use RuntimeException;

class AutomationSchemaNotReadyException extends RuntimeException
{
    protected array $missingItems;

    public function __construct(string $message, array $missingItems = [], int $code = 0, ?\Throwable $previous = null)
    {
        $this->missingItems = $missingItems;
        parent::__construct($message, $code, $previous);
    }

    public function getMissingItems(): array
    {
        return $this->missingItems;
    }
}
