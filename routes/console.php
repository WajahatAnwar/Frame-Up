<?php

use App\Jobs\PullCatalogFrom3dFrames;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('catalog:pull', function (): void {
    PullCatalogFrom3dFrames::dispatch();

    $this->info('Catalog pull queued.');
})->purpose('Queue a catalog pull from 3d-frames');

Schedule::command('catalog:pull')->everyThreeHours()->withoutOverlapping();
