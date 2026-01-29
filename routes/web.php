<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\StepUpController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/register', [RegisterController::class, 'show'])->name('register.show');
Route::post('/register', [RegisterController::class, 'register'])->name('register');

use App\Http\Controllers\Auth\LoginController;

Route::get('/login', [LoginController::class, 'show'])->name('login.show');
Route::post('/login', [LoginController::class, 'login'])->name('login');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/step-up', [StepUpController::class, 'show'])->middleware('auth')->name('stepup.show');
Route::get('/step-up/attempt-image', [StepUpController::class, 'attemptImage'])->middleware('auth')->name('stepup.attempt_image');
Route::post('/step-up/verify', [StepUpController::class, 'verify'])->middleware('auth')->name('stepup.verify');
Route::get('/dashboard', function () { return view('dashboard'); })->middleware('auth')->name('dashboard');

// Serve the current user's registered face image (thumbnail for UI)
Route::get('/user/registered-face', function (\Illuminate\Http\Request $request) {
    $user = $request->user();
    $face = $user?->userFace;
    $path = $face?->face_data['path'] ?? null;
    if (!$path || !\Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
        abort(404);
    }
    $mime = \Illuminate\Support\Facades\Storage::disk('local')->mimeType($path) ?: 'image/jpeg';
    return response()->stream(function () use ($path) {
        $stream = \Illuminate\Support\Facades\Storage::disk('local')->readStream($path);
        fpassthru($stream);
        fclose($stream);
    }, 200, [
        'Content-Type' => $mime,
        'Cache-Control' => 'private, max-age=60',
    ]);
})->middleware('auth')->name('user.registered_face');

// Example special operation that requires privileged role + step-up verification
Route::post('/special-operation', function () {
    $user = auth()->user();
    $verification = session('stepup_verification_result');
    return view('special_operation_result', [
        'user' => $user,
        'verification' => $verification,
    ]);
})->middleware(['auth', 'require.stepup'])->name('special.operation');

// Endpoint to mark step-up as verified (e.g. from a simulated or client-side flow).
// The main step-up flow uses SearchFacesByImage via StepUpController::verify.
use Illuminate\Support\Facades\Route as RouteFacade;
RouteFacade::post('/rekognition/mark-stepup-verified', function (\Illuminate\Http\Request $request) {
    $data = $request->validate([
        'user_id' => 'required|integer',
        'verification' => 'required|array',
    ]);

    $user = App\Models\User::find($data['user_id']);
    if (!$user) {
        return redirect()->route('dashboard')->withErrors(['verification' => 'Usuario no encontrado.']);
    }

    $verification = $data['verification'];
    // Basic acceptance logic: client indicates success. For production, verify server-side (e.g. Rekognition SearchFacesByImage).
    if (!empty($verification['success'])) {
        $face = $user->userFace;
        if ($face) {
            $face->verification_status = 'verified';
            $face->save();
        }
        // mark session so subsequent operations within timeout are allowed
        $request->session()->put('stepup_verified_at', \Carbon\Carbon::now()->toDateTimeString());
        return redirect()->route('dashboard')->with('status', 'Verificación (simulada) completada. Ya puedes acceder a la operación protegida.');
    }

    return redirect()->route('dashboard')->withErrors(['verification' => 'Verificación simulada fallida.']);
});

Route::get('/simulate-verify', function () {
    return view('simulate_verify');
})->middleware('auth')->name('simulate.verify');

// Debug: list users (development only)
Route::get('/debug-users', function () {
    return response()->json(App\Models\User::all()->map->only(['id','email','name']));
});

Route::get('/debug-check-password', function (\Illuminate\Http\Request $request) {
    $email = $request->query('email');
    $password = $request->query('password');
    $user = App\Models\User::where('email', $email)->first();
    if (!$user) return response()->json(['ok' => false, 'reason' => 'no_user']);
    return response()->json(['ok' => \Illuminate\Support\Facades\Hash::check($password, $user->password)]);
});

// Debug: login as user id (development only)
Route::get('/debug-login/{id}', function ($id) {
    $user = App\Models\User::find($id);
    if (!$user) return redirect('/login');
    Auth::login($user);
    return redirect('/dashboard');
});

Route::get('/debug-create-face/{id}', function ($id) {
    $user = App\Models\User::find($id);
    if (!$user) return response()->json(['ok'=>false,'reason'=>'no_user']);
    $face = $user->userFace ?: new App\Models\UserFace();
    $face->user_id = $user->id;
    $face->face_data = ['debug'=>'created'];
    $face->verification_status = 'pending';
    $face->save();
    return response()->json(['ok'=>true,'face'=>$face]);
});

Route::get('/debug-face/{id}', function ($id) {
    $user = App\Models\User::find($id);
    if (!$user) return response()->json(['ok'=>false,'reason'=>'no_user']);
    return response()->json($user->userFace);
});

// Optional: AWS Face Liveness API (not used by the main step-up flow, which uses SearchFacesByImage).
use App\Http\Controllers\RekognitionController;
Route::post('/rekognition/create-face-liveness-session', [RekognitionController::class, 'createFaceLivenessSession'])->middleware('auth');
Route::get('/rekognition/face-liveness-results/{sessionId}', [RekognitionController::class, 'getFaceLivenessResults'])->middleware('auth');

Route::get('/debug-session', function (\Illuminate\Http\Request $r) {
    return response()->json([
        'session_id' => $r->cookie(config('session.cookie')),
        'stepup_verified_at' => $r->session()->get('stepup_verified_at'),
    ]);
})->middleware('auth');