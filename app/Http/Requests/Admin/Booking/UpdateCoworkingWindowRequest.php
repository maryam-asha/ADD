<?php

namespace App\Http\Requests\Admin\Booking;

use App\Domain\Booking\Models\SpaceCoworkingWindow;
use App\Domain\Foundation\Models\Space;
use App\Support\Time\LocalTime;

/**
 * Changing a lent stretch: any of its times or seats, the rest kept. The
 * same rules as lending it; `override` lets staff take back seats the pool
 * was counting on, by arrangement.
 */
class UpdateCoworkingWindowRequest extends StoreCoworkingWindowRequest
{
    protected function prepareForValidation(): void
    {
        $local = app(LocalTime::class);

        // What is not sent stays as it is, as a local wall-clock for the
        // same interpretation as what is.
        $this->merge([
            'starts_at' => $this->input('starts_at') ?? $local->toLocal($this->window()->starts_at)->format('Y-m-d H:i'),
            'ends_at' => $this->input('ends_at') ?? $local->toLocal($this->window()->ends_at)->format('Y-m-d H:i'),
            'seats' => $this->input('seats') ?? $this->window()->seats,
        ]);

        parent::prepareForValidation();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'override' => ['sometimes', 'boolean'],
        ];
    }

    protected function room(): Space
    {
        return $this->window()->space;
    }

    protected function ignoredWindowId(): ?int
    {
        return $this->window()->id;
    }

    private function window(): SpaceCoworkingWindow
    {
        return $this->route('coworkingWindow');
    }
}
