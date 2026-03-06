<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use App\Models\LoginSession;

class CheckForceLogout
{
    /**
     * Handle an incoming request.
     * Checks if user has been force-logged out and redirects to login.
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $user = Auth::user();
            
            // Refresh user from database to get latest force_logout status
            $user->refresh();
            
            // Check if user has been force-logged out
            if ($user->force_logout) {
                // Clear remember token and sessions
                LoginSession::invalidateAllForUser($user->id);
                $user->forceFill(['remember_token' => null])->save();
                
                // Store message in session
                Session::flash('toast_type', 'error');
                Session::flash('toast_message', 'Your session has ended. Please log in again.');
                
                // Log the user out
                Auth::logout();
                
                // Invalidate the current session
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                
                // Always redirect to login - don't return JSON
                return redirect()->route('login');
            }
            
            // Also check if user still has an active session in the database
            $hasActiveSession = LoginSession::where('user_id', $user->id)
                ->where('is_active', true)
                ->where('expires_at', '>', now())
                ->exists();
            
            if (!$hasActiveSession) {
                // Clear remember token
                $user->forceFill(['remember_token' => null])->save();
                
                // Store message in session
                Session::flash('toast_type', 'error');
                Session::flash('toast_message', 'Your session has ended. Please log in again.');
                
                // Log the user out
                Auth::logout();
                
                // Invalidate the current session
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                
                // Always redirect to login - don't return JSON
                return redirect()->route('login');
            }
        }

        return $next($request);
    }
}
