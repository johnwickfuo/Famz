<?php

use App\Enums\CourseStatus;
use App\Enums\LessonType;
use App\Enums\RoleName;
use App\Filament\Admin\Resources\Certificates\Pages\ListCertificates;
use App\Filament\Admin\Resources\CourseCategories\Pages\ListCourseCategories;
use App\Filament\Admin\Resources\Courses\Pages\CreateCourse;
use App\Filament\Admin\Resources\Courses\Pages\EditCourse;
use App\Filament\Admin\Resources\Courses\Pages\ListCourses;
use App\Filament\Admin\Resources\Courses\RelationManagers\EnrolmentsRelationManager;
use App\Filament\Admin\Resources\Courses\RelationManagers\ModulesRelationManager;
use App\Filament\Admin\Resources\Enrolments\Pages\ListEnrolments;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\User;
use App\Services\Academy\CertificateIssuer;
use App\Services\Academy\EnrolmentService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

/**
 * The administrator's side of the academy.
 *
 * The upload path is the part that earns its test: lesson files go to a private
 * disk with no `url` key at all, so anything that quietly asked for a URL would
 * throw. Rendering the edit form with a file already attached is exactly when
 * that would happen, and it is the one thing here that could break the "no
 * downloads, ever" rule by accident rather than on purpose.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);
    $this->actingAs($this->admin);

    $this->subject = CourseCategory::factory()->create(['name' => 'Poultry']);
});

it('lists course subjects and courses', function () {
    $course = Course::factory()->published()->create(['course_category_id' => $this->subject->id]);

    livewire(ListCourseCategories::class)->assertCanSeeTableRecords([$this->subject])->assertOk();
    livewire(ListCourses::class)->assertCanSeeTableRecords([$course])->assertOk();
    livewire(ListEnrolments::class)->assertOk();
    livewire(ListCertificates::class)->assertOk();
});

it('writes a course as a draft, with the price kept in kobo', function () {
    livewire(CreateCourse::class)
        ->fillForm([
            'title' => 'Brooding without losses',
            'course_category_id' => $this->subject->id,
            'summary' => 'Keep every chick you buy.',
            'description' => '<p>Four weeks of brooding, step by step.</p>',
            'level' => 'beginner',
            'is_free' => false,
            'price_naira' => 7500,
            'currency' => 'NGN',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $course = Course::query()->firstOrFail();

    expect($course->price_kobo)->toBe(750_000)
        // Nothing is published by accident. Publishing is its own act.
        ->and($course->status)->toBe(CourseStatus::Draft)
        ->and($course->slug)->toBe('brooding-without-losses');
});

it('forces a free course to cost nothing whatever was typed', function () {
    livewire(CreateCourse::class)
        ->fillForm([
            'title' => 'Reading a feed label',
            'course_category_id' => $this->subject->id,
            'summary' => 'What the numbers on the bag mean.',
            'description' => '<p>Short and free.</p>',
            'level' => 'beginner',
            'is_free' => true,
            'price_naira' => 5000,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Course::query()->firstOrFail()->price_kobo)->toBe(0);
});

it('refuses to publish a course with no lessons in it', function () {
    $course = Course::factory()->create(['course_category_id' => $this->subject->id]);

    livewire(ListCourses::class)
        ->callAction(TestAction::make('publish')->table($course));

    expect($course->fresh()->status)->toBe(CourseStatus::Draft);
});

it('publishes a course once it has something to read', function () {
    $course = Course::factory()->create(['course_category_id' => $this->subject->id]);
    $module = CourseModule::factory()->for($course)->create();
    CourseLesson::factory()->create(['course_module_id' => $module->id]);

    livewire(ListCourses::class)
        ->callAction(TestAction::make('publish')->table($course));

    $course->refresh();

    expect($course->status)->toBe(CourseStatus::Published)
        ->and($course->published_at)->not->toBeNull();
});

it('will not delete a course people have already bought', function () {
    $course = Course::factory()->published()->create(['course_category_id' => $this->subject->id]);
    $module = CourseModule::factory()->for($course)->create();
    CourseLesson::factory()->create(['course_module_id' => $module->id]);

    app(EnrolmentService::class)->enrol(User::factory()->create(), $course);

    livewire(ListCourses::class)
        ->callAction(TestAction::make('delete')->table($course));

    // Lifetime access was the promise; archiving is the way out, not deleting.
    expect(Course::query()->whereKey($course->getKey())->exists())->toBeTrue();
});

it('will not delete a subject that still has courses filed under it', function () {
    Course::factory()->create(['course_category_id' => $this->subject->id]);

    livewire(ListCourseCategories::class)
        ->callAction(TestAction::make('delete')->table($this->subject));

    expect(CourseCategory::query()->whereKey($this->subject->getKey())->exists())->toBeTrue();
});

/*
 * Uploads. The reason this file exists.
 */
it('puts an uploaded handout on the private disk and nowhere else', function () {
    Storage::fake('course-content');

    $course = Course::factory()->create(['course_category_id' => $this->subject->id]);
    $module = CourseModule::factory()->for($course)->create(['title' => 'Week one']);

    livewire(ModulesRelationManager::class, [
        'ownerRecord' => $course,
        'pageClass' => EditCourse::class,
    ])
        ->mountAction(TestAction::make('edit')->table($module))
        ->fillForm([
            'title' => 'Week one',
            'sort_order' => 0,
            'lessons' => [
                [
                    'title' => 'Brooder setup checklist',
                    'type' => LessonType::Pdf->value,
                    // Real bytes, not a fake of a given size: Laravel's sized fake
                    // reports a length it does not actually contain, and the
                    // mime type and size are read off the stored file.
                    'file_path' => [UploadedFile::fake()->createWithContent(
                        'checklist.pdf',
                        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>",
                    )],
                    'is_preview' => false,
                    'sort_order' => 1,
                ],
            ],
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $lesson = CourseLesson::query()->firstOrFail();

    expect($lesson->file_path)->not->toBeNull()
        ->and(Storage::disk('course-content')->exists($lesson->file_path))->toBeTrue()
        // The name the administrator recognises, kept for the Content-Disposition.
        ->and($lesson->file_name)->toBe('checklist.pdf')
        ->and($lesson->file_size)->toBeGreaterThan(0)
        // Read off the file itself, so it cannot disagree with what is served.
        ->and($lesson->mime_type)->toBe('application/pdf');

    // And nothing about it reached anywhere the web server can serve.
    expect(file_exists(public_path($lesson->file_path)))->toBeFalse();
});

it('renders the lesson form for a file already uploaded without asking the disk for a URL', function () {
    Storage::fake('course-content');
    Storage::disk('course-content')->put('lessons/existing.pdf', '%PDF-1.4 fake');

    $course = Course::factory()->create(['course_category_id' => $this->subject->id]);
    $module = CourseModule::factory()->for($course)->create();

    CourseLesson::factory()->create([
        'course_module_id' => $module->id,
        'type' => LessonType::Pdf,
        'file_path' => 'lessons/existing.pdf',
        'file_name' => 'existing.pdf',
    ]);

    // The private disk has no `url` key and throws on Storage::url(). If
    // Filament's own file preview were left on, opening this form would be a
    // 500 — and switching it on to "fix" that would hand out a public path.
    livewire(ModulesRelationManager::class, [
        'ownerRecord' => $course,
        'pageClass' => EditCourse::class,
    ])->mountAction(TestAction::make('edit')->table($module))
        ->assertHasNoErrors()
        ->assertOk();
});

it('clears up the old file when a lesson file is replaced', function () {
    Storage::fake('course-content');
    Storage::disk('course-content')->put('lessons/old.pdf', '%PDF-1.4 old');

    $lesson = CourseLesson::factory()->create([
        'course_module_id' => CourseModule::factory()->create()->id,
        'type' => LessonType::Pdf,
        'file_path' => 'lessons/old.pdf',
        'file_name' => 'old.pdf',
    ]);

    Storage::disk('course-content')->put('lessons/new.pdf', '%PDF-1.4 new');

    $lesson->forceFill(['file_path' => 'lessons/new.pdf', 'file_name' => null])->save();

    expect(Storage::disk('course-content')->exists('lessons/old.pdf'))->toBeFalse()
        ->and(Storage::disk('course-content')->exists('lessons/new.pdf'))->toBeTrue()
        ->and($lesson->fresh()->file_name)->toBe('new.pdf');
});

it('takes the files with it when a module is deleted', function () {
    Storage::fake('course-content');
    Storage::disk('course-content')->put('lessons/doomed.pdf', '%PDF-1.4');

    $module = CourseModule::factory()->create();

    CourseLesson::factory()->create([
        'course_module_id' => $module->id,
        'type' => LessonType::Pdf,
        'file_path' => 'lessons/doomed.pdf',
    ]);

    $module->delete();

    // A database cascade would have taken the rows and left the bytes.
    expect(Storage::disk('course-content')->exists('lessons/doomed.pdf'))->toBeFalse()
        ->and(CourseLesson::query()->count())->toBe(0);
});

/*
 * The quiz builder, which writes through two nested relationships at once.
 */
it('builds a quiz with its questions and answers from the course form', function () {
    $course = Course::factory()->create(['course_category_id' => $this->subject->id]);

    livewire(EditCourse::class, ['record' => $course->getRouteKey()])
        ->fillForm([
            'quiz' => [
                'title' => 'Final assessment',
                'pass_mark_percent' => 80,
                'max_attempts' => 3,
                'is_required_for_certificate' => true,
                'is_active' => true,
                'questions' => [
                    [
                        'question' => 'What temperature should a brooder hold on day one?',
                        'type' => 'single_choice',
                        'explanation' => 'Day-old chicks cannot regulate their own heat.',
                        'options' => [
                            ['text' => '33°C', 'is_correct' => true],
                            ['text' => '21°C', 'is_correct' => false],
                        ],
                    ],
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $quiz = $course->fresh()->quiz;

    expect($quiz)->not->toBeNull()
        ->and($quiz->pass_mark_percent)->toBe(80)
        ->and($quiz->max_attempts)->toBe(3)
        ->and($quiz->questions()->count())->toBe(1);

    $question = $quiz->questions()->with('options')->first();

    expect($question->options)->toHaveCount(2)
        ->and($question->options->where('is_correct', true))->toHaveCount(1);
});

it('shows who is on a course and how far they got', function () {
    $course = Course::factory()->published()->create(['course_category_id' => $this->subject->id]);
    $module = CourseModule::factory()->for($course)->create();
    CourseLesson::factory()->count(2)->create(['course_module_id' => $module->id]);

    $student = User::factory()->create(['name' => 'Musa Danjuma']);
    $enrolment = app(EnrolmentService::class)->enrol($student, $course, null, 500_000);

    livewire(EnrolmentsRelationManager::class, [
        'ownerRecord' => $course,
        'pageClass' => EditCourse::class,
    ])
        ->assertCanSeeTableRecords([$enrolment])
        ->assertSee('Musa Danjuma');
});

it('withdraws a certificate with a reason, and the public page says so', function () {
    $course = Course::factory()->published()->create(['course_category_id' => $this->subject->id]);
    $module = CourseModule::factory()->for($course)->create();
    CourseLesson::factory()->create(['course_module_id' => $module->id]);

    $student = User::factory()->create();
    $enrolment = app(EnrolmentService::class)->enrol($student, $course);

    foreach ($course->lessons()->get() as $lesson) {
        app(EnrolmentService::class)->setCompleted($enrolment->fresh(), $lesson);
    }

    $certificate = app(CertificateIssuer::class)->issue($enrolment->fresh());

    livewire(ListCertificates::class)->callAction(
        TestAction::make('revoke')->table($certificate),
        ['reason' => 'Awarded against the wrong enrolment.'],
    );

    $certificate->refresh();

    expect($certificate->isValid())->toBeFalse();

    $this->get(route('certificates.verify', $certificate->verification_code))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('certificate.is_valid', false)
            ->where('certificate.revoked_reason', 'Awarded against the wrong enrolment.'));

    expect(Certificate::query()->count())->toBe(1);
});
