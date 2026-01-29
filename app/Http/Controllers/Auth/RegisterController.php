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
            'face_image' => 'required|image|max:5120',
            'role' => 'required|string|in:' . implode(',', $roleNames),
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
        ]);

        $user->assignRole($data['role']);

        $path = $request->file('face_image')->store('faces');
        $fullPath = storage_path('app/' . $path);

        // Index face to Rekognition collection and save returned faceIds
        try {
            $index = $rekognition->indexFace($fullPath, (string)$user->id);
            $faceIds = $index['FaceRecords'] ?? [];
        } catch (\Exception $e) {
            $faceIds = [];
        }

        $userFace = UserFace::create([
            'user_id' => $user->id,
            'face_data' => [
                'path' => $path,
                'indexed' => count($faceIds) > 0,
                'face_records' => $faceIds,
            ],
            'verification_status' => 'pending',
        ]);

        Auth::login($user);

        return redirect('/dashboard');
    }
}
