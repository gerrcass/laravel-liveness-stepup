<?php

namespace App\Http\Controllers;

use App\Services\RekognitionService;
use App\Services\StsService;
use Illuminate\Http\Request;

class RekognitionController extends Controller
{
    public function createFaceLivenessSession(Request $request, RekognitionService $rekognition, StsService $sts)
    {
        $user = $request->user();

        // Create server-side Face Liveness session
        $session = $rekognition->createFaceLivenessSession((string) ($user->id ?? null));

        // Provide temporary credentials for client to call Rekognition directly (optional)
        $creds = $sts->getSessionToken(900); // 15 minutes

        return response()->json([
            'session' => $session,
            'credentials' => $creds['Credentials'] ?? $creds,
        ]);
    }

    public function getFaceLivenessResults(Request $request, RekognitionService $rekognition, $sessionId)
    {
        $result = $rekognition->getFaceLivenessSessionResults($sessionId);
        return response()->json($result);
    }
}
