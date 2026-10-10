<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $roles = Role::with('permissions')->get();

        return response()->json($roles);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role = Role::create(['name' => $validated['name']]);

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
            ActivityLogger::log(
                action: 'updated',
                subject: $role,
                old: ['permissions' => []],
                new: ['permissions' => $validated['permissions']],
            );
        }

        return response()->json($role->load('permissions'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $role = Role::with('permissions')->findOrFail($id);

        return response()->json($role);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $role = Role::findOrFail($id);

        if ($role->is_builtin && $request->filled('name') && $request->input('name') !== $role->name) {
            return response()->json([
                'message' => 'Nama role bawaan tidak dapat diubah.',
            ], 422);
        }

        $rules = [
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
        ];

        if (! $role->is_builtin) {
            $rules['name'] = 'required|string|max:255|unique:roles,name,'.$role->id;
        }

        $validated = $request->validate($rules);

        if (! $role->is_builtin && isset($validated['name'])) {
            $role->update(['name' => $validated['name']]);
        }

        $oldPermissions = $role->permissions()->pluck('name')->toArray();

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);

            $newPermissions = $validated['permissions'];
            sort($oldPermissions);
            sort($newPermissions);

            if ($oldPermissions !== $newPermissions) {
                ActivityLogger::log(
                    action: 'updated',
                    subject: $role,
                    old: ['permissions' => $oldPermissions],
                    new: ['permissions' => $newPermissions],
                );
            }
        }

        // Flush Spatie's cached permissions immediately so changes take effect for all users
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json($role->load('permissions'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $role = Role::findOrFail($id);

        if ($role->is_builtin) {
            return $this->refuseBuiltIn();
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted successfully']);
    }

    /** See Role::BUILT_IN: the seeder would undo any change to these roles. */
    private function refuseBuiltIn(): JsonResponse
    {
        return response()->json(['message' => 'Built-in roles cannot be changed or deleted.'], 422);
    }
}
