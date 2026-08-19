<?php

namespace App\Filament\Admin\Resources\Courses\Schemas;

use App\Enums\CourseLevel;
use App\Enums\QuizQuestionType;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Support\Money;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Writing a course.
 *
 * The lessons themselves are not here — they live under their module, in the
 * relation manager on the edit page, because a course with forty lessons in one
 * nested repeater is a form nobody can save. What is here is everything that
 * describes the course, plus the final quiz, which is one per course and has
 * nowhere else sensible to live.
 */
class CourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Tabs\Tab::make(__('The course'))
                        ->icon(Heroicon::OutlinedBookOpen)
                        ->columns(2)
                        ->schema(self::details()),

                    Tabs\Tab::make(__('Price and publishing'))
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->columns(2)
                        ->schema(self::commerce()),

                    Tabs\Tab::make(__('Selling points'))
                        ->icon(Heroicon::OutlinedSparkles)
                        ->schema(self::sellingPoints()),

                    Tabs\Tab::make(__('Final quiz'))
                        ->icon(Heroicon::OutlinedQuestionMarkCircle)
                        ->schema(self::quiz()),
                ]),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    private static function details(): array
    {
        return [
            TextInput::make('title')
                ->label(__('Title'))
                ->required()
                ->maxLength(160)
                ->placeholder(__('Brooding day-old chicks without losses'))
                ->columnSpanFull(),

            Select::make('course_category_id')
                ->label(__('Subject'))
                ->required()
                ->searchable()
                ->preload()
                ->options(fn (): array => CourseCategory::query()
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (CourseCategory $category): array => [
                        $category->id => $category->pathName(),
                    ])
                    ->all()),

            Select::make('level')
                ->label(__('Level'))
                ->options(CourseLevel::options())
                ->default(CourseLevel::Beginner->value)
                ->required(),

            Textarea::make('summary')
                ->label(__('One-line summary'))
                ->required()
                ->rows(2)
                ->maxLength(400)
                ->helperText(__('Shown on every course card. Say what somebody will be able to do afterwards.'))
                ->columnSpanFull(),

            RichEditor::make('description')
                ->label(__('Full description'))
                ->required()
                ->columnSpanFull(),

            FileUpload::make('cover_image')
                ->label(__('Cover picture'))
                ->image()
                ->imageEditor()
                ->maxSize(4096)
                ->disk('public')
                ->directory('courses')
                ->visibility('public')
                ->helperText(__('Shown on the card and at the top of the course page.')),

            TextInput::make('promo_video_url')
                ->label(__('Trailer link'))
                ->url()
                ->maxLength(255)
                // Named as a warning, not a suggestion: lesson videos are
                // uploaded to private storage and never given a public URL.
                ->helperText(__('A public trailer hosted elsewhere. Never put lesson material here.')),

            TextInput::make('estimated_minutes')
                ->label(__('Length in minutes'))
                ->numeric()
                ->minValue(1)
                ->helperText(__('Leave empty to add up the lesson lengths instead.')),

            TextInput::make('sort_order')
                ->label(__('Sort order'))
                ->numeric()
                ->default(0)
                ->helperText(__('Lower numbers come first in the catalogue.')),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function commerce(): array
    {
        return [
            Toggle::make('is_free')
                ->label(__('Free course'))
                ->live()
                ->helperText(__('Anybody signed in can enrol, and the price is forced to nothing.'))
                ->columnSpanFull(),

            TextInput::make('price_naira')
                ->label(__('Price'))
                ->prefix(Money::SIGN)
                ->numeric()
                ->minValue(1)
                ->required(fn (Get $get): bool => ! $get('is_free'))
                ->visible(fn (Get $get): bool => ! $get('is_free'))
                ->helperText(__('The whole amount goes to the platform. There is no commission split on a course.'))
                ->afterStateHydrated(fn (TextInput $component, $state, ?Course $record) => $component->state(
                    $record !== null && $record->price_kobo > 0 ? $record->price_kobo / 100 : $state,
                )),

            Select::make('currency')
                ->label(__('Currency'))
                ->options(['NGN' => 'NGN'])
                ->default('NGN')
                ->required()
                ->visible(fn (Get $get): bool => ! $get('is_free')),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function sellingPoints(): array
    {
        return [
            Repeater::make('what_you_will_learn')
                ->label(__('What they will be able to do'))
                ->simple(
                    TextInput::make('point')
                        ->required()
                        ->maxLength(200)
                        ->placeholder(__('Set up a brooder that holds 33°C overnight')),
                )
                ->addActionLabel(__('Add a point'))
                ->defaultItems(0)
                ->helperText(__('Written as things they will do, not topics the course covers.')),

            Repeater::make('requirements')
                ->label(__('What they need first'))
                ->simple(
                    TextInput::make('requirement')
                        ->required()
                        ->maxLength(200)
                        ->placeholder(__('A pen or shed you can heat')),
                )
                ->addActionLabel(__('Add a requirement'))
                ->defaultItems(0),
        ];
    }

    /**
     * The final quiz, and the questions inside it.
     *
     * A HasOne, so the whole tab writes through the relationship: leave it
     * alone and the course simply has no quiz, which is a legitimate course.
     *
     * @return array<int, mixed>
     */
    private static function quiz(): array
    {
        return [
            Group::make()
                ->relationship('quiz')
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->label(__('Quiz title'))
                        ->required()
                        ->maxLength(160)
                        ->default(__('Final assessment'))
                        ->columnSpanFull(),

                    Textarea::make('description')
                        ->label(__('Instructions'))
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),

                    TextInput::make('pass_mark_percent')
                        ->label(__('Pass mark'))
                        ->suffix('%')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->default(70)
                        ->required(),

                    TextInput::make('max_attempts')
                        ->label(__('Attempts allowed'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->helperText(__('Leave empty for unlimited.')),

                    Toggle::make('is_required_for_certificate')
                        ->label(__('Required for the certificate'))
                        ->default(true)
                        ->helperText(__('Off means finishing the lessons is enough.')),

                    Toggle::make('is_active')
                        ->label(__('Active'))
                        ->default(true)
                        ->helperText(__('Off hides the quiz from students. A quiz with no questions is ignored either way.')),

                    Repeater::make('questions')
                        ->label(__('Questions'))
                        ->relationship()
                        ->orderColumn('sort_order')
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['question'] ?? null)
                        ->addActionLabel(__('Add a question'))
                        ->defaultItems(0)
                        ->columnSpanFull()
                        ->schema([
                            Textarea::make('question')
                                ->label(__('Question'))
                                ->required()
                                ->rows(2)
                                ->columnSpanFull(),

                            Select::make('type')
                                ->label(__('Kind'))
                                ->options(QuizQuestionType::options())
                                ->default(QuizQuestionType::SingleChoice->value)
                                ->required()
                                ->live(),

                            Textarea::make('explanation')
                                ->label(__('Why that is the answer'))
                                ->rows(2)
                                ->helperText(__('Shown after the attempt, right or wrong.')),

                            Repeater::make('options')
                                ->label(__('Answers'))
                                ->relationship()
                                ->orderColumn('sort_order')
                                ->reorderable()
                                ->addActionLabel(__('Add an answer'))
                                ->minItems(2)
                                ->defaultItems(2)
                                ->columns(4)
                                ->columnSpanFull()
                                ->schema([
                                    TextInput::make('text')
                                        ->label(__('Answer'))
                                        ->required()
                                        ->maxLength(500)
                                        ->columnSpan(3),

                                    Toggle::make('is_correct')
                                        ->label(__('Correct'))
                                        ->inline(false),
                                ])
                                // Marking is exact — every correct option and
                                // no wrong ones — so a question with nothing
                                // ticked is one nobody can ever get right.
                                ->rules(['array', 'min:2'])
                                ->validationMessages([
                                    'min' => __('A question needs at least two answers.'),
                                ]),
                        ]),
                ]),
        ];
    }
}
