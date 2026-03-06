<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserRevokedPermission extends Model
{
    use HasFactory;

    protected $table = 'user_revoked_permissions';

    protected $fillable = [
        'user_id',
        'permission_id',
        'reason',
        'revoked_by',
    ];

    /**
     * The user that had the permission revoked.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The permission that was revoked.
     */
    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * The admin who revoked the permission.
     */
    public function revokedBy()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
