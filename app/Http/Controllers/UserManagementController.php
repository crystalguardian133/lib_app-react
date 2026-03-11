<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Models\LoginSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UserManagementController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request)
    {
        $query = User::with(['role', 'specialPermissions']);

        // Search filter
        if ($request->has('search') && $request->search) {
            $sei
arch = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%");
            });
        }

        // Role filter
        if ($request->has('role') && $request->role) {
            $query->where('role_id', $request->role);
        }

        $users = $query->orderBy('created_at', 'desc')->paginate(10);
        $roles = Role::all();

        return view('admin.users.index', compact('users', 'roles'));
    }

    /**
     * Show the form for creating a new user.
     */
    public function create()
    {
        $roles = Role::all();
        return view('admin.users.create', compact('roles'));
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'username' => 'required|string|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role_id' => 'required|exists:roles,id',
        ]);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'username' => $request->username,
                'password' => Hash::make($request->password),
                'role_id' => $request->role_id,
            ]);

            // Log the action
            SystemLogsController::log(
                'user_created',
                "Created user: {$user->name} ({$user->email}) with role: {$user->role->name}",
                auth()->id(),
                ['new_user_id' => $user->id, 'role' => $user->role->slug]
            );

            DB::commit();

            return redirect()->route('admin.users.index')
                ->with('toast_type', 'success')
                ->with('toast_message', "User '{$user->name}' created successfully with role '{$user->role->name}'.");
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'Failed to create user. Please try again.')
                ->withInput();
        }
    }

    /**
     * Display the specified user with their permissions.
     */
    public function show($id)
    {
        $user = User::with(['role.permissions', 'specialPermissions', 'revokedPermissions'])->findOrFail($id);
        $allPermissions = Permission::all();
        $rolePermissions = $user->role ? $user->role->permissions : collect([]);

        return view('admin.users.show', compact('user', 'rolePermissions', 'allPermissions'));
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit($id)
    {
        $user = User::findOrFail($id);
        $roles = Role::all();

        return view('admin.users.edit', compact('user', 'roles'));
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $id,
            'username' => 'required|string|max:255|unique:users,username,' . $id,
            'role_id' => 'required|exists:roles,id',
        ];

        // Only require password if it's being changed
        if ($request->password) {
            $rules['password'] = 'string|min:8|confirmed';
        }

        $request->validate($rules);

        DB::beginTransaction();
        try {
            $oldRole = $user->role ? $user->role->name : 'No Role';
            
            $user->name = $request->name;
            $user->email = $request->email;
            $user->username = $request->username;
            
            if ($request->password) {
                $user->password = Hash::make($request->password);
            }
            
            $user->role_id = $request->role_id;
            $user->save();

            $newRole = $user->role ? $user->role->name : 'No Role';
            $roleChange = $oldRole !== $newRole ? " (role changed from '{$oldRole}' to '{$newRole}')" : '';

            // Log the action
            SystemLogsController::log(
                'user_updated',
                "Updated user: {$user->name}",
                auth()->id(),
                ['updated_user_id' => $user->id, 'role' => $user->role->slug]
            );

            DB::commit();

            return redirect()->route('admin.users.index')
                ->with('toast_type', 'success')
                ->with('toast_message', "User '{$user->name}' updated successfully{$roleChange}.");
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'Failed to update user. Please try again.')
                ->withInput();
        }
    }

    /**
     * Remove the specified user.
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        // Prevent deleting yourself
        if ($user->id === auth()->id()) {
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'You cannot delete your own account.');
        }

        DB::beginTransaction();
        try {
            $userName = $user->name;
            
            // Delete user's special permissions first
            $user->specialPermissions()->detach();
            
            // Delete the user
            $user->delete();

            // Log the action
            SystemLogsController::log(
                'user_deleted',
                "Deleted user: {$userName}",
                auth()->id(),
                ['deleted_user_id' => $id]
            );

            DB::commit();

            return redirect()->route('admin.users.index')
                ->with('toast_type', 'success')
                ->with('toast_message', "User '{$userName}' deleted successfully.");
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'Failed to delete user. Please try again.');
        }
    }

    /**
     * Change a user's role (for escalation/de-escalation).
     */
    public function changeRole(Request $request, $id)
    {
        $request->validate([
            'role_id' => 'required|exists:roles,id',
        ]);

        $user = User::findOrFail($id);
        $newRole = Role::findOrFail($request->role_id);

        // Prevent changing your own role
        if ($user->id === auth()->id()) {
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'You cannot change your own role.');
        }

        DB::beginTransaction();
        try {
            $oldRole = $user->role ? $user->role->name : 'No Role';
            
            $user->role_id = $request->role_id;
            $user->save();

            // Log the action
            SystemLogsController::log(
                'user_role_changed',
                "Changed role for {$user->name} from {$oldRole} to {$newRole->name}",
                auth()->id(),
                ['target_user_id' => $user->id, 'old_role' => $oldRole, 'new_role' => $newRole->slug]
            );

            DB::commit();

            return redirect()->back()
                ->with('toast_type', 'success')
                ->with('toast_message', "Role changed from '{$oldRole}' to '{$newRole->name}' for user '{$user->name}'.");
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'Failed to change role. Please try again.');
        }
    }

    /**
     * Grant a special permission to a user (bypasses role restrictions).
     */
    public function grantSpecialPermission(Request $request, $id)
    {
        $request->validate([
            'permission_id' => 'required|exists:permissions,id',
            'expires_at' => 'nullable|date|after_or_equal:today',
        ]);

        $user = User::findOrFail($id);
        $permission = Permission::findOrFail($request->permission_id);

        // Check if user already has this permission (either via role or special)
        if ($user->role && $user->role->permissions->contains($permission->id)) {
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', "User already has '{$permission->name}' permission via their role.");
        }

        if ($user->specialPermissions->contains($permission->id)) {
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', "User already has special permission '{$permission->name}'.");
        }

        DB::beginTransaction();
        try {
            $expiresAt = $request->expires_at ? Carbon::parse($request->expires_at) : null;
            
            $user->specialPermissions()->attach($permission->id, [
                'granted_by' => auth()->id(),
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Log the action
            SystemLogsController::log(
                'permission_granted',
                "Granted '{$permission->name}' to {$user->name}",
                auth()->id(),
                ['target_user_id' => $user->id, 'permission' => $permission->slug, 'expires_at' => $expiresAt?->format('Y-m-d H:i:s')]
            );

            DB::commit();

            return redirect()->back()
                ->with('toast_type', 'success')
                ->with('toast_message', "Special permission '{$permission->name}' granted to '{$user->name}'.");
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'Failed to grant special permission. Please try again.');
        }
    }

    /**
     * Revoke a special permission from a user.
     */
    public function revokeSpecialPermission($userId, $permissionId)
    {
        $user = User::findOrFail($userId);
        $permission = Permission::findOrFail($permissionId);

        if (!$user->specialPermissions->contains($permission->id)) {
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', "User does not have special permission '{$permission->name}'.");
        }

        DB::beginTransaction();
        try {
            $user->specialPermissions()->detach($permission->id);

            // Log the action
            SystemLogsController::log(
                'permission_revoked',
                "Revoked '{$permission->name}' from {$user->name}",
                auth()->id(),
                ['target_user_id' => $user->id, 'permission' => $permission->slug]
            );

            DB::commit();

            return redirect()->back()
                ->with('toast_type', 'success')
                ->with('toast_message', "Special permission '{$permission->name}' revoked from '{$user->name}'.");
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'Failed to revoke special permission. Please try again.');
        }
    }

    /**
     * Revoke a role-based permission from a user.
     */
    public function revokeRolePermission(Request $request, $userId)
    {
        $request->validate([
            'permission_id' => 'required|exists:permissions,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $user = User::findOrFail($userId);
        $permission = Permission::findOrFail($request->permission_id);

        // Check if user has this permission via role
        if (!$user->role || !$user->role->permissions->contains($permission->id)) {
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', "User does not have '{$permission->name}' permission via their role.");
        }

        // Check if already revoked
        if ($user->revokedPermissions()->where('permission_id', $permission->id)->exists()) {
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', "Permission '{$permission->name}' is already revoked for this user.");
        }

        DB::beginTransaction();
        try {
            // Revoke the permission
            $user->revokedPermissions()->attach($permission->id, [
                'reason' => $request->reason,
                'revoked_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Refresh the user to get updated relationships
            $user->refresh();

            // Log the action
            SystemLogsController::log(
                'role_permission_revoked',
                "Revoked '{$permission->name}' from {$user->name}",
                auth()->id(),
                ['target_user_id' => $user->id, 'permission' => $permission->slug, 'reason' => $request->reason]
            );

            DB::commit();

            return redirect()->back()
                ->with('toast_type', 'success')
                ->with('toast_message', "Permission '{$permission->name}' revoked from '{$user->name}'.");
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'Failed to revoke permission. Please try again.');
        }
    }

    /**
     * Restore a revoked permission for a user.
     */
    public function restorePermission($userId, $permissionId)
    {
        $user = User::findOrFail($userId);
        $permission = Permission::findOrFail($permissionId);

        if (!$user->revokedPermissions()->where('permission_id', $permission->id)->exists()) {
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', "Permission '{$permission->name}' is not revoked for this user.");
        }

        DB::beginTransaction();
        try {
            $user->revokedPermissions()->detach($permission->id);
            
            // Refresh the user to get updated relationships
            $user->refresh();

            // Log the action
            SystemLogsController::log(
                'permission_restored',
                "Restored '{$permission->name}' for {$user->name}",
                auth()->id(),
                ['target_user_id' => $user->id, 'permission' => $permission->slug]
            );

            DB::commit();

            return redirect()->back()
                ->with('toast_type', 'success')
                ->with('toast_message', "Permission '{$permission->name}' restored for '{$user->name}'.");
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'Failed to restore permission. Please try again.');
        }
    }

    /**
     * Force logout a user (invalidate their session).
     */
    public function forceLogout(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // Prevent force logging out yourself
        if ($user->id === auth()->id()) {
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'You cannot force logout your own account.');
        }

        DB::beginTransaction();
        try {
            // Invalidate all login sessions for this user
            LoginSession::invalidateAllForUser($user->id);

            // Clear remember token and set force_logout flag
            $user->forceFill([
                'remember_token' => null,
                'force_logout' => true,
            ])->save();

            // Log the action
            SystemLogsController::log(
                'user_force_logout',
                "Force logged out user: {$user->name}",
                auth()->id(),
                ['target_user_id' => $user->id, 'target_user_name' => $user->name]
            );

            DB::commit();

            return redirect()->back()
                ->with('toast_type', 'success')
                ->with('toast_message', "User '{$user->name}' has been force logged out.");
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'Failed to force logout user. Please try again.');
        }
    }

    /**
     * Check if a user has been force-logged out.
     * Returns JSON response for AJAX polling.
     */
    public function checkForceLogoutStatus($id)
    {
        $user = User::findOrFail($id);
        
        // Check if user has force_logout flag set
        $forceLogout = $user->force_logout;
        
        return response()->json([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'force_logout' => $forceLogout,
            'checked_at' => now()->toISOString(),
        ]);
    }

    /**
     * Clear the force_logout flag after user has been logged out.
     * This is called after the user successfully logs in again.
     */
    public function clearForceLogoutFlag(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        // Only allow clearing if the user is themselves logging in
        // This prevents others from clearing the flag
        if (auth()->id() !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $user->forceFill(['force_logout' => false])->save();
        
        return response()->json(['success' => true]);
    }

    /**
     * Terminate a specific session (admin function for managing all sessions).
     */
    public function terminateSession(Request $request, $sessionId)
    {
        $session = LoginSession::find($sessionId);
        
        if (!$session) {
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'Session not found.');
        }
        
        $user = User::find($session->user_id);
        $sessionUserName = $user ? $user->name : 'Unknown User';
        
        DB::beginTransaction();
        try {
            $session->invalidate();
            
            // Log the action
            SystemLogsController::log(
                'user_session_terminated',
                "Admin terminated session for {$sessionUserName}",
                auth()->id(),
                [
                    'target_user_id' => $session->user_id,
                    'target_user_name' => $sessionUserName,
                    'session_id' => $sessionId,
                    'ip_address' => $session->ip_address,
                ]
            );
            
            DB::commit();
            
            return redirect()->back()
                ->with('toast_type', 'success')
                ->with('toast_message', "Session for '{$sessionUserName}' has been terminated.");
        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_message', 'Failed to terminate session. Please try again.');
        }
    }
}