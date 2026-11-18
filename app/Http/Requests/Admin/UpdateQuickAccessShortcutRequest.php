<?php

namespace App\Http\Requests\Admin;

/**
 * Same shape as creating one — the `key` uniqueness rule already ignores
 * the shortcut being edited.
 */
class UpdateQuickAccessShortcutRequest extends StoreQuickAccessShortcutRequest {}
