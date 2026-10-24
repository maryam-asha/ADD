<?php

return [
    'unknown_location' => 'This location does not exist.',
    'unknown_properties' => 'These fields do not belong to the category: :keys.',
    'location_updated' => 'Location updated.',
    'location_in_use' => 'This location still holds places, assets or stock history.',
    'unit_updated' => 'Unit updated.',
    'unit_in_use' => 'Items are still counted in this unit.',
    'category_updated' => 'Category updated.',
    'category_not_empty' => 'Remove the categories and items under this category first.',
    'kind_follows_parent' => 'A category is always of the same kind as its parent.',
    'kind_has_items' => 'This category already holds items; its kind can no longer change.',
    'parent_in_subtree' => 'A category cannot move under itself or one of its own subcategories.',
    'field_updated' => 'Field updated.',
    'key_taken' => 'The field ":key" is already defined in this branch of the catalog.',
    'item_updated' => 'Item updated.',
    'item_kind_locked' => 'This item already has assets or stock history; it cannot move to a category of another kind.',
    'unit_locked' => 'This item already has stock history; its unit can no longer change.',
    'item_has_history' => 'This item already has assets or stock history; deactivate it instead.',
];
