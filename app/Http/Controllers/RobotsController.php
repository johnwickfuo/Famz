<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * robots.txt, served from a route rather than as a static file.
 *
 * One reason: the Sitemap directive must be an ABSOLUTE url. A static file
 * cannot know the domain it is being served from, so it either hard-codes one —
 * wrong in every environment but production — or ships a relative path, which
 * crawlers reject as invalid. Serving it here means the line is correct
 * wherever the application is deployed.
 *
 * The disallow list is the important part, and it says the same thing three
 * different ways alongside the sitemap and the pages' own noindex tags: worker
 * profiles and mentor invitations do not go in an index.
 */
class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        $lines = [
            'User-agent: *',
            '',
            '# The mentor invitation flow. There is no public link to it anywhere on',
            '# the site and it will not open without a valid, unused, unexpired token',
            '# — but a crawler that finds a forwarded link in somebody\'s public post',
            '# should not put it in an index. Belt and braces; the token is the lock.',
            'Disallow: /mentors/join',
            '',
            '# The worker directory. These are individual people\'s profiles, and the',
            '# ones an employer can see carry a phone number. Indexing them would put',
            '# those numbers in a search engine forever — outside the rate limit,',
            '# outside the disclosure log, and outside anything we could take back.',
            'Disallow: /jobs/workers',
            '',
            '# Somebody\'s own account and dashboard. Nothing here renders for a',
            '# crawler, but there is no reason to send one down the corridor.',
            'Disallow: /account',
            'Disallow: /dashboard',
            'Disallow: /notifications',
            '',
            '# Signed, single-use links to course material and private checkouts.',
            'Disallow: /academy/content/',
            'Disallow: /agreed/',
            '',
            'Allow: /',
            '',
            // Absolute, which is what the specification requires and what a
            // static file could not produce.
            'Sitemap: '.route('sitemap'),
            '',
        ];

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
