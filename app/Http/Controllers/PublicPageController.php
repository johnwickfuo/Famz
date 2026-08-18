<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public shell: home, search and the four marketplace sections. The
 * sections are deliberately thin — they exist so the public layout, nav and
 * search are real and navigable while the catalogue is built out.
 */
class PublicPageController extends Controller
{
    /**
     * @var array<string, array{title: string, empty_title: string, empty_description: string}>
     */
    private const SECTIONS = [
        'marketplace' => [
            'title' => 'Marketplace',
            'empty_title' => 'No listings yet',
            'empty_description' => 'Feed, chicks, cages and equipment will appear here as sellers publish them.',
        ],
        'training' => [
            'title' => 'Training',
            'empty_title' => 'No courses yet',
            'empty_description' => 'Short, practical courses with a certificate at the end are on the way.',
        ],
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

    public function search(Request $request): Response
    {
        return Inertia::render('Search', [
            'query' => (string) $request->string('q'),
        ]);
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
