<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\SystemLog;

class SystemLogsController extends Controller
{
    /**
     * Log a system event (static method for use from anywhere).
     */
    public static function log(string $action, string $description = null, int $userId = null, array $metadata = []): void
    {
        SystemLog::create([
            'action' => $action,
            'description' => $description ?? $action,
            'user_id' => $userId ?? (Auth::check() ? Auth::id() : null),
            'metadata' => !empty($metadata) ? json_encode($metadata) : null,
        ]);
    }

    public function index(Request $request)
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();
        
        // Check if user has permission to view system logs (permission check includes revocation)
        // Admins also go through this check so revocations are respected
        if (!$user->hasPermission('view_system_logs')) {
            abort(403, 'Unauthorized. You do not have permission to access system logs.');
        }

        $perPage = $request->get('per_page', 50);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 50;

        $query = SystemLog::with('user')->orderBy('created_at', 'desc');

        // Search filter
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function($q) use ($search) {
                $q->where('description', 'LIKE', "%{$search}%")
                  ->orWhere('action', 'LIKE', "%{$search}%");
            });
        }

        // Action type filter
        if ($request->filled('action')) {
            $query->where('action', $request->get('action'));
        }

        // Date range filters
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->get('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->get('date_to'));
        }

        $logs = $query->paginate($perPage)->appends($request->except('page'));

        return view('system-logs.index', compact('logs', 'perPage'));
    }

    public function clear()
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Check if user is admin
        $user = Auth::user();
        if (!$user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized. Only admins can clear system logs.'], 403);
        }

        SystemLog::truncate();

        return response()->json(['success' => true, 'message' => 'Logs cleared successfully']);
    }

    public function uiTest(Request $request)
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        return view('ui-test');
    }
}
