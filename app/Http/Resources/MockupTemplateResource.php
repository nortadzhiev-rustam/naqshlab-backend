<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MockupTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'category' => $this->category,
            'name' => $this->name,
            'basePath' => $this->base_path,
            'baseUrl' => Storage::disk('public')->url($this->base_path),
            'maskPath' => $this->mask_path,
            'maskUrl' => $this->mask_path ? Storage::disk('public')->url($this->mask_path) : null,
            'printArea' => $this->print_area,
            'displacementScale' => $this->displacement_scale,
            'shadingStrength' => $this->shading_strength,
            'sortOrder' => $this->sort_order,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
