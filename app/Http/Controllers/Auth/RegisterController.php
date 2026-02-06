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
            'liveness_session_id' => ['exclude_if:registration_method,image', 'required_if:registration_method,liveness', 'string'],
            'role' => 'required|string|in:' . implode(',', $roleNames),
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
        ]);

        $user->assignRole($data['role']);

        if ($data['registration_method'] === 'image') {
            // Traditional image-based registration using S3
            $tempPath = $request->file('face_image')->store('temp');
            $fullPath = storage_path('app/' . $tempPath);

            try {
                // Store image in S3
                $s3Object = $rekognition->storeImageToS3($fullPath, (string)$user->id);
                
                // Index face in Rekognition collection
                $index = $rekognition->indexFace($fullPath, (string)$user->id);
                $faceIds = $index['FaceRecords'] ?? [];
            } catch (\Exception $e) {
                $faceIds = [];
                $s3Object = null;
            }

            // Clean up temp file
            if (isset($tempPath) && \Illuminate\Support\Facades\Storage::exists($tempPath)) {
                \Illuminate\Support\Facades\Storage::delete($tempPath);
            }

            UserFace::create([
                'user_id' => $user->id,
                'registration_method' => 'image',
                'collection_name' => $rekognition->getCollectionId(),
                'face_data' => [
                    's3_object' => $s3Object,
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
                $result = $rekognition->indexFaceFromLivenessSession($sessionId, (string)$user->id, null, $livenessResult);
                
                UserFace::create([
                    'user_id' => $user->id,
                    'registration_method' => 'liveness',
                    'collection_name' => $rekognition->getCollectionId(),
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

    /**
     * Show face registration page for users without facial data
     */
    public function showFaceRegistration(Request $request)
    {
        $user = $request->user();
        
        // Check if user already has face data
        if ($user->userFace) {
            return redirect('/dashboard');
        }
        
        return view('auth.register-face');
    }

    /**
     * Store face registration for existing users
     */
    public function storeFaceRegistration(Request $request, RekognitionService $rekognition)
    {
        $user = $request->user();
        
        // Check if user already has face data
        if ($user->userFace) {
            if ($request->expectsJson()) {
                return response()->json(['success' => true, 'message' => 'Ya tienes información facial registrada.']);
            }
            return redirect('/dashboard');
        }
        
        $data = $request->validate([
            'registration_method' => 'required|string|in:image,liveness',
            'face_image' => 'required_if:registration_method,image|image|max:5120',
            'liveness_session_id' => ['exclude_if:registration_method,image', 'required_if:registration_method,liveness', 'string'],
        ]);

        $isAjax = $request->expectsJson();
        $responseData = ['success' => false, 'message' => ''];
        
        try {
            if ($data['registration_method'] === 'image') {
                // Traditional image-based registration using S3
                if (!$request->hasFile('face_image')) {
                    throw new \Exception('No se ha proporcionado ninguna imagen.');
                }
                
                $tempPath = $request->file('face_image')->store('temp');
                $fullPath = storage_path('app/' . $tempPath);

                // Store image in S3
                $s3Object = $rekognition->storeImageToS3($fullPath, (string)$user->id);
                
                // Index face in Rekognition collection
                $index = $rekognition->indexFace($fullPath, (string)$user->id);
                $faceIds = $index['FaceRecords'] ?? [];

                // Clean up temp file
                if (isset($tempPath) && \Illuminate\Support\Facades\Storage::exists($tempPath)) {
                    \Illuminate\Support\Facades\Storage::delete($tempPath);
                }

                if (count($faceIds) === 0) {
                    throw new \Exception('No se detectó ningún rostro en la imagen. Por favor intenta con una imagen más clara.');
                }

                UserFace::create([
                    'user_id' => $user->id,
                    'registration_method' => 'image',
                    'collection_name' => $rekognition->getCollectionId(),
                    'face_data' => [
                        's3_object' => $s3Object,
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
                    throw new \Exception('Face Liveness session ID not found. Please complete the Face Liveness check again.');
                }
                
                // If we have livenessResult from session, use it directly
                // Otherwise, try to get it from AWS (this might fail if already consumed)
                if ($livenessResult === null) {
                    $livenessResult = $rekognition->getFaceLivenessSessionResults($sessionId);
                }
                
                // Index the face from liveness session
                $result = $rekognition->indexFaceFromLivenessSession($sessionId, (string)$user->id, null, $livenessResult);
                
                UserFace::create([
                    'user_id' => $user->id,
                    'registration_method' => 'liveness',
                    'collection_name' => $rekognition->getCollectionId(),
                    'face_data' => $result['indexResult'],
                    'liveness_data' => $result['livenessResult'],
                    'verification_status' => 'verified',
                    'last_verified_at' => now(),
                ]);
                
                // Clear session data
                $request->session()->forget(['pending_liveness_session_id', 'pending_liveness_result', 'pending_liveness_confidence']);
            }

            // Clear the needs_face_registration flag
            $request->session()->forget('needs_face_registration');
            
            $responseData['success'] = true;
            $responseData['message'] = 'Tu información facial ha sido registrada exitosamente.';
            
            if ($isAjax) {
                return response()->json($responseData);
            }
            
            return redirect('/dashboard')->with('status', 'Tu información facial ha sido registrada exitosamente.');
            
        } catch (\Exception $e) {
            $responseData['message'] = $e->getMessage();
            
            if ($isAjax) {
                return response()->json($responseData, 400);
            }
            
            return back()->withErrors(['registration' => $e->getMessage()]);
        }
    }
}
