<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'basePrice' => (float) $this->base_price,
            'category' => $this->category->value,
            'isCustomizable' => $this->is_customizable,
            'images' => $this->images ?? [],
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->relationLoaded('variants')) {
            $data['variantCount'] = $this->variants->count();
            $data['variants'] = ProductVariantResource::collection($this->variants);
        } else {
            $data['variantCount'] = $this->variants_count ?? 0;
        }

        if ($this->relationLoaded('presetDesigns')) {
            $data['presetDesigns'] = PresetDesignResource::collection($this->presetDesigns);
        }

        return $data;
    }
}
