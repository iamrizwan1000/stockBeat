<?php

namespace App\Http\Resources;

use App\Models\AiConversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiConversation
 */
class AiConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'messages' => $this->whenLoaded('messages', fn () => AiMessageResource::collection($this->messages)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
