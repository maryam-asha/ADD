<?php

namespace Add\Inventory\Catalog\Models;

use Add\Inventory\Catalog\Enums\AttributeType;
use Add\Inventory\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

/**
 * One field the items of a category (and of every category below it)
 * answer in their `properties` — a screen's resolution, a chair's colour.
 */
class AttributeDefinition extends Model
{
    use HasTranslations;

    protected $table = 'inv_attribute_definitions';

    protected $fillable = [
        'category_id',
        'key',
        'label',
        'type',
        'options',
        'unit',
        'is_required',
        'order',
    ];

    protected $casts = [
        'label' => 'array',
        'type' => AttributeType::class,
        'options' => 'array',
        'is_required' => 'boolean',
        'order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The rules an item's value for this field must pass.
     *
     * @return list<mixed>
     */
    public function valueRules(): array
    {
        return [
            $this->is_required ? 'required' : 'nullable',
            ...match ($this->type) {
                AttributeType::Text => ['string', 'max:1000'],
                AttributeType::Number => ['numeric'],
                AttributeType::Boolean => ['boolean'],
                AttributeType::Date => ['date_format:Y-m-d'],
                AttributeType::Select => [Rule::in($this->options ?? [])],
            },
        ];
    }
}
