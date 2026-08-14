<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\GodPanelProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    GodPanelProvider::class,
    RateLimitServiceProvider::class,
];
