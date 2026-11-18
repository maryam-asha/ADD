<?php

namespace App\Domain\Renewals\Services;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\Company;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Models\Membership;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Http\Middleware\SetLocaleFromHeader;
use App\Services\Notification\NotificationService;
use App\Services\Notification\NotificationText;

/**
 * Who hears about an automatic renewal: a stop reaches whoever may renew by
 * hand (`renewals.manage`) and, for a provider's company, its provider; a
 * renewal done reaches that provider. The company's own admins hear of a
 * renewed plan from MembershipNotifier.
 */
class RenewalNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function stopped(Membership|OfficeLease $subject, RenewalStopReason $reason): void
    {
        $company = $this->company($subject);
        $params = [
            'subject' => $this->subject($subject),
            'company' => (string) $company?->legal_name,
            'reason' => NotificationText::translations('api.renewal.reason.'.$reason->value),
        ];

        $this->notifications->sendToHolders('renewals.manage', 'renewal.stopped', $params, $this->target($subject));
        $this->tellProvider($company, 'renewal.stopped', $params, $this->target($subject));
    }

    /** $until: the subscription's or lease's new last day. */
    public function done(Membership|OfficeLease $subject, string $until): void
    {
        $company = $this->company($subject);

        $this->tellProvider($company, 'renewal.done', [
            'subject' => $this->subject($subject),
            'company' => (string) $company?->legal_name,
            'date' => $until,
        ], $this->target($subject));
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $target
     */
    private function tellProvider(?Company $company, string $templateKey, array $params, array $target): void
    {
        $accounts = $company?->provider?->users()->where('users.status', 'active')->get();

        if ($accounts !== null && $accounts->isNotEmpty()) {
            $this->notifications->sendToUsers($accounts, $templateKey, $params, $target, NotificationAudience::Provider);
        }
    }

    private function company(Membership|OfficeLease $subject): ?Company
    {
        return $subject instanceof OfficeLease
            ? $subject->company
            : Company::query()->find($subject->owner_id);
    }

    /**
     * "the Flexi plan" / "the lease of Office 3", per language.
     *
     * @return array<string, string>
     */
    private function subject(Membership|OfficeLease $subject): array
    {
        $lines = [];

        foreach (SetLocaleFromHeader::SUPPORTED_LOCALES as $locale) {
            $lines[$locale] = $subject instanceof OfficeLease
                ? __('notifications.renewal.subjects.lease', ['space' => NotificationText::pick((array) $subject->space?->name, $locale)], $locale)
                : __('notifications.renewal.subjects.membership', ['plan' => NotificationText::pick((array) $subject->plan?->name, $locale)], $locale);
        }

        return $lines;
    }

    /** @return array<string, string> */
    private function target(Membership|OfficeLease $subject): array
    {
        return $subject instanceof OfficeLease
            ? ['target_type' => 'office_lease', 'target_id' => (string) $subject->id]
            : ['target_type' => 'membership', 'target_id' => (string) $subject->id];
    }
}
