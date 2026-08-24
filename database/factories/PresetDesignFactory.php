<?php

namespace Database\Factories;

use App\Models\PresetDesign;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PresetDesign>
 */
class PresetDesignFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->words(2, true),
            'image_url' => fake()->imageUrl(),
        ];
    }
}
