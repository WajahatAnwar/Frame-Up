<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class CatalogPageController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Catalog', [
            'catalogSyncUrl' => route('catalog.sync', absolute: false),
            'homeUrl' => route('home', absolute: false),
        ]);
    }
}
