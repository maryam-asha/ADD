<?php

namespace Add\Inventory\Catalog\Models;

use Add\Inventory\Catalog\Enums\ItemKind;
use Add\Inventory\Database\Factories\CategoryFactory;
use Add\Inventory\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A node of the catalog tree (Electronics › Displays). Its `kind` holds for
 * its whole branch; its attribute definitions apply to its items and to
 * every item further down. `show_to_members` lets a host show the assets of
 * this category to the people using the place they sit in.
 */
class Category extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $table = 'inv_categories';

    protected $fillable = [
        'parent_id',
        'kind',
        'name',
        'show_to_members',
        'order',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'kind' => ItemKind::class,
        'name' => 'array',
        'show_to_members' => 'boolean',
        'order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function attributeDefinitions(): HasMany
    {
        return $this->hasMany(AttributeDefinition::class)->orderBy('order')->orderBy('id');
    }

    /**
     * Its parents, outermost first.
     *
     * @return Collection<int, self>
     */
    public function ancestors(): Collection
    {
        $ancestors = collect();

        for ($parent = $this->parent; $parent !== null; $parent = $parent->parent) {
            $ancestors->prepend($parent);
        }

        return $ancestors;
    }

    /**
     * Its own id and every id below it.
     *
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        $ids = [$this->id];

        for ($level = [$this->id]; $level !== [];) {
            $level = self::query()->whereIn('parent_id', $level)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    /**
     * Every field its items answer: its ancestors' first, then its own.
     *
     * @return Collection<int, AttributeDefinition>
     */
    public function effectiveDefinitions(): Collection
    {
        return $this->ancestors()
            ->push($this)
            ->flatMap(fn (self $category) => $category->attributeDefinitions)
            ->values();
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}
