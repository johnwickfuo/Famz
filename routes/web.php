<?php

use App\Http\Controllers\Academy\AcademyController;
use App\Http\Controllers\Academy\CertificateController;
use App\Http\Controllers\Academy\CourseCheckoutController;
use App\Http\Controllers\Academy\CoursePlayerController;
use App\Http\Controllers\Academy\LessonFileController;
use App\Http\Controllers\Academy\QuizController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\BuyerRequestController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Consultations\ConsultationController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\Jobs\EmployerController;
use App\Http\Controllers\Jobs\JobApplicationController;
use App\Http\Controllers\Jobs\JobBoardController;
use App\Http\Controllers\Jobs\JobRatingController;
use App\Http\Controllers\Jobs\WorkerDirectoryController;
use App\Http\Controllers\Jobs\WorkerProfileController;
use App\Http\Controllers\Mentorship\EngagementController;
use App\Http\Controllers\Mentorship\MentorDirectoryController;
use App\Http\Controllers\Mentorship\MentorRegistrationController;
use App\Http\Controllers\NegotiatedPurchaseController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\Platform\HealthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\Quotations\QuotationController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SellerApplicationController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicPageController::class, 'home'])->name('home');
Route::get('/s/{section}', [PublicPageController::class, 'section'])->name('sections.show');

/*
 * The jobs board.
 *
 * Public and free. The worker side of it is NOT public — see the routes inside
 * the auth group below, and WorkerProfilePolicy for why: a crawlable index of
 * people looking for work is the raw material for exactly the harvesting the
 * contact rule exists to prevent.
 */
Route::get('/jobs', [JobBoardController::class, 'index'])->name('jobs.index');

// The catalogue.
Route::get('/market', [CatalogueController::class, 'home'])->name('catalogue.home');
/*
 * The sitemap.
 *
 * The rule that shapes it: nothing carrying a contact detail goes in. A sitemap
 * is an invitation to index, and indexing a page with somebody's phone number
 * on it puts that number in a search engine forever, outside every rate limit
 * and log built to protect it.
 */
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

/*
 * robots.txt from a route, not a static file: the Sitemap directive has to be
 * an absolute URL, and a file on disk cannot know which domain it is being
 * served from.
 */
Route::get('/robots.txt', RobotsController::class)->name('robots');

/*
 * The endpoint an uptime monitor watches.
 *
 * Deliberately outside the throttle groups: a monitor checking every minute
 * from one address is exactly the traffic a rate limiter is built to stop, and
 * an alert that fires because the monitor got 429ed is worse than no alert.
 */
Route::get('/health', HealthController::class)->name('health');

/*
 * The pages that explain what this place is.
 *
 * Copy lives in App\Content with every company mention as a {company}
 * placeholder, expanded server-side. A rename in settings renames the platform
 * in its own terms of service on the next request.
 */
Route::get('/about', [PageController::class, 'about'])->name('pages.about');
Route::get('/how-it-works', [PageController::class, 'howItWorks'])->name('pages.how-it-works');
Route::get('/faq', [PageController::class, 'faq'])->name('pages.faq');
Route::get('/terms', [PageController::class, 'terms'])->name('pages.terms');
Route::get('/privacy', [PageController::class, 'privacy'])->name('pages.privacy');
Route::get('/guides/{role}', [PageController::class, 'guide'])
    ->whereIn('role', ['seller', 'mentor', 'worker'])
    ->name('pages.guide');

Route::get('/contact', [ContactController::class, 'create'])->name('contact.create');
Route::post('/contact', [ContactController::class, 'store'])->name('contact.store')
    ->middleware('throttle:forms');

/*
 * One search box, five kinds of answer.
 *
 * Deliberately NOT including worker profiles. Those exist to be found by
 * employers who have registered and accepted a rate limit, and a public search
 * box is exactly the harvesting route the jobs module was built to close.
 */
Route::get('/search', SearchController::class)->name('search');

/*
 * The marketplace's own product search, which is a different tool and keeps its
 * own address. Global search gives a cross-platform overview; this one has the
 * filters, the sorting and the pagination somebody uses when they already know
 * they want to buy a thing.
 */
Route::get('/market/search', [CatalogueController::class, 'search'])->name('catalogue.search');

// The wanted-ad board is public: somebody who has not signed up should be able
// to see that there is business here before being asked to register.
Route::get('/requests', [BuyerRequestController::class, 'index'])->name('requests.index');

/*
 * The academy. Landing, catalogue and course pages are public; a course page
 * shows its curriculum and plays its preview lessons without an account,
 * because a locked list of titles sells nothing.
 */
Route::get('/academy', [AcademyController::class, 'home'])->name('academy.home');
Route::get('/academy/courses', [AcademyController::class, 'catalogue'])->name('academy.catalogue');

/*
 * The free assistant.
 *
 * Public and guest-friendly, deliberately. The people most likely to need a
 * quick answer at six in the morning about a flock that is off feed are the
 * least likely to have signed up, and a registration wall here would hide the
 * platform's most useful free thing from exactly the people it is for.
 *
 * Abuse control is not a middleware here — it lives in ChatGuard, because three
 * different limits with three different windows and a platform-wide token
 * budget is more than a throttle string can express, and because running out of
 * budget must degrade to cached answers rather than return a 429.
 */
Route::get('/ask', [AssistantController::class, 'show'])->name('assistant.show');
Route::post('/ask', [AssistantController::class, 'ask'])->name('assistant.ask')
    ->middleware('throttle:assistant');
Route::post('/ask/reset', [AssistantController::class, 'reset'])->name('assistant.reset')
    ->middleware('throttle:assistant');

/*
 * Consultations with the company itself. Public and guest-friendly on purpose:
 * asking a farmer whose birds are dying to register before they can describe
 * the problem loses the booking. Four fields and no account.
 */
Route::get('/consult', [ConsultationController::class, 'create'])->name('consultations.create');
Route::post('/consult', [ConsultationController::class, 'store'])->name('consultations.store')
    ->middleware('throttle:forms');

/*
 * Finding a mentor. Public: somebody should be able to see who is here and
 * what they charge before signing up. What they cannot see, at any point on
 * these pages, is how to reach anybody — that arrives with a paid engagement.
 */
Route::get('/mentors', [MentorDirectoryController::class, 'create'])->name('mentors.find');
Route::post('/mentors/match', [MentorDirectoryController::class, 'match'])->name('mentors.match')
    ->middleware('throttle:forms');
Route::get('/mentors/shortlist/{match}', [MentorDirectoryController::class, 'shortlist'])
    ->name('mentors.shortlist');

/*
 * Becoming a mentor. Invitation only, and deliberately not in any navigation:
 * an administrator sends the signed link by hand. `signed` proves we made the
 * URL; the controller proves the invitation behind it is still good, which is
 * the part that actually matters.
 */
Route::middleware('noindex')->group(function () {
    Route::get('/mentors/join/{token}', [MentorRegistrationController::class, 'create'])
        ->middleware('signed')
        ->name('mentors.join');

    /*
     * Not signed, on purpose. The form would have to post back to the exact
     * signed URL for a signature to survive the round trip, and the signature
     * was never the lock here anyway: anybody who can reach this route already
     * holds the token, and the token is checked again — under a row lock —
     * before a single row is written.
     */
    Route::post('/mentors/join/{token}', [MentorRegistrationController::class, 'store'])
        ->name('mentors.join.store');
});

// A certificate is checked by whoever is holding the printed copy, which is
// usually an employer with no account here.
Route::get('/verify/{code}', [CertificateController::class, 'verify'])->name('certificates.verify');

/*
 * Course material. Deliberately outside the auth group: a preview lesson has
 * to play for somebody who has not signed up yet, and the controller does the
 * real work — signed, minted for one person, and an active enrolment checked
 * again on every fetch. A signature proves we made the URL, not that whoever
 * is holding it may still use it.
 */
Route::get('/academy/content/{lesson}', LessonFileController::class)
    ->middleware('signed')
    ->name('academy.lesson.file');
Route::get('/category/{category}', [CatalogueController::class, 'category'])->name('catalogue.category');
// Storefronts live under /store: /seller is the Filament seller panel, and a
// seller slug could otherwise collide with one of its routes.
Route::get('/store/{seller}', [CatalogueController::class, 'storefront'])->name('catalogue.storefront');
Route::get('/product/{product}', [ProductController::class, 'show'])->name('catalogue.product');

// The cart works for guests too; it moves into their account when they sign in.
Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
Route::patch('/cart', [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart', [CartController::class, 'destroy'])->name('cart.destroy');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Applying to sell. Anyone with an account may apply; an administrator decides.
    Route::get('/sell', [SellerApplicationController::class, 'create'])->name('seller-application.create');
    Route::post('/sell', [SellerApplicationController::class, 'store'])->name('seller-application.store')
        ->middleware('throttle:forms');

    // Checkout. The callback changes nothing — see CheckoutController::callback.
    Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store')
        ->middleware('throttle:checkout');
    Route::get('/checkout/callback', [CheckoutController::class, 'callback'])->name('checkout.callback');
    Route::get('/checkout/{order}/status', [CheckoutController::class, 'status'])->name('checkout.status');
    // An order that was never paid for is not a dead end.
    Route::post('/checkout/{order}/pay', [CheckoutController::class, 'pay'])->name('checkout.pay')
        ->middleware('throttle:checkout');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/parts/{subOrder}/received', [OrderController::class, 'markReceived'])
        ->name('orders.received')->middleware('throttle:checkout');

    /*
     * Disputes. Raised against one seller's part of an order, because that is
     * the unit the money is held in.
     */
    /*
     * Wanted ads. The board itself is public; posting, managing and offering
     * need an account.
     */
    Route::get('/requests/new', [BuyerRequestController::class, 'create'])->name('requests.create');
    Route::post('/requests', [BuyerRequestController::class, 'store'])->name('requests.store')
        ->middleware('throttle:forms');
    Route::get('/requests/mine', [BuyerRequestController::class, 'mine'])->name('requests.mine');
    Route::get('/requests/{buyerRequest}/manage', [BuyerRequestController::class, 'manage'])
        ->name('requests.manage');
    Route::post('/requests/{buyerRequest}/close', [BuyerRequestController::class, 'close'])
        ->name('requests.close')->middleware('throttle:offers');
    Route::post('/requests/{buyerRequest}/offers', [BuyerRequestController::class, 'offer'])
        ->name('requests.offer')->middleware('throttle:offers');

    /*
     * Haggling over a listing. The seller answers in their panel; a buyer
     * answers a counter here.
     */
    Route::post('/listings/{product}/offers', [OfferController::class, 'store'])->name('offers.store')
        ->middleware('throttle:offers');
    Route::post('/offers/{offer}/respond', [OfferController::class, 'respond'])->name('offers.respond')
        ->middleware('throttle:offers');
    Route::post('/offers/{offer}/withdraw', [OfferController::class, 'withdraw'])->name('offers.withdraw')->middleware('throttle:offers');

    // The private checkout an accepted offer earns.
    Route::get('/agreed/{negotiatedPurchase}', [NegotiatedPurchaseController::class, 'show'])
        ->name('negotiated.show');
    Route::post('/agreed/{negotiatedPurchase}', [NegotiatedPurchaseController::class, 'store'])
        ->name('negotiated.store')->middleware('throttle:checkout');

    // What has happened since somebody last looked.
    /*
     * The academy behind a sign-in: buying, the player, the quiz and
     * certificates.
     */
    Route::get('/academy/my-courses', [AcademyController::class, 'mine'])->name('academy.mine');

    Route::get('/academy/{course}/buy', [CourseCheckoutController::class, 'show'])->name('academy.checkout');
    Route::post('/academy/{course}/buy', [CourseCheckoutController::class, 'store'])->name('academy.checkout.store')
        ->middleware('throttle:checkout');

    Route::get('/academy/{course}/learn', [CoursePlayerController::class, 'show'])->name('academy.player');
    Route::get('/academy/{course}/learn/{lesson}', [CoursePlayerController::class, 'show'])
        ->name('academy.player.lesson');
    Route::post('/academy/{course}/lessons/{lesson}/position', [CoursePlayerController::class, 'position'])
        ->name('academy.player.position')->middleware('throttle:polling');
    Route::post('/academy/{course}/lessons/{lesson}/complete', [CoursePlayerController::class, 'complete'])
        ->name('academy.player.complete')->middleware('throttle:polling');
    Route::get('/academy/{course}/lessons/{lesson}/link', [CoursePlayerController::class, 'contentUrl'])
        ->name('academy.player.content');

    Route::get('/academy/{course}/quiz', [QuizController::class, 'show'])->name('academy.quiz');
    Route::post('/academy/{course}/quiz', [QuizController::class, 'submit'])->name('academy.quiz.submit')->middleware('throttle:polling');
    Route::get('/academy/{course}/quiz/review', [QuizController::class, 'review'])->name('academy.quiz.review');

    Route::post('/academy/{course}/certificate', [CertificateController::class, 'issue'])
        ->name('academy.certificate.issue')->middleware('throttle:checkout');
    Route::get('/certificates/{certificate}', [CertificateController::class, 'show'])
        ->name('academy.certificate.show');
    Route::get('/certificates/{certificate}/pdf', [CertificateController::class, 'pdf'])
        ->name('academy.certificate.pdf');

    // The waiting room a newly registered mentor lands in.
    Route::get('/mentors/pending', [MentorRegistrationController::class, 'pending'])
        ->name('mentors.pending');

    /*
     * Engagements. Hiring, paying, confirming and — only once one of those has
     * happened — seeing who you hired.
     */
    Route::get('/mentorship', [EngagementController::class, 'index'])->name('mentorship.index');
    Route::post('/mentorship/packages/{package}', [EngagementController::class, 'store'])
        ->name('mentorship.store');
    Route::get('/mentorship/{engagement}', [EngagementController::class, 'show'])->name('mentorship.show');
    Route::post('/mentorship/{engagement}/pay', [EngagementController::class, 'pay'])->name('mentorship.pay');
    Route::post('/mentorship/{engagement}/confirm', [EngagementController::class, 'confirm'])
        ->name('mentorship.confirm');
    Route::post('/mentorship/{engagement}/review', [EngagementController::class, 'review'])
        ->name('mentorship.review');
    Route::post('/mentorship/{engagement}/dispute', [EngagementController::class, 'dispute'])
        ->name('mentorship.dispute');

    // Everything this person has asked us about.
    Route::get('/consultations', [ConsultationController::class, 'index'])->name('consultations.index');
    Route::post('/consultations/{consultation}/pay', [ConsultationController::class, 'pay'])
        ->name('consultations.pay')->middleware('throttle:checkout');

    /*
     * Farm setup quotations. Every route here is behind auth, unlike a
     * consultation: a study fee has to be paid before anything happens, and
     * paying means somebody the company can identify and come back to months
     * later.
     */
    Route::get('/farm-setup', [QuotationController::class, 'create'])->name('quotations.create');
    Route::post('/farm-setup', [QuotationController::class, 'store'])->name('quotations.store')
        ->middleware('throttle:forms');
    Route::get('/farm-setup/requests', [QuotationController::class, 'index'])->name('quotations.index');
    Route::get('/farm-setup/{quotationRequest}', [QuotationController::class, 'show'])->name('quotations.show');
    Route::post('/farm-setup/{quotationRequest}/study-fee', [QuotationController::class, 'payStudyFee'])
        ->name('quotations.studyFee')->middleware('throttle:checkout');
    Route::get('/farm-setup/{quotationRequest}/proposal/v{version}.pdf', [QuotationController::class, 'proposal'])
        ->whereNumber('version')
        ->name('quotations.proposal');

    /*
     * The worker directory is for employers, enforced by WorkerProfilePolicy
     * rather than by being unlinked. Every view of a worker is logged, and the
     * phone number is released by WorkerContactGuard or not at all.
     */
    Route::get('/jobs/workers', [WorkerDirectoryController::class, 'index'])->name('jobs.workers.index');

    // A worker setting themselves up and seeing where their applications got.
    Route::get('/jobs/my-profile', [WorkerProfileController::class, 'edit'])->name('jobs.worker.edit');
    Route::post('/jobs/my-profile', [WorkerProfileController::class, 'save'])->name('jobs.worker.save')->middleware('throttle:forms');
    Route::get('/jobs/my-applications', [WorkerProfileController::class, 'dashboard'])->name('jobs.worker.dashboard');

    // An employer setting themselves up, posting work and reading applicants.
    Route::get('/jobs/hiring/profile', [EmployerController::class, 'edit'])->name('jobs.employer.edit');
    Route::post('/jobs/hiring/profile', [EmployerController::class, 'save'])->name('jobs.employer.save')->middleware('throttle:forms');
    Route::get('/jobs/hiring', [EmployerController::class, 'dashboard'])->name('jobs.employer.dashboard');
    Route::get('/jobs/hiring/post', [EmployerController::class, 'editListing'])->name('jobs.listings.create');
    Route::post('/jobs/hiring/post', [EmployerController::class, 'saveListing'])->name('jobs.listings.store')->middleware('throttle:forms');
    Route::get('/jobs/hiring/{listing}/edit', [EmployerController::class, 'editListing'])->name('jobs.listings.edit');
    Route::post('/jobs/hiring/{listing}/edit', [EmployerController::class, 'saveListing'])->name('jobs.listings.update')->middleware('throttle:forms');
    Route::post('/jobs/hiring/{listing}/close', [EmployerController::class, 'closeListing'])->name('jobs.listings.close')->middleware('throttle:forms');
    Route::get('/jobs/hiring/{listing}/applicants', [JobApplicationController::class, 'index'])->name('jobs.applicants');

    // Applying, withdrawing, and moving somebody along.
    Route::post('/jobs/{listing}/apply', [JobApplicationController::class, 'store'])->name('jobs.apply')->middleware('throttle:forms');
    Route::post('/jobs/applications/{application}/withdraw', [JobApplicationController::class, 'withdraw'])
        ->name('jobs.applications.withdraw')->middleware('throttle:forms');
    Route::post('/jobs/applications/{application}/status', [JobApplicationController::class, 'updateStatus'])
        ->name('jobs.applications.status')->middleware('throttle:forms');

    // Rating, which the policy allows only on a hire.
    Route::post('/jobs/applications/{application}/rate', [JobRatingController::class, 'store'])
        ->name('jobs.applications.rate')->middleware('throttle:forms');

    /*
     * The worker slug route comes last inside this group, so every literal
     * /jobs/... path above wins over it.
     */
    Route::get('/jobs/workers/{worker}', [WorkerDirectoryController::class, 'show'])->name('jobs.workers.show');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/{notification}', [NotificationController::class, 'read'])
        ->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.readAll');

    /*
     * One place for everything about somebody's own account.
     *
     * Before this, a seller changed their bank details in the seller panel,
     * their password at /profile, and their notification settings nowhere —
     * three addresses for "things about me", which is how people end up unable
     * to find the one that matters.
     */
    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('/account/notifications', [NotificationPreferenceController::class, 'update'])
        ->name('account.notifications.update');
    Route::delete('/account/sessions', [AccountController::class, 'destroyOtherSessions'])
        ->name('account.sessions.destroy');

    Route::get('/disputes', [DisputeController::class, 'index'])->name('disputes.index');
    Route::get('/orders/parts/{subOrder}/dispute', [DisputeController::class, 'create'])
        ->name('disputes.create');
    Route::post('/orders/parts/{subOrder}/dispute', [DisputeController::class, 'store'])
        ->name('disputes.store')->middleware('throttle:forms');
    Route::get('/disputes/{dispute}', [DisputeController::class, 'show'])->name('disputes.show');
    Route::post('/disputes/{dispute}/reply', [DisputeController::class, 'reply'])->name('disputes.reply')->middleware('throttle:forms');
});

require __DIR__.'/auth.php';

/*
 * Registered last on purpose: a slug route declared before /requests/new would
 * swallow it and every other literal path under /requests.
 */
Route::get('/requests/{buyerRequest}', [BuyerRequestController::class, 'show'])->name('requests.show');

/*
 * Registered last, like the requests slug route: declared earlier it would
 * swallow /academy/courses and every other literal path under /academy.
 */
Route::get('/academy/{course}', [AcademyController::class, 'show'])->name('academy.course');

/*
 * Same reason again: declared earlier this would swallow /mentors/join,
 * /mentors/shortlist and /mentors/pending.
 */
Route::get('/mentors/{mentor}', [MentorDirectoryController::class, 'show'])->name('mentors.show');

/*
 * A single consultation, open to the person who booked it — signed in, or a
 * guest holding the reference in their session. Registered here, after
 * /consultations, for the same reason as every other slug route in this file.
 */
/*
 * One job. Registered here, after /jobs, for the same reason as every other
 * slug route in this file: a literal path must win over a wildcard.
 */
Route::get('/jobs/{listing}', [JobBoardController::class, 'show'])->name('jobs.show');

Route::get('/consult/{consultation}', [ConsultationController::class, 'show'])->name('consultations.show');
Route::post('/consult/{consultation}/follow-up', [ConsultationController::class, 'followUp'])
    ->name('consultations.followup')->middleware('throttle:forms');
Route::get('/consult/{consultation}/report.pdf', [ConsultationController::class, 'reportPdf'])
    ->name('consultations.report');
