<?php

namespace Database\Seeders;

use App\Domain\Services\Enums\RequestMethod;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceType;
use Illuminate\Database\Seeder;

/**
 * The brand's services for the member app's "Explore ADD", by layer: the
 * axes ServiceAxisSeeder creates. Placeholder text, meant to be replaced
 * from the dashboard — hence firstOrCreate: running this again never
 * overwrites what an admin wrote.
 *
 * Those with somewhere to go in the app are active; the rest wait,
 * inactive, for their content. Business Address and Business Café already
 * come from ServiceAxisSeeder. ADD Club is left out on purpose: it stays
 * unmodeled until its decisions are made (PRD §7.2, AddClubUnmodeledTest).
 *
 * Run after ServiceAxisSeeder: php artisan db:seed --class=BrandServiceSeeder
 */
class BrandServiceSeeder extends Seeder
{
    public function run(): void
    {
        $axes = ServiceAxis::query()->whereIn('slug', ['core', 'experience', 'ecosystem'])->pluck('id', 'slug');

        $services = [
            ['core', 'rooms', 'ADD Rooms', 10, true,
                'Meeting, workshop and training rooms.', 'قاعات للاجتماعات وورش العمل والتدريب.',
                ['route' => 'spaces', 'params' => ['space_type' => 'room']]],
            ['core', 'co-space', 'ADD Co-Space', 11, true,
                'Flexible co-working desks.', 'مساحات عمل مشتركة مرنة.',
                ['route' => 'spaces', 'params' => ['space_type' => 'co_space']]],
            ['core', 'business', 'ADD Business', 12, true,
                'Private offices for teams and companies.', 'مكاتب خاصة للفرق والشركات.',
                ['route' => 'spaces', 'params' => ['space_type' => 'business']]],
            ['core', 'accelerate', 'ADD Accelerate', 13, false,
                'Acceleration programmes for growing startups.', 'برامج تسريع للشركات الناشئة.', null],
            ['core', 'incubate', 'ADD Incubate', 14, false,
                'Incubation for early-stage ideas.', 'احتضان للأفكار في مراحلها الأولى.', null],
            ['experience', 'events', 'ADD Events', 10, true,
                'Talks, workshops and gatherings.', 'لقاءات وورش عمل وفعاليات.',
                ['route' => 'events', 'params' => []]],
            ['ecosystem', 'community', 'ADD Community', 10, false,
                'The people who work and meet at ADD.', 'مجتمع من يعملون ويلتقون في ADD.', null],
            ['ecosystem', 'members', 'ADD Members', 11, false,
                'ADD members and what they share.', 'أعضاء ADD وما يتشاركونه.', null],
            ['ecosystem', 'partners', 'ADD Partners', 12, false,
                'The partners ADD works with.', 'الشركاء الذين تعمل معهم ADD.', null],
            ['ecosystem', 'place', 'ADD Place', 13, false,
                'Places to meet and host.', 'أماكن للقاء والاستضافة.', null],
        ];

        foreach ($services as [$axis, $slug, $name, $order, $active, $en, $ar, $redirect]) {
            if (! isset($axes[$axis])) {
                continue;
            }

            ServiceType::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'service_axis_id' => $axes[$axis],
                    'name' => ['en' => $name, 'ar' => $name],
                    'description' => ['en' => $en, 'ar' => $ar],
                    'about' => [
                        'en' => "<h2>{$name}</h2><p>{$en}</p>",
                        'ar' => "<h2>{$name}</h2><p>{$ar}</p>",
                    ],
                    'is_active' => $active,
                    'is_coming_soon' => false,
                    'show_on_home' => true,
                    'requires_location' => false,
                    'requires_review' => false,
                    'visibility' => 'member',
                    'request_method' => $redirect === null ? RequestMethod::InfoOnly->value : RequestMethod::InternalRedirect->value,
                    'request_config' => $redirect ?? ['content' => ['en' => $en, 'ar' => $ar]],
                    'order' => $order,
                ],
            );
        }
    }
}
