<?php

namespace App\Services\Academy;

use App\Mail\CertificateIssuedMail;
use App\Models\Certificate;
use App\Models\Enrolment;
use App\Services\Branding\BrandingKey;
use App\Services\Branding\BrandingService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Issuing a certificate.
 *
 * Two conditions, both required, neither inferable from the other: every lesson
 * finished AND the final quiz passed. A course whose quiz is marked not required
 * needs only the lessons.
 *
 * The company's identity is COPIED onto the certificate at issue time rather
 * than looked up when the PDF renders. That is the whole reason this class
 * exists rather than the document reading BrandingService directly: a student
 * downloading their certificate again in two years must get the document they
 * were given, not one bearing whatever the company has since renamed itself.
 * The verification page has to be able to say what it said on the day, too.
 */
class CertificateIssuer
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly BrandingService $branding,
    ) {}

    /**
     * Whether this enrolment has earned one.
     */
    public function isEarned(Enrolment $enrolment): bool
    {
        if (! $enrolment->isActive()) {
            return false;
        }

        if (! $enrolment->hasFinishedEveryLesson()) {
            return false;
        }

        $quiz = $enrolment->course->quiz;

        if ($quiz === null || ! $quiz->is_required_for_certificate || ! $quiz->isUsable()) {
            return true;
        }

        return $enrolment->hasPassedQuiz();
    }

    /**
     * What is still outstanding, so the player can say rather than just refuse.
     *
     * @return array<int, string>
     */
    public function outstanding(Enrolment $enrolment): array
    {
        $missing = [];

        if (! $enrolment->hasFinishedEveryLesson()) {
            $total = $enrolment->course->lessons()->count();
            $done = $enrolment->completedLessonIds()->count();

            $missing[] = trans_choice(
                'One lesson still to finish|:count lessons still to finish',
                max(0, $total - $done),
                ['count' => max(0, $total - $done)],
            );
        }

        $quiz = $enrolment->course->quiz;

        if ($quiz !== null && $quiz->is_required_for_certificate && $quiz->isUsable() && ! $enrolment->hasPassedQuiz()) {
            $missing[] = __('The final quiz, at :mark% or better', ['mark' => $quiz->pass_mark_percent]);
        }

        return $missing;
    }

    /**
     * Issue it, or hand back the one already issued.
     *
     * Idempotent on purpose: a student pressing the button twice, or a job
     * retried after a timeout, must not produce a second certificate with a
     * different verification code on it.
     */
    public function issue(Enrolment $enrolment): Certificate
    {
        $existing = $enrolment->certificate;

        if ($existing !== null) {
            return $existing;
        }

        if (! $this->isEarned($enrolment)) {
            throw new RuntimeException(__('This course is not finished yet.'));
        }

        return DB::transaction(function () use ($enrolment): Certificate {
            $enrolment->loadMissing(['user', 'course']);

            $certificate = new Certificate;

            $certificate->forceFill([
                'enrolment_id' => $enrolment->getKey(),
                'user_id' => $enrolment->user_id,
                'course_id' => $enrolment->course_id,

                // Frozen, all of it. See the class docblock.
                'holder_name' => $enrolment->user->displayName(),
                'course_title' => $enrolment->course->title,
                ...$this->issuerSnapshot(),

                'quiz_score_percent' => $enrolment->bestQuizAttempt()?->score_percent,
                'issued_at' => now(),
            ])->save();

            $enrolment->forceFill([
                'certificate_issued_at' => now(),
                'completed_at' => $enrolment->completed_at ?? now(),
            ])->save();

            Mail::to($enrolment->user->email)->send(new CertificateIssuedMail(
                $enrolment->user,
                $enrolment->course->title,
                $certificate->verification_code,
            ));

            return $certificate->refresh();
        });
    }

    /**
     * The company as it stands right now.
     *
     * Read from settings rather than from BrandingService's payload so the raw
     * stored logo PATH is captured, not a URL: a URL would break the day the
     * app moves domain, and the certificate has to render for as long as the
     * student keeps it.
     *
     * The name is the exception. It comes from BrandingService, which is the
     * only thing in this application allowed to answer "what is the company
     * called" — including when nobody has set it yet.
     *
     * @return array<string, string|null>
     */
    private function issuerSnapshot(): array
    {
        return [
            'issuer_name' => $this->branding->name(),
            'issuer_short_name' => $this->branding->shortName(),
            'issuer_rc_number' => $this->settings->string(BrandingKey::RcNumber->value),
            'issuer_logo_path' => $this->settings->string(BrandingKey::Logo->value),
            'issuer_signature_name' => $this->settings->string(BrandingKey::SignatoryName->value),
            'issuer_signature_title' => $this->settings->string(BrandingKey::SignatoryTitle->value),
        ];
    }
}
