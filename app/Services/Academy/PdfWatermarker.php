<?php

namespace App\Services\Academy;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;
use Throwable;

/**
 * Stamps the reader's own name and email onto every page of a handout.
 *
 * This is not protection, and pretending otherwise would be worse than doing
 * nothing: anybody can crop a footer, and a screenshot has no footer to crop.
 * What it does is make a shared file traceable to whoever shared it, which is
 * enough to stop the casual passing-around that actually costs a course its
 * sales. Everything else — key servers, encrypted viewers, disabled right-click
 * — buys nothing and breaks the reading experience for the people who paid.
 *
 * A file that cannot be parsed is served through untouched rather than
 * withheld: a student who paid should get their handout even if it was
 * produced by something FPDI cannot read.
 */
class PdfWatermarker
{
    /**
     * @return string the stamped PDF, or the original bytes if it could not be stamped
     */
    public function stamp(string $pdf, string $name, string $email, ?string $issuedTo = null): string
    {
        try {
            return $this->apply($pdf, $name, $email, $issuedTo);
        } catch (CrossReferenceException|PdfParserException $exception) {
            // Compressed cross-reference streams and a few generators FPDI
            // will not read. Not worth failing a paid-for download over.
            report($exception);

            return $pdf;
        } catch (Throwable $exception) {
            report($exception);

            return $pdf;
        }
    }

    private function apply(string $pdf, string $name, string $email, ?string $issuedTo): string
    {
        $source = tempnam(sys_get_temp_dir(), 'lesson-').'.pdf';
        file_put_contents($source, $pdf);

        try {
            $fpdi = new Fpdi;
            $pageCount = $fpdi->setSourceFile($source);

            $footer = $this->footerText($name, $email, $issuedTo);

            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $fpdi->importPage($page);
                $size = $fpdi->getTemplateSize($template);

                $fpdi->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $fpdi->useTemplate($template);

                // Below the content, small and grey: a watermark that fights
                // the page makes the handout worse for the person who paid for
                // it, which is the opposite of the point.
                $fpdi->SetFont('Helvetica', '', 7);
                $fpdi->SetTextColor(120, 120, 120);
                $fpdi->SetXY(8, $size['height'] - 8);
                $fpdi->Cell($size['width'] - 16, 4, $this->latin1($footer), 0, 0, 'C');
            }

            return $fpdi->Output('S');
        } finally {
            @unlink($source);
        }
    }

    private function footerText(string $name, string $email, ?string $issuedTo): string
    {
        $issued = $issuedTo ?? __('Licensed to');

        return sprintf('%s %s (%s) · %s', $issued, $name, $email, now()->format('j M Y'));
    }

    /**
     * FPDF's core fonts are Latin-1 only.
     *
     * Nigerian names carry dot-below vowels that would otherwise come out as
     * mojibake in the one place the reader is meant to recognise themselves, so
     * they are transliterated rather than mangled.
     */
    private function latin1(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);

        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '', $text) ?? $text : $converted;
    }
}
