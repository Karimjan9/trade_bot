<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ServiceToken
{
    public function handle(Request $request, Closure $next, string $scope)
    {
        $expected = config('trading.'.$scope.'_token');
        abort_unless(is_string($expected) && strlen($expected) >= 32 && hash_equals($expected, $request->bearerToken() ?? ''), 401, 'Invalid service token.');
        abort_if(strlen($request->getContent()) > 262144, 413, 'Payload too large.');

        return $next($request);
    }
}
