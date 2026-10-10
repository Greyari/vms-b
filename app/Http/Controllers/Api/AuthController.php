<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Username;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Gate-post accounts carried over from VMS-old sign in with an Employee
        // ID and have no email, so one field takes either. The old `email`
        // field is still accepted.
        $request->validate([
            'login' => 'nullable|required_without:email|string|max:255',
            'email' => 'nullable|required_without:login|string|max:255',
            'password' => 'required|string',
        ]);

        $login = trim((string) ($request->input('login') ?? $request->input('email')));
        $field = str_contains($login, '@') ? 'email' : 'username';
        if ($field === 'username') {
            // Stored upper-cased: an Employee ID typed in lower case still signs in.
            $login = (string) Username::normalize($login);
        }

        if (! Auth::attempt([$field => $login, 'password' => $request->input('password')])) {
            // Record failed attempts too (no causer, since nobody's authenticated yet).
            // Name the account only when it exists: people type their password
            // into the login field by mistake, and the activity log must never
            // keep that text.
            $knownAccount = User::where($field, $login)->exists();
            ActivityLogger::logEvent('login_failed', ['login' => $knownAccount ? $login : null]);

            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            ActivityLogger::logEvent('login_failed', ['login' => $login, 'reason' => 'inactive_account']);

            return response()->json([
                'message' => 'Akun Anda berstatus nonaktif. Silakan hubungi administrator.',
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        ActivityLogger::logEvent('login');

        $user->load('roles');
        // Roles hold the permissions; expose the full effective permission list to the SPA.
        $user->setRelation('permissions', $user->getAllPermissions());

        return response()->json([
            'message' => 'Login success',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('roles');
        $user->setRelation('permissions', $user->getAllPermissions());

        return response()->json($user);
    }

    public function logout(Request $request)
    {
        ActivityLogger::logEvent('logout');

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
