<?php

use App\Providers\ApiDocumentationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\GroupPackageServiceProvider;
use App\Providers\NostrAuthServiceProvider;

return [
    AppServiceProvider::class,
    // App\Providers\FolioServiceProvider::class, // Disabled - laravel/folio package removed during Laravel 12 upgrade
    NostrAuthServiceProvider::class,
    GroupPackageServiceProvider::class,
    ApiDocumentationServiceProvider::class,
];
