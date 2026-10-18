<?php

namespace Add\Hr\Payroll\Http\Resources;

use Add\Hr\Payroll\Models\PayComponent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PayComponent
 */
class PayComponentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'calculator' => $this->calculator,
            // An object, so JsonResource keeps its keys.
            'config' => $this->config === null ? null : (object) $this->config,
            'applies_to_all' => $this->applies_to_all,
            'taxable' => $this->taxable,
            'insurable' => $this->insurable,
            'sort' => $this->sort,
            'is_active' => $this->is_active,
        ];
    }
}
