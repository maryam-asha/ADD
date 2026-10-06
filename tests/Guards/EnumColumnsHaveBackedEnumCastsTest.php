<?php

namespace Tests\Guards;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Enums\PasscodeType;
use App\Domain\Access\Models\AccessEvent;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\ExchangeRateSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use App\Domain\Finance\Models\Payment;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormAnswer;
use App\Domain\Forms\Models\FormField;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\AmenityType;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\ResourceCategory;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Resource;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\SpaceAmenity;
use App\Domain\Foundation\Models\SpaceTypeAmenity;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Enums\ConsentSubjectType;
use App\Domain\Identity\Enums\ConsentType;
use App\Domain\Identity\Enums\ErrorLogPlatform;
use App\Domain\Identity\Enums\Gender;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\Consent;
use App\Domain\Identity\Models\ErrorLog;
use App\Domain\Identity\Models\OtpVerification;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\UserPersonalProfile;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\PlanBalance;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\CouponRevisionAction;
use App\Domain\Pricing\Enums\CouponSource;
use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Models\CouponRevision;
use App\Domain\Pricing\Models\CouponTarget;
use App\Domain\Settings\Enums\SettingScope;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Models\Setting;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use App\Support\Forms\FieldType;
use Tests\TestCase;

/**
 * Build plan §A.4: every enum-shaped column introduced from Phase 1 onward
 * is `string` + a PHP backed enum cast, never a MySQL ENUM. This is the
 * other half of that convention — NoNewMysqlEnumColumnsTest guards the
 * migration side; this guards that the model side actually casts what it
 * claims to. A column that's a plain string with no cast is exactly as
 * fragile as the MySQL ENUM the convention was meant to replace.
 *
 * Grows with each phase — add an entry here when a new enum-shaped column
 * lands, rather than trusting it was remembered.
 */
class EnumColumnsHaveBackedEnumCastsTest extends TestCase
{
    /** @var array<class-string, array<string, class-string>> model => [column => expected enum class] */
    private const EXPECTED_CASTS = [
        EventAttendee::class => [
            'status' => EventRegistrationStatus::class,
            'payment_state' => EventPaymentState::class,
        ],
        AccessGrant::class => [
            'grantee_type' => OwnerType::class,
            'source_type' => AccessSourceType::class,
            'allocation_model' => AllocationModel::class,
            'passcode_type' => PasscodeType::class,
            'status' => AccessGrantStatus::class,
        ],
        AccessEvent::class => [
            'event_type' => AccessEventType::class,
            'channel' => AccessEventChannel::class,
        ],
        ArrivalRequest::class => [
            'status' => ArrivalRequestStatus::class,
        ],
        Booking::class => [
            'status' => BookingStatus::class,
            'payment_state' => PaymentState::class,
            'payment_source' => PaymentSource::class,
            'termination_source' => TerminationSource::class,
            'payment_method' => PaymentMethod::class,
            'checked_in_via' => SessionStartSource::class,
        ],
        WalkinSession::class => [
            'started_via' => SessionStartSource::class,
            'payment_state' => PaymentState::class,
            'payment_source' => PaymentSource::class,
            'termination_source' => TerminationSource::class,
            'payment_method' => PaymentMethod::class,
        ],
        SessionEndRequest::class => [
            'status' => SessionEndRequestStatus::class,
        ],
        Space::class => [
            'space_type' => SpaceType::class,
            'allocation_model' => AllocationModel::class,
            'status' => OperationalStatus::class,
        ],
        Resource::class => [
            'category' => ResourceCategory::class,
            'status' => OperationalStatus::class,
        ],
        SpaceTypeAmenity::class => [
            'space_type' => SpaceType::class,
            'amenity' => AmenityType::class,
        ],
        SpaceAmenity::class => [
            'amenity' => AmenityType::class,
        ],
        BusinessHour::class => [
            'day_of_week' => DayOfWeek::class,
        ],
        ExchangeRate::class => [
            'source' => ExchangeRateSource::class,
        ],
        ExchangeRateSuggestion::class => [
            'source' => ExchangeRateSuggestionSource::class,
            'status' => ExchangeRateSuggestionStatus::class,
        ],
        Coupon::class => [
            'source' => CouponSource::class,
            'discount_type' => CouponDiscountType::class,
        ],
        CouponRevision::class => [
            'action' => CouponRevisionAction::class,
            'discount_type' => CouponDiscountType::class,
        ],
        CouponTarget::class => [
            'purpose' => PricingPurpose::class,
            'space_type' => SpaceType::class,
        ],
        AppliedDiscount::class => [
            'purpose' => PricingPurpose::class,
            'discount_type' => CouponDiscountType::class,
            'kind' => DiscountKind::class,
        ],
        Payment::class => [
            'status' => PaymentStatus::class,
            'purpose' => PaymentPurpose::class,
            'collected_via' => PaymentMethod::class,
        ],
        PrivateOfficeRequest::class => [
            'status' => PrivateOfficeRequestStatus::class,
        ],
        ErrorLog::class => [
            'platform' => ErrorLogPlatform::class,
        ],
        UserPersonalProfile::class => [
            'gender' => Gender::class,
        ],
        Company::class => [
            'status' => CompanyStatus::class,
        ],
        Consent::class => [
            'subject_type' => ConsentSubjectType::class,
            'consent_type' => ConsentType::class,
        ],
        OtpVerification::class => [
            'purpose' => OtpPurpose::class,
        ],
        Wallet::class => [
            'owner_type' => OwnerType::class,
        ],
        Membership::class => [
            'owner_type' => OwnerType::class,
            'status' => MembershipStatus::class,
        ],
        PlanBalance::class => [
            'category' => WalletTransactionCategory::class,
        ],
        WalletTransaction::class => [
            'category' => WalletTransactionCategory::class,
            'source' => WalletTransactionSource::class,
            'payment_method' => PaymentMethod::class,
        ],
        Setting::class => [
            'scope_type' => SettingScope::class,
            'type' => SettingValueType::class,
        ],
        Form::class => [
            'purpose' => FormPurpose::class,
        ],
        FormField::class => [
            'type' => FieldType::class,
        ],
        FormAnswer::class => [
            'field_type' => FieldType::class,
        ],
    ];

    public function test_every_registered_enum_column_casts_to_its_backed_enum(): void
    {
        $violations = [];

        foreach (self::EXPECTED_CASTS as $modelClass => $columns) {
            $casts = (new $modelClass)->getCasts();

            foreach ($columns as $column => $expectedEnum) {
                if (($casts[$column] ?? null) !== $expectedEnum) {
                    $actual = $casts[$column] ?? 'none';
                    $violations[] = "{$modelClass}::{$column} casts to \"{$actual}\", expected {$expectedEnum}";
                }
            }
        }

        $this->assertSame([], $violations, "Build plan §A.4 — every enum column casts to a PHP backed enum:\n".implode("\n", $violations));
    }
}
