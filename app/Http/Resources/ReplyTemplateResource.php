<?php

namespace App\Http\Resources;

use App\Models\ReplyTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReplyTemplate
 */
class ReplyTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'body_with_variables' => $this->body_with_variables,
        ];
    }
}
