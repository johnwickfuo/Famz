<?php

namespace Database\Seeders;

use App\Enums\CourseLevel;
use App\Enums\LessonType;
use App\Enums\QuizQuestionType;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * An academy with something in it.
 *
 * Deliberately not run by DatabaseSeeder: `php artisan db:seed --class=DemoAcademySeeder`.
 * Nothing here names the client — the company still has no name — and the
 * courses below are written the way the real ones would be: about the work
 * these farms actually do, in the units they actually use.
 *
 * The handouts are generated as real PDFs onto the private disk, so the player,
 * the watermark and the five-minute signed link can all be looked at rather
 * than taken on trust.
 */
class DemoAcademySeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, description: string, icon: string}>
     */
    private const SUBJECTS = [
        [
            'name' => 'Poultry',
            'description' => 'Brooding, feeding, disease and the arithmetic of a laying flock.',
            'icon' => 'heroicon-o-sparkles',
        ],
        [
            'name' => 'Feed and nutrition',
            'description' => 'Mixing your own, reading a label, and what a bag is really worth.',
            'icon' => 'heroicon-o-beaker',
        ],
        [
            'name' => 'Farm business',
            'description' => 'Records, pricing, and knowing whether the farm made money.',
            'icon' => 'heroicon-o-calculator',
        ],
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    private const COURSES = [
        [
            'subject' => 'Poultry',
            'title' => 'Brooding day-old chicks without losses',
            'summary' => 'Get the first fourteen days right and the rest of the batch takes care of itself.',
            'level' => CourseLevel::Beginner,
            'price' => 750_000,
            'minutes' => 95,
            'learn' => [
                'Set up a brooder that holds 33°C through the night',
                'Read chick behaviour instead of guessing at the thermometer',
                'Space feeders and drinkers so the small ones still eat',
                'Spot and act on the first day of a problem, not the third',
            ],
            'requirements' => [
                'A pen or shed you can heat and keep draught-free',
                'A thermometer — any kind',
            ],
            'modules' => [
                [
                    'title' => 'Before the chicks arrive',
                    'summary' => 'Everything that has to be finished the day before.',
                    'lessons' => [
                        ['title' => 'Why the first two weeks decide the batch', 'type' => 'text', 'preview' => true],
                        ['title' => 'Preparing and pre-heating the brooder', 'type' => 'pdf', 'preview' => true],
                        ['title' => 'Feeder and drinker spacing, by flock size', 'type' => 'pdf'],
                    ],
                ],
                [
                    'title' => 'The first fourteen days',
                    'summary' => 'Day by day, and what to look at each morning.',
                    'lessons' => [
                        ['title' => 'Reading chick behaviour', 'type' => 'text'],
                        ['title' => 'Temperature, week by week', 'type' => 'pdf'],
                        ['title' => 'Daily checks and what they tell you', 'type' => 'text'],
                    ],
                ],
            ],
            'quiz' => [
                'title' => 'Final assessment',
                'pass_mark' => 70,
                'questions' => [
                    [
                        'question' => 'What temperature should the brooder hold on day one?',
                        'explanation' => 'Day-old chicks cannot regulate their own heat for about a week.',
                        'options' => [['33°C', true], ['21°C', false], ['40°C', false]],
                    ],
                    [
                        'question' => 'Chicks crowded together directly under the heat source are telling you what?',
                        'explanation' => 'They huddle towards heat when they are cold and spread away when too hot.',
                        'options' => [['They are cold', true], ['They are too hot', false], ['They are hungry', false]],
                    ],
                    [
                        'question' => 'Which of these belong in the daily check? Tick all that apply.',
                        'type' => QuizQuestionType::MultipleChoice,
                        'explanation' => 'All three change day to day and all three are early warnings.',
                        'options' => [
                            ['Water intake', true],
                            ['Litter condition', true],
                            ['Chick behaviour at the heat source', true],
                            ['The price of feed', false],
                        ],
                    ],
                ],
            ],
        ],
        [
            'subject' => 'Feed and nutrition',
            'title' => 'Mixing your own poultry feed',
            'summary' => 'What goes in a 100kg batch, and when mixing it yourself is cheaper than buying it.',
            'level' => CourseLevel::Intermediate,
            'price' => 1_200_000,
            'minutes' => 140,
            'learn' => [
                'Work a starter, grower and finisher ration from what is in the market',
                'Cost a batch against the bagged price before you mix anything',
                'Store maize and soya so a month of rain does not spoil it',
            ],
            'requirements' => ['A weighing scale', 'Somewhere dry to store raw materials'],
            'modules' => [
                [
                    'title' => 'What a ration is made of',
                    'summary' => 'Energy, protein and the premix that ties them together.',
                    'lessons' => [
                        ['title' => 'The four things every ration needs', 'type' => 'text', 'preview' => true],
                        ['title' => 'Worked rations for 100kg batches', 'type' => 'pdf'],
                    ],
                ],
                [
                    'title' => 'Costing and storage',
                    'summary' => 'The sums that decide whether it is worth doing.',
                    'lessons' => [
                        ['title' => 'Costing a batch against the bagged price', 'type' => 'pdf'],
                        ['title' => 'Storing raw materials through the rains', 'type' => 'text'],
                    ],
                ],
            ],
            'quiz' => [
                'title' => 'Final assessment',
                'pass_mark' => 75,
                'questions' => [
                    [
                        'question' => 'Mixing your own feed is worth doing when what is true?',
                        'explanation' => 'It is an arithmetic question, not a matter of principle.',
                        'options' => [
                            ['The costed batch comes in under the bagged price', true],
                            ['Maize is in season', false],
                            ['The flock is over 500 birds', false],
                        ],
                    ],
                    [
                        'question' => 'Premix is left out of a batch. What happens?',
                        'explanation' => 'The vitamins and minerals are the part birds cannot make themselves.',
                        'options' => [
                            ['Deficiencies show up within weeks', true],
                            ['Nothing, it is optional', false],
                            ['The feed spoils faster', false],
                        ],
                    ],
                ],
            ],
        ],
        [
            'subject' => 'Farm business',
            'title' => 'Keeping records that tell you the truth',
            'summary' => 'Three books, kept daily, that show whether the farm actually made money.',
            'level' => CourseLevel::Beginner,
            'price' => 0,
            'free' => true,
            'minutes' => 45,
            'learn' => [
                'Keep a daily book that takes five minutes',
                'Work out the real cost of a crate of eggs',
                'See a loss forming while there is still time to stop it',
            ],
            'requirements' => ['A notebook and a pen'],
            'modules' => [
                [
                    'title' => 'The three books',
                    'summary' => 'Feed, sales and mortality — nothing else, to begin with.',
                    'lessons' => [
                        ['title' => 'Why three books and not one', 'type' => 'text', 'preview' => true],
                        ['title' => 'Daily record sheets to print', 'type' => 'pdf', 'preview' => true],
                        ['title' => 'Working out what a crate really cost you', 'type' => 'text'],
                    ],
                ],
            ],
            'quiz' => null,
        ],
    ];

    public function run(): void
    {
        $subjects = collect(self::SUBJECTS)->mapWithKeys(function (array $definition, int $index): array {
            $subject = CourseCategory::query()->firstOrCreate(
                ['slug' => Str::slug($definition['name'])],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'icon' => $definition['icon'],
                    'sort_order' => $index,
                    'is_active' => true,
                ],
            );

            return [$definition['name'] => $subject];
        });

        foreach (self::COURSES as $index => $definition) {
            $this->createCourse($definition, $subjects[$definition['subject']], $index);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function createCourse(array $definition, CourseCategory $subject, int $sort): void
    {
        $slug = Str::slug($definition['title']);

        if (Course::query()->where('slug', $slug)->exists()) {
            return;
        }

        $course = new Course([
            'course_category_id' => $subject->getKey(),
            'title' => $definition['title'],
            'summary' => $definition['summary'],
            'description' => $this->description($definition),
            'price_kobo' => $definition['price'],
            'is_free' => $definition['free'] ?? false,
            'level' => $definition['level'],
            'estimated_minutes' => $definition['minutes'],
            'what_you_will_learn' => $definition['learn'],
            'requirements' => $definition['requirements'],
            'sort_order' => $sort,
        ]);

        $course->slug = $slug;
        $course->save();
        $course->publish();

        foreach ($definition['modules'] as $moduleIndex => $moduleDefinition) {
            $module = CourseModule::query()->create([
                'course_id' => $course->getKey(),
                'title' => $moduleDefinition['title'],
                'summary' => $moduleDefinition['summary'],
                'sort_order' => $moduleIndex,
            ]);

            foreach ($moduleDefinition['lessons'] as $lessonIndex => $lesson) {
                $this->createLesson($course, $module, $lesson, $lessonIndex);
            }
        }

        if ($definition['quiz'] !== null) {
            $this->createQuiz($course, $definition['quiz']);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function createLesson(Course $course, CourseModule $module, array $definition, int $sort): void
    {
        $type = LessonType::from($definition['type']);

        $lesson = new CourseLesson([
            'course_module_id' => $module->getKey(),
            'title' => $definition['title'],
            'type' => $type,
            'is_preview' => $definition['preview'] ?? false,
            'sort_order' => $sort,
            'duration_seconds' => random_int(240, 900),
        ]);

        if ($type === LessonType::Text) {
            $lesson->content = $this->reading($definition['title']);
        }

        if ($type === LessonType::Pdf) {
            // A real PDF, written to the private disk exactly where an upload
            // would land — so the watermark, the signed link and the reader can
            // all be exercised rather than described.
            $lesson->file_path = $this->handout($course, $definition['title']);
            $lesson->file_name = Str::slug($definition['title']).'.pdf';
        }

        $lesson->save();
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function createQuiz(Course $course, array $definition): void
    {
        $quiz = Quiz::query()->create([
            'course_id' => $course->getKey(),
            'title' => $definition['title'],
            'description' => __('Pass this and your certificate is issued straight away.'),
            'pass_mark_percent' => $definition['pass_mark'],
            'max_attempts' => 3,
            'is_required_for_certificate' => true,
            'is_active' => true,
        ]);

        foreach ($definition['questions'] as $index => $questionDefinition) {
            $question = QuizQuestion::query()->create([
                'quiz_id' => $quiz->getKey(),
                'question' => $questionDefinition['question'],
                'type' => $questionDefinition['type'] ?? QuizQuestionType::SingleChoice,
                'explanation' => $questionDefinition['explanation'],
                'sort_order' => $index,
            ]);

            foreach ($questionDefinition['options'] as $optionIndex => [$text, $isCorrect]) {
                QuizOption::query()->create([
                    'quiz_question_id' => $question->getKey(),
                    'text' => $text,
                    'is_correct' => $isCorrect,
                    'sort_order' => $optionIndex,
                ]);
            }
        }
    }

    /**
     * Write a handout onto the private course-content disk.
     */
    private function handout(Course $course, string $title): string
    {
        $path = 'lessons/'.Str::ulid().'.pdf';

        $pdf = Pdf::loadView('academy.demo-handout', [
            'courseTitle' => $course->title,
            'lessonTitle' => $title,
            'sections' => $this->handoutSections($title),
        ])->setPaper('a4');

        Storage::disk('course-content')->put($path, $pdf->output());

        return $path;
    }

    /**
     * @return array<int, array{heading: string, body: string}>
     */
    private function handoutSections(string $title): array
    {
        return [
            [
                'heading' => __('What this covers'),
                'body' => __(':title — the practical version, with the numbers you need on the day.', ['title' => $title]),
            ],
            [
                'heading' => __('Before you start'),
                'body' => __('Read this the evening before rather than on the morning. Most of what goes wrong is decided by preparation, and preparation takes a day.'),
            ],
            [
                'heading' => __('On the day'),
                'body' => __('Work through it in order. Where a figure is given, it is a figure to measure against rather than to remember: check it, write down what you actually saw, and act on the difference.'),
            ],
            [
                'heading' => __('If something is wrong'),
                'body' => __('Act on the first day, not the third. Nearly everything on a small farm is cheap to fix early and expensive to fix late.'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function description(array $definition): string
    {
        // Deliberately not the summary again and not the learning points
        // again: both are already on the page, and a description that repeats
        // them reads as filler.
        return '<p>'.e(__('Written for farms working at the scale most people here are actually working at, in the units and at the prices of this market rather than somebody else\'s.')).'</p>'
            .'<p>'.e(__('Every lesson is short enough to read between jobs, and the handouts are made to be worked from rather than filed away. Nothing here needs equipment you do not already have.')).'</p>'
            .'<p>'.e(__('Buy it once and it is yours: there is no monthly fee, no expiry, and you can come back to it whenever the season comes round again.')).'</p>';
    }

    private function reading(string $title): string
    {
        return '<h2>'.e($title).'</h2>'
            .'<p>'.e(__('This lesson is the short version of something that takes a season to learn the hard way. Read it once now and again the week you need it.')).'</p>'
            .'<p>'.e(__('Where a number appears, it is there to be measured against rather than memorised. Write down what you actually see, compare, and act on the difference.')).'</p>'
            .'<p>'.e(__('Nothing here needs equipment you do not already have.')).'</p>';
    }
}
