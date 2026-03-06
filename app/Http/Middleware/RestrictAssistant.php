<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictAssistant
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            return redirect()->route('login');
        }

        // Check if user is an assistant - only then apply restrictions
        // If user is NOT an assistant (admin, librarian, or no role), let them pass
        if ($request->user()->isAssistant()) {
            // Get allowed routes for assistant
            $allowedRoutes = [
                'timelog.index',
                'timelog.qrScanner',
            ];

            $currentRoute = $request->route()->getName();

            // Check if current route is allowed
            if (!in_array($currentRoute, $allowedRoutes)) {
                // Show toast notification and reload after 3 seconds
                $toastScript = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof toast !== 'undefined') {
        toast.show({
            title: 'Restricted Access',
            message: 'Only Librarians and Admins can access this feature. Redirecting to Time Logs...',
            type: 'warning',
            duration: 3000,
            reloadAfter: 3000
        });
    } else {
        // Fallback if toast.js is not loaded
        setTimeout(function() {
            window.location.href = '/timelog';
        }, 3000);
    }
});
</script>
HTML;
                
                // Redirect to timelog with toast notification
                return redirect()->route('timelog.index')
                    ->with('toast_type', 'warning')
                    ->with('toast_title', 'Restricted Access')
                    ->with('toast_message', 'Only Librarians and Admins can access this feature.');
            }
        }

        // For admin, librarian, or users without a role - allow access
        return $next($request);
    }
}
