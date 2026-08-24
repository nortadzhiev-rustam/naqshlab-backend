<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mockup_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category')->nullable();
            $table->string('name');
            $table->string('base_path');
            $table->string('mask_path')->nullable();

            // Where the print sits on this photo: four [x, y] corners in the base
            // image's pixel space, ordered top-left, top-right, bottom-right,
            // bottom-left. A quad rather than a rectangle so the print can follow
            // the garment's perspective.
            $table->json('print_area');

            $table->unsignedTinyInteger('displacement_scale')->default(12);
            $table->unsignedTinyInteger('shading_strength')->default(70);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
            $table->index(['category', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mockup_templates');
    }
};
