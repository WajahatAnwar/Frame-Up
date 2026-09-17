<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Osiset\ShopifyApp\Exceptions\HttpException as ShopifyHttpException;

/** TEMPORARY: normalizes package auth failures for the diagnostic JSON route. */
class TemporaryAuthJson
{
    public function handle(Request $request, Closure $next)
    {
        try {
            return $next($request);
        } catch (ShopifyHttpException $exception) {
            $status = $exception->getCode() === 400 ? 401 : $exception->getCode();

            return response()->json([
                'error' => 'Shopify session token is missing or invalid.',
            ], in_array($status, [401, 403], true) ? $status : 401);
        }
    }
}
