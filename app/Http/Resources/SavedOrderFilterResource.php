<?php

namespace App\Http\Resources;

use App\Models\SavedOrderFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavedOrderFilter
 */
class SavedOrderFilterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'filters' => $this->filters,
            'created_at' => $this->created_at,
        ];
    }
}
