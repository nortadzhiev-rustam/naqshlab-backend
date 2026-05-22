<?php

namespace App\Models;

use App\Enums\ProductCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'base_price', 'category', 'is_customizable', 'images'])]
class Product extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'base_price' => 'float',
            'is_customizable' => 'boolean',
            'images' => 'array',
            'category' => ProductCategory::class,
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function presetDesigns(): HasMany
    {
        return $this->hasMany(PresetDesign::class);
    }
}
