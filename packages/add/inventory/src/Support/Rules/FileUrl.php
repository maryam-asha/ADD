<?php

namespace Add\Inventory\Support\Rules;

/**
 * Where an uploaded file (an item's picture, an operation's attachment) is
 * served: an http(s) URL, or a site-relative path, which is what a host's
 * own upload may answer (ADD: `/storage/uploads/...`). Nothing else — no
 * `javascript:` or `data:` URL reaches a page that links it.
 */
final class FileUrl
{
    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['string', 'max:2048', 'regex:/^(https?:\/\/\S+|\/[^\/\s]\S*)$/i'];
    }
}
