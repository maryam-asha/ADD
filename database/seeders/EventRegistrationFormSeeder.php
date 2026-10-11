<?php

namespace Database\Seeders;

use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\FormField;
use App\Support\Forms\FieldType;
use Illuminate\Database\Seeder;

class EventRegistrationFormSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedFigmaWorkshop();
        $this->seedPythonWorkshop();
        $this->seedProjectManagement();
        $this->seedPublicSpeaking();
        $this->seedFoundersBreakfast();
        $this->seedResumeClinic();
        $this->seedIndustryMixer();
        $this->seedOpenMicNight();
        $this->seedFundraisingExternalForm();

        // FormField's model events normally keep has_registration_form in
        // step, but DatabaseSeeder runs WithoutModelEvents, which silences
        // them for the whole seed — so set the flag from the forms directly.
        foreach (Event::with('registrationForm')->get() as $event) {
            $hasForm = (bool) $event->registrationForm?->activeFields()->exists();

            if ($event->has_registration_form !== $hasForm) {
                $event->forceFill(['has_registration_form' => $hasForm])->save();
            }
        }
    }

    private function findEvent(string $titleEn): ?Event
    {
        return Event::query()->where('title->en', $titleEn)->first();
    }

    private function seedFigmaWorkshop(): void
    {
        $event = $this->findEvent('Figma for Non-Designers');
        if (! $event) {
            return;
        }

        $fields = [
            [
                'key' => 'experience_level',
                'type' => FieldType::Select,
                'label' => ['en' => 'Design Experience Level', 'ar' => 'مستوى الخبرة في التصميم'],
                'placeholder' => ['en' => 'Select your level', 'ar' => 'اختر مستواك'],
                'options' => [
                    ['value' => 'beginner', 'label' => ['en' => 'Total Beginner', 'ar' => 'مبتدئ تماماً']],
                    ['value' => 'intermediate', 'label' => ['en' => 'Occasional User', 'ar' => 'مستخدم متقطع']],
                    ['value' => 'advanced', 'label' => ['en' => 'Intermediate to Advanced', 'ar' => 'متوسط أو متقدم']],
                ],
                'is_required' => true,
                'is_active' => true,
                'order' => 1,
            ],
            [
                'key' => 'has_laptop',
                'type' => FieldType::Select,
                'label' => ['en' => 'Will you bring your own laptop?', 'ar' => 'هل ستحضر حاسوبك المحمول؟'],
                'placeholder' => ['en' => 'Select an option', 'ar' => 'اختر إجابة'],
                'options' => [
                    ['value' => 'yes', 'label' => ['en' => 'Yes, I will bring my laptop', 'ar' => 'نعم، سأحضر لابتوب شخصي']],
                    ['value' => 'need_pc', 'label' => ['en' => 'No, I need a workstation', 'ar' => 'لا، أحتاج محطة عمل في القاعة']],
                ],
                'is_required' => true,
                'is_active' => true,
                'order' => 2,
            ],
            [
                'key' => 'portfolio_url',
                'type' => FieldType::Text,
                'label' => ['en' => 'Portfolio or Website Link (optional)', 'ar' => 'رابط معرض الأعمال أو الموقع (اختياري)'],
                'placeholder' => ['en' => 'https://portfolio.local/...', 'ar' => 'https://portfolio.local/...'],
                'is_required' => false,
                'is_active' => true,
                'order' => 3,
            ],
            [
                'key' => 'learning_goal',
                'type' => FieldType::Textarea,
                'label' => ['en' => 'What do you hope to build by the end?', 'ar' => 'ما الذي تأمل تعلمه وبناؤه بنهاية الورشة؟'],
                'placeholder' => ['en' => 'Tell us about your learning goal...', 'ar' => 'أخبرنا عن هدفك من الحضور...'],
                'is_required' => false,
                'is_active' => true,
                'order' => 4,
            ],
        ];

        $this->syncFields($event, $fields);
    }

    private function seedPythonWorkshop(): void
    {
        $event = $this->findEvent('Intro to Data Analysis with Python');
        if (! $event) {
            return;
        }

        $fields = [
            [
                'key' => 'python_knowledge',
                'type' => FieldType::Select,
                'label' => ['en' => 'Previous Python Knowledge', 'ar' => 'مدى معرفتك السابقة بلغة بايثون'],
                'placeholder' => ['en' => 'Select your background', 'ar' => 'اختر مدى معرفتك'],
                'options' => [
                    ['value' => 'none', 'label' => ['en' => 'No prior knowledge', 'ar' => 'لا توجد خلفية سابقة']],
                    ['value' => 'basic', 'label' => ['en' => 'Basic syntax (variables, loops)', 'ar' => 'أساسيات بسيطة (متغيرات، شروط)']],
                    ['value' => 'comfortable', 'label' => ['en' => 'Write Python regularly', 'ar' => 'أكتب كود بايثون بانتظام']],
                ],
                'is_required' => true,
                'is_active' => true,
                'order' => 1,
            ],
            [
                'key' => 'operating_system',
                'type' => FieldType::Select,
                'label' => ['en' => 'Your Operating System', 'ar' => 'نظام التشغيل على جهازك'],
                'options' => [
                    ['value' => 'windows', 'label' => ['en' => 'Windows', 'ar' => 'ويندوز']],
                    ['value' => 'macos', 'label' => ['en' => 'macOS', 'ar' => 'ماك (macOS)']],
                    ['value' => 'linux', 'label' => ['en' => 'Linux', 'ar' => 'لينكس (Linux)']],
                ],
                'is_required' => true,
                'is_active' => true,
                'order' => 2,
            ],
            [
                'key' => 'github_username',
                'type' => FieldType::Text,
                'label' => ['en' => 'GitHub Username (optional)', 'ar' => 'اسم المستخدم على GitHub (اختياري)'],
                'placeholder' => ['en' => 'e.g. octocat', 'ar' => 'مثال: octocat'],
                'is_required' => false,
                'is_active' => true,
                'order' => 3,
            ],
        ];

        $this->syncFields($event, $fields);
    }

    private function seedProjectManagement(): void
    {
        $event = $this->findEvent('Project Management Fundamentals');
        if (! $event) {
            return;
        }

        $fields = [
            [
                'key' => 'current_role',
                'type' => FieldType::Text,
                'label' => ['en' => 'Current Job Title', 'ar' => 'المسمى الوظيفي الحالي'],
                'placeholder' => ['en' => 'e.g. Product Manager, Engineer...', 'ar' => 'مثال: مدير منتج، مهندس برمجيات...'],
                'is_required' => true,
                'is_active' => true,
                'order' => 1,
            ],
            [
                'key' => 'organization',
                'type' => FieldType::Text,
                'label' => ['en' => 'Company or Organization', 'ar' => 'الشركة أو المنظمة'],
                'placeholder' => ['en' => 'Company name...', 'ar' => 'اسم جهة العمل...'],
                'is_required' => true,
                'is_active' => true,
                'order' => 2,
            ],
            [
                'key' => 'years_of_experience',
                'type' => FieldType::Number,
                'label' => ['en' => 'Years of Professional Experience', 'ar' => 'سنوات الخبرة المهنية'],
                'placeholder' => ['en' => 'Number of years', 'ar' => 'عدد السنوات'],
                'validation' => ['min' => 0, 'max' => 50],
                'is_required' => true,
                'is_active' => true,
                'order' => 3,
            ],
            [
                'key' => 'methodology',
                'type' => FieldType::Select,
                'label' => ['en' => 'Current Project Methodology', 'ar' => 'المنهجية المتبعة في مشاريعك حالياً'],
                'options' => [
                    ['value' => 'agile_scrum', 'label' => ['en' => 'Agile / Scrum', 'ar' => 'أجايل / سكرام (Agile / Scrum)']],
                    ['value' => 'waterfall', 'label' => ['en' => 'Traditional Waterfall', 'ar' => 'الشلال التقليدي (Waterfall)']],
                    ['value' => 'hybrid', 'label' => ['en' => 'Hybrid', 'ar' => 'مزيج هجين (Hybrid)']],
                    ['value' => 'none', 'label' => ['en' => 'No formal methodology', 'ar' => 'لا توجد منهجية محددة']],
                ],
                'is_required' => true,
                'is_active' => true,
                'order' => 4,
            ],
        ];

        $this->syncFields($event, $fields);
    }

    private function seedPublicSpeaking(): void
    {
        $event = $this->findEvent('Public Speaking Intensive');
        if (! $event) {
            return;
        }

        $fields = [
            [
                'key' => 'speaking_frequency',
                'type' => FieldType::Select,
                'label' => ['en' => 'How often do you speak in public?', 'ar' => 'كم مرة تتحدث أمام جمهور سنوياً؟'],
                'options' => [
                    ['value' => 'never', 'label' => ['en' => 'Never', 'ar' => 'لم أتحدث أمام جمهور من قبل']],
                    ['value' => 'rarely', 'label' => ['en' => 'Rarely (1-2 times)', 'ar' => 'نادراً (مرة أو مرتين)']],
                    ['value' => 'regularly', 'label' => ['en' => 'Regularly as part of my job', 'ar' => 'باستمرار بحكم عملي']],
                ],
                'is_required' => true,
                'is_active' => true,
                'order' => 1,
            ],
            [
                'key' => 'topic_idea',
                'type' => FieldType::Text,
                'label' => ['en' => 'A topic idea you want to speak about', 'ar' => 'فكرة أو موضوع تود التحدث عنه في الجلسة'],
                'placeholder' => ['en' => 'e.g. Lessons learned in 2026...', 'ar' => 'مثلاً: دروس تعلمتها في 2026...'],
                'is_required' => true,
                'is_active' => true,
                'order' => 2,
            ],
            [
                'key' => 'recording_consent',
                'type' => FieldType::Select,
                'label' => ['en' => 'Consent to record practice sessions', 'ar' => 'الموافقة على تسجيل محاولات الإلقاء للتحليل'],
                'options' => [
                    ['value' => 'accepted', 'label' => ['en' => 'I agree to be recorded for training review', 'ar' => 'أوافق على التسجيل لغرض التدريب والتحليل']],
                    ['value' => 'declined', 'label' => ['en' => 'I prefer not to be recorded', 'ar' => 'أفضّل عدم التسجيل']],
                ],
                'is_required' => true,
                'is_active' => true,
                'order' => 3,
            ],
        ];

        $this->syncFields($event, $fields);
    }

    private function seedFoundersBreakfast(): void
    {
        $event = $this->findEvent('Founders Breakfast');
        if (! $event) {
            return;
        }

        $fields = [
            // 1. Text
            [
                'key' => 'startup_name',
                'type' => FieldType::Text,
                'label' => ['en' => 'Startup / Project Name', 'ar' => 'اسم الشركة الناشئة / المشروع'],
                'placeholder' => ['en' => 'Enter your startup name...', 'ar' => 'أدخل اسم مشروعك أو شركتك...'],
                'validation' => ['min' => 2, 'max' => 100],
                'is_required' => true,
                'is_active' => true,
                'order' => 1,
            ],
            // 2. Textarea
            [
                'key' => 'startup_bio',
                'type' => FieldType::Textarea,
                'label' => ['en' => 'Startup & Product Overview', 'ar' => 'نبذة عن المشروع والمنتج'],
                'placeholder' => ['en' => 'Briefly explain the problem you solve and your value proposition...', 'ar' => 'اشرح باختصار المشكلة التي تحلها والقيمة المضافة...'],
                'validation' => ['min' => 10, 'max' => 500],
                'is_required' => true,
                'is_active' => true,
                'order' => 2,
            ],
            // 3. Select
            [
                'key' => 'startup_stage',
                'type' => FieldType::Select,
                'label' => ['en' => 'Current Startup Stage', 'ar' => 'مرحلة نضج المشروع الحالية'],
                'placeholder' => ['en' => 'Select your stage', 'ar' => 'اختر مرحلة المشروع'],
                'options' => [
                    ['value' => 'idea', 'label' => ['en' => 'Idea / Market Research', 'ar' => 'مرحلة الفكرة ودراسة الجدوى']],
                    ['value' => 'mvp', 'label' => ['en' => 'Building / Launching MVP', 'ar' => 'منتج أولي قيد الإطلاق (MVP)']],
                    ['value' => 'revenue', 'label' => ['en' => 'Live Product with Revenue', 'ar' => 'مشروع عامل ولديه مبيعات وعملاء']],
                    ['value' => 'scaling', 'label' => ['en' => 'Scaling & Fundraising', 'ar' => 'مرحلة التوسع وجذب الاستثمار']],
                ],
                'is_required' => true,
                'is_active' => true,
                'order' => 3,
            ],
            // 4. Checkbox (Multi-select)
            [
                'key' => 'discussion_topics',
                'type' => FieldType::Checkbox,
                'label' => ['en' => 'Topics You Want to Discuss with Peers', 'ar' => 'المواضيع التي تود مناقشتها مع زملائك المؤسسين'],
                'options' => [
                    ['value' => 'fundraising', 'label' => ['en' => 'Fundraising & Investor Relations', 'ar' => 'استراتيجيات جمع التمويل والمستثمرين']],
                    ['value' => 'hiring', 'label' => ['en' => 'Hiring & Team Building', 'ar' => 'استقطاب الكفاءات وبناء الفريق']],
                    ['value' => 'growth', 'label' => ['en' => 'Growth Marketing & B2B Sales', 'ar' => 'التسويق ونمو المبيعات B2B']],
                    ['value' => 'product', 'label' => ['en' => 'Product Development & UX', 'ar' => 'تطوير المنتج وتجربة المستخدم']],
                ],
                'validation' => ['min' => 1, 'max' => 4],
                'is_required' => true,
                'is_active' => true,
                'order' => 4,
            ],
            // 5. Number
            [
                'key' => 'team_size',
                'type' => FieldType::Number,
                'label' => ['en' => 'Number of Full-Time Team Members', 'ar' => 'عدد أعضاء الفريق بدوام كامل'],
                'placeholder' => ['en' => 'e.g. 5', 'ar' => 'مثلاً: 5'],
                'validation' => ['min' => 1, 'max' => 500],
                'is_required' => true,
                'is_active' => true,
                'order' => 5,
            ],
            // 6. Date
            [
                'key' => 'founding_date',
                'type' => FieldType::Date,
                'label' => ['en' => 'Project Launch / Founding Date', 'ar' => 'تاريخ انطلاق العمل بالمشروع'],
                'placeholder' => ['en' => 'Select date', 'ar' => 'اختر التاريخ'],
                'validation' => ['min_date' => '2015-01-01', 'max_date' => '2026-12-31'],
                'is_required' => false,
                'is_active' => true,
                'order' => 6,
            ],
            // 7. File
            [
                'key' => 'pitch_deck',
                'type' => FieldType::File,
                'label' => ['en' => 'Company Profile or Pitch Deck (PDF)', 'ar' => 'ملف العرض التقديمي أو بروفايل الشركة (PDF)'],
                'validation' => ['accept' => 'pdf', 'max_kb' => 10240],
                'is_required' => false,
                'is_active' => true,
                'order' => 7,
            ],
            // 8. Rating
            [
                'key' => 'investment_readiness',
                'type' => FieldType::Rating,
                'label' => ['en' => 'Self-Assessment: Investment Readiness (1 to 5)', 'ar' => 'تقييمك الذاتي لمدى جاهزية شركتك لتلقي استثمار (من 1 إلى 5)'],
                'validation' => ['min' => 1, 'max' => 5],
                'is_required' => true,
                'is_active' => true,
                'order' => 8,
            ],
        ];

        $this->syncFields($event, $fields);
    }

    private function seedResumeClinic(): void
    {
        $event = $this->findEvent('Resume Clinic');
        if (! $event) {
            return;
        }

        $fields = [
            [
                'key' => 'target_role',
                'type' => FieldType::Text,
                'label' => ['en' => 'Target Role or Industry', 'ar' => 'المسمى أو المجال الوظيفي المستهدف'],
                'placeholder' => ['en' => 'e.g. Frontend Developer, Marketing Lead...', 'ar' => 'مثال: مطور واجهات أمامية، مسؤول تسويق...'],
                'is_required' => true,
                'is_active' => true,
                'order' => 1,
            ],
            [
                'key' => 'cv_file',
                'type' => FieldType::File,
                'label' => ['en' => 'Current CV File (PDF)', 'ar' => 'ملف السيرة الذاتية الحالية (PDF)'],
                'validation' => ['accept' => 'pdf', 'max_kb' => 5120],
                'is_required' => false,
                'is_active' => true,
                'order' => 2,
            ],
            [
                'key' => 'main_challenge',
                'type' => FieldType::Textarea,
                'label' => ['en' => 'What is your main question or struggle?', 'ar' => 'ما هو استفسارك أو التحدي الأساسي في سيرتك الذاتية؟'],
                'placeholder' => ['en' => 'Tell us what you want to improve...', 'ar' => 'أخبرنا عما تود تحسينه في جلسة المراجعة...'],
                'is_required' => false,
                'is_active' => true,
                'order' => 3,
            ],
        ];

        $this->syncFields($event, $fields);
    }

    private function seedIndustryMixer(): void
    {
        $event = $this->findEvent('Industry Mixer at the Chamber');
        if (! $event) {
            return;
        }

        $fields = [
            [
                'key' => 'organization_name',
                'type' => FieldType::Text,
                'label' => ['en' => 'Company or Organization Name', 'ar' => 'اسم المؤسسة أو الشركة'],
                'placeholder' => ['en' => 'Your organization name...', 'ar' => 'اسم جهة عملك...'],
                'is_required' => true,
                'is_active' => true,
                'order' => 1,
            ],
            [
                'key' => 'job_title',
                'type' => FieldType::Text,
                'label' => ['en' => 'Job Title / Position', 'ar' => 'المسمى الوظيفي / المنصب'],
                'placeholder' => ['en' => 'Your position...', 'ar' => 'منصبك الوظيفي...'],
                'is_required' => true,
                'is_active' => true,
                'order' => 2,
            ],
            [
                'key' => 'partnership_interests',
                'type' => FieldType::Checkbox,
                'label' => ['en' => 'Areas of Interest for Collaboration', 'ar' => 'مجالات الاهتمام والشراكة المرجوة'],
                'options' => [
                    ['value' => 'investment', 'label' => ['en' => 'Investment & Capital', 'ar' => 'استثمار وتمويل']],
                    ['value' => 'b2b_sales', 'label' => ['en' => 'B2B Sales & Commercial', 'ar' => 'خدمات وعقود B2B']],
                    ['value' => 'recruitment', 'label' => ['en' => 'Talent & Recruitment', 'ar' => 'توظيف واستقطاب كفاءات']],
                    ['value' => 'supply_chain', 'label' => ['en' => 'Supply Chain & Logistics', 'ar' => 'سلاسل إمداد وتوريد']],
                ],
                'is_required' => true,
                'is_active' => true,
                'order' => 3,
            ],
        ];

        $this->syncFields($event, $fields);
    }

    private function seedOpenMicNight(): void
    {
        $event = $this->findEvent('Open Mic Night');
        if (! $event) {
            return;
        }

        $fields = [
            [
                'key' => 'act_type',
                'type' => FieldType::Select,
                'label' => ['en' => 'Type of Performance', 'ar' => 'نوع المشاركة على المسرح'],
                'options' => [
                    ['value' => 'poetry', 'label' => ['en' => 'Poetry or Prose', 'ar' => 'شعر أو خواطر أدبية']],
                    ['value' => 'demo', 'label' => ['en' => 'Project / App Demo', 'ar' => 'عرض مشروع تجريبي أو تطبيق']],
                    ['value' => 'rant', 'label' => ['en' => 'Tech Rant or Short Talk', 'ar' => 'نقاش تقني أو فكرة سريعة']],
                    ['value' => 'comedy', 'label' => ['en' => 'Humor or Story', 'ar' => 'موقف طريف أو قصة ملهمة']],
                    ['value' => 'other', 'label' => ['en' => 'Other Open Act', 'ar' => 'مشاركة حرة أخرى']],
                ],
                'is_required' => true,
                'is_active' => true,
                'order' => 1,
            ],
            [
                'key' => 'act_summary',
                'type' => FieldType::Text,
                'label' => ['en' => 'Brief Summary of Your 5-Minute Act', 'ar' => 'نبذة سريعة عن فقرتك (خلال 5 دقائق)'],
                'placeholder' => ['en' => 'What will you present?', 'ar' => 'عن ماذا ستتحدث على المسرح؟'],
                'is_required' => true,
                'is_active' => true,
                'order' => 2,
            ],
        ];

        $this->syncFields($event, $fields);
    }

    private function seedFundraisingExternalForm(): void
    {
        $event = $this->findEvent('Fundraising in a Hard Market');
        if (! $event) {
            return;
        }

        // Make sure it has an external form URL to demonstrate external registrations
        $event->forceFill([
            'external_form_url' => 'https://forms.aleppo.local/sample-fundraising-application',
            'has_registration_form' => false,
        ])->save();

        // Ensure no questions exist for an externally registered event
        $event->registrationForm?->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function syncFields(Event $event, array $fields): void
    {
        $event->forceFill(['external_form_url' => null])->save();

        $form = $event->formOrCreate(FormPurpose::Registration);
        $activeKeys = collect($fields)->pluck('key')->all();

        $form->fields()->whereNotIn('key', $activeKeys)->delete();

        foreach ($fields as $fieldData) {
            FormField::query()->updateOrCreate(
                [
                    'form_id' => $form->id,
                    'key' => $fieldData['key'],
                ],
                $fieldData
            );
        }
    }
}
