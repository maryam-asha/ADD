<?php

namespace Database\Seeders;

use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceType;
use App\Domain\Services\Models\ServiceTypeField;
use App\Domain\Services\Enums\RequestMethod;
use Illuminate\Database\Seeder;

/**
 * Seeds the three brand axes and the initial service types visible in the UI
 * (General Service Request, Technical Issue, Business Address, Business Café).
 *
 * Run: php artisan db:seed --class=ServiceAxisSeeder
 */
class ServiceAxisSeeder extends Seeder
{
    public function run(): void
    {
        // ── Axes ─────────────────────────────────────────────────────────────

        $core = ServiceAxis::updateOrCreate(
            ['slug' => 'core'],
            ['name' => ['en' => 'ADD Core', 'ar' => 'ADD Core'], 'color' => '#E5352B', 'order' => 1]
        );

        $experience = ServiceAxis::updateOrCreate(
            ['slug' => 'experience'],
            ['name' => ['en' => 'ADD Experience', 'ar' => 'ADD Experience'], 'color' => '#00C2D4', 'order' => 2]
        );

        $ecosystem = ServiceAxis::updateOrCreate(
            ['slug' => 'ecosystem'],
            ['name' => ['en' => 'ADD Ecosystem', 'ar' => 'ADD Ecosystem'], 'color' => '#4CAF50', 'order' => 3]
        );

        // ── Core Services ─────────────────────────────────────────────────────

        $general = ServiceType::updateOrCreate(
            ['slug' => 'general'],
            [
                'service_axis_id'  => $core->id,
                'name'             => ['en' => 'General Service Request', 'ar' => 'طلب خدمة عام'],
                'description'      => ['en' => 'Submit a request for general services and support.', 'ar' => 'تقديم طلب للحصول على دعم أو خدمة عامة.'],
                'icon'             => 'grid-dots',
                'is_active'        => true,
                'is_coming_soon'   => false,
                'requires_location'=> true,
                'requires_review'  => false,
                'visibility'       => 'member',
                'request_method'   => RequestMethod::Form->value,
                'request_config'   => null,
                'order'            => 1,
            ]
        );

        // General service form fields
        ServiceTypeField::updateOrCreate(
            ['service_type_id' => $general->id, 'key' => 'description'],
            [
                'label'       => ['en' => 'Description', 'ar' => 'الوصف'],
                'placeholder' => ['en' => 'Describe your request…', 'ar' => 'اكتب تفاصيل طلبك…'],
                'type'        => 'textarea',
                'validation'  => ['max' => 500],
                'is_required' => false,
                'order'       => 1,
            ]
        );

        ServiceTypeField::updateOrCreate(
            ['service_type_id' => $general->id, 'key' => 'attachments'],
            [
                'label'       => ['en' => 'Add files or photos', 'ar' => 'أضف ملفات أو صور'],
                'placeholder' => null,
                'type'        => 'file',
                'validation'  => ['accept' => 'image/*', 'max_kb' => 5120],
                'is_required' => false,
                'order'       => 2,
            ]
        );

        // Technical Issue
        $technical = ServiceType::updateOrCreate(
            ['slug' => 'technical'],
            [
                'service_axis_id'  => $core->id,
                'name'             => ['en' => 'Technical Issue', 'ar' => 'مشكلة تقنية'],
                'description'      => ['en' => 'Report an internet / screen / A/C / equipment problem.', 'ar' => 'أبلغ عن مشكلة في الإنترنت أو الشاشة أو التكييف أو المعدات.'],
                'icon'             => 'tool',
                'is_active'        => true,
                'is_coming_soon'   => false,
                'requires_location'=> true,
                'requires_review'  => false,
                'visibility'       => 'member',
                'request_method'   => RequestMethod::Form->value,
                'request_config'   => null,
                'order'            => 2,
            ]
        );

        ServiceTypeField::updateOrCreate(
            ['service_type_id' => $technical->id, 'key' => 'issue_type'],
            [
                'label'       => ['en' => 'Issue Type', 'ar' => 'نوع المشكلة'],
                'placeholder' => null,
                'type'        => 'select',
                'options'     => [
                    ['value' => 'internet',  'label' => ['en' => 'Internet',   'ar' => 'إنترنت']],
                    ['value' => 'screen',    'label' => ['en' => 'Screen',     'ar' => 'شاشة']],
                    ['value' => 'ac',        'label' => ['en' => 'A/C',        'ar' => 'تكييف']],
                    ['value' => 'equipment', 'label' => ['en' => 'Equipment',  'ar' => 'معدات']],
                    ['value' => 'other',     'label' => ['en' => 'Other',      'ar' => 'أخرى']],
                ],
                'validation'  => ['required' => true],
                'is_required' => true,
                'order'       => 1,
            ]
        );

        ServiceTypeField::updateOrCreate(
            ['service_type_id' => $technical->id, 'key' => 'description'],
            [
                'label'       => ['en' => 'Description', 'ar' => 'الوصف'],
                'placeholder' => ['en' => 'Describe the problem…', 'ar' => 'اوصف المشكلة…'],
                'type'        => 'textarea',
                'validation'  => ['max' => 500],
                'is_required' => false,
                'order'       => 2,
            ]
        );

        // Business Address
        ServiceType::updateOrCreate(
            ['slug' => 'business-address'],
            [
                'service_axis_id'  => $core->id,
                'name'             => ['en' => 'Business Address', 'ar' => 'عنوان تجاري'],
                'description'      => ['en' => 'Separate request • reviewed by operations.', 'ar' => 'طلب منفصل • يُراجعه فريق العمليات.'],
                'icon'             => 'map-pin',
                'is_active'        => true,
                'is_coming_soon'   => false,
                'requires_location'=> false,
                'requires_review'  => true,
                'visibility'       => 'member',
                'request_method'   => RequestMethod::Form->value,
                'request_config'   => null,
                'order'            => 3,
            ]
        );

        // ── Experience Services ───────────────────────────────────────────────

        // Business Café — coming soon, info_only until ordering system is ready
        ServiceType::updateOrCreate(
            ['slug' => 'business-cafe'],
            [
                'service_axis_id'  => $experience->id,
                'name'             => ['en' => 'Business Café', 'ar' => 'البيزنس كافيه'],
                'description'      => ['en' => 'Order food & beverages for your team.', 'ar' => 'اطلب طعاماً ومشروبات لفريقك.'],
                'icon'             => 'coffee',
                'is_active'        => true,
                'is_coming_soon'   => true,
                'requires_location'=> false,
                'requires_review'  => false,
                'visibility'       => 'member',
                'request_method'   => RequestMethod::InfoOnly->value,
                'request_config'   => ['content' => ['en' => 'Coming soon — stay tuned!', 'ar' => 'قريباً — ترقبوا الإطلاق!']],
                'order'            => 1,
            ]
        );
    }
}
