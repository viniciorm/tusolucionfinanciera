<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAuthorized
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }
            return redirect()->route('login');
        }

        $user = Auth::user();
        if (!$user->active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Cuenta inactiva o no autorizada.'], 403);
            }
            return redirect()->route('login')->withErrors(['error' => 'Tu cuenta ha sido desactivada o no está autorizada.']);
        }

        return $next($request);
    }
}
