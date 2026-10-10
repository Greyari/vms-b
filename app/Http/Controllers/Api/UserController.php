<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Username;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('roles')->get();

        return response()->json($users);
    }

    public function store(Request $request)
    {
        if ($request->has('role') && ! $request->has('roles')) {
            $roleInput = $request->input('role');
            $request->merge(['roles' => is_array($roleInput) ? $roleInput : (empty($roleInput) ? [] : [$roleInput])]);
        }

        // One spelling per username (App\Support\Username), so the unique rule
        // compares the stored form.
        if (is_string($request->input('username'))) {
            $request->merge(['username' => Username::normalize($request->input('username'))]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['nullable', 'required_without:email', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
            'email' => ['nullable', 'required_without:username', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => 'required|string|min:8',
            // The old fallback assigned a role named "user" that no seeder
            // creates, so a request without roles crashed with a 500.
            'roles' => 'required|array|min:1',
            'roles.*' => 'exists:roles,name',
            'is_active' => 'sometimes|boolean',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'] ?? null,
            'email' => $validated['email'] ?? null,
            'password' => Hash::make($validated['password']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $user->assignRole($validated['roles']);

        ActivityLogger::log(
            action: 'updated',
            subject: $user,
            old: ['roles' => []],
            new: ['roles' => $user->roles()->pluck('name')->toArray()],
        );

        return response()->json($user->load('roles'), 201);
    }

    public function show($id)
    {
        $user = User::with('roles')->findOrFail($id);

        return response()->json($user);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($request->has('role') && ! $request->has('roles')) {
            $roleInput = $request->input('role');
            $request->merge(['roles' => is_array($roleInput) ? $roleInput : (empty($roleInput) ? [] : [$roleInput])]);
        }

        if (is_string($request->input('username'))) {
            $request->merge(['username' => Username::normalize($request->input('username'))]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['nullable', 'required_without:email', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['nullable', 'required_without:username', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'roles' => 'nullable|array',
            'roles.*' => 'exists:roles,name',
            'is_active' => 'sometimes|boolean',
        ]);

        $oldRoles = $user->roles()->pluck('name')->toArray();

        $user->name = $validated['name'];
        // Partial update: a field that was not sent keeps its value.
        if (array_key_exists('username', $validated)) {
            $user->username = $validated['username'];
        }
        if (array_key_exists('email', $validated)) {
            $user->email = $validated['email'];
        }
        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        if (array_key_exists('is_active', $validated)) {
            $user->is_active = (bool) $validated['is_active'];
        }
        $user->save();

        if (isset($validated['roles'])) {
            $user->syncRoles($validated['roles']);

            $newRoles = $validated['roles'];
            sort($oldRoles);
            $sortedNewRoles = $newRoles;
            sort($sortedNewRoles);

            if ($oldRoles !== $sortedNewRoles) {
                ActivityLogger::log(
                    action: 'updated',
                    subject: $user,
                    old: ['roles' => $oldRoles],
                    new: ['roles' => $sortedNewRoles],
                );
            }
        }

        return response()->json($user->load('roles'));
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}
