<?php

namespace Database\Seeders;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventCategory;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Support\Time\LocalTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Five categories and twenty events spread across past, present and
 * future, so every browse filter and badge has something real to return:
 * free and paid, draft/published/cancelled, full and half-empty, indoor
 * (space-linked) and external (location string).
 *
 * Prices are whole points — the only stored price since
 * 2026_09_14_150000 dropped events.price/currency. At the default rate
 * of 0.10 USD/point, 100 points reads as $10.00.
 *
 * Dates are relative to seed time, so the calendar is never stale.
 * Keyed on the English title via firstOrCreate, so re-running never
 * duplicates.
 */
class EventSeeder extends Seeder
{
    public function run(): void
    {
        $categories = $this->seedCategories();
        $branchId = Branch::query()->value('id');

        // Indoor events point at a real space; the rest carry a location
        // string for venues ADD does not own.
        $hallId = Space::query()->where('space_type', 'event_hall')->value('id');
        $roomId = Space::query()->where('space_type', 'room')->value('id');

        foreach ($this->events($categories, $hallId, $roomId) as $index => $event) {
            Event::query()->firstOrCreate(
                ['title->en' => $event['title']['en']],
                array_merge($event, [
                    'branch_id' => $branchId,
                    'order' => $index + 1,
                ])
            );
        }
    }

    /**
     * @return array<string, int> slug => id
     */
    private function seedCategories(): array
    {
        $categories = [
            ['slug' => 'workshops', 'name' => ['en' => 'Workshops', 'ar' => 'ورش عمل'], 'description' => ['en' => 'Hands-on sessions where you build something by the end.', 'ar' => 'جلسات تطبيقية تخرج منها بشيء صنعته بيدك.'], 'color' => '#2563EB', 'icon' => 'tabler:tools'],
            ['slug' => 'networking', 'name' => ['en' => 'Networking', 'ar' => 'تواصل'], 'description' => ['en' => 'Meet the people building things next door.', 'ar' => 'تعرّف على من يبنون مشاريعهم بجوارك.'], 'color' => '#059669', 'icon' => 'tabler:users-group'],
            ['slug' => 'talks', 'name' => ['en' => 'Talks & Panels', 'ar' => 'محاضرات وندوات'], 'description' => ['en' => 'Someone who did the thing tells you how.', 'ar' => 'من خاض التجربة يروي لك كيف.'], 'color' => '#7C3AED', 'icon' => 'tabler:microphone'],
            ['slug' => 'community', 'name' => ['en' => 'Community', 'ar' => 'مجتمع'], 'description' => ['en' => 'Lighter gatherings — food, games, and no agenda.', 'ar' => 'لقاءات خفيفة: طعام وألعاب وبلا جدول أعمال.'], 'color' => '#EA580C', 'icon' => 'tabler:confetti'],
            ['slug' => 'training', 'name' => ['en' => 'Training', 'ar' => 'تدريب'], 'description' => ['en' => 'Multi-session programmes with a certificate at the end.', 'ar' => 'برامج متعددة الجلسات تُختتم بشهادة.'], 'color' => '#DB2777', 'icon' => 'tabler:certificate'],
        ];

        $ids = [];

        foreach ($categories as $index => $category) {
            $ids[$category['slug']] = EventCategory::query()->firstOrCreate(
                ['slug' => $category['slug']],
                array_merge($category, ['order' => $index + 1, 'is_active' => true])
            )->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $categories
     * @return list<array<string, mixed>>
     */
    private function events(array $categories, ?int $hallId, ?int $roomId): array
    {
        // setTime() below states the wall clock these events run at, so the
        // base has to be local. Each value is then converted with
        // LocalTime::toUtc(), because Eloquent's datetime cast *relabels*
        // rather than converts — handing it 19:00+03 stores 19:00 UTC and
        // silently moves the event three hours.
        $local = app(LocalTime::class);
        $now = Carbon::now($local->timezone());
        $at = fn (int $days, int $h, int $m) => $local->toUtc(
            $now->copy()->addDays($days)->setTime($h, $m)->format('Y-m-d H:i:s')
        );

        return [
            // ── Upcoming, published, free ────────────────────────────────
            [
                'category_id' => $categories['networking'],
                'space_id' => $hallId,
                'title' => ['en' => 'Founders Breakfast', 'ar' => 'فطور المؤسسين'],
                'description' => ['en' => 'An open table for founders to trade notes over coffee before the day starts.', 'ar' => 'طاولة مفتوحة للمؤسسين لتبادل الخبرات على فنجان قهوة قبل أن يبدأ اليوم.'],
                'start_at' => $at(3, 8, 30),
                'end_at' => $at(3, 10, 0),
                'location' => null,
                'capacity' => 30,
                'is_free' => true,
                'price_points' => 0,
                'status' => 'published',
            ],
            [
                'category_id' => $categories['community'],
                'space_id' => $hallId,
                'title' => ['en' => 'Open Mic Night', 'ar' => 'ليلة المايك المفتوح'],
                'description' => ['en' => 'Five minutes on stage, any topic. Poetry, a demo, or a rant about CSS.', 'ar' => 'خمس دقائق على المسرح بأي موضوع: شعر، أو عرض مشروع، أو شكوى من CSS.'],
                'start_at' => $at(6, 19, 0),
                'end_at' => $at(6, 21, 30),
                'location' => null,
                'capacity' => 60,
                'is_free' => true,
                'price_points' => 0,
                'status' => 'published',
            ],
            [
                'category_id' => $categories['talks'],
                'space_id' => $roomId,
                'title' => ['en' => 'How We Shipped Without a Roadmap', 'ar' => 'كيف أطلقنا المنتج بلا خارطة طريق'],
                'description' => ['en' => 'A candid post-mortem from a team that launched in nine weeks, and what it cost them.', 'ar' => 'مراجعة صريحة من فريق أطلق منتجه في تسعة أسابيع، وما كلّفهم ذلك.'],
                'start_at' => $at(9, 17, 0),
                'end_at' => $at(9, 18, 30),
                'location' => null,
                'capacity' => 40,
                'is_free' => true,
                'price_points' => 0,
                'status' => 'published',
            ],

            // ── Upcoming, published, paid ────────────────────────────────
            [
                'category_id' => $categories['workshops'],
                'space_id' => $roomId,
                'title' => ['en' => 'Figma for Non-Designers', 'ar' => 'فيغما لغير المصممين'],
                'description' => ['en' => 'Build a real screen from a blank canvas. No design background needed.', 'ar' => 'ابنِ واجهة حقيقية من صفحة بيضاء. لا تحتاج خلفية في التصميم.'],
                'start_at' => $at(5, 16, 0),
                'end_at' => $at(5, 19, 0),
                'location' => null,
                'capacity' => 20,
                'is_free' => false,
                'price_points' => 150,
                'status' => 'published',
            ],
            [
                'category_id' => $categories['workshops'],
                'space_id' => $roomId,
                'title' => ['en' => 'Intro to Data Analysis with Python', 'ar' => 'مدخل إلى تحليل البيانات بلغة بايثون'],
                'description' => ['en' => 'From a messy spreadsheet to a chart that answers a question. Bring a laptop.', 'ar' => 'من جدول بيانات فوضوي إلى رسم بياني يجيب عن سؤال. أحضر حاسوبك.'],
                'start_at' => $at(12, 15, 0),
                'end_at' => $at(12, 18, 0),
                'location' => null,
                'capacity' => 18,
                'is_free' => false,
                'price_points' => 200,
                'status' => 'published',
            ],
            [
                'category_id' => $categories['training'],
                'space_id' => $roomId,
                'title' => ['en' => 'Project Management Fundamentals', 'ar' => 'أساسيات إدارة المشاريع'],
                'description' => ['en' => 'Four sessions covering scope, estimation, risk and delivery. Certificate on completion.', 'ar' => 'أربع جلسات تغطي النطاق والتقدير والمخاطر والتسليم، مع شهادة عند الإتمام.'],
                'start_at' => $at(14, 10, 0),
                'end_at' => $at(17, 14, 0),
                'location' => null,
                'capacity' => 25,
                'is_free' => false,
                'price_points' => 800,
                'status' => 'published',
            ],
            [
                'category_id' => $categories['training'],
                'space_id' => $hallId,
                'title' => ['en' => 'Public Speaking Intensive', 'ar' => 'دورة مكثّفة في الخطابة'],
                'description' => ['en' => 'You will speak four times and be recorded each time. Uncomfortable by design.', 'ar' => 'ستتحدث أربع مرات وتُسجَّل في كل مرة. الانزعاج جزء من التصميم.'],
                'start_at' => $at(21, 9, 0),
                'end_at' => $at(22, 16, 0),
                'location' => null,
                'capacity' => 15,
                'is_free' => false,
                'price_points' => 650,
                'status' => 'published',
            ],
            [
                'category_id' => $categories['talks'],
                'space_id' => $hallId,
                'title' => ['en' => 'Fundraising in a Hard Market', 'ar' => 'جمع التمويل في سوق صعب'],
                'description' => ['en' => 'What investors actually ask when capital is tight, from people on both sides of the table.', 'ar' => 'ما يسأله المستثمرون فعلاً حين يشحّ رأس المال، من طرفَي الطاولة.'],
                'start_at' => $at(18, 18, 0),
                'end_at' => $at(18, 20, 0),
                'location' => null,
                'capacity' => 80,
                'is_free' => false,
                'price_points' => 100,
                'status' => 'published',
            ],

            // ── External venues (no space_id) ────────────────────────────
            [
                'category_id' => $categories['community'],
                'space_id' => null,
                'title' => ['en' => 'City Walk & Photo Hunt', 'ar' => 'جولة في المدينة ومطاردة الصور'],
                'description' => ['en' => 'A guided walk through the old city with a photo brief. Meet at the citadel gate.', 'ar' => 'جولة مرشدة في المدينة القديمة مع مهمة تصوير. اللقاء عند باب القلعة.'],
                'start_at' => $at(10, 9, 0),
                'end_at' => $at(10, 12, 0),
                'location' => 'Aleppo Citadel Gate',
                'capacity' => 25,
                'is_free' => true,
                'price_points' => 0,
                'status' => 'published',
            ],
            [
                'category_id' => $categories['networking'],
                'space_id' => null,
                'title' => ['en' => 'Industry Mixer at the Chamber', 'ar' => 'لقاء القطاعات في الغرفة التجارية'],
                'description' => ['en' => 'A joint evening with the Chamber of Commerce. Bring cards, leave with contacts.', 'ar' => 'أمسية مشتركة مع الغرفة التجارية. أحضر بطاقاتك واخرج بعلاقات.'],
                'start_at' => $at(16, 18, 30),
                'end_at' => $at(16, 21, 0),
                'location' => 'Aleppo Chamber of Commerce',
                'capacity' => 100,
                'is_free' => false,
                'price_points' => 50,
                'status' => 'published',
            ],

            // ── Nearly full / full, to exercise seat badges ──────────────
            [
                'category_id' => $categories['workshops'],
                'space_id' => $roomId,
                'title' => ['en' => 'Resume Clinic', 'ar' => 'عيادة السيرة الذاتية'],
                'description' => ['en' => 'Bring your CV; leave with it rewritten. One-to-one review slots.', 'ar' => 'أحضر سيرتك الذاتية واخرج بها معاد كتابتها. جلسات مراجعة فردية.'],
                'start_at' => $at(4, 14, 0),
                'end_at' => $at(4, 17, 0),
                'location' => null,
                'capacity' => 8,
                'is_free' => true,
                'price_points' => 0,
                'status' => 'published',
            ],
            [
                'category_id' => $categories['talks'],
                'space_id' => $roomId,
                'title' => ['en' => 'Legal Basics for Freelancers', 'ar' => 'أساسيات قانونية للعاملين المستقلين'],
                'description' => ['en' => 'Contracts, invoices and what to do when a client stops replying.', 'ar' => 'العقود والفواتير وما تفعله حين يتوقف العميل عن الرد.'],
                'start_at' => $at(7, 17, 30),
                'end_at' => $at(7, 19, 0),
                'location' => null,
                'capacity' => 12,
                'is_free' => false,
                'price_points' => 120,
                'status' => 'published',
            ],

            // ── Far future ───────────────────────────────────────────────
            [
                'category_id' => $categories['community'],
                'space_id' => $hallId,
                'title' => ['en' => 'Annual Community Day', 'ar' => 'اليوم السنوي للمجتمع'],
                'description' => ['en' => 'The whole district in one room: demos, food, and the year in review.', 'ar' => 'الحي كله في قاعة واحدة: عروض وطعام ومراجعة العام.'],
                'start_at' => $at(45, 11, 0),
                'end_at' => $at(45, 20, 0),
                'location' => null,
                'capacity' => 200,
                'is_free' => true,
                'price_points' => 0,
                'status' => 'published',
            ],
            [
                'category_id' => $categories['training'],
                'space_id' => $roomId,
                'title' => ['en' => 'Advanced Excel for Finance', 'ar' => 'إكسل متقدّم للماليين'],
                'description' => ['en' => 'Pivot tables, lookups and models that do not break when someone inserts a row.', 'ar' => 'الجداول المحورية ودوال البحث ونماذج لا تنهار حين يُدرج أحدهم صفاً.'],
                'start_at' => $at(38, 16, 0),
                'end_at' => $at(39, 19, 0),
                'location' => null,
                'capacity' => 22,
                'is_free' => false,
                'price_points' => 450,
                'status' => 'published',
            ],

            // ── Draft: admin-visible, hidden from members ────────────────
            [
                'category_id' => $categories['workshops'],
                'space_id' => $roomId,
                'title' => ['en' => 'Brand Identity Sprint', 'ar' => 'سباق بناء الهوية البصرية'],
                'description' => ['en' => 'Still being scheduled — a one-day sprint from positioning to logo.', 'ar' => 'ما زال قيد الجدولة — سباق ليوم واحد من التموضع إلى الشعار.'],
                'start_at' => $at(30, 10, 0),
                'end_at' => $at(30, 18, 0),
                'location' => null,
                'capacity' => 16,
                'is_free' => false,
                'price_points' => 300,
                'status' => 'draft',
            ],
            [
                'category_id' => $categories['networking'],
                'space_id' => null,
                'title' => ['en' => 'Investor Office Hours', 'ar' => 'ساعات مكتبية مع المستثمرين'],
                'description' => ['en' => 'Draft — waiting on confirmation from the visiting fund.', 'ar' => 'مسودة — بانتظار تأكيد الصندوق الزائر.'],
                'start_at' => $at(25, 13, 0),
                'end_at' => $at(25, 17, 0),
                'location' => 'To be confirmed',
                'capacity' => 10,
                'is_free' => true,
                'price_points' => 0,
                'status' => 'draft',
            ],

            // ── Cancelled ───────────────────────────────────────────────
            [
                'category_id' => $categories['talks'],
                'space_id' => $hallId,
                'title' => ['en' => 'Supply Chain After the Border Closure', 'ar' => 'سلاسل التوريد بعد إغلاق الحدود'],
                'description' => ['en' => 'Cancelled — the speaker could not travel. A new date will be announced.', 'ar' => 'ألغيت — تعذّر سفر المتحدث. سيُعلن موعد جديد.'],
                'start_at' => $at(8, 18, 0),
                'end_at' => $at(8, 20, 0),
                'location' => null,
                'capacity' => 70,
                'is_free' => true,
                'price_points' => 0,
                'status' => 'cancelled',
            ],

            // ── Past, for "my events" history and past filters ───────────
            [
                'category_id' => $categories['workshops'],
                'space_id' => $roomId,
                'title' => ['en' => 'Pitch Deck Teardown', 'ar' => 'تشريح عرض المستثمرين'],
                'description' => ['en' => 'Six decks reviewed live, slide by slide, in front of everyone.', 'ar' => 'ستة عروض روجعت مباشرة، شريحة شريحة، أمام الجميع.'],
                'start_at' => $at(-12, 16, 0),
                'end_at' => $at(-12, 19, 0),
                'location' => null,
                'capacity' => 20,
                'is_free' => false,
                'price_points' => 180,
                'status' => 'published',
            ],
            [
                'category_id' => $categories['community'],
                'space_id' => $hallId,
                'title' => ['en' => 'Board Games Night', 'ar' => 'ليلة ألعاب الطاولة'],
                'description' => ['en' => 'Catan, Codenames, and an argument about the rules that lasted an hour.', 'ar' => 'كتان وكودنيمز، ونقاش حول القواعد دام ساعة كاملة.'],
                'start_at' => $at(-5, 19, 0),
                'end_at' => $at(-5, 23, 0),
                'location' => null,
                'capacity' => 40,
                'is_free' => true,
                'price_points' => 0,
                'status' => 'published',
            ],
            [
                'category_id' => $categories['networking'],
                'space_id' => $hallId,
                'title' => ['en' => 'Hiring Fair: Winter Edition', 'ar' => 'ملتقى التوظيف: نسخة الشتاء'],
                'description' => ['en' => 'Twelve companies, ninety candidates, and eleven offers by the end of the month.', 'ar' => 'اثنتا عشرة شركة وتسعون مرشحاً وأحد عشر عرض عمل بنهاية الشهر.'],
                'start_at' => $at(-30, 10, 0),
                'end_at' => $at(-30, 16, 0),
                'location' => null,
                'capacity' => 120,
                'is_free' => true,
                'price_points' => 0,
                'status' => 'published',
            ],
        ];
    }
}
