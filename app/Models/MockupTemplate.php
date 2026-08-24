<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id', 'category', 'name', 'base_path', 'mask_path', 'print_area',
    'displacement_scale', 'shading_strength', 'sort_order', 'is_active',
])]
class MockupTemplate extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'print_area' => 'array',
            'displacement_scale' => 'integer',
            'shading_strength' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function mockups(): HasMany
    {
        return $this->hasMany(Mockup::class);
    }

    /**
     * The print quad as four [x, y] pairs: top-left, top-right, bottom-right,
     * bottom-left, in the base image's pixel space.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    public function quad(): array
    {
        return array_map(
            fn (array $point): array => [(float) $point[0], (float) $point[1]],
            $this->print_area['quad']
        );
    }

    /**
     * Any change here must produce a different cache key, or stale renders from
     * the previous geometry would be served forever.
     */
    public function renderSignature(): string
    {
        return hash('sha256', json_encode([
            $this->id,
            $this->base_path,
            $this->mask_path,
            $this->print_area,
            $this->displacement_scale,
            $this->shading_strength,
            $this->updated_at?->getTimestamp(),
        ]));
    }
}
