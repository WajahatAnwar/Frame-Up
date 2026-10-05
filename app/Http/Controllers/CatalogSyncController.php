<?php

namespace App\Http\Controllers;

use App\Jobs\PullCatalogFrom3dFrames;
use Illuminate\Http\JsonResponse;

class CatalogSyncController extends Controller
{
    public function __invoke(): JsonResponse
    {
        PullCatalogFrom3dFrames::dispatch();

        return response()->json(['success' => true, 'message' => 'Catalog pull queued.'], 202);
    }
}
