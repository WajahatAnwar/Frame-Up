<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Home', [
            'shop' => [
                'domain' => $request->user()?->name,
            ],
            'embeddedContext' => [
                'shop' => $request->query('shop'),
                'host' => $request->query('host'),
            ],
            'temporaryAuthCheckUrl' => route('temporary.auth.check', absolute: false),
        ]);
    }
}
