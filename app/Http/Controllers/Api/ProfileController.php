<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Username;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user()->load('roles');
        $user->setRelation('permissions', $user->getAllPermissions());

        return response()->json($user);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        if (is_string($request->input('username'))) {
            $request->merge(['username' => Username::normalize($request->input('username'))]);
        }

        $newUsername = $request->has('username') ? $request->input('username') : $user->username;
        $newEmail = $request->has('email') ? $request->input('email') : $user->email;

        if (empty($newUsername) && empty($newEmail)) {
            throw ValidationException::withMessages([
                'username' => ['At least one of username or email is required.'],
            ]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => [
                'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'email' => [
                'nullable', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $user->name = $validated['name'];
        if (array_key_exists('username', $validated)) {
            $user->username = $validated['username'];
        }
        if (array_key_exists('email', $validated)) {
            $user->email = $validated['email'];
        }
        $user->save();

        $user->load('roles');
        $user->setRelation('permissions', $user->getAllPermissions());

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user,
        ]);
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The provided password does not match your current password.'],
            ]);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return response()->json(['message' => 'Password updated successfully']);
    }
}
