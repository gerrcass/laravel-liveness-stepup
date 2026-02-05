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
        
        // Create session WITH S3 output so images persist after session completion
        // This is required because the frontend component may consume results before backend can retrieve them
        $session = $rekognition->createFaceLivenessSession(null, [], true); // true = use S3

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

    /** Complete Face Liveness registration for guest users (no auth required)
     * 
     * IMPORTANT: This endpoint must be called BEFORE the frontend callback completes.
     * The frontend should call this endpoint and wait for response before returning from callback.
     */
    public function completeLivenessRegistrationGuest(Request $request, RekognitionService $rekognition)
    {
        $request->validate([
            'sessionId' => 'required|string',
        ]);

        $sessionId = $request->input('sessionId');

        try {
            // Get liveness session results FIRST (before frontend consumes them)
            $livenessResult = $rekognition->getFaceLivenessSessionResults($sessionId);
            
            // Clean liveness result for session storage (exclude all binary data)
            $livenessResultForStorage = $this->cleanLivenessResultForStorage($livenessResult);
            
            // Store session ID and results in session for later use during registration
            $request->session()->put('pending_liveness_session_id', $sessionId);
            $request->session()->put('pending_liveness_result', $livenessResultForStorage);

            return response()->json([
                'success' => true,
                'verified' => true,
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

        // Create session WITH S3 output so images persist after session completion
        // This is required because the frontend component may consume results before backend can retrieve them
        // Also needed to avoid browser connecting directly to AWS (network/firewall issues)
        $session = $rekognition->createFaceLivenessSession(null, [], true); // true = use S3

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
            logger('Face Liveness verification started', ['sessionId' => $sessionId, 'userId' => $user->id]);
            $result = $rekognition->verifyFaceWithLiveness($sessionId, (string) $user->id);
            
            $searchResult = $result['searchResult'];
            $livenessResult = $result['livenessResult'];
            $livenessConfidence = $result['livenessConfidence'];
            
            logger('Verification results', [
                'livenessConfidence' => $livenessConfidence,
                'faceMatchesCount' => count($searchResult['FaceMatches'] ?? [])
            ]);

            $matches = $searchResult['FaceMatches'] ?? [];
            $success = false;
            $faceConfidence = 0;
            $matchedExternalId = null;
            $allMatchesDetails = [];

            logger('FaceMatches count', ['count' => count($matches)]);

            // Iterate through ALL matches to find one that belongs to the current user
            foreach ($matches as $index => $match) {
                $externalId = $match['Face']['ExternalImageId'] ?? null;
                $similarity = $match['Similarity'] ?? 0;

                logger("Match $index details", [
                    'externalId' => $externalId,
                    'similarity' => $similarity,
                    'userId' => (string) $user->id,
                    'isCurrentUser' => $externalId == (string) $user->id
                ]);

                // Check if this match belongs to the current user
                if ($externalId == (string) $user->id) {
                    // Found a match for the current user
                    $faceConfidence = $similarity;
                    $matchedExternalId = $externalId;

                    if ($faceConfidence >= 60.0 && $livenessConfidence >= 60.0) {
                        $success = true;

                        // Mark session as verified
                        $request->session()->put('stepup_verified_at', now()->toDateTimeString());

                        // Store verification image S3 reference for UI
                        $livenessS3Object = $livenessResult['ReferenceImage']['S3Object'] ?? null;
                        if ($livenessS3Object) {
                            $request->session()->put('stepup_liveness_verification_image', $livenessS3Object);
                        }

                        // Store verification result for success page
                        $verificationData = [
                            'method' => 'liveness',
                            'liveness_confidence' => $livenessConfidence,
                            'face_confidence' => $faceConfidence,
                            'face_id' => $match['Face']['FaceId'] ?? null,
                            'external_id' => $matchedExternalId,
                            'user_id' => (string) $user->id,
                            'checked_at' => now()->toDateTimeString(),
                            'rekognition_response' => $searchResult,
                            'liveness_result' => $livenessResult,
                        ];
                        $request->session()->put('stepup_verification_result', $verificationData);

                        logger('Verification SUCCESS for current user', [
                            'userId' => $user->id,
                            'faceConfidence' => $faceConfidence,
                            'livenessConfidence' => $livenessConfidence
                        ]);

                        break;
                    }
                }

                $allMatchesDetails[] = [
                    'externalId' => $externalId,
                    'similarity' => $similarity,
                    'isCurrentUser' => $externalId == (string) $user->id
                ];
            }

            if (!$success) {
                logger('Verification FAILED - no matching face found for current user', [
                    'userId' => $user->id,
                    'allMatches' => $allMatchesDetails,
                    'livenessConfidence' => $livenessConfidence
                ]);
            }

            return response()->json([
                'success' => $success,
                'livenessConfidence' => $livenessConfidence,
                'faceConfidence' => $faceConfidence,
                'matchedExternalId' => $matchedExternalId,
                'matchesCount' => count($matches),
                'allMatches' => $allMatchesDetails,
                'verified' => $success,
            ]);
        } catch (\Exception $e) {
            logger('Face Liveness verification ERROR', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500)
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
