<?php

namespace App\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;
use Illuminate\Contracts\Support\Renderable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base for every PDF the platform produces.
 *
 * The identity on the page comes from BrandingService through the Blade view
 * composer, exactly as it does for the site and for email, so a rename in
 * Settings changes documents produced from the next request onwards.
 */
abstract class BrandedDocument implements Renderable
{
    abstract protected function view(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function data(): array;

    protected string $paper = 'a4';

    protected string $orientation = 'portrait';

    abstract public function filename(): string;

    public function pdf(): PdfWrapper
    {
        return Pdf::loadView($this->view(), $this->data())
            ->setPaper($this->paper, $this->orientation);
    }

    /**
     * The rendered HTML, which is what the tests assert against — rendering to
     * PDF bytes as well would only prove DomPDF works.
     */
    public function render(): string
    {
        return view($this->view(), $this->data())->render();
    }

    public function output(): string
    {
        return $this->pdf()->output();
    }

    public function download(): Response
    {
        return $this->pdf()->download($this->filename());
    }

    public function stream(): Response
    {
        return $this->pdf()->stream($this->filename());
    }
}
