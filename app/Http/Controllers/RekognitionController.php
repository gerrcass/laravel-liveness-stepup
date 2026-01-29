<?php

namespace App\Http\Controllers;

use App\Services\RekognitionService;
use App\Services\StsService;
use Illuminate\Http\Request;

/**
 * Exposes Rekognition-related HTTP endpoints.
 * The main step-up flow uses face verification via SearchFacesByImage (see StepUpController).
 * The methods below wrap the optional AWS Face Liveness API (CreateFaceLivenessSession / GetFaceLivenessSessionResults).
 */
class RekognitionController extends Controller
{
    /** Creates an AWS Face Liveness session (optional flow; main step-up uses SearchFacesByImage). */
    public function createFaceLivenessSession(Request $request, RekognitionService $rekognition, StsService $sts)
    {
        $user = $request->user();

        $session = $rekognition->createFaceLivenessSession((string) ($user->id ?? null));

        // Provide temporary credentials for client to call Rekognition directly (optional)
        $creds = $sts->getSessionToken(900); // 15 minutes

        return response()->json([
            'session' => $session,
            'credentials' => $creds['Credentials'] ?? $creds,
        ]);
    }

    /** Returns results for an AWS Face Liveness session (optional flow). */
    public function getFaceLivenessResults(Request $request, RekognitionService $rekognition, $sessionId)
    {
        $result = $rekognition->getFaceLivenessSessionResults($sessionId);
        return response()->json($result);
    }
}
