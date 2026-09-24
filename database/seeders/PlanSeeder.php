<?php

namespace Database\Seeders;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'id' => 1,
                'plan_module_id' => 1,
                'name' => [
                    'en' => 'The Smart Starter',
                    'ar' => 'ذا سمارت ستارتر',
                ],
                'subtitle' => [
                    'en' => 'Perfect for individuals getting started with flexible hours.',
                    'ar' => 'مثالية للأفراد الراغبين ببداية مرنة وبساعات محددة.',
                ],
                'is_subscription' => true,
                'price_points' => 500,
                'duration_days' => 30,
                'is_active' => true,
                'order' => 1,
                'balances' => [
                    [
                        'category' => WalletTransactionCategory::Cafe,
                        'amount_points' => 200,
                        'description' => ['en' => 'Café Balance', 'ar' => 'رصيد الكافيه'],
                        'order' => 1,
                    ],
                    [
                        'category' => WalletTransactionCategory::PrintingInternet,
                        'amount_points' => 150,
                        'description' => ['en' => 'Printing & Internet Balance', 'ar' => 'رصيد الطباعة والإنترنت'],
                        'order' => 2,
                    ],
                ],
                'features' => [
                    [
                        'name' => ['en' => '8 Days per month', 'ar' => '8 أيام شهرياً'],
                        'value' => ['en' => '8 Days', 'ar' => '8 أيام'],
                        'icon' => 'calendar',
                        'is_included' => true,
                        'order' => 1,
                    ],
                    [
                        'name' => ['en' => 'Access to a Hot Desk', 'ar' => 'وصول إلى مكتب مشترك'],
                        'value' => null,
                        'icon' => 'monitor',
                        'is_included' => true,
                        'order' => 2,
                    ],
                    [
                        'name' => ['en' => 'Internet Access', 'ar' => 'إنترنت عالي السرعة'],
                        'value' => null,
                        'icon' => 'wifi',
                        'is_included' => true,
                        'order' => 3,
                    ],
                    [
                        'name' => ['en' => 'Unlimited Coffee', 'ar' => 'قهوة غير محدودة'],
                        'value' => null,
                        'icon' => 'coffee',
                        'is_included' => true,
                        'order' => 4,
                    ],
                    [
                        'name' => ['en' => 'First access during reception hours', 'ar' => 'أول دخول خلال ساعات الاستقبال'],
                        'value' => null,
                        'icon' => 'clock',
                        'is_included' => true,
                        'order' => 5,
                    ],
                ],
                'discounts' => [
                    [
                        'percentage' => 30,
                        'space_type' => SpaceType::Room->value,
                        'description' => ['en' => '30% on Meeting Rooms', 'ar' => 'خصم 30% على غرف الاجتماعات'],
                        'order' => 1,
                    ],
                    [
                        'percentage' => 30,
                        'space_type' => SpaceType::EventHall->value,
                        'description' => ['en' => '30% on Event Spaces', 'ar' => 'خصم 30% على مساحات الفعاليات'],
                        'order' => 2,
                    ],
                ],
            ],
            [
                'id' => 2,
                'plan_module_id' => 1,
                'name' => [
                    'en' => 'The Free Bird',
                    'ar' => 'ذا فري بيرد',
                ],
                'subtitle' => [
                    'en' => 'Designed for growing professionals needing more flexibility.',
                    'ar' => 'مصممة للمحترفين مع مرونة أكبر في الحضور.',
                ],
                'is_subscription' => true,
                'price_points' => 750,
                'duration_days' => 30,
                'is_active' => true,
                'order' => 2,
                'balances' => [
                    [
                        'category' => WalletTransactionCategory::Cafe,
                        'amount_points' => 300,
                        'description' => ['en' => 'Café Balance', 'ar' => 'رصيد الكافيه'],
                        'order' => 1,
                    ],
                    [
                        'category' => WalletTransactionCategory::PrintingInternet,
                        'amount_points' => 200,
                        'description' => ['en' => 'Printing & Internet Balance', 'ar' => 'رصيد الطباعة والإنترنت'],
                        'order' => 2,
                    ],
                ],
                'features' => [
                    [
                        'name' => ['en' => '12 Days per month', 'ar' => '12 يوماً شهرياً'],
                        'value' => ['en' => '12 Days', 'ar' => '12 يوماً'],
                        'icon' => 'calendar',
                        'is_included' => true,
                        'order' => 1,
                    ],
                    [
                        'name' => ['en' => 'Access to a Hot Desk', 'ar' => 'وصول إلى مكتب مشترك'],
                        'value' => null,
                        'icon' => 'monitor',
                        'is_included' => true,
                        'order' => 2,
                    ],
                    [
                        'name' => ['en' => 'Internet Access', 'ar' => 'إنترنت عالي السرعة'],
                        'value' => null,
                        'icon' => 'wifi',
                        'is_included' => true,
                        'order' => 3,
                    ],
                    [
                        'name' => ['en' => 'Unlimited Coffee', 'ar' => 'قهوة غير محدودة'],
                        'value' => null,
                        'icon' => 'coffee',
                        'is_included' => true,
                        'order' => 4,
                    ],
                    [
                        'name' => ['en' => 'First access during reception hours', 'ar' => 'أول دخول خلال ساعات الاستقبال'],
                        'value' => null,
                        'icon' => 'clock',
                        'is_included' => true,
                        'order' => 5,
                    ],
                ],
                'discounts' => [
                    [
                        'percentage' => 30,
                        'space_type' => SpaceType::Room->value,
                        'description' => ['en' => '30% on Meeting Rooms', 'ar' => 'خصم 30% على غرف الاجتماعات'],
                        'order' => 1,
                    ],
                    [
                        'percentage' => 30,
                        'space_type' => SpaceType::EventHall->value,
                        'description' => ['en' => '30% on Event Spaces', 'ar' => 'خصم 30% على مساحات الفعاليات'],
                        'order' => 2,
                    ],
                ],
            ],
            [
                'id' => 3,
                'plan_module_id' => 1,
                'name' => [
                    'en' => 'The Happy Committer',
                    'ar' => 'ذا هابي كوميتر',
                ],
                'subtitle' => [
                    'en' => 'Full month access for everyday productive work.',
                    'ar' => 'وصول كامل طوال الشهر لإنتاجية يومية مستمرة.',
                ],
                'is_subscription' => true,
                'price_points' => 1100,
                'duration_days' => 30,
                'is_active' => true,
                'order' => 3,
                'balances' => [
                    [
                        'category' => WalletTransactionCategory::Cafe,
                        'amount_points' => 400,
                        'description' => ['en' => 'Café Balance', 'ar' => 'رصيد الكافيه'],
                        'order' => 1,
                    ],
                    [
                        'category' => WalletTransactionCategory::PrintingInternet,
                        'amount_points' => 250,
                        'description' => ['en' => 'Printing & Internet Balance', 'ar' => 'رصيد الطباعة والإنترنت'],
                        'order' => 2,
                    ],
                ],
                'features' => [
                    [
                        'name' => ['en' => '1 month unlimited', 'ar' => 'شهر كامل غير محدود'],
                        'value' => ['en' => '1 Month', 'ar' => 'شهر واحد'],
                        'icon' => 'calendar',
                        'is_included' => true,
                        'order' => 1,
                    ],
                    [
                        'name' => ['en' => 'Access to a Hot Desk', 'ar' => 'وصول إلى مكتب مشترك'],
                        'value' => null,
                        'icon' => 'monitor',
                        'is_included' => true,
                        'order' => 2,
                    ],
                    [
                        'name' => ['en' => 'Internet Access', 'ar' => 'إنترنت عالي السرعة'],
                        'value' => null,
                        'icon' => 'wifi',
                        'is_included' => true,
                        'order' => 3,
                    ],
                    [
                        'name' => ['en' => 'Unlimited Coffee', 'ar' => 'قهوة غير محدودة'],
                        'value' => null,
                        'icon' => 'coffee',
                        'is_included' => true,
                        'order' => 4,
                    ],
                    [
                        'name' => ['en' => 'First access to ADD during reception hours', 'ar' => 'أول دخول إلى ADD خلال ساعات الاستقبال'],
                        'value' => null,
                        'icon' => 'clock',
                        'is_included' => true,
                        'order' => 5,
                    ],
                ],
                'discounts' => [
                    [
                        'percentage' => 30,
                        'space_type' => SpaceType::Room->value,
                        'description' => ['en' => '30% on Meeting Rooms', 'ar' => 'خصم 30% على غرف الاجتماعات'],
                        'order' => 1,
                    ],
                    [
                        'percentage' => 30,
                        'space_type' => SpaceType::EventHall->value,
                        'description' => ['en' => '30% on Event Spaces', 'ar' => 'خصم 30% على مساحات الفعاليات'],
                        'order' => 2,
                    ],
                ],
            ],
            [
                'id' => 4,
                'plan_module_id' => 2,
                'name' => [
                    'en' => '1 Day Pass',
                    'ar' => 'بطاقة يوم واحد',
                ],
                'subtitle' => [
                    'en' => 'A dedicated desk for a full single day.',
                    'ar' => 'مكتب مخصص ليوم كامل بجميع الخدمات.',
                ],
                'is_subscription' => false,
                'price_points' => 220,
                'duration_days' => 1,
                'is_active' => true,
                'order' => 4,
                'balances' => [
                    [
                        'category' => WalletTransactionCategory::Cafe,
                        'amount_points' => 50,
                        'description' => ['en' => 'Day Café Credit', 'ar' => 'رصيد كافيه يومي'],
                        'order' => 1,
                    ],
                ],
                'features' => [
                    [
                        'name' => ['en' => '1 Day pass', 'ar' => 'بطاقة يوم واحد'],
                        'value' => ['en' => '1 Day', 'ar' => 'يوم واحد'],
                        'icon' => 'calendar',
                        'is_included' => true,
                        'order' => 1,
                    ],
                    [
                        'name' => ['en' => 'Access to a Dedicated Desk', 'ar' => 'وصول لمكتب مخصص'],
                        'value' => null,
                        'icon' => 'briefcase',
                        'is_included' => true,
                        'order' => 2,
                    ],
                    [
                        'name' => ['en' => 'Internet Access', 'ar' => 'إنترنت عالي السرعة'],
                        'value' => null,
                        'icon' => 'wifi',
                        'is_included' => true,
                        'order' => 3,
                    ],
                    [
                        'name' => ['en' => 'Unlimited Coffee', 'ar' => 'قهوة غير محدودة'],
                        'value' => null,
                        'icon' => 'coffee',
                        'is_included' => true,
                        'order' => 4,
                    ],
                    [
                        'name' => ['en' => 'First access during reception hours', 'ar' => 'أول دخول خلال ساعات الاستقبال'],
                        'value' => null,
                        'icon' => 'clock',
                        'is_included' => true,
                        'order' => 5,
                    ],
                ],
            ],
            [
                'id' => 5,
                'plan_module_id' => 2,
                'name' => [
                    'en' => 'The Dedicated',
                    'ar' => 'ذا ديديكيتد',
                ],
                'subtitle' => [
                    'en' => 'Your personal permanent desk with private storage.',
                    'ar' => 'مكتبك الخاص والدائم مع خزانة وقفل شخصي.',
                ],
                'is_subscription' => true,
                'price_points' => 1990,
                'duration_days' => 30,
                'is_active' => true,
                'order' => 5,
                'balances' => [
                    [
                        'category' => WalletTransactionCategory::Cafe,
                        'amount_points' => 400,
                        'description' => ['en' => 'Café Balance', 'ar' => 'رصيد الكافيه'],
                        'order' => 1,
                    ],
                    [
                        'category' => WalletTransactionCategory::PrintingInternet,
                        'amount_points' => 250,
                        'description' => ['en' => 'Printing & Internet Balance', 'ar' => 'رصيد الطباعة والإنترنت'],
                        'order' => 2,
                    ],
                ],
                'features' => [
                    [
                        'name' => ['en' => '1 Month access', 'ar' => 'وصول لمدة شهر'],
                        'value' => ['en' => '1 Month', 'ar' => 'شهر واحد'],
                        'icon' => 'calendar',
                        'is_included' => true,
                        'order' => 1,
                    ],
                    [
                        'name' => ['en' => 'Access to a Dedicated Desk', 'ar' => 'وصول لمكتب مخصص'],
                        'value' => null,
                        'icon' => 'briefcase',
                        'is_included' => true,
                        'order' => 2,
                    ],
                    [
                        'name' => ['en' => 'Internet Access', 'ar' => 'إنترنت عالي السرعة'],
                        'value' => null,
                        'icon' => 'wifi',
                        'is_included' => true,
                        'order' => 3,
                    ],
                    [
                        'name' => ['en' => 'Unlimited Coffee', 'ar' => 'قهوة غير محدودة'],
                        'value' => null,
                        'icon' => 'coffee',
                        'is_included' => true,
                        'order' => 4,
                    ],
                    [
                        'name' => ['en' => 'Access to Locker', 'ar' => 'خزانة شخصية'],
                        'value' => null,
                        'icon' => 'lock',
                        'is_included' => true,
                        'order' => 5,
                    ],
                    [
                        'name' => ['en' => 'First access to ADD during reception hours', 'ar' => 'أول دخول إلى ADD خلال ساعات الاستقبال'],
                        'value' => null,
                        'icon' => 'clock',
                        'is_included' => true,
                        'order' => 6,
                    ],
                ],
                'discounts' => [
                    [
                        'percentage' => 30,
                        'space_type' => SpaceType::Room->value,
                        'description' => ['en' => '30% on Meeting Rooms', 'ar' => 'خصم 30% على غرف الاجتماعات'],
                        'order' => 1,
                    ],
                    [
                        'percentage' => 30,
                        'space_type' => SpaceType::EventHall->value,
                        'description' => ['en' => '30% on Event Spaces', 'ar' => 'خصم 30% على مساحات الفعاليات'],
                        'order' => 2,
                    ],
                ],
            ],
            [
                'id' => 6,
                'plan_module_id' => 2,
                'name' => [
                    'en' => 'The Dedicated +',
                    'ar' => 'ذا ديديكيتد بلس',
                ],
                'subtitle' => [
                    'en' => 'Premium dedicated desk with printing quotas and meeting room hours.',
                    'ar' => 'مكتب مخصص مميز مع حصص طباعة وساعات غرف اجتماعات مجانية.',
                ],
                'is_subscription' => true,
                'price_points' => 2500,
                'duration_days' => 30,
                'is_active' => true,
                'order' => 6,
                'balances' => [
                    [
                        'category' => WalletTransactionCategory::Cafe,
                        'amount_points' => 500,
                        'description' => ['en' => 'Café Balance', 'ar' => 'رصيد الكافيه'],
                        'order' => 1,
                    ],
                    [
                        'category' => WalletTransactionCategory::PrintingInternet,
                        'amount_points' => 350,
                        'description' => ['en' => 'Printing & Internet Balance', 'ar' => 'رصيد الطباعة والإنترنت'],
                        'order' => 2,
                    ],
                    [
                        'category' => WalletTransactionCategory::SpaceSpecific,
                        'amount_points' => 80,
                        'description' => ['en' => 'Meeting Room Hours', 'ar' => 'ساعات غرف الاجتماعات'],
                        'order' => 3,
                    ],
                ],
                'features' => [
                    [
                        'name' => ['en' => '1 Month access', 'ar' => 'وصول لمدة شهر'],
                        'value' => ['en' => '1 Month', 'ar' => 'شهر واحد'],
                        'icon' => 'calendar',
                        'is_included' => true,
                        'order' => 1,
                    ],
                    [
                        'name' => ['en' => 'Access to a Dedicated Desk', 'ar' => 'وصول لمكتب مخصص'],
                        'value' => null,
                        'icon' => 'briefcase',
                        'is_included' => true,
                        'order' => 2,
                    ],
                    [
                        'name' => ['en' => 'Internet Access', 'ar' => 'إنترنت عالي السرعة'],
                        'value' => null,
                        'icon' => 'wifi',
                        'is_included' => true,
                        'order' => 3,
                    ],
                    [
                        'name' => ['en' => 'Unlimited Coffee', 'ar' => 'قهوة غير محدودة'],
                        'value' => null,
                        'icon' => 'coffee',
                        'is_included' => true,
                        'order' => 4,
                    ],
                    [
                        'name' => ['en' => 'Access to Locker', 'ar' => 'خزانة شخصية'],
                        'value' => null,
                        'icon' => 'lock',
                        'is_included' => true,
                        'order' => 5,
                    ],
                    [
                        'name' => ['en' => 'Printing: 100 B&W, 50 Colored', 'ar' => 'طباعة: 100 أبيض وأسود، 50 ملون'],
                        'value' => ['en' => '100 B&W, 50 Colored', 'ar' => '100 أبيض وأسود، 50 ملون'],
                        'icon' => 'printer',
                        'is_included' => true,
                        'order' => 6,
                    ],
                    [
                        'name' => ['en' => 'Access to Meeting Rooms: 8 Hours, 50% on additional hours', 'ar' => 'غرف الاجتماعات: 8 ساعات، 50% على الساعات الإضافية'],
                        'value' => ['en' => '8 Hours', 'ar' => '8 ساعات'],
                        'icon' => 'clock',
                        'is_included' => true,
                        'order' => 7,
                    ],
                    [
                        'name' => ['en' => 'First access to ADD during reception hours', 'ar' => 'أول دخول إلى ADD خلال ساعات الاستقبال'],
                        'value' => null,
                        'icon' => 'clock',
                        'is_included' => true,
                        'order' => 8,
                    ],
                ],
                'discounts' => [
                    [
                        'percentage' => 50,
                        'space_type' => SpaceType::EventHall->value,
                        'description' => ['en' => '50% on Event Spaces', 'ar' => 'خصم 50% على مساحات الفعاليات'],
                        'order' => 1,
                    ],
                ],
            ],
        ];

        foreach ($plans as $planData) {
            $balances = $planData['balances'] ?? [];
            $features = $planData['features'] ?? [];
            $discounts = $planData['discounts'] ?? [];
            unset($planData['balances'], $planData['features'], $planData['discounts']);

            $plan = Plan::updateOrCreate(
                ['id' => $planData['id']],
                $planData
            );

            $plan->balances()->delete();
            if (! empty($balances)) {
                $plan->balances()->createMany($balances);
            }

            $plan->features()->delete();
            if (! empty($features)) {
                $plan->features()->createMany($features);
            }

            $plan->discounts()->delete();
            if (! empty($discounts)) {
                $plan->discounts()->createMany($discounts);
            }
        }
    }
}
