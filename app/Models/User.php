<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'role_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The role that belongs to the user.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The permissions that belong to the user through their role.
     */
    public function rolePermissions(): BelongsToMany
    {
        return $this->role->permissions();
    }

    /**
     * Special permissions granted directly to the user (bypasses role restrictions).
     */
    public function specialPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')
            ->withPivot(['reason', 'expires_at'])
            ->withTimestamps();
    }

    /**
     * Permissions revoked from the user (even if role grants them).
     */
    public function revokedPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_revoked_permissions')
            ->withPivot(['reason', 'revoked_by'])
            ->withTimestamps();
    }

    /**
     * Get all permissions (role + special) for the user.
     */
    public function allPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')
            ->withPivot(['reason', 'expires_at'])
            ->withTimestamps()
            ->union($this->rolePermissions());
    }

    /**
     * Check if the user has a specific role.
     */
    public function hasRole(string $roleSlug): bool
    {
        return $this->role && $this->role->slug === $roleSlug;
    }

    /**
     * Check if the user has a specific permission (through role or special).
     */
    public function hasPermission(string $permissionSlug): bool
    {
        // Check if permission is revoked
        if ($this->revokedPermissions()->where('slug', $permissionSlug)->exists()) {
            return false;
        }

        // Check through role permissions
        if ($this->role) {
            if ($this->role->permissions()->where('slug', $permissionSlug)->exists()) {
                return true;
            }
        }

        // Check special permissions
        return $this->specialPermissions()
            ->where('slug', $permissionSlug)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * Check if the user has any of the given permissions.
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the user has all of the given permissions.
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Grant a special permission to the user.
     */
    public function grantSpecialPermission($permission, string $reason = null, $expiresAt = null)
    {
        if (is_string($permission)) {
            $permission = Permission::where('slug', $permission)->firstOrFail();
        }

        $this->specialPermissions()->syncWithoutDetaching([
            $permission->id => [
                'reason' => $reason,
                'expires_at' => $expiresAt,
            ]
        ]);
    }

    /**
     * Revoke a special permission from the user.
     */
    public function revokeSpecialPermission($permission)
    {
        if (is_string($permission)) {
            $permission = Permission::where('slug', $permission)->firstOrFail();
        }

        $this->specialPermissions()->detach($permission->id);
    }

    /**
     * Revoke a role-based permission from the user.
     */
    public function revokeRolePermission($permission, string $reason = null)
    {
        if (is_string($permission)) {
            $permission = Permission::where('slug', $permission)->firstOrFail();
        }

        if (!$this->revokedPermissions()->where('permission_id', $permission->id)->exists()) {
            $this->revokedPermissions()->attach($permission->id, [
                'reason' => $reason,
                'revoked_by' => auth()->id(),
            ]);
        }
    }

    /**
     * Restore a previously revoked permission.
     */
    public function restorePermission($permission)
    {
        if (is_string($permission)) {
            $permission = Permission::where('slug', $permission)->firstOrFail();
        }

        $this->revokedPermissions()->detach($permission->id);
    }

    /**
     * Check if a specific permission is revoked.
     */
    public function isPermissionRevoked(string $permissionSlug): bool
    {
        return $this->revokedPermissions()->where('slug', $permissionSlug)->exists();
    }

    /**
     * Check if the user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Check if the user is a librarian.
     */
    public function isLibrarian(): bool
    {
        return $this->hasRole('librarian');
    }

    /**
     * Check if the user is an assistant.
     */
    public function isAssistant(): bool
    {
        return $this->hasRole('assistant');
    }

    /**
     * Check if the user has a specific special permission.
     */
    public function hasSpecialPermission(string $permissionSlug): bool
    {
        return $this->specialPermissions()
            ->where('slug', $permissionSlug)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * Get expired special permissions for the user.
     */
    public function getExpiredSpecialPermissions()
    {
        return $this->specialPermissions()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();
    }

    /**
     * Clean up expired special permissions.
     */
    public function cleanupExpiredPermissions()
    {
        $this->specialPermissions()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->detach();
    }
}
