<?php

namespace App\Support\Connections;

final readonly class ActionResult
{
    public function __construct(
        public bool $success,
        public string $message,
    ) {}

    public static function success(string $message = 'Done.'): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
