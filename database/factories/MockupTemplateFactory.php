<?php

namespace Database\Factories;

use App\Models\MockupTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MockupTemplate>
 */
class MockupTemplateFactory extends Factory
{
    protected $model = MockupTemplate::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' mockup',
            'category' => 'APPAREL',
            'base_path' => 'mockup-templates/base.png',
            'mask_path' => 'mockup-templates/mask.png',
            'print_area' => ['quad' => [[300, 250], [640, 285], [615, 620], [285, 585]]],
            'displacement_scale' => 12,
            'shading_strength' => 70,
            'is_active' => true,
        ];
    }
}
