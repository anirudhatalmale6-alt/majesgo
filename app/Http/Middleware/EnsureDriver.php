<?php

namespace App\Http\Middleware;

use App\Models\Driver;
use Closure;
use Illuminate\Http\Request;

class EnsureDriver
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->session()->get('driver_id');
        $driver = $id ? Driver::find($id) : null;

        if (! $driver) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $request->setUserResolver(fn () => $driver);
        $request->attributes->set('driver', $driver);

        return $next($request);
    }
}
