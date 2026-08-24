<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'name', 'image_url'])]
class PresetDesign extends Model
{
    use HasFactory, HasUuids;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
