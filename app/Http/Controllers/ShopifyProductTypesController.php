<?php

namespace App\Http\Controllers;

use App\Services\ShopifyProductCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopifyProductTypesController extends Controller
{
    public function __invoke(Request $request, ShopifyProductCatalog $catalog): JsonResponse
    {
        return response()->json(['product_types' => $catalog->productTypes($request->user())]);
    }
}
