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
     * Serve the image from the last/current step-up attempt (for display in UI).
     * Uses path stored in session (stepup_verification_image_path or stepup_last_attempt_image_path).
     */
    public function attemptImage(Request $request): StreamedResponse|\Illuminate\Http\Response
    {
        // Prefer last attempt image (e.g. failed attempt on step-up page) over verification image (success page)
        $path = $request->session()->get('stepup_last_attempt_image_path')
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

                // Store verification attempt details in session
                $request->session()->put('stepup_verification_result', [
                    'method' => 'liveness',
                    'liveness_confidence' => $livenessConfidence,
                    'session_id' => $sessionId,
                    'liveness_result' => $livenessResult,
                    'search_result' => $searchResult,
                    'checked_at' => now()->toDateTimeString(),
                ]);

                $matches = $searchResult['FaceMatches'] ?? [];
                if (count($matches) > 0) {
                    $best = $matches[0];
                    $externalId = $best['Face']['ExternalImageId'] ?? null;
                    $faceConfidence = $best['Similarity'] ?? 0;

                    if ($externalId == (string) $user->id && $faceConfidence >= 85.0 && $livenessConfidence >= 85.0) {
                        // Mark verified
                        $userFace->verification_status = 'verified';
                        $userFace->last_verified_at = now();
                        $userFace->save();

                        $request->session()->put('stepup_verified_at', now()->toDateTimeString());

                        // Redirect to intended URL
                        $intended = $request->session()->pull('stepup_intended');
                        if ($intended && isset($intended['method']) && strtoupper($intended['method']) === 'POST') {
                            $targetUrl = $intended['url'];
                            $inputs = $intended['input'] ?? [];
                            return view('stepup_post_redirect', compact('targetUrl', 'inputs'));
                        }

                        $target = $intended['url'] ?? route('dashboard');
                        return redirect($target)->with('status', 'Step-up verification passed with Face Liveness');
                    }
                }

                return back()->withErrors(['liveness' => 'Face Liveness verification failed']);
            } catch (\Exception $e) {
                $request->session()->put('stepup_last_attempt_rekognition_response', [
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

            // Store image path so UI can show the image that was submitted
            $request->session()->put('stepup_last_attempt_image_path', $path);

            try {
                $result = $rekognition->searchFace($fullPath);
            } catch (\Aws\Rekognition\Exception\RekognitionException $e) {
                $request->session()->put('stepup_last_attempt_rekognition_response', [
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);
                return back()->withErrors(['rekognition' => 'Face detection error: ' . $e->getMessage()]);
            } catch (\Exception $e) {
                $request->session()->put('stepup_last_attempt_rekognition_response', [
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);
                return back()->withErrors(['rekognition' => 'Unexpected error: ' . $e->getMessage()]);
            }

            $matches = $result['FaceMatches'] ?? [];
            if (count($matches) > 0) {
                $best = $matches[0];
                $externalId = $best['Face']['ExternalImageId'] ?? null;
                $confidence = $best['Similarity'] ?? ($best['Face']['Confidence'] ?? 0);

                // store verification attempt details in session for later inspection
                $request->session()->put('stepup_verification_result', [
                    'method' => 'image',
                    'external_id' => $externalId,
                    'confidence' => $confidence,
                    'raw_match' => $best,
                    'rekognition_full_response' => $result,
                    'checked_at' => now()->toDateTimeString(),
                ]);
                $request->session()->put('stepup_verification_image_path', $path);

                if ($externalId == (string) $user->id && $confidence >= 85.0) {
                    // mark verified
                    if ($userFace) {
                        $userFace->verification_status = 'verified';
                        $userFace->last_verified_at = now();
                        $userFace->save();
                    }
                    
                    $request->session()->put('stepup_verified_at', now()->toDateTimeString());

                    // redirect to intended URL
                    $intended = $request->session()->pull('stepup_intended');
                    if ($intended && isset($intended['method']) && strtoupper($intended['method']) === 'POST') {
                        $targetUrl = $intended['url'];
                        $inputs = $intended['input'] ?? [];
                        return view('stepup_post_redirect', compact('targetUrl', 'inputs'));
                    }

                    $target = $intended['url'] ?? route('dashboard');
                    return redirect($target)->with('status', 'Step-up verification passed');
                }
            }

            // No match or wrong user
            $request->session()->put('stepup_last_attempt_rekognition_response', $result);
            return back()->withErrors(['face' => 'Face verification failed']);
        }
    }
}
