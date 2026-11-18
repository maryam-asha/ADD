<?php

namespace App\Domain\Services\Enums;

enum RequestMethod: string
{
    /** Dynamic form: the service type's request form (App\Domain\Forms) */
    case Form = 'form';

    /** Open a URL in browser or webview — config: {url, open} */
    case ExternalLink = 'external_link';

    /** Show a phone number to call — config: {number, label} */
    case Phone = 'phone';

    /** Deep-link into WhatsApp — config: {number, message_template} */
    case Whatsapp = 'whatsapp';

    /** Navigate to an internal app route — config: {route, params} */
    case InternalRedirect = 'internal_redirect';

    /** Display information only, no action — config: {content} */
    case InfoOnly = 'info_only';

    /** Whether this method produces a ServiceRequest record in the DB */
    public function createsRequest(): bool
    {
        return $this === self::Form;
    }
}
