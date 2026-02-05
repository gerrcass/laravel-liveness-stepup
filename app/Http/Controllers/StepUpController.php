<?php

namespace App\Http\Controllers;

use App\Services\RekognitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StepUpController extends Controller
{
    public function show()
    {
        $user = Auth::user();
        $userFace = $user->userFace;
        $registrationMethod = $userFace ? $userFace->registration_method : 'image';
        
        return view('auth.stepup', compact('registrationMethod'));
    }

    /**
     * Serve the image from a failed step-up attempt (for display in error UI).
     */
    public function errorImage(Request $request): StreamedResponse|\Illuminate\Http\Response
    {
        $path = $request->session()->get('stepup_error_image_path');

        if (!$path || !Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $mime = Storage::disk('local')->mimeType($path) ?: 'image/jpeg';
        return response()->stream(function () use ($path) {
            $stream = Storage::disk('local')->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    /**
     * Serve the image from the last/current step-up attempt (for display in UI).
     * Uses path stored in session.
     */
    public function attemptImage(Request $request): StreamedResponse|\Illuminate\Http\Response
    {
        $path = $request->session()->get('stepup_error_image_path')
            ?? $request->session()->get('stepup_verification_image_path');

        if (!$path || !Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $mime = Storage::disk('local')->mimeType($path) ?: 'image/jpeg';
        return response()->stream(function () use ($path) {
            $stream = Storage::disk('local')->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    /**
     * Serve the Face Liveness verification reference image from S3.
     * For error UI, always use the error reference image (most recent attempt).
     * For success UI, use the success reference image.
     */
    public function livenessVerificationImage(Request $request): StreamedResponse|\Illuminate\Http\Response
    {
        // Check if this is for error display - always use error reference image
        $s3Object = $request->session()->get('stepup_error_reference_image');
        
        // If no error image, fall back to success image
        if (!$s3Object) {
            $s3Object = $request->session()->get('stepup_liveness_verification_image');
        }

        if (!$s3Object || !isset($s3Object['Bucket']) || !isset($s3Object['Name'])) {
            abort(404);
        }

        try {
            $s3Client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            ]);

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
    }

    public function verify(Request $request, RekognitionService $rekognition)
    {
        $user = Auth::user();
        $userFace = $user->userFace;
        $registrationMethod = $userFace ? $userFace->registration_method : 'image';

        if ($registrationMethod === 'liveness') {
            // Face Liveness verification
            $request->validate([
                'liveness_session_id' => 'required|string',
            ]);

            $sessionId = $request->input('liveness_session_id');

            try {
                $result = $rekognition->verifyFaceWithLiveness($sessionId, (string) $user->id);
                
                $searchResult = $result['searchResult'];
                $livenessResult = $result['livenessResult'];
                $livenessConfidence = $result['livenessConfidence'];

                $matches = $searchResult['FaceMatches'] ?? [];
                if (count($matches) > 0) {
                    $best = $matches[0];
                    $externalId = $best['Face']['ExternalImageId'] ?? null;
                    $faceConfidence = $best['Similarity'] ?? 0;

                    if ($externalId == (string) $user->id && $faceConfidence >= 60.0 && $livenessConfidence >= 60.0) {
                        // Mark verified
                        $userFace->verification_status = 'verified';
                        $userFace->last_verified_at = now();
                        $userFace->save();

                        $request->session()->put('stepup_verified_at', now()->toDateTimeString());

                        // Generate verification data FIRST
                        $verificationData = [
                            'method' => 'liveness',
                            'liveness_confidence' => $livenessConfidence,
                            'face_confidence' => $faceConfidence,
                            'face_id' => $best['Face']['FaceId'] ?? null,
                            'external_id' => $externalId,
                            'user_id' => (string) $user->id,
                            'checked_at' => now()->toDateTimeString(),
                            'rekognition_response' => $searchResult,
                            'liveness_result' => $livenessResult,
                        ];
                        
                        // Store in session
                        $request->session()->put('stepup_verification_result', $verificationData);

                        // Redirect to intended URL
                        $intended = $request->session()->pull('stepup_intended');
                        if ($intended && isset($intended['method']) && strtoupper($intended['method']) === 'POST') {
                            $targetUrl = $intended['url'];
                            $inputs = $intended['input'] ?? [];
                            return view('stepup_post_redirect', compact('targetUrl', 'inputs', 'verificationData'));
                        }

                        $target = $intended['url'] ?? route('dashboard');
                        
                        return redirect($target)->with('status', 'Step-up verification passed with Face Liveness');
                    }

                    // Liveness passed but face match failed
                    $request->session()->flash('stepup_error_message', 'Face Liveness passed but no matching face found for your registered image');
                    $request->session()->flash('stepup_error_details', [
                        'liveness_result' => $livenessResult,
                        'search_result' => $searchResult,
                        'Message' => 'Face matched but similarity was below threshold or did not match your user ID',
                    ]);
                    return back()->withErrors(['liveness' => 'Face verification failed - no matching face found']);
                }

                // No face match found
                $request->session()->flash('stepup_error_message', 'Face Liveness verification completed but no face matched your registered image');
                $request->session()->flash('stepup_error_details', [
                    'liveness_result' => $livenessResult,
                    'search_result' => [],
                    'Message' => 'No matching face found in the Rekognition collection',
                ]);
                return back()->withErrors(['liveness' => 'Face verification failed']);

            } catch (\Exception $e) {
                $request->session()->flash('stepup_error_message', 'Face Liveness error: ' . $e->getMessage());
                $request->session()->flash('stepup_error_details', [
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);
                return back()->withErrors(['liveness' => 'Face Liveness error: ' . $e->getMessage()]);
            }
        } else {
            // Traditional image-based verification
            
            $request->validate([
                'live_image' => 'required|image|max:5120',
            ]);

            $path = $request->file('live_image')->store('live');
            $fullPath = storage_path('app/' . $path);

            // Store image path so UI can show the image that was submitted on error
            $request->session()->put('stepup_error_image_path', $path);

            try {
                $result = $rekognition->searchFace($fullPath);
            } catch (\Aws\Rekognition\Exception\RekognitionException $e) {
                $request->session()->flash('stepup_error_message', 'Face detection error');
                $request->session()->flash('stepup_error_details', [
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);
                return redirect()->route('stepup.show')->withErrors(['rekognition' => 'Face detection error: ' . $e->getMessage()]);
            } catch (\Exception $e) {
                $request->session()->flash('stepup_error_message', 'Unexpected error');
                $request->session()->flash('stepup_error_details', [
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);
                return redirect()->route('stepup.show')->withErrors(['rekognition' => 'Unexpected error: ' . $e->getMessage()]);
            }

            $matches = $result['FaceMatches'] ?? [];
            
            if (count($matches) > 0) {
                $best = $matches[0];
                $externalId = $best['Face']['ExternalImageId'] ?? null;
                $confidence = $best['Similarity'] ?? ($best['Face']['Confidence'] ?? 0);
                $userId = (string) $user->id;

                if ($externalId == $userId && $confidence >= 60.0) {
                    // mark verified
                    if ($userFace) {
                        $userFace->verification_status = 'verified';
                        $userFace->last_verified_at = now();
                        $userFace->save();
                    }
                    
                    $request->session()->put('stepup_verified_at', now()->toDateTimeString());

                    // Generate verification data FIRST (before any redirect)
                    $verificationData = [
                        'method' => 'image',
                        'confidence' => $confidence,
                        'face_id' => $best['Face']['FaceId'] ?? null,
                        'external_id' => $externalId,
                        'user_id' => (string) $user->id,
                        'checked_at' => now()->toDateTimeString(),
                        'rekognition_response' => $result,
                    ];
                    
                    // Store in session for both GET and POST flows
                    $request->session()->put('stepup_verification_result', $verificationData);
                    $request->session()->put('stepup_verification_image_path', $path);
                    
                    // redirect to intended URL
                    $intended = $request->session()->pull('stepup_intended');
                    
                    if ($intended && isset($intended['method']) && strtoupper($intended['method']) === 'POST') {
                        $targetUrl = $intended['url'];
                        $inputs = $intended['input'] ?? [];
                        return view('stepup_post_redirect', compact('targetUrl', 'inputs', 'verificationData'));
                    }

                    $target = $intended['url'] ?? route('dashboard');
                    
                    return redirect($target)->with([
                        'status' => 'Step-up verification passed',
                        'verification' => $verificationData,
                    ]);
                } else {
                    // Face matched but wrong user or below threshold
                    $errorMessage = $externalId != $userId 
                        ? 'Face matched a different user\'s registered face'
                        : 'Face match confidence below required threshold';

                    $request->session()->flash('stepup_error_message', $errorMessage);
                    $request->session()->flash('stepup_error_details', [
                        'FaceMatches' => $result['FaceMatches'] ?? [],
                        'Message' => $errorMessage,
                        'YourUserId' => $userId,
                        'MatchedExternalId' => $externalId,
                    ]);
                    return redirect()->route('stepup.show')->withErrors(['face' => 'Face verification failed - ' . $errorMessage]);
                }
            }

            // No face match found
            $request->session()->flash('stepup_error_message', 'No matching face found in the verification attempt');
            $request->session()->flash('stepup_error_details', [
                'FaceMatches' => [],
                'Message' => 'No faces were detected or matched in the uploaded image',
                'SearchedAgainst' => 'Your registered face collection',
            ]);
            return redirect()->route('stepup.show')->withErrors(['face' => 'Face verification failed - no matching face found']);
        }
    }
}
