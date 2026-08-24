<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mockups', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // sha256 of the design bytes + template + pipeline version. The same
            // artwork on the same template is rendered once and shared by every
            // customer who lands on it.
            $table->string('cache_key')->unique();
            $table->string('design_hash');
            $table->foreignUuid('mockup_template_id')->constrained()->cascadeOnDelete();
            $table->string('path')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('status')->default('PENDING');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index('design_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mockups');
    }
};
