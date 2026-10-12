<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StorefrontProductPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontProductPreviewController extends Controller
{
    public function __invoke(Request $request, StorefrontProductPreview $preview): JsonResponse
    {
        $timestamp = $request->query('timestamp');
        abort_unless(is_string($timestamp) && ctype_digit($timestamp) && abs(now()->timestamp - (int) $timestamp) <= 300, 401);
        $input = $request->validate([
            'product_id' => ['required', 'string', 'regex:/^(?:gid:\/\/shopify\/Product\/)?[1-9][0-9]*$/'],
            'product_type' => ['required', 'string', 'max:255'],
        ]);
        // Resolve the signed shop explicitly; never fall back to an admin browser session.
        $shop = User::query()->where('name', $request->query('shop'))->first();
        abort_unless($shop, 401);
        $data = $preview->forProduct($shop, $input['product_id'], $input['product_type']);

        return response()->json(['enabled' => $data !== null, 'data' => $data])
            ->header('Cache-Control', 'no-store');
    }
}
