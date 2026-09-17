<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** TEMPORARY diagnostic endpoint; remove with routes/api.php entry after testing. */
class TemporaryAuthCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $shop = $request->user();

        return response()->json([
            'ok' => true,
            'message' => 'Shopify session authenticated.',
            'shop' => $shop?->name,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
