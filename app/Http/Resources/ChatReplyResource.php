<?php

namespace App\Http\Resources;

use App\Services\Chat\BotReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property BotReply $resource
 */
class ChatReplyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'text' => $this->resource->text,
            'admin_link' => $this->resource->adminPath === null ? null : [
                'label' => $this->resource->linkLabel,
                'path' => $this->resource->adminPath,
            ],
            'help_url' => $this->resource->helpUrl,
            'answered_by' => $this->resource->answeredBy,
            'confidence' => $this->resource->confidence === [] ? null : $this->resource->confidence,
            'feedback' => null,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
