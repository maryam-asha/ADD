<?php

namespace Add\Hr\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One look at a document: a link issued to a user, or the file served.
 */
class DocumentAccess extends Model
{
    public const LINK_ISSUED = 'link_issued';

    public const DOWNLOADED = 'downloaded';

    public const UPDATED_AT = null;

    protected $table = 'hr_document_accesses';

    protected $guarded = [];
}
