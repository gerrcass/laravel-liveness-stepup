<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class RequireStepUp
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login');
        }

        // If user has role privileged, require face verification
        if ($user->hasRole('privileged')) {
            $timeout = env('STEPUP_TIMEOUT', 60); // seconds

            // 1) allow if session has recent verification
            $verifiedAt = $request->session()->get('stepup_verified_at');
            if ($verifiedAt) {
                try {
                    $then = Carbon::parse($verifiedAt);
                    if (Carbon::now()->diffInSeconds($then) <= (int) $timeout) {
                        return $next($request);
                    }
                } catch (\Exception $e) {
                    // ignore parse errors
                }
            }

            // Only trust the per-session marker for allowing step-up within the session.
            // Store the intended request info so after step-up we can replay or
            // return the user to the original action. We capture method and
            // non-sensitive input (excluding _token and passwords).
            $intended = [
                'url' => url()->full(),
                'method' => $request->method(),
                'input' => $request->except(['_token', 'password', 'password_confirmation']),
            ];
            $request->session()->put('stepup_intended', $intended);

            return redirect()->route('stepup.show');
        }

        return $next($request);
    }
}
