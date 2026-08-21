<?php

use App\Services\Uploads\ImageIngest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * What happens to a file between somebody choosing it and it reaching disk.
 *
 * The first test is the reason this whole service exists. Laravel's `image`
 * rule checks a MIME type and an extension, and both are claims made by the
 * person uploading — a file can be a completely valid JPEG *and* carry a PHP
 * payload in a comment segment. It passes every validation rule there is.
 */
beforeEach(function (): void {
    Storage::fake('public');

    $this->ingest = app(ImageIngest::class);

    /**
     * A real, decodable JPEG of a given size.
     */
    $this->jpeg = function (int $width = 80, int $height = 60): string {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 12, 110, 60));

        ob_start();
        imagejpeg($image, null, 90);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $binary;
    };

    /**
     * Write bytes to a temporary file and wrap it as an upload.
     */
    $this->upload = function (string $binary, string $name = 'photo.jpg', string $mime = 'image/jpeg'): UploadedFile {
        $path = tempnam(sys_get_temp_dir(), 'up').'.jpg';
        file_put_contents($path, $binary);

        return new UploadedFile($path, $name, $mime, null, true);
    };
});

it('destroys a payload hidden inside a valid image', function (): void {
    /*
     * A polyglot: a genuine JPEG with PHP appended. It passes mime_content_type,
     * it passes getimagesize, and Laravel's `image` rule accepts it. The only
     * thing that removes it is decoding the pixels and writing a new file.
     */
    $poisoned = ($this->jpeg)()."<?php system(\$_GET['c']); ?>";
    $upload = ($this->upload)($poisoned);

    // Confirm the premise: this really does look like an image.
    expect(getimagesize($upload->getRealPath()))->not->toBeFalse()
        ->and(file_get_contents($upload->getRealPath()))->toContain('<?php');

    $clean = $this->ingest->reencode($upload);

    expect($clean)->not->toContain('<?php')
        ->and($clean)->not->toContain('system(')
        // Still a usable photograph, not just a scrubbed blob.
        ->and(@imagecreatefromstring($clean))->not->toBeFalse();
});

it('strips EXIF, including where the photograph was taken', function (): void {
    /*
     * This matters more here than on most platforms. A farmer photographing a
     * sick bird is photographing it at their farm, and a phone writes the
     * coordinates into the file. Publishing that beside a public listing tells
     * anybody who downloads it where that person lives.
     */
    $withExif = ($this->jpeg)();

    // A minimal APP1/Exif segment spliced in after the SOI marker.
    $exif = "\xFF\xE1\x00\x16Exif\x00\x00MM\x00\x2A\x00\x00\x00\x08\x00\x00";
    $withExif = substr($withExif, 0, 2).$exif.substr($withExif, 2);

    $upload = ($this->upload)($withExif);

    expect(file_get_contents($upload->getRealPath()))->toContain('Exif');

    expect($this->ingest->reencode($upload))->not->toContain('Exif');
});

it('refuses a file that is not an image at all', function (): void {
    $upload = ($this->upload)('<?php echo "not an image"; ?>', 'evil.jpg');

    expect(fn () => $this->ingest->reencode($upload))
        ->toThrow(RuntimeException::class);
});

it('refuses an SVG, whatever it claims to be', function (): void {
    // SVG is XML, and XML can carry script. It is an image format the browser
    // executes, which is not a category this platform accepts.
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

    expect(fn () => $this->ingest->reencode(($this->upload)($svg, 'logo.svg', 'image/svg+xml')))
        ->toThrow(RuntimeException::class);
});

it('caps a very large photograph', function (): void {
    // A modern phone camera produces images nobody's screen can show and
    // nobody's mobile data wants to download.
    $huge = ($this->jpeg)(4000, 3000);

    $clean = $this->ingest->reencode(($this->upload)($huge));
    [$width, $height] = getimagesizefromstring($clean);

    expect(max($width, $height))->toBe(ImageIngest::MAX_EDGE)
        // Aspect ratio preserved: a squashed product photograph is a bug.
        ->and(round($width / $height, 2))->toBe(round(4000 / 3000, 2));
});

it('leaves a small photograph alone rather than scaling it up', function (): void {
    $clean = $this->ingest->reencode(($this->upload)(($this->jpeg)(120, 90)));
    [$width, $height] = getimagesizefromstring($clean);

    expect($width)->toBe(120)->and($height)->toBe(90);
});

it('names the stored file itself rather than trusting the upload', function (): void {
    /*
     * An uploaded filename is user input. "../../../.env" and
     * "shell.php.jpg" are both things a browser will happily send.
     */
    $path = $this->ingest->store(
        ($this->upload)(($this->jpeg)(), '../../../evil.php.jpg'),
        'consultations',
    );

    expect($path)->toStartWith('consultations/')
        ->and($path)->toEndWith('.jpg')
        ->and($path)->not->toContain('..')
        ->and($path)->not->toContain('evil');

    Storage::disk('public')->assertExists($path);
});

it('writes nothing executable to disk', function (): void {
    $path = $this->ingest->store(
        ($this->upload)(($this->jpeg)()."<?php echo 1; ?>"),
        'requests',
    );

    expect(Storage::disk('public')->get($path))->not->toContain('<?php');
});

it('stores private uploads without public visibility', function (): void {
    Storage::fake('local');

    $path = $this->ingest->store(($this->upload)(($this->jpeg)()), 'private-things', 'local');

    Storage::disk('local')->assertExists($path);
});

it('routes every image upload path through the ingest', function (): void {
    /*
     * A sweep rather than a list. The risk is not the controllers that exist
     * today — those are wired — it is the one somebody adds next month with a
     * bare ->store() call, which would look completely normal in review.
     */
    $raw = [];

    foreach (\Illuminate\Support\Facades\File::allFiles(app_path('Http/Controllers')) as $file) {
        $contents = $file->getContents();

        // A ->store( on an uploaded file, not on a cart or a session store.
        if (preg_match('/->file\([^)]*\)->store\(|\$file->store\(/', $contents)) {
            $raw[] = $file->getRelativePathname();
        }
    }

    /*
     * The seller ID document is the one deliberate exception: it may be a PDF,
     * and re-encoding a PDF as a JPEG would destroy the document somebody is
     * being asked to provide.
     */
    expect($raw)->toBe(['SellerApplicationController.php']);
});
