<?php

namespace App\Domain\Forms\Enums;

/**
 * What a form is for, relative to its owner. An owner holds at most one
 * form per purpose (`forms_formable_purpose_unique`), so a second form on
 * the same model — a post-event feedback survey beside the registration
 * form, say — is a new case here, not a schema change.
 */
enum FormPurpose: string
{
    /** Asked when a member registers — an Event's attendee questions. */
    case Registration = 'registration';

    /** Asked when a member files a request — a ServiceType's request form. */
    case Request = 'request';
}
