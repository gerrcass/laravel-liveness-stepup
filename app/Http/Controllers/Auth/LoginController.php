<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (Auth::attempt($data)) {
            $request->session()->regenerate();
            
            $user = Auth::user();
            $hasFaceData = $user->userFace !== null;
            
            if (!$hasFaceData) {
                // User has no facial data - set flag for popup
                // Modal will be shown on dashboard via app.blade.php
                return redirect()->route('dashboard');
            }
            
            return redirect()->intended('/dashboard');
        }

        return back()->withErrors(['email' => 'Credenciales inválidas']);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    }
}
