<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * Same shape as a chat reply, so the UI renders history and new replies the same way.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'text' => $this->content,
            'admin_link' => $this->admin_path === null ? null : [
                'label' => $this->link_label,
                'path' => $this->admin_path,
            ],
            'help_url' => $this->help_url,
            'answered_by' => $this->answered_by,
            'confidence' => $this->confidence,
            'feedback' => $this->feedback,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
