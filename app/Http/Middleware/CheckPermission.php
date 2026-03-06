<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, string $permissions, Closure $next): Response
    {
        if (!$request->user()) {
            return redirect()->route('login');
        }

        // Parse permissions (can be comma or pipe separated)
        $permissions = explode('|', $permissions);

        // Check if user has any of the required permissions
        $hasPermission = false;
        foreach ($permissions as $permission) {
            if ($request->user()->hasPermission(trim($permission))) {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            // Log unauthorized access attempt
            \App\Models\SystemLog::log(
                'unauthorized_access',
                'Unauthorized access attempt to restricted resource',
                $request->user()->id,
                [
                    'route' => $request->path(),
                    'required_permissions' => $permissions,
                    'user_role' => $request->user()->role ? $request->user()->role->slug : 'no_role'
                ]
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'You do not have permission to access this resource.',
                    'required' => $permissions
                ], 403);
            }

            return redirect()->back()
                ->with('toast_type', 'error')
                ->with('toast_title', 'Access Denied')
                ->with('toast_message', 'You do not have permission to access this feature.');
        }

        return $next($request);
    }
}
