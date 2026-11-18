<?php

namespace Database\Seeders;

use App\Domain\Membership\Models\ResourceType;
use Illuminate\Database\Seeder;

class ResourceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $resourceTypes = [
            [
                'id' => 1,
                'slug' => 'printing',
                'name' => [
                    'en' => 'Printing',
                    'ar' => 'طباعة',
                ],
                'unit_label' => [
                    'en' => 'page',
                    'ar' => 'صفحة',
                ],
                'icon' => 'printer',
                'order' => 1,
            ],
            [
                'id' => 2,
                'slug' => 'internet',
                'name' => [
                    'en' => 'Internet',
                    'ar' => 'إنترنت',
                ],
                'unit_label' => [
                    'en' => 'hour',
                    'ar' => 'ساعة',
                ],
                'icon' => 'wifi',
                'order' => 2,
            ],
            [
                // The pool an access-window plan's session hours are drawn
                // from (docs/decisions/plan-access-windows.md). Its slug is
                // load-bearing: PlanHourCoverageService looks the type up by
                // it, so renaming the slug silently disables plan coverage.
                'id' => 3,
                'slug' => 'space-hours',
                'name' => [
                    'en' => 'Space hours',
                    'ar' => 'ساعات المساحة',
                ],
                'unit_label' => [
                    'en' => 'hour',
                    'ar' => 'ساعة',
                ],
                'icon' => 'clock',
                'order' => 3,
            ],
        ];

        foreach ($resourceTypes as $resourceTypeData) {
            ResourceType::updateOrCreate(
                ['id' => $resourceTypeData['id']],
                $resourceTypeData
            );
        }
    }
}
