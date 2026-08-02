<?php

namespace App\Http\Middleware;

use App\Models\Passenger;
use Closure;
use Illuminate\Http\Request;

class EnsurePassenger
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->session()->get('passenger_id');
        $passenger = $id ? Passenger::find($id) : null;

        if (! $passenger) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // disponible para los controladores vía $request->passenger()
        $request->setUserResolver(fn () => $passenger);
        $request->attributes->set('passenger', $passenger);

        return $next($request);
    }
}
