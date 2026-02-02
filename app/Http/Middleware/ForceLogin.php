<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ForceLogin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Allow these public paths without authentication. Note: do NOT allow
        // the root path ('/') so unauthenticated users are redirected to login.
        $allow = [
            'login',
            'login/*',
            'register',
            'register/*',
            'favicon.ico',
            'robots.txt',
            'storage/*',
            'rekognition/*',
            'api/rekognition/*',
        ];

        foreach ($allow as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        if (!Auth::check()) {
            return redirect()->route('login.show');
        }

        return $next($request);
    }
}
