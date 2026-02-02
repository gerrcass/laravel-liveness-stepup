<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RekognitionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Face Liveness API endpoints for registration (no auth required, but need session)
Route::middleware(['web'])->withoutMiddleware(['auth'])->group(function () {
    Route::post('/rekognition/create-face-liveness-session-registration', [RekognitionController::class, 'createFaceLivenessSessionForRegistration']);
    Route::post('/rekognition/complete-liveness-registration-guest', [RekognitionController::class, 'completeLivenessRegistrationGuest']);
});

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
