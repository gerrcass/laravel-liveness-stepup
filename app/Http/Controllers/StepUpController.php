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
        return view('auth.stepup');
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

            // store verification attempt details in session for later inspection (including full Rekognition response)
            $request->session()->put('stepup_verification_result', [
                'external_id' => $externalId,
                'confidence' => $confidence,
                'raw_match' => $best,
                'rekognition_full_response' => $result,
                'checked_at' => \Carbon\Carbon::now()->toDateTimeString(),
            ]);
            $request->session()->put('stepup_verification_image_path', $path);

            if ($externalId == (string) Auth::id() && $confidence >= 85.0) {
                // mark verified (session-first, DB for audit)
                $user = Auth::user();
                $face = $user->userFace;
                if ($face) {
                    $face->verification_status = 'verified';
                    $face->last_verified_at = \Carbon\Carbon::now();
                    $face->save();
                }
                // mark session so subsequent operations within timeout are allowed
                $request->session()->put('stepup_verified_at', \Carbon\Carbon::now()->toDateTimeString());

                // redirect to intended URL if present. If the intended request was
                // a POST, return an auto-submitting form view that replays the POST.
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

        // No match or wrong user: keep image path and full Rekognition response for UI
        $request->session()->put('stepup_last_attempt_rekognition_response', $result);
        return back()->withErrors(['face' => 'Face verification failed']);
    }
}
