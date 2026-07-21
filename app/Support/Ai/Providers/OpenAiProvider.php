<?php

namespace App\Support\Ai\Providers;

class OpenAiProvider extends OpenAiCompatibleProvider
{
    protected function baseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }
}
