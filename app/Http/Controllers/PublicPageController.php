<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public shell outside the catalogue. These sections are deliberately thin
 * — they hold a place in the navigation for parts of the platform that arrive
 * in later phases.
 */
class PublicPageController extends Controller
{
    /**
     * @var array<string, array{title: string, empty_title: string, empty_description: string}>
     */
    private const SECTIONS = [
        'mentors' => [
            'title' => 'Mentors',
            'empty_title' => 'No mentors listed yet',
            'empty_description' => 'Experienced farmers answering questions will be listed here.',
        ],
        'jobs' => [
            'title' => 'Farm jobs',
            'empty_title' => 'No jobs posted yet',
            'empty_description' => 'Employers looking for farm workers will post here, by state and LGA.',
        ],
    ];

    public function home(): Response
    {
        return Inertia::render('Welcome');
    }

    public function section(string $section): Response
    {
        if (! array_key_exists($section, self::SECTIONS)) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('Section', [
            'section' => self::SECTIONS[$section],
        ]);
    }
}
