<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderId' => $this->order_id,
            'productId' => $this->product_id,
            'variantId' => $this->variant_id,
            'presetDesignId' => $this->preset_design_id,
            'quantity' => $this->quantity,
            'unitPrice' => (float) $this->unit_price,
            'customizationData' => $this->customization_data,
            'product' => $this->whenLoaded('product', fn () => [
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'images' => $this->product->images ?? [],
            ]),
            'variant' => $this->whenLoaded('variant', fn () => $this->variant
                ? ['label' => $this->variant->label]
                : null),
            'presetDesign' => $this->whenLoaded('presetDesign', fn () => $this->presetDesign
                ? ['name' => $this->presetDesign->name, 'imageUrl' => $this->presetDesign->image_url]
                : null),
        ];
    }
}
