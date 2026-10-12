<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StorefrontPreviewAssetController extends Controller
{
    public function __invoke(string $filename): BinaryFileResponse
    {
        $types = [
            'product-preview.js' => 'application/javascript',
            'preview-runtime.js' => 'application/javascript',
            'preview-model.js' => 'application/javascript',
            'preview-webgl.js' => 'application/javascript',
            'product-preview.css' => 'text/css',
            'lebombo_1k.hdr' => 'application/octet-stream',
            'concrete-light.jpg' => 'image/jpeg',
            'room-living.jpg' => 'image/jpeg',
        ];
        abort_unless(isset($types[$filename]), 404);

        return response()->file(public_path('frame-up-preview/'.$filename), [
            'Content-Type' => $types[$filename],
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
