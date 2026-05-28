<?php

declare(strict_types=1);

namespace Mypos\Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    /**
     * @param array<string, array<int, string>>|null $errors
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 400,
        private readonly ?array $errors = null
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, array<int, string>>|null
     */
    public function errors(): ?array
    {
        return $this->errors;
    }
}
