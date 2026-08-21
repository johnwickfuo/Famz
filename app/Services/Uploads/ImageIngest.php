<?php

namespace App\Services\Uploads;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Every image a user uploads goes through here, and comes out a different file.
 *
 * The point is re-encoding. Laravel's `image` rule checks a MIME type and an
 * extension, and both of those are claims made by the person uploading. A file
 * can be a completely valid JPEG *and* contain a PHP payload in a comment
 * segment — it passes every validation rule there is, and then it only needs
 * somewhere that will execute it.
 *
 * Decoding the pixels and writing a fresh file from them destroys that. What
 * comes out the other side contains exactly the image data GD understood and
 * nothing else: no comment segments, no appended archive, no second file
 * pretending to be a thumbnail.
 *
 * Stripping EXIF falls out of the same step, and matters here more than on most
 * platforms. A farmer photographing a sick bird is photographing it at their
 * farm, and a modern phone writes the coordinates into the file. Publishing
 * that alongside a public listing tells anybody who downloads it exactly where
 * the person lives.
 *
 * Everything is also capped in pixels. A 12,000 × 9,000 photograph from a phone
 * is thirty megabytes of memory to decode and nobody's screen is that big.
 */
class ImageIngest
{
    /**
     * The only image types accepted, by what the FILE says rather than what the
     * upload claims.
     */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Long edge, in pixels. Generous enough for a product photograph on a
     * desktop screen, small enough to send over mobile data.
     */
    public const MAX_EDGE = 2000;

    /**
     * Quality for the re-encode. 82 is the point where most people stop being
     * able to tell, and the file is roughly a third the size of 100.
     */
    private const QUALITY = 82;

    /**
     * Take an upload, and give back the stored path.
     *
     * @param  string  $directory  Where under the disk this belongs.
     */
    public function store(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        $binary = $this->reencode($file);

        // The name is ours, not theirs. An uploaded filename is user input and
        // has no business becoming a path.
        $path = trim($directory, '/').'/'.Str::uuid().'.jpg';

        Storage::disk($disk)->put($path, $binary, [
            // Explicit rather than inherited from the disk: a private disk that
            // later gains a default of public should not silently publish
            // everything already on it.
            'visibility' => $disk === 'public' ? 'public' : 'private',
        ]);

        return $path;
    }

    /**
     * Decode the pixels and write a fresh file from them.
     *
     * Everything that was not pixels — EXIF, comment segments, an appended zip,
     * a PHP tag hidden in a colour profile — does not survive this.
     */
    public function reencode(UploadedFile $file): string
    {
        $this->assertLooksLikeAnImage($file);

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            // Passed the MIME check and still would not decode. That is either
            // a corrupt file or something pretending to be an image, and the
            // answer is the same either way.
            throw new RuntimeException(__('That image could not be read. Please try another photograph.'));
        }

        $source = $this->resizeToFit($source);

        /*
         * Flattened onto white before writing as JPEG. A PNG with transparency
         * written straight to JPEG gets a black background, which on a product
         * photograph of a white feed bag looks like a printing fault.
         */
        $flattened = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagefill($flattened, 0, 0, imagecolorallocate($flattened, 255, 255, 255));
        imagecopy($flattened, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));

        ob_start();
        imagejpeg($flattened, null, self::QUALITY);
        $binary = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($flattened);

        return $binary;
    }

    /**
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function resizeToFit($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= self::MAX_EDGE) {
            return $image;
        }

        $scale = self::MAX_EDGE / $longest;

        $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));

        if ($resized === false) {
            return $image;
        }

        imagedestroy($image);

        return $resized;
    }

    /**
     * Check what the file IS, not what the upload said it was.
     *
     * getMimeType() reads the leading bytes; getClientMimeType() reads a header
     * the browser was told to send. Only the first is evidence.
     */
    private function assertLooksLikeAnImage(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new RuntimeException(__('That file did not upload properly. Please try again.'));
        }

        if (! in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            throw new RuntimeException(__('Photographs must be JPG, PNG or WEBP.'));
        }

        // getimagesize() fails on anything that is not really an image, which
        // catches a file whose first bytes were crafted to pass a MIME sniff.
        if (@getimagesize($file->getRealPath()) === false) {
            throw new RuntimeException(__('That file is not a photograph.'));
        }
    }
}
