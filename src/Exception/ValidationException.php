<?php

declare(strict_types=1);

namespace SCS\Exception;

class ValidationException extends \RuntimeException
{
    public function __construct(private readonly array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
