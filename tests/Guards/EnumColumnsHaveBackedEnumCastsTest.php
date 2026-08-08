<?php

namespace Tests\Guards;

use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\ResourceCategory;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Resource;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Enums\ConsentSubjectType;
use App\Domain\Identity\Enums\ConsentType;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\Consent;
use App\Domain\Identity\Models\PrivateOfficeRequest;
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
        Space::class => [
            'space_type' => SpaceType::class,
            'allocation_model' => AllocationModel::class,
            'status' => OperationalStatus::class,
        ],
        Resource::class => [
            'category' => ResourceCategory::class,
            'status' => OperationalStatus::class,
        ],
        PrivateOfficeRequest::class => [
            'status' => PrivateOfficeRequestStatus::class,
        ],
        Company::class => [
            'status' => CompanyStatus::class,
        ],
        Consent::class => [
            'subject_type' => ConsentSubjectType::class,
            'consent_type' => ConsentType::class,
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
