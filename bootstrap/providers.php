<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\AuthServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    // After AuthServiceProvider on purpose: it defers to the catalogue's gate
    // wherever one already exists. See AuditServiceProvider.
    AuditServiceProvider::class,
];
