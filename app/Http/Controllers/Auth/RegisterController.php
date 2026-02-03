<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserFace;
use App\Services\RekognitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

class RegisterController extends Controller
{
    public function show()
    {
        $roles = Role::orderBy('name')->get();

        return view('auth.register', compact('roles'));
    }

    public function register(Request $request, RekognitionService $rekognition)
    {
        $roleNames = Role::pluck('name')->toArray();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'registration_method' => 'required|string|in:image,liveness',
            'face_image' => 'required_if:registration_method,image|image|max:5120',
            'liveness_session_id' => 'required_if:registration_method,liveness|string',
            'role' => 'required|string|in:' . implode(',', $roleNames),
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
        ]);

        $user->assignRole($data['role']);

        if ($data['registration_method'] === 'image') {
            // Traditional image-based registration
            $path = $request->file('face_image')->store('faces');
            $fullPath = storage_path('app/' . $path);

            try {
                $index = $rekognition->indexFace($fullPath, (string)$user->id);
                $faceIds = $index['FaceRecords'] ?? [];
            } catch (\Exception $e) {
                $faceIds = [];
            }

            UserFace::create([
                'user_id' => $user->id,
                'registration_method' => 'image',
                'face_data' => [
                    'path' => $path,
                    'indexed' => count($faceIds) > 0,
                    'face_records' => $faceIds,
                ],
                'verification_status' => 'pending',
            ]);
        } else {
            // Face Liveness registration - use session data stored during liveness completion
            $sessionId = $data['liveness_session_id'] ?? $request->session()->get('pending_liveness_session_id');
            $livenessResult = $request->session()->get('pending_liveness_result');
            
            if (!$sessionId) {
                return back()->withErrors(['liveness' => 'Face Liveness session ID not found. Please complete the Face Liveness check again.']);
            }
            
            try {
                // If we have livenessResult from session, use it directly
                // Otherwise, try to get it from AWS (this might fail if already consumed)
                if ($livenessResult === null) {
                    $livenessResult = $rekognition->getFaceLivenessSessionResults($sessionId);
                }
                
                // Index the face from liveness session
                $result = $rekognition->indexFaceFromLivenessSession($sessionId, (string)$user->id, 'users', $livenessResult);
                
                UserFace::create([
                    'user_id' => $user->id,
                    'registration_method' => 'liveness',
                    'face_data' => $result['indexResult'],
                    'liveness_data' => $result['livenessResult'],
                    'verification_status' => 'verified',
                    'last_verified_at' => now(),
                ]);
                
                // Clear session data
                $request->session()->forget(['pending_liveness_session_id', 'pending_liveness_result', 'pending_liveness_confidence']);
            } catch (\Exception $e) {
                // If registration fails, delete the user and return error
                $user->delete();
                return back()->withErrors(['liveness' => 'Face Liveness registration failed: ' . $e->getMessage()]);
            }
        }

        Auth::login($user);

        return redirect('/dashboard');
    }
}
