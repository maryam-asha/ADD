<?php

namespace Add\Hr\Database\Factories;

use Add\Hr\Core\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentType>
 */
class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    public function definition(): array
    {
        $word = $this->faker->unique()->word();

        return [
            'code' => $word,
            'name' => ['ar' => 'وثيقة '.$word, 'en' => ucfirst($word)],
            'is_required' => false,
            'has_expiry' => false,
            'is_active' => true,
        ];
    }
}
