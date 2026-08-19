<?php

use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\User;
use App\Services\Academy\EnrolmentService;
use App\Services\Academy\LessonAccess;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

/**
 * Getting at course material.
 *
 * This is the file that matters most in the academy. Everything else is a
 * feature; this is the difference between a course somebody paid for and a
 * course anybody can read.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    Storage::fake('course-content');

    $this->enrolments = app(EnrolmentService::class);
    $this->access = app(LessonAccess::class);

    $this->course = Course::factory()->published()->create();
    $this->module = CourseModule::factory()->for($this->course)->create();

    Storage::disk('course-content')->put('handouts/sample.pdf', '%PDF-1.4 pretend');
    Storage::disk('course-content')->put('videos/sample.mp4', 'pretend video bytes');

    $this->pdf = CourseLesson::factory()->pdf()->create(['course_module_id' => $this->module->id]);
    $this->video = CourseLesson::factory()->video()->create(['course_module_id' => $this->module->id]);

    $this->student = User::factory()->create();
    $this->stranger = User::factory()->create();

    $this->enrol = fn (User $user) => $this->enrolments->enrol($user, $this->course->fresh());
});

// ---------------------------------------------------------------------------
// The files themselves
// ---------------------------------------------------------------------------

it('keeps course material out of the public directory entirely', function () {
    $root = config('filesystems.disks.course-content.root');

    // Not under public/, and no `url` key, so Storage::url() on this disk has
    // nothing to hand back even if somebody called it.
    expect(str_starts_with((string) $root, public_path()))->toBeFalse()
        ->and(config('filesystems.disks.course-content.url'))->toBeNull()
        ->and(config('filesystems.disks.course-content.visibility'))->toBe('private');
});

it('never puts a storage path or a direct link into the page', function () {
    ($this->enrol)($this->student);

    $response = $this->actingAs($this->student)
        ->get(route('academy.player.lesson', [$this->course->slug, $this->pdf->id]))
        ->assertOk();

    $props = json_encode($response->viewData('page')['props'], JSON_UNESCAPED_SLASHES);

    // The signed route, and nothing that resembles a path on disk.
    expect($props)
        ->toContain('/academy/content/')
        ->not->toContain('handouts/sample.pdf')
        ->not->toContain('/storage/')
        ->not->toContain('course-content/');
});

// ---------------------------------------------------------------------------
// Who may fetch
// ---------------------------------------------------------------------------

it('refuses a signed link to somebody with no enrolment', function () {
    ($this->enrol)($this->student);

    // A real, unexpired link, minted for the student who paid.
    $url = $this->access->urlFor($this->pdf, $this->student);

    // Forwarded to somebody else, well inside the five minutes.
    $this->actingAs($this->stranger)->get($url)->assertForbidden();

    // And to nobody at all. A 403 rather than a login redirect: signing in
    // as somebody else would not help, so sending them to a form pretends
    // otherwise.
    $this->get($url)->assertForbidden();
});

it('refuses a signed link once the enrolment is gone', function () {
    $enrolment = ($this->enrol)($this->student);
    $url = $this->access->urlFor($this->pdf, $this->student);

    $this->actingAs($this->student)->get($url)->assertOk();

    // Refunded, revoked, however it happened: the signature is still perfectly
    // valid and must no longer be enough.
    $enrolment->forceFill(['enrolled_at' => null])->save();

    $this->actingAs($this->student)->get($url)->assertForbidden();
});

it('rejects a link whose five minutes have run out', function () {
    ($this->enrol)($this->student);

    $url = $this->access->urlFor($this->pdf, $this->student);

    $this->travel(LessonAccess::TTL_MINUTES + 1)->minutes();

    $this->actingAs($this->student)->get($url)->assertForbidden();
});

it('rejects a link somebody has edited', function () {
    ($this->enrol)($this->student);

    $url = $this->access->urlFor($this->pdf, $this->student);

    // Same lesson, signature untouched, expiry pushed out by a year.
    $tampered = preg_replace('/expires=\d+/', 'expires='.now()->addYear()->timestamp, $url);

    $this->actingAs($this->student)->get($tampered)->assertForbidden();
});

it('lets nobody reach a lesson by guessing the route without a signature', function () {
    ($this->enrol)($this->student);

    $this->actingAs($this->student)
        ->get(route('academy.lesson.file', ['lesson' => $this->pdf->id, 'u' => $this->student->id]))
        ->assertForbidden();
});

it('serves the file to the student who paid for it', function () {
    ($this->enrol)($this->student);

    $this->actingAs($this->student)
        ->get($this->access->urlFor($this->pdf, $this->student))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

// ---------------------------------------------------------------------------
// How it is served
// ---------------------------------------------------------------------------

it('serves inline and never as a download', function () {
    ($this->enrol)($this->student);

    $response = $this->actingAs($this->student)
        ->get($this->access->urlFor($this->pdf, $this->student))
        ->assertOk();

    expect($response->headers->get('Content-Disposition'))
        ->toStartWith('inline')
        ->not->toContain('attachment');
});

it('sends headers that discourage keeping a copy', function () {
    ($this->enrol)($this->student);

    $response = $this->actingAs($this->student)
        ->get($this->access->urlFor($this->pdf, $this->student))
        ->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Pragma'))->toBe('no-cache')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('has no download route for course material at all', function () {
    // A route that offered an attachment would undo everything above, so the
    // absence of one is worth asserting rather than assuming.
    $offending = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => (string) $route->getName())
        ->filter(fn (string $name): bool => str_contains($name, 'lesson') && str_contains($name, 'download'));

    expect($offending)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Previews
// ---------------------------------------------------------------------------

it('lets anybody play a preview lesson on a published course', function () {
    $preview = CourseLesson::factory()->pdf()->preview()->create([
        'course_module_id' => $this->module->id,
    ]);

    $this->get($this->access->urlFor($preview))->assertOk();
});

it('does not treat a preview on an unpublished course as public', function () {
    $draft = Course::factory()->create();
    $module = CourseModule::factory()->for($draft)->create();
    $preview = CourseLesson::factory()->pdf()->preview()->create(['course_module_id' => $module->id]);

    // A preview flag on a draft is a leak, not a preview.
    $this->get($this->access->urlFor($preview))->assertForbidden();
});

// ---------------------------------------------------------------------------
// Watermarking
// ---------------------------------------------------------------------------

it('stamps the reader\'s own name onto a handout', function () {
    ($this->enrol)($this->student);

    // A real one-page PDF, so FPDI has something it can actually parse.
    Storage::disk('course-content')->put('handouts/sample.pdf', realPdf());

    $bytes = $this->actingAs($this->student)
        ->get($this->access->urlFor($this->pdf, $this->student))
        ->assertOk()
        ->getContent();

    expect($bytes)->toStartWith('%PDF');

    // The name is in there somewhere; the point is that it is traceable, not
    // that it is at any particular byte offset.
    $text = extractPdfText($bytes);

    expect($text)->toContain($this->student->displayName())
        ->toContain($this->student->email);
});

it('serves a handout it cannot parse rather than withholding it', function () {
    ($this->enrol)($this->student);

    // Not a real PDF. A student who paid should still get their file.
    Storage::disk('course-content')->put('handouts/sample.pdf', 'not really a pdf');

    $this->actingAs($this->student)
        ->get($this->access->urlFor($this->pdf, $this->student))
        ->assertOk();
});

/**
 * A minimal, genuinely parseable one-page PDF.
 */
function realPdf(): string
{
    $fpdf = new Fpdi;
    $fpdf->AddPage();
    $fpdf->SetFont('Helvetica', '', 12);
    $fpdf->Cell(0, 10, 'Brooder management, page one');

    return $fpdf->Output('S');
}

/**
 * Pull the readable text out of a PDF.
 *
 * Content streams are Flate-compressed, so they are inflated first — the
 * alternative would be turning compression off in the watermarker to make a
 * test easier, which is the wrong way round.
 */
function extractPdfText(string $pdf): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

    $text = '';

    foreach ($streams[1] ?? [] as $stream) {
        $inflated = @gzuncompress($stream);
        $body = $inflated === false ? $stream : $inflated;

        preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/', $body, $chunks);

        foreach ($chunks[0] ?? [] as $chunk) {
            $text .= stripcslashes(substr($chunk, 1, -1)).' ';
        }
    }

    return $text;
}
