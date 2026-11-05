<?php

use App\Providers\AppServiceProvider;
use App\Providers\CommerceServiceProvider;
use App\Providers\ContentServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    ContentServiceProvider::class,
    CommerceServiceProvider::class,
    FortifyServiceProvider::class,
];
