<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Services\GoogleAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class AuthController extends Controller
{
    public function __construct(private readonly GoogleAuthService $googleAuthService) {}

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check() && Auth::user()->active) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function googleRedirect(Request $request): RedirectResponse
    {
        try {
            $url = $this->googleAuthService->getAuthorizationUrl($request);
            return redirect()->away($url);
        } catch (Throwable $e) {
            return redirect()->route('login')->withErrors(['error' => 'No se pudo iniciar el flujo de autenticación: ' . $e->getMessage()]);
        }
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        try {
            $user = $this->googleAuthService->handleCallback($request);

            // Regenerar sesión para prevenir fijación de sesión
            $request->session()->regenerate();

            Auth::login($user);

            return redirect()->intended(route('dashboard'));
        } catch (Throwable $e) {
            return redirect()->route('login')->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        $userId = Auth::id();
        AuditEvent::log('auth.logout', $userId, null, [
            'ip' => $request->ip(),
        ]);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Sesión cerrada correctamente.');
    }
}
