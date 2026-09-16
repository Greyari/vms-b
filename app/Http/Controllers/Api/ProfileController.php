<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return response()->json($request->user()->load('roles'));
    }

    public function update(Request $request)
    {
        $user = $request->user();

        // An account signed in by username may have no email; one that has no
        // username still needs its email.
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                Rule::requiredIf($user->username === null),
                'nullable', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $user->name = $validated['name'];
        if (array_key_exists('email', $validated)) {
            $user->email = $validated['email'];
        }
        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->load('roles'),
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
