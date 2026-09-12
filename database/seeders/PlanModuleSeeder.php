<?php

namespace Database\Seeders;

use App\Domain\Membership\Models\PlanModule;
use Illuminate\Database\Seeder;

class PlanModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            [
                'id' => 1,
                'name' => [
                    'en' => 'Flexi Desk',
                    'ar' => 'مكتب مرن',
                ],
                'slug' => 'flexi_desk',
                'description' => [
                    'en' => 'Flexible shared desks with hot desk access.',
                    'ar' => 'مكاتب مشتركة ومرنة في المساحات المفتوحة.',
                ],
                'icon' => 'layout',
                'order' => 1,
                'is_active' => true,
            ],
            [
                'id' => 2,
                'name' => [
                    'en' => 'Dedicated Desk',
                    'ar' => 'مكتب مخصص',
                ],
                'slug' => 'dedicated_desk',
                'description' => [
                    'en' => 'Your own reserved desk with premium amenities.',
                    'ar' => 'مكتبك الخاص والمحجوز لك بالكامل مع خدمات مميزة.',
                ],
                'icon' => 'briefcase',
                'order' => 2,
                'is_active' => true,
            ],
        ];

        foreach ($modules as $moduleData) {
            PlanModule::updateOrCreate(
                ['id' => $moduleData['id']],
                $moduleData
            );
        }
    }
}
