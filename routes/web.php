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

// Face registration route for users without facial data after login
Route::get('/register/face', [RegisterController::class, 'showFaceRegistration'])->middleware('auth')->name('register.face');
Route::post('/register/face', [RegisterController::class, 'storeFaceRegistration'])->middleware('auth')->name('register.face.store');

use App\Http\Controllers\Auth\LoginController;

Route::get('/login', [LoginController::class, 'show'])->name('login.show');
Route::post('/login', [LoginController::class, 'login'])->name('login');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/step-up', [StepUpController::class, 'show'])->middleware('auth')->name('stepup.show');
Route::get('/step-up/attempt-image', [StepUpController::class, 'attemptImage'])->middleware('auth')->name('stepup.attempt_image');
Route::get('/step-up/liveness-verification-image', [StepUpController::class, 'livenessVerificationImage'])->middleware('auth')->name('stepup.liveness_verification_image');
Route::get('/step-up/error-image', [StepUpController::class, 'errorImage'])->middleware('auth')->name('stepup.error_image');
Route::post('/step-up/verify', [StepUpController::class, 'verify'])->middleware('auth')->name('stepup.verify');
Route::get('/dashboard', function () { return view('dashboard'); })->middleware('auth')->name('dashboard');

// Serve the current user's registered face image (thumbnail for UI)
Route::get('/user/registered-face', function (\Illuminate\Http\Request $request) {
    $user = $request->user();
    $face = $user?->userFace;
    $faceData = $face?->face_data ?: [];
    $livenessData = $face?->liveness_data ?: [];
    
    // Check for S3 object first (new format), then local path (legacy format)
    $s3Object = $faceData['s3_object'] ?? null;
    $localPath = $faceData['path'] ?? null;
    
    // For liveness users, check ReferenceImage in liveness_data
    if (!$s3Object && !$localPath && $face?->registration_method === 'liveness') {
        $livenessS3Object = $livenessData['ReferenceImage']['S3Object'] ?? null;
        if ($livenessS3Object && isset($livenessS3Object['Bucket']) && isset($livenessS3Object['Name'])) {
            $s3Object = $livenessS3Object;
        }
    }
    
    if ($s3Object && isset($s3Object['Bucket']) && isset($s3Object['Name'])) {
        // Serve from S3
        $s3Client = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ]);
        
        try {
            $result = $s3Client->getObject([
                'Bucket' => $s3Object['Bucket'],
                'Key' => $s3Object['Name'],
            ]);
            $body = $result->get('Body');
            $mime = $result->get('ContentType') ?: 'image/jpeg';
            
            return response($body, 200)->header('Content-Type', $mime)->header('Cache-Control', 'private, max-age=60');
        } catch (\Exception $e) {
            abort(404);
        }
    } elseif ($localPath && \Illuminate\Support\Facades\Storage::disk('local')->exists($localPath)) {
        // Serve from local storage (legacy format)
        $mime = \Illuminate\Support\Facades\Storage::disk('local')->mimeType($localPath) ?: 'image/jpeg';
        return response()->stream(function () use ($localPath) {
            $stream = \Illuminate\Support\Facades\Storage::disk('local')->readStream($localPath);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=60',
        ]);
    } else {
        abort(404);
    }
})->middleware('auth')->name('user.registered_face');

// Example special operation that requires privileged role + step-up verification
Route::post('/special-operation', function () {
    $user = auth()->user();
    // Use flash data first, then fall back to session
    $verification = session('verification') ?? session('stepup_verification_result');
    
    logger('special-operation POST', [
        'user_id' => $user->id,
        'verification_data' => $verification,
        'has_flash_verification' => session()->has('verification'),
        'has_session_verification' => session()->has('stepup_verification_result'),
    ]);
    
    return view('special_operation_result', [
        'user' => $user,
        'verification' => $verification,
    ]);
})->middleware(['auth', 'require.stepup'])->name('special.operation');

// GET route for special operation (after Face Liveness verification)
Route::get('/special-operation', function () {
    $user = auth()->user();
    // Use flash data first, then fall back to session
    $verification = session('verification') ?? session('stepup_verification_result');
    
    logger('special-operation GET', [
        'user_id' => $user->id,
        'verification_data' => $verification,
        'has_flash_verification' => session()->has('verification'),
        'has_session_verification' => session()->has('stepup_verification_result'),
    ]);
    
    return view('special_operation_result', [
        'user' => $user,
        'verification' => $verification,
    ]);
})->middleware(['auth', 'require.stepup'])->name('special.operation.get');

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

// Face Liveness API endpoints
use App\Http\Controllers\RekognitionController;

// Registration endpoint (no auth required)
Route::post('/rekognition/create-face-liveness-session-registration', [RekognitionController::class, 'createFaceLivenessSessionForRegistration']);
Route::post('/rekognition/complete-liveness-registration-guest', [RekognitionController::class, 'completeLivenessRegistrationGuest']);

// Test endpoint (temporary)
Route::get('/test-liveness-registration', function(App\Services\RekognitionService $rekognition, App\Services\StsService $sts) {
    try {
        $session = $rekognition->createFaceLivenessSession();
        $creds = $sts->getSessionToken(900);
        
        return response()->json([
            'success' => true,
            'sessionId' => $session['SessionId'],
            'credentials' => [
                'accessKeyId' => $creds['Credentials']['AccessKeyId'],
                'secretAccessKey' => $creds['Credentials']['SecretAccessKey'],
                'sessionToken' => $creds['Credentials']['SessionToken'],
            ],
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});

// Authenticated endpoints
Route::post('/rekognition/create-face-liveness-session', [RekognitionController::class, 'createFaceLivenessSession'])->middleware('auth');
Route::get('/rekognition/face-liveness-results/{sessionId}', [RekognitionController::class, 'getFaceLivenessResults'])->middleware('auth');
Route::post('/rekognition/complete-liveness-registration', [RekognitionController::class, 'completeLivenessRegistration'])->middleware('auth');
Route::post('/rekognition/complete-liveness-verification', [RekognitionController::class, 'completeLivenessVerification'])->middleware('auth');

Route::get('/debug-session', function (\Illuminate\Http\Request $r) {
    return response()->json([
        'session_id' => $r->cookie(config('session.cookie')),
        'stepup_verified_at' => $r->session()->get('stepup_verified_at'),
    ]);
})->middleware('auth');