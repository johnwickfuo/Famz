<?php

use Symfony\Component\Finder\Finder;

/**
 * The company has not been named yet, and when it is, the name will live in one
 * place: the `company_name` setting, read through BrandingService.
 *
 * This test is the guard rail. It scans app/, resources/ and config/ for two
 * kinds of mistake:
 *
 *   1. a brand-shaped literal — the repository name, or a proper noun paired
 *      with an agriculture word, written into source;
 *   2. a template or component reaching for config('app.name') / APP_NAME
 *      directly instead of going through BrandingService.
 *
 * Seeders and tests are exempt: sample data has to say something, and the tests
 * below deliberately set a company name in order to assert it propagates.
 */

/**
 * The patterns that identify a leaked client name. Shared by the scan and by
 * the test that proves the scan can fail.
 *
 * The repository is currently called "Famz" — exactly the kind of working name
 * that ends up hardcoded — so it is matched explicitly. The rest catch a
 * capitalised proper noun glued to an agriculture word, which is the shape
 * almost every candidate name in this sector takes.
 *
 * @return array<string, string>
 */
function brandLiteralPatterns(): array
{
    return [
        'repository or working name' => '/\b(fam+z|famz\w*)\b/i',
        'proper noun + agriculture word' => '/\b[A-Z][a-z]{2,}\s?(Agro|Agric|AgriTech|Agritech|Farms|Poultry|Feeds|Livestock)\b/',
        'a registered company suffix' => '/\b[A-Z][A-Za-z]{2,}\s+(Nigeria\s+)?(Limited|Ltd\.?|Plc|Enterprises|Ventures|Cooperative)\b/',
        'an RC number' => '/\bRC[\s:-]?\d{5,}\b/i',
        'a company email on a real domain' => '/[\w.+-]+@(?!example\.(com|test|org)\b)[\w-]+\.(com|ng|org|net|africa)\b/i',
    ];
}

/**
 * Sample data and tests are exempt from both scans.
 *
 * A factory that generates seller names has to generate something that looks
 * like a company, and the tests below set a company name deliberately in order
 * to assert it propagates. Neither is a surface a rename needs to reach.
 */
function isSampleDataOrTest(string $path): bool
{
    foreach (['database/seeders', 'database/factories', 'tests'] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<int, SplFileInfo>
 */
function scannedSourceFiles(): array
{
    /*
     * The whole repository, not three directories.
     *
     * The earlier version scanned app/, resources/ and config/, which is where
     * a name is most likely to leak and not where it is hardest to notice. A
     * name written into a route file, a migration default, a deployment script
     * or the README is just as much a rename that will not happen — and nobody
     * would think to look there.
     */
    $finder = (new Finder)
        ->files()
        ->in(base_path())
        /*
         * public/ holds published vendor assets and the compiled bundle —
         * other people's code and generated output, neither of which a rename
         * has to reach.
         */
        ->exclude(['vendor', 'node_modules', 'storage', 'public', 'bootstrap/cache', '.git'])
        ->name(['*.php', '*.blade.php', '*.vue', '*.js', '*.ts', '*.css', '*.md', '*.json', '*.yml', '*.yaml', '*.conf', '*.sh'])
        // Lock files are generated, enormous, and full of package names that
        // trip the proper-noun patterns.
        ->notName(['package-lock.json', 'composer.lock'])
        ->notPath('fonts')
        ->ignoreDotFiles(true);

    return iterator_to_array($finder);
}

it('has no brand-shaped literal in app, resources or config', function () {
    $patterns = brandLiteralPatterns();

    $violations = [];

    foreach (scannedSourceFiles() as $file) {
        $path = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getRealPath());

        if (isSampleDataOrTest($path)) {
            continue;
        }

        $contents = $file->getContents();

        foreach ($patterns as $label => $pattern) {
            if (preg_match_all($pattern, $contents, $matches)) {
                foreach (array_unique($matches[0]) as $match) {
                    $violations[] = "{$path}: {$label} — \"".trim($match).'"';
                }
            }
        }
    }

    expect($violations)->toBe([], "Brand-shaped literals found:\n".implode("\n", $violations));
});

it('reads the application name only through BrandingService', function () {
    /*
     * config('app.name') is the last-resort fallback, and BrandingService owns
     * it. Anywhere else that a person can see — a Blade template, a Vue
     * component, a notification, a document — reaching for it directly means a
     * rename would not reach that surface.
     *
     * config/ is scanned by the literal test above but not by this one: Laravel
     * uses APP_NAME there to derive cache, session and queue key prefixes,
     * which are infrastructure identifiers nobody ever reads.
     */
    $allowed = [
        'app/Services/Branding/BrandingService.php',
    ];

    $violations = [];

    foreach (scannedSourceFiles() as $file) {
        $path = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getRealPath());

        if (in_array($path, $allowed, true)
            || isSampleDataOrTest($path)
            || str_starts_with($path, 'config/')
            // Documentation has to be able to name the variable it documents.
            || str_ends_with($path, '.md')) {
            continue;
        }

        $contents = $file->getContents();

        if (preg_match('/config\(\s*[\'"]app\.name|APP_NAME|VITE_APP_NAME/i', $contents, $matches)) {
            $violations[] = "{$path}: reaches for {$matches[0]} directly";
        }
    }

    expect($violations)->toBe([], "The company name must come from BrandingService:\n".implode("\n", $violations));
});

it('actually catches a brand literal, so a clean scan means something', function (string $sample) {
    /*
     * A scanner that cannot fail is decoration. Every line below is the kind of
     * literal that would really leak into a Blade template or a Vue component,
     * and at least one pattern has to catch each of them.
     */
    $caught = collect(brandLiteralPatterns())
        ->contains(fn (string $pattern): bool => preg_match($pattern, $sample) === 1);

    expect($caught)->toBeTrue("No pattern caught: {$sample}");
})->with([
    'the repository name in a title tag' => '<title>Famz</title>',
    'a proper noun plus an agriculture word' => 'const appName = "Kwara Poultry";',
    'a trading name' => "return 'Sabon Gari Agro';",
    'a registered company' => '<p>Ilorin Ventures</p>',
    'an RC number' => 'RC 1234567',
    'a company email' => 'support@somefarm.com.ng',
]);

it('does not flag the language the platform legitimately uses', function (string $sample) {
    // The scan must not cry wolf over ordinary copy, or people will disable it.
    $caught = collect(brandLiteralPatterns())
        ->contains(fn (string $pattern): bool => preg_match($pattern, $sample) === 1);

    expect($caught)->toBeFalse("A pattern wrongly flagged: {$sample}");
})->with([
    'a generic section title' => "'title' => 'Marketplace',",
    'ordinary product copy' => 'Feed, day-old chicks, cages and equipment.',
    'a role description' => 'Lists feed, equipment and produce, and fulfils orders.',
    'an example address' => "'email' => 'no-reply@example.test',",
    'a placeholder in authored content' => 'Welcome to {company} ({company_short})',
]);
