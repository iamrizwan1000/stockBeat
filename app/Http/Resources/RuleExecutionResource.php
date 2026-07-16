<?php

namespace App\Http\Resources;

use App\Models\RuleExecution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RuleExecution
 */
class RuleExecutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'trigger' => $this->trigger,
            'actions_result' => $this->actions_result,
            'fired_at' => $this->fired_at,
        ];
    }
}
