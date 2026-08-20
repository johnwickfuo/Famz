<?php

namespace App\Http\Controllers;

use App\Mail\ContactMessageMail;
use App\Services\Branding\BrandingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Somebody writing to the company.
 *
 * Deliberately open to guests. The people most likely to need to write are the
 * ones who could not work out how to do something, and putting a registration
 * wall in front of the contact form means never hearing from them.
 *
 * Rate-limited by address rather than by session, because a contact form is the
 * single most reliably abused endpoint on any website and a session token costs
 * nothing to discard.
 */
class ContactController extends Controller
{
    private const HOURLY_LIMIT = 5;

    public function __construct(private readonly BrandingService $branding) {}

    public function create(): Response
    {
        return Inertia::render('Pages/Contact', [
            'contact' => [
                'email' => $this->branding->email(),
                'phone' => $this->branding->phone(),
                'whatsapp' => $this->branding->whatsapp(),
                'address' => $this->branding->address(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        $key = 'contact:'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::HOURLY_LIMIT)) {
            return back()->withErrors([
                'message' => __('You have sent several messages already. Please wait a little before sending another.'),
            ]);
        }

        RateLimiter::hit($key, 3600);

        $to = $this->branding->email();

        if (blank($to)) {
            /*
             * No company address configured yet. Saying so is better than
             * silently dropping somebody's message into a queue addressed to
             * nobody — which is what Mail::to(null) amounts to.
             */
            return back()->withErrors([
                'message' => __('We cannot receive messages just now. Please try the phone number instead.'),
            ]);
        }

        Mail::to($to)->send(new ContactMessageMail(
            name: $validated['name'],
            fromEmail: $validated['email'],
            messageSubject: $validated['subject'],
            body: $validated['message'],
        ));

        return back()->with('success', __('Thank you — we have your message and will reply to :email.', [
            'email' => $validated['email'],
        ]));
    }
}
