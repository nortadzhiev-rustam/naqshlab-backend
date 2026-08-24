<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MockupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'cacheKey' => $this->cache_key,
            'templateId' => $this->mockup_template_id,
            'status' => $this->status->value,
            'url' => $this->path ? Storage::disk('public')->url($this->path) : null,
            'width' => $this->width,
            'height' => $this->height,
            'failureReason' => $this->failure_reason,
        ];
    }
}
