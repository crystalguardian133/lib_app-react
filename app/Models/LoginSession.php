<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoginSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'ip_address',
        'user_agent',
        'last_activity',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'last_activity' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Get the user that owns the session.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get active sessions for a specific user.
     */
    public static function getActiveSessionsForUser($userId)
    {
        return static::where('user_id', $userId)
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->orderBy('last_activity', 'desc');
    }

    /**
     * Invalidate all sessions for a specific user (except current).
     */
    public static function invalidateAllForUser($userId, $currentSessionId = null)
    {
        $query = static::where('user_id', $userId)
            ->where('is_active', true);

        if ($currentSessionId) {
            $query->where('session_id', '!=', $currentSessionId);
        }

        return $query->update(['is_active' => false]);
    }

    /**
     * Invalidate a specific session.
     */
    public function invalidate()
    {
        $this->update(['is_active' => false]);
    }

    /**
     * Check if session is still valid.
     */
    public function isValid()
    {
        return $this->is_active && $this->expires_at->isFuture();
    }

    /**
     * Clean up expired sessions.
     */
    public static function cleanupExpired()
    {
        return static::where('expires_at', '<', now())->delete();
    }

    /**
     * Get all active sessions across all users (for admin view).
     */
    public static function getAllActiveSessions()
    {
        return static::with('user')
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->orderBy('last_activity', 'desc');
    }

    /**
     * Get count of active sessions per user.
     */
    public static function getActiveSessionCountPerUser()
    {
        return static::where('is_active', true)
            ->where('expires_at', '>', now())
            ->groupBy('user_id')
            ->select('user_id', \Illuminate\Support\Facades\DB::raw('count(*) as session_count'));
    }

    /**
     * Create a new session record.
     */
    public static function createForUser($user, $request)
    {
        return static::create([
            'user_id' => $user->id,
            'session_id' => session()->getId(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_activity' => now(),
            'expires_at' => now()->addHours(24), // Session expires in 24 hours
            'is_active' => true,
        ]);
    }

    /**
     * Update the last activity timestamp.
     */
    public function touchActivity()
    {
        $this->update(['last_activity' => now()]);
    }
}
