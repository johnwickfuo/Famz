<?php

namespace App\Http\Controllers\Academy;

use App\Enums\LessonType;
use App\Http\Controllers\Controller;
use App\Models\CourseLesson;
use App\Models\User;
use App\Services\Academy\LessonAccess;
use App\Services\Academy\PdfWatermarker;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to a lesson's bytes.
 *
 * Four things have to be true, and all four are checked here rather than any of
 * them being assumed from the last:
 *
 *   1. the signature is ours and has not expired (route middleware),
 *   2. somebody is signed in,
 *   3. the link was minted for THAT somebody,
 *   4. they still hold an active enrolment — or the lesson is an open preview.
 *
 * The fourth matters most: a signature proves we made the URL, not that the
 * person holding it is still entitled to it. Somebody refunded an hour ago has
 * a perfectly valid signature.
 *
 * Nothing here is ever sent as an attachment. There is no download route in
 * this application, and adding one would undo the rest.
 */
class LessonFileController extends Controller
{
    public function __construct(
        private readonly LessonAccess $access,
        private readonly PdfWatermarker $watermarker,
    ) {}

    public function __invoke(Request $request, CourseLesson $lesson): Response|StreamedResponse
    {
        $user = $request->user();

        // The link was minted for one person. Forwarding it does not transfer
        // it, even inside the five minutes.
        abort_unless(
            $this->access->isOpenPreview($lesson) || (int) $request->query('u') === (int) $user?->getKey(),
            403,
        );

        abort_unless($this->access->may($user, $lesson), 403);
        abort_unless($lesson->hasFile(), 404);

        $disk = Storage::disk('course-content');

        abort_unless($disk->exists($lesson->file_path), 404);

        return $lesson->type === LessonType::Pdf
            ? $this->stampedPdf($lesson, $user, $disk)
            : $this->streamed($lesson, $disk);
    }

    /**
     * A handout, with the reader's own name along the bottom of every page.
     */
    private function stampedPdf(CourseLesson $lesson, ?User $user, Filesystem $disk): Response
    {
        $bytes = $disk->get($lesson->file_path);

        if ($user !== null) {
            $bytes = $this->watermarker->stamp(
                $bytes,
                $user->displayName(),
                (string) $user->email,
            );
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            // Inline, never attachment. The viewer renders it; nothing offers
            // to save it.
            'Content-Disposition' => 'inline; filename="'.$this->safeName($lesson).'"',
            ...$this->noCacheHeaders(),
        ]);
    }

    /**
     * Video, with range support so seeking works.
     *
     * Without ranges a browser downloads the whole file before it will play,
     * which on a Nigerian mobile connection means a student watching nothing
     * for two minutes and then leaving.
     */
    private function streamed(CourseLesson $lesson, Filesystem $disk): StreamedResponse
    {
        $response = $disk->response(
            $lesson->file_path,
            $this->safeName($lesson),
            [
                'Content-Type' => $lesson->mime_type ?? 'application/octet-stream',
                ...$this->noCacheHeaders(),
            ],
            'inline',
        );

        $response->headers->set('Accept-Ranges', 'bytes');

        return $response;
    }

    /**
     * Headers that discourage keeping a copy.
     *
     * "Discourage" is the honest word: a proxy that ignores them will ignore
     * them, and a browser that has already painted a page has the bytes. These
     * stop the ordinary cases — a shared machine, a back button, a corporate
     * cache — and nothing more is claimed for them.
     *
     * @return array<string, string>
     */
    private function noCacheHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'same-origin',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive, noimageindex',
        ];
    }

    private function safeName(CourseLesson $lesson): string
    {
        $name = $lesson->file_name ?? ($lesson->title.'.pdf');

        return str_replace(['"', "\r", "\n"], '', $name);
    }
}
