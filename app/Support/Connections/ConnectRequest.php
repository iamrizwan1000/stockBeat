<?php

namespace App\Support\Connections;

use App\Models\Team;

final readonly class ConnectRequest
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        public Team $team,
        public string $name,
        public array $credentials,
    ) {}
}
