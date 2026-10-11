<?php

namespace Database\Factories;

use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\Form;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    protected $model = Form::class;

    /**
     * An event's registration form unless told otherwise — prefer
     * `$owner->formOrCreate($purpose)` in tests that already have an owner.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'formable_type' => (new Event)->getMorphClass(),
            'formable_id' => Event::factory(),
            'purpose' => FormPurpose::Registration,
        ];
    }
}
