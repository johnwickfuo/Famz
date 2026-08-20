<?php

return [
    /*
     * Routes the front end never asks for by name.
     *
     * Ziggy inlines the route list into the HTML of every page, and it was
     * 24 KB of it — a third of the document, before any content. Most of that
     * was the Filament panels, which are Blade-rendered and never reached
     * through Vue's route() helper, plus webhook and signed-link endpoints
     * nothing on the client side can call.
     *
     * On a good connection this is invisible. On the mobile data this platform
     * is built for it is a third of the first response, on every page, forever.
     *
     * `except` rather than `only` on purpose: a new public route should work
     * without anybody remembering to add it here. The failure mode of getting
     * this wrong is a route() call throwing in the browser, so the list is
     * confined to prefixes the client demonstrably cannot use.
     */
    'except' => [
        // Filament panels: server-rendered, and their own JS resolves its own
        // URLs without Ziggy.
        'filament.*',

        // Livewire's internal endpoints.
        'livewire.*',

        // Payment provider callbacks. Called by Paystack and Flutterwave, not
        // by anything in a browser.
        'webhooks.*',

        // Laravel's own debugging and storage plumbing.
        'debugbar.*',
        'horizon.*',
        'ignition.*',
        'sanctum.*',
        'storage.local',
    ],
];
