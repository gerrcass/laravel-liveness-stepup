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
    /** Creates an AWS Face Liveness session for registration (no auth required) */
    public function createFaceLivenessSessionForRegistration(Request $request, RekognitionService $rekognition, StsService $sts)
    {
        $purpose = 'registration';

        $session = $rekognition->createFaceLivenessSession();

        // Provide temporary credentials for client to call Rekognition directly
        $creds = $sts->getSessionToken(900); // 15 minutes

        return response()->json([
            'sessionId' => $session['SessionId'],
            'purpose' => $purpose,
            'credentials' => [
                'accessKeyId' => $creds['Credentials']['AccessKeyId'],
                'secretAccessKey' => $creds['Credentials']['SecretAccessKey'],
                'sessionToken' => $creds['Credentials']['SessionToken'],
            ],
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ]);
    }

    /** Complete Face Liveness registration for guest users (no auth required) */
    public function completeLivenessRegistrationGuest(Request $request, RekognitionService $rekognition)
    {
        $request->validate([
            'sessionId' => 'required|string',
        ]);

        $sessionId = $request->input('sessionId');

        try {
            // Just get the liveness session results to validate the session
            $livenessResult = $rekognition->getFaceLivenessSessionResults($sessionId);
            
            // Clean liveness result for session storage (exclude all binary data)
            $livenessResultForStorage = $this->cleanLivenessResultForStorage($livenessResult);
            
            // Store session ID in session for later use during actual registration
            $request->session()->put('pending_liveness_session_id', $sessionId);
            $request->session()->put('pending_liveness_result', $livenessResultForStorage);

            return response()->json([
                'success' => true,
                'sessionId' => $sessionId,
                'confidence' => $livenessResult['Confidence'] ?? 0,
                'message' => 'Face Liveness session completed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Clean Face Liveness results by removing binary data for safe JSON storage
     */
    private function cleanLivenessResultForStorage(array $livenessResult): array
    {
        $cleaned = $livenessResult;
        
        // Handle ReferenceImage binary data
        if (isset($cleaned['ReferenceImage']['Bytes'])) {
            $bytesLength = strlen($cleaned['ReferenceImage']['Bytes']);
            unset($cleaned['ReferenceImage']['Bytes']);
            $cleaned['ReferenceImage']['HasBytes'] = true;
            $cleaned['ReferenceImage']['BytesLength'] = $bytesLength;
        }
        
        // Handle AuditImages binary data
        if (isset($cleaned['AuditImages']) && is_array($cleaned['AuditImages'])) {
            foreach ($cleaned['AuditImages'] as $index => $auditImage) {
                if (isset($auditImage['Bytes'])) {
                    $bytesLength = strlen($auditImage['Bytes']);
                    unset($cleaned['AuditImages'][$index]['Bytes']);
                    $cleaned['AuditImages'][$index]['HasBytes'] = true;
                    $cleaned['AuditImages'][$index]['BytesLength'] = $bytesLength;
                }
            }
        }
        
        return $cleaned;
    }

    public function createFaceLivenessSession(Request $request, RekognitionService $rekognition, StsService $sts)
    {
        $user = $request->user();
        $purpose = $request->input('purpose', 'verification'); // 'registration' or 'verification'

        $session = $rekognition->createFaceLivenessSession((string) ($user->id ?? null));

        // Provide temporary credentials for client to call Rekognition directly
        $creds = $sts->getSessionToken(900); // 15 minutes

        return response()->json([
            'sessionId' => $session['SessionId'],
            'purpose' => $purpose,
            'credentials' => [
                'accessKeyId' => $creds['Credentials']['AccessKeyId'],
                'secretAccessKey' => $creds['Credentials']['SecretAccessKey'],
                'sessionToken' => $creds['Credentials']['SessionToken'],
            ],
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ]);
    }

    /** Returns results for an AWS Face Liveness session */
    public function getFaceLivenessResults(Request $request, RekognitionService $rekognition, $sessionId)
    {
        $result = $rekognition->getFaceLivenessSessionResults($sessionId);
        return response()->json($result);
    }

    /** Complete Face Liveness registration - index face from liveness session */
    public function completeLivenessRegistration(Request $request, RekognitionService $rekognition)
    {
        $request->validate([
            'sessionId' => 'required|string',
        ]);

        $user = $request->user();
        $sessionId = $request->input('sessionId');

        try {
            $result = $rekognition->indexFaceFromLivenessSession($sessionId, (string) $user->id);
            
            // Update user face record
            $userFace = $user->userFace;
            if (!$userFace) {
                $userFace = new \App\Models\UserFace();
                $userFace->user_id = $user->id;
            }
            
            $userFace->registration_method = 'liveness';
            $userFace->face_data = $result['indexResult'];
            $userFace->liveness_data = $result['livenessResult'];
            $userFace->verification_status = 'verified';
            $userFace->last_verified_at = now();
            $userFace->save();

            return response()->json([
                'success' => true,
                'sessionId' => $sessionId,
                'confidence' => $result['livenessResult']['Confidence'] ?? 0,
                'faceRecords' => $result['indexResult']['FaceRecords'] ?? [],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /** Complete Face Liveness verification - verify face from liveness session */
    public function completeLivenessVerification(Request $request, RekognitionService $rekognition)
    {
        $request->validate([
            'sessionId' => 'required|string',
        ]);

        $user = $request->user();
        $sessionId = $request->input('sessionId');

        try {
            $result = $rekognition->verifyFaceWithLiveness($sessionId, (string) $user->id);
            
            $searchResult = $result['searchResult'];
            $livenessResult = $result['livenessResult'];
            $livenessConfidence = $result['livenessConfidence'];

            $matches = $searchResult['FaceMatches'] ?? [];
            $success = false;
            $faceConfidence = 0;

            if (count($matches) > 0) {
                $best = $matches[0];
                $externalId = $best['Face']['ExternalImageId'] ?? null;
                $faceConfidence = $best['Similarity'] ?? 0;

                if ($externalId == (string) $user->id && $faceConfidence >= 85.0 && $livenessConfidence >= 85.0) {
                    $success = true;
                    
                    // Mark session as verified
                    $request->session()->put('stepup_verified_at', now()->toDateTimeString());
                    
                    // Update user face record
                    $userFace = $user->userFace;
                    if ($userFace) {
                        $userFace->verification_status = 'verified';
                        $userFace->last_verified_at = now();
                        $userFace->save();
                    }
                }
            }

            return response()->json([
                'success' => $success,
                'livenessConfidence' => $livenessConfidence,
                'faceConfidence' => $faceConfidence,
                'matches' => count($matches),
                'verified' => $success,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
