<?php

use App\Providers\AppServiceProvider;
use App\Providers\BrandingServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\MentorPanelProvider;
use App\Providers\Filament\SellerPanelProvider;
use App\Providers\MailServiceProvider;

return [
    AppServiceProvider::class,
    BrandingServiceProvider::class,
    MailServiceProvider::class,
    AdminPanelProvider::class,
    SellerPanelProvider::class,
    MentorPanelProvider::class,
];
