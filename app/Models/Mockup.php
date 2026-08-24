<?php

namespace App\Models;

use App\Enums\MockupStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cache_key', 'design_hash', 'mockup_template_id', 'path',
    'width', 'height', 'status', 'failure_reason',
])]
class Mockup extends Model
{
    use HasUuids;

    /**
     * Customer artwork is kept on the private disk -- only the rendered mockup
     * is public. Keying by content hash means the same artwork is stored once
     * however many templates or customers reference it.
     */
    public static function designPathFor(string $designHash): string
    {
        return "mockup-designs/{$designHash}";
    }

    protected function casts(): array
    {
        return [
            'status' => MockupStatus::class,
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MockupTemplate::class, 'mockup_template_id');
    }
}
