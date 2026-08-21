<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Enrolment;
use App\Models\MentorshipPackage;
use App\Models\User;
use App\Services\Academy\CertificateIssuer;
use App\Services\Academy\EnrolmentService;
use App\Services\Mentorship\EngagementService;
use App\Services\Mentorship\MentorshipCheckout;
use App\Services\Payments\PaymentProcessor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * People part-way through a course, and mentorships in progress.
 *
 * DemoAcademySeeder and DemoMentorSeeder put courses and mentors on the shelf.
 * Nobody had bought either, which meant the two screens that matter most —
 * "my courses" and an active engagement — were empty on a platform whose
 * catalogue looked full.
 *
 * Progress is left uneven on purpose. Somebody two lessons in, somebody
 * finished with a certificate, and somebody who has paid and not started. All
 * three are real, and only the middle one demonstrates a certificate.
 */
class DemoLearningSeeder extends Seeder
{
    public function run(): void
    {
        Mail::fake();
        Notification::fake();

        $learners = User::query()
            ->whereIn('email', ['buyer@example.test', 'musa@example.test', 'tunde@example.test'])
            ->get();

        if ($learners->isEmpty()) {
            $this->command?->warn('No demo learners. Run DemoTradeSeeder first.');

            return;
        }

        $this->enrol($learners);
        $this->engageMentors($learners);

        $this->command?->info('Demo enrolments and engagements seeded.');
    }

    private function enrol($learners): void
    {
        $enrolments = app(EnrolmentService::class);
        $courses = Course::query()->with('lessons')->get();

        if ($courses->isEmpty()) {
            $this->command?->warn('No courses. Run DemoAcademySeeder first.');

            return;
        }

        foreach ($courses as $index => $course) {
            foreach ($learners as $position => $learner) {
                try {
                    $enrolment = $enrolments->enrol(
                        user: $learner,
                        course: $course,
                        pricePaidKobo: (int) ($course->price_kobo ?? 0),
                    );

                    $lessons = $course->lessons;

                    if ($lessons->isEmpty()) {
                        continue;
                    }

                    /*
                     * Uneven on purpose: a platform where every learner is on
                     * lesson one shows the player and nothing about progress,
                     * and a platform where everybody has finished shows the
                     * certificate and nothing about the work.
                     */
                    $through = match (($index + $position) % 3) {
                        0 => 0,
                        1 => (int) ceil($lessons->count() / 2),
                        default => $lessons->count(),
                    };

                    if ($through === 0) {
                        continue;
                    }

                    foreach ($lessons->take($through) as $lesson) {
                        $enrolment->progress()->updateOrCreate(
                            ['course_lesson_id' => $lesson->getKey()],
                            [
                                'is_completed' => true,
                                'completed_at' => now()->subDays(random_int(1, 14)),
                            ],
                        );
                    }

                    $finished = $through === $lessons->count();

                    $enrolment->forceFill([
                        'last_lesson_id' => $lessons[$through - 1]->getKey(),
                        'last_seen_at' => now()->subDays(random_int(0, 6)),
                        'completed_at' => $finished ? now()->subDays(2) : null,
                    ])->save();

                    if ($finished) {
                        $this->passTheQuizAndCertify($enrolment->refresh(), $course);
                    }
                } catch (Throwable $exception) {
                    $this->command?->warn('Enrolment skipped: '.$exception->getMessage());
                }
            }
        }
    }

    /**
     * Sit the quiz and take the certificate.
     *
     * The certificate is the thing people sign up for, and it is a branded PDF
     * with somebody's name frozen onto it — so a demonstration with no
     * certificate anywhere cannot show either. The attempt is written rather
     * than simulated because a pass is what the issuer checks for, and there is
     * no other way to reach one without answering questions.
     */
    private function passTheQuizAndCertify(Enrolment $enrolment, Course $course): void
    {
        $quiz = $course->quiz;

        if ($quiz !== null && $quiz->is_required_for_certificate) {
            $questions = max($quiz->questions()->count(), 1);

            $enrolment->quizAttempts()->create([
                'quiz_id' => $quiz->getKey(),
                'score_percent' => 90,
                'correct_count' => (int) round($questions * 0.9),
                'question_count' => $questions,
                'passed' => true,
                'answers' => [],
                'attempted_at' => now()->subDays(2),
            ]);
        }

        $issuer = app(CertificateIssuer::class);

        if ($issuer->isEarned($enrolment->refresh())) {
            $issuer->issue($enrolment);
        }
    }

    private function engageMentors($learners): void
    {
        $engagements = app(EngagementService::class);
        $packages = MentorshipPackage::query()->where('is_active', true)->with('mentor')->take(3)->get();

        if ($packages->isEmpty()) {
            $this->command?->warn('No mentorship packages. Run DemoMentorSeeder first.');

            return;
        }

        foreach ($packages as $index => $package) {
            $client = $learners[$index % $learners->count()];

            try {
                $engagement = $engagements->request(
                    client: $client,
                    package: $package,
                    brief: 'I have 2,000 layers coming into production next month and I have never taken a flock through a peak. I want somebody who has, on the phone once a week.',
                );

                if ($index === 0) {
                    /*
                     * Left waiting on the mentor. Contact details stay hidden
                     * on both sides until they confirm, which is the rule this
                     * module is built around and the one worth showing.
                     */
                    continue;
                }

                /*
                 * The invoice request() already opened, not a second one.
                 * Opening sequence 1 again hits the unique index — which is the
                 * guard doing its job, and a good reminder that the engagement
                 * arrives with its first period already billed.
                 */
                $invoice = app(MentorshipCheckout::class)
                    ->outstanding($engagement->refresh());

                if ($invoice === null) {
                    continue;
                }

                /*
                 * Paid through the payment processor, so the commission split
                 * and the held balance are the real ones. Forging this would
                 * put the demonstration ledger out by the mentor's share.
                 */
                $order = app(MentorshipCheckout::class)->begin($client, $invoice);

                app(PaymentProcessor::class)->markPaid($order, 'paystack', 'DEMO-'.$order->reference);
            } catch (Throwable $exception) {
                $this->command?->warn('Engagement skipped: '.$exception->getMessage());
            }
        }
    }
}
