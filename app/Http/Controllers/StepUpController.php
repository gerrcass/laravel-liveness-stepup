<?php

namespace App\Http\Controllers;

use App\Services\RekognitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StepUpController extends Controller
{
    public function show()
    {
        return view('auth.stepup');
    }

    public function verify(Request $request, RekognitionService $rekognition)
    {
        $request->validate([
            'live_image' => 'required|image|max:5120',
        ]);

        $path = $request->file('live_image')->store('live');
        $fullPath = storage_path('app/' . $path);

        try {
            $result = $rekognition->searchFace($fullPath);
        } catch (\Aws\Rekognition\Exception\RekognitionException $e) {
            // Handle Rekognition errors (e.g., no face detected or bad request)
            return back()->withErrors(['rekognition' => 'Face detection error: ' . $e->getMessage()]);
        } catch (\Exception $e) {
            return back()->withErrors(['rekognition' => 'Unexpected error: ' . $e->getMessage()]);
        }

        $matches = $result['FaceMatches'] ?? [];
        if (count($matches) > 0) {
            $best = $matches[0];
            $externalId = $best['Face']['ExternalImageId'] ?? null;
            $confidence = $best['Similarity'] ?? ($best['Face']['Confidence'] ?? 0);

            // store verification attempt details in session for later inspection
            $request->session()->put('stepup_verification_result', [
                'external_id' => $externalId,
                'confidence' => $confidence,
                'raw_match' => $best,
                'checked_at' => \Carbon\Carbon::now()->toDateTimeString(),
            ]);

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

        return back()->withErrors(['face' => 'Face verification failed']);
    }
}
