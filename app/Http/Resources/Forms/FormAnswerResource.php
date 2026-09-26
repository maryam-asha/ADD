<?php

namespace App\Http\Resources\Forms;

use App\Domain\Forms\Models\FormAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One submitted answer, as the member and operations read it back. The key
 * and label come from the field as it stands now, or from the snapshot
 * taken when it was answered if the field has since been deleted.
 *
 * @mixin FormAnswer
 */
class FormAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'field_key' => $this->displayKey(),
            'label' => $this->displayLabel(),
            'value' => $this->resolvedValue(),
        ];
    }
}
