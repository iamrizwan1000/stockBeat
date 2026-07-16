<?php

namespace App\Http\Resources;

use App\Models\Rule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rule
 */
class RuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'trigger' => $this->trigger,
            'conditions' => $this->conditions,
            'actions' => $this->actions,
            'controls' => $this->controls,
            'enabled' => $this->enabled,
            'created_at' => $this->created_at,
        ];
    }
}
