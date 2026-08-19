<?php

use App\Documents\CompletionCertificate;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\Academy\CertificateIssuer;
use App\Services\Academy\EnrolmentService;
use App\Services\Academy\QuizGrader;
use App\Services\Branding\BrandingKey;
use App\Services\Branding\BrandingService;
use App\Services\Settings\SettingsService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Mail;

/**
 * Finishing a course, and what that earns.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->settings = app(SettingsService::class);
    $this->enrolments = app(EnrolmentService::class);
    $this->grader = app(QuizGrader::class);
    $this->issuer = app(CertificateIssuer::class);

    $this->student = User::factory()->create(['name' => 'Chinonso Eze']);
    $this->course = Course::factory()->published()->create(['title' => 'Brooder management']);
    $this->module = CourseModule::factory()->for($this->course)->create();

    $this->lessons = collect(range(1, 4))->map(fn (int $i): CourseLesson => CourseLesson::factory()->create([
        'course_module_id' => $this->module->id,
        'sort_order' => $i,
        'title' => 'Lesson '.$i,
    ]));

    $this->enrolment = $this->enrolments->enrol($this->student, $this->course->fresh());

    $this->finishAll = function (): void {
        foreach ($this->lessons as $lesson) {
            $this->enrolments->setCompleted($this->enrolment->fresh(), $lesson);
        }
    };

    $this->makeQuiz = function (int $questions = 4, int $passMark = 70): Quiz {
        $quiz = Quiz::factory()->for($this->course)->create(['pass_mark_percent' => $passMark]);

        QuizQuestion::factory()->count($questions)->withOptions()->create(['quiz_id' => $quiz->id]);

        return $quiz->fresh();
    };

    $this->answers = fn (Quiz $quiz, int $rightCount): array => $quiz->questions()->with('options')->get()
        ->values()
        ->mapWithKeys(function (QuizQuestion $question, int $index) use ($rightCount): array {
            $option = $index < $rightCount
                ? $question->options->firstWhere('is_correct', true)
                : $question->options->firstWhere('is_correct', false);

            return [$question->id => [$option->id]];
        })->all();
});

// ---------------------------------------------------------------------------
// Progress
// ---------------------------------------------------------------------------

it('works progress out from the lessons that exist now', function () {
    expect($this->enrolment->progressPercent())->toBe(0);

    $this->enrolments->setCompleted($this->enrolment, $this->lessons[0]);
    expect($this->enrolment->fresh()->progressPercent())->toBe(25);

    $this->enrolments->setCompleted($this->enrolment->fresh(), $this->lessons[1]);
    expect($this->enrolment->fresh()->progressPercent())->toBe(50);

    ($this->finishAll)();
    expect($this->enrolment->fresh()->progressPercent())->toBe(100)
        ->and($this->enrolment->fresh()->completed_at)->not->toBeNull();
});

it('un-finishes a course when a lesson is added to it', function () {
    ($this->finishAll)();

    expect($this->enrolment->fresh()->completed_at)->not->toBeNull();

    // A stored percentage would go on saying 100% for a course this student
    // has not finished any more. A derived one tells the truth.
    CourseLesson::factory()->create(['course_module_id' => $this->module->id, 'sort_order' => 9]);

    expect($this->enrolment->fresh()->progressPercent())->toBe(80);

    $this->enrolments->syncCompletion($this->enrolment->fresh());

    expect($this->enrolment->fresh()->completed_at)->toBeNull();
});

it('remembers where somebody got to', function () {
    $this->enrolments->touch($this->enrolment, $this->lessons[2], position: 137);

    $enrolment = $this->enrolment->fresh();

    expect($enrolment->last_lesson_id)->toBe($this->lessons[2]->id)
        ->and($this->enrolments->resumeLesson($enrolment)->id)->toBe($this->lessons[2]->id)
        ->and($enrolment->progress()->where('course_lesson_id', $this->lessons[2]->id)->sole()->last_position)
        ->toBe(137);
});

it('drops somebody at the first unfinished lesson when there is nothing to resume', function () {
    $this->enrolments->setCompleted($this->enrolment, $this->lessons[0]);
    $this->enrolments->setCompleted($this->enrolment->fresh(), $this->lessons[1]);

    expect($this->enrolments->resumeLesson($this->enrolment->fresh())->id)->toBe($this->lessons[2]->id);
});

it('refuses to record progress against another course\'s lesson', function () {
    $other = CourseModule::factory()->create();
    $stray = CourseLesson::factory()->create(['course_module_id' => $other->id]);

    expect(fn () => $this->enrolments->setCompleted($this->enrolment, $stray))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// The quiz
// ---------------------------------------------------------------------------

it('marks the quiz on the server from the stored options', function () {
    $quiz = ($this->makeQuiz)(questions: 4, passMark: 70);

    $attempt = $this->grader->grade($this->enrolment, $quiz, ($this->answers)($quiz, 3));

    expect($attempt->correct_count)->toBe(3)
        ->and($attempt->question_count)->toBe(4)
        ->and($attempt->score_percent)->toBe(75)
        ->and($attempt->passed)->toBeTrue();
});

it('fails an attempt below the pass mark', function () {
    $quiz = ($this->makeQuiz)(questions: 4, passMark: 70);

    $attempt = $this->grader->grade($this->enrolment, $quiz, ($this->answers)($quiz, 2));

    expect($attempt->score_percent)->toBe(50)->and($attempt->passed)->toBeFalse();
});

it('gives no marks for ticking everything', function () {
    $quiz = Quiz::factory()->for($this->course)->create(['pass_mark_percent' => 50]);
    $question = QuizQuestion::factory()->withOptions(wrong: 2)->create(['quiz_id' => $quiz->id]);

    $everything = [$question->id => $question->fresh()->options->pluck('id')->all()];

    $attempt = $this->grader->grade($this->enrolment, $quiz->fresh(), $everything);

    // Missing a right answer is as wrong as adding a wrong one; half marks for
    // half an answer would let somebody tick the lot and pass.
    expect($attempt->correct_count)->toBe(0)->and($attempt->passed)->toBeFalse();
});

it('stops somebody once they have used their attempts', function () {
    $quiz = ($this->makeQuiz)(questions: 4, passMark: 90);
    $quiz->forceFill(['max_attempts' => 2])->save();

    $this->grader->grade($this->enrolment, $quiz->fresh(), ($this->answers)($quiz, 1));
    $this->grader->grade($this->enrolment->fresh(), $quiz->fresh(), ($this->answers)($quiz, 2));

    expect(fn () => $this->grader->grade($this->enrolment->fresh(), $quiz->fresh(), ($this->answers)($quiz, 3)))
        ->toThrow(RuntimeException::class);
});

it('will not mark a quiz with no questions', function () {
    $empty = Quiz::factory()->for($this->course)->create();

    // A quiz with nothing in it would score 0 out of 0 and pass everybody,
    // which is how a certificate reaches somebody who answered nothing.
    expect(fn () => $this->grader->grade($this->enrolment, $empty, []))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// The certificate
// ---------------------------------------------------------------------------

it('withholds a certificate until the lessons are finished AND the quiz is passed', function () {
    $quiz = ($this->makeQuiz)();

    expect($this->issuer->isEarned($this->enrolment))->toBeFalse();

    // Lessons done, quiz not.
    ($this->finishAll)();
    expect($this->issuer->isEarned($this->enrolment->fresh()))->toBeFalse();
    expect(fn () => $this->issuer->issue($this->enrolment->fresh()))->toThrow(RuntimeException::class);

    // Quiz passed, but a lesson un-done again.
    $this->grader->grade($this->enrolment->fresh(), $quiz, ($this->answers)($quiz, 4));
    $this->enrolments->setCompleted($this->enrolment->fresh(), $this->lessons[0], false);

    expect($this->issuer->isEarned($this->enrolment->fresh()))->toBeFalse();

    // Both.
    $this->enrolments->setCompleted($this->enrolment->fresh(), $this->lessons[0], true);
    expect($this->issuer->isEarned($this->enrolment->fresh()))->toBeTrue();
});

it('issues on lessons alone when the course has no quiz', function () {
    ($this->finishAll)();

    expect($this->issuer->isEarned($this->enrolment->fresh()))->toBeTrue();

    $certificate = $this->issuer->issue($this->enrolment->fresh());

    expect($certificate->holder_name)->toBe('Chinonso Eze')
        ->and($certificate->course_title)->toBe('Brooder management');
});

it('says what is still outstanding rather than only refusing', function () {
    $quiz = ($this->makeQuiz)(passMark: 80);

    $this->enrolments->setCompleted($this->enrolment, $this->lessons[0]);

    $missing = $this->issuer->outstanding($this->enrolment->fresh());

    expect($missing)->toHaveCount(2)
        ->and($missing[0])->toContain('3 lessons')
        ->and($missing[1])->toContain('80%');
});

it('issues one certificate however many times it is asked for', function () {
    ($this->finishAll)();

    $first = $this->issuer->issue($this->enrolment->fresh());
    $second = $this->issuer->issue($this->enrolment->fresh());

    expect($second->id)->toBe($first->id)
        ->and($second->verification_code)->toBe($first->verification_code)
        ->and(Certificate::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// The company on the certificate
// ---------------------------------------------------------------------------

it('takes the issuing company from settings and never from a literal', function () {
    $this->settings->setMany([
        BrandingKey::Name->value => 'Ilorin Grainstore Limited',
        BrandingKey::RcNumber->value => 'RC 1234567',
        BrandingKey::SignatoryName->value => 'Amina Yusuf',
        BrandingKey::SignatoryTitle->value => 'Head of Training',
    ], BrandingKey::GROUP);

    ($this->finishAll)();

    $certificate = $this->issuer->issue($this->enrolment->fresh());

    expect($certificate->issuer_name)->toBe('Ilorin Grainstore Limited')
        ->and($certificate->issuer_rc_number)->toBe('RC 1234567')
        ->and($certificate->issuer_signature_name)->toBe('Amina Yusuf');

    $rendered = CompletionCertificate::fromRecord($certificate)->render();

    expect($rendered)->toContain('Ilorin Grainstore Limited')
        ->toContain('RC 1234567')
        ->toContain('Amina Yusuf')
        ->toContain('Head of Training');
});

it('reissues an old certificate exactly as the student was given it', function () {
    $this->settings->setMany([
        BrandingKey::Name->value => 'Ilorin Grainstore Limited',
        BrandingKey::RcNumber->value => 'RC 1234567',
    ], BrandingKey::GROUP);

    ($this->finishAll)();
    $certificate = $this->issuer->issue($this->enrolment->fresh());

    // The company renames itself a year later.
    $this->settings->setMany([
        BrandingKey::Name->value => 'Kwara Agro Holdings PLC',
        BrandingKey::RcNumber->value => 'RC 7654321',
    ], BrandingKey::GROUP);

    app(BrandingService::class)->flush();

    expect(app(BrandingService::class)->name())->toBe('Kwara Agro Holdings PLC');

    // The old certificate must still say what it said the day it was awarded.
    $rendered = CompletionCertificate::fromRecord($certificate->fresh())->render();

    expect($rendered)->toContain('Ilorin Grainstore Limited')
        ->toContain('RC 1234567')
        ->not->toContain('Kwara Agro Holdings PLC');
});

it('renders a certificate issued after the rename with the new company', function () {
    $this->settings->setMany([BrandingKey::Name->value => 'Kwara Agro Holdings PLC'], BrandingKey::GROUP);

    $other = User::factory()->create(['name' => 'Bilkisu Sani']);
    $enrolment = $this->enrolments->enrol($other, $this->course->fresh());

    foreach ($this->lessons as $lesson) {
        $this->enrolments->setCompleted($enrolment->fresh(), $lesson);
    }

    $certificate = $this->issuer->issue($enrolment->fresh());

    expect(CompletionCertificate::fromRecord($certificate)->render())
        ->toContain('Kwara Agro Holdings PLC')
        ->toContain('Bilkisu Sani');
});

it('renders the certificate to real PDF bytes', function () {
    ($this->finishAll)();

    $bytes = CompletionCertificate::fromRecord($this->issuer->issue($this->enrolment->fresh()))
        ->output();

    expect($bytes)->toStartWith('%PDF');
});
