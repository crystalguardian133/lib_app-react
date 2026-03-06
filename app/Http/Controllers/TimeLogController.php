<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Member;
use App\Models\TimeLog;
use App\Models\Transaction;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class TimeLogController extends Controller
{
    public function index()
    {
        // Check if user has permission to access timelog
        if (!Auth::check() || !Auth::user()->hasPermission('time-in-out')) {
            abort(403, 'Unauthorized. You do not have permission to access timelog.');
        }
        
        $logs = TimeLog::with('member')->whereNull('time_out')->get();
        $historyLogs = TimeLog::with('member')
            ->whereNotNull('time_out')
            ->orderBy('time_out', 'desc')
            ->take(50)
            ->get();
        return view('timelog.index', compact('logs', 'historyLogs'));
    }

    public function qrScanner()
    {
        // Check if user has permission to access QR scanner
        if (!Auth::check() || !Auth::user()->hasPermission('scan-qr')) {
            abort(403, 'Unauthorized. You do not have permission to access QR scanner.');
        }
        
        return view('timelog.qr-scanner');
    }

    public function search(Request $request)
    {
        $query = $request->input('q');

        $members = Member::where('name', 'LIKE', '%' . $query . '%')
            ->select('name')
            ->get();

        return response()->json($members);
    }

    public function timeIn(Request $request)
    {
        // Check if user has permission for time-in
        if (!Auth::check() || !Auth::user()->hasPermission('time-in-out')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to perform time-in.'], 403);
        }
        
        $name = $request->input('member_name');
        $member = Member::where('name', $name)->first();

        if (!$member) {
            return response()->json(['message' => '❌ Member not found.'], 404);
        }

        $existing = TimeLog::where('member_id', $member->id)->whereNull('time_out')->first();
        if ($existing) {
            return response()->json(['message' => '⚠️ Already timed in.']);
        }

        $timeLog = TimeLog::create([
            'member_id' => $member->id,
            'time_in' => now()
        ]);

        // Log time-in activity
        SystemLog::log(
            'member_time_in',
            "Member '{$member->first_name} {$member->last_name}' timed in",
            Auth::id(),
            [
                'member_id' => $member->id,
                'member_name' => trim($member->first_name . ' ' . ($member->middle_name ?? '') . ' ' . $member->last_name),
                'time_log_id' => $timeLog->id,
                'time_in' => $timeLog->time_in
            ]
        );

        return response()->json(['message' => '✅ Time-in recorded.']);
    }

    public function timeOut(Request $request)
    {
        // Check if user has permission for time-out
        if (!Auth::check() || !Auth::user()->hasPermission('time-in-out')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to perform time-out.'], 403);
        }
        
        $id = $request->input('id');
        $log = TimeLog::find($id);

        if (!$log) {
            return response()->json(['message' => '❌ Log not found.'], 404);
        }

        // Check if member has pending books to return
        $member = $log->member;
        $pendingBooks = Transaction::where('member_id', $member->id)->where('status', 'borrowed')->count();

        if ($pendingBooks > 0) {
            return response()->json(['message' => '❌ Cannot time out: Member has pending books to return.'], 400);
        }

        $log->update(['time_out' => now()]);

        // Log time-out activity
        SystemLog::log(
            'member_time_out',
            "Member '{$log->member->first_name} {$log->member->last_name}' timed out",
            Auth::id(),
            [
                'member_id' => $log->member->id,
                'member_name' => trim($log->member->first_name . ' ' . ($log->member->middle_name ?? '') . ' ' . $log->member->last_name),
                'time_log_id' => $log->id,
                'time_in' => $log->time_in,
                'time_out' => $log->time_out
            ]
        );

        return response()->json(['message' => '✅ Time-out recorded.']);
    }

    public function scan(Request $request, $id)
    {
        // Check if user has permission for QR scan
        if (!Auth::check() || !Auth::user()->hasPermission('scan-qr')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to use QR scanner.'], 403);
        }
        
        $member = Member::find($id);
        if (!$member) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        $mode = $request->input('mode', 'auto'); // default to auto for backward compatibility

        $log = TimeLog::where('member_id', $member->id)
                    ->whereNull('time_out')
                    ->latest()
                    ->first();

        if ($mode === 'time_out') {
            if (!$log) {
                return response()->json(['message' => '❌ Member is not timed in. Cannot time out.'], 400);
            }
            // Check if member has pending books to return before time out
            $pendingBooks = Transaction::where('member_id', $member->id)->where('status', 'borrowed')->count();

            if ($pendingBooks > 0) {
                return response()->json(['message' => '❌ Cannot time out: Member has pending books to return.'], 400);
            }

            // Time out
            $log->update(['time_out' => now()]);

            // Log time-out activity
            SystemLog::log(
                'member_time_out_qr',
                "Member '{$member->first_name} {$member->last_name}' timed out via QR scan",
                Auth::id(),
                [
                    'member_id' => $member->id,
                    'member_name' => trim($member->first_name . ' ' . ($member->middle_name ?? '') . ' ' . $member->last_name),
                    'time_log_id' => $log->id,
                    'time_in' => $log->time_in,
                    'time_out' => $log->time_out,
                    'method' => 'qr_scan',
                    'mode' => 'time_out'
                ]
            );

            return response()->json(['message' => '✅ Time-Out successful for ' . $member->name]);
        } elseif ($mode === 'time_in') {
            if ($log) {
                return response()->json(['message' => '❌ Member is already timed in. Cannot time in again.'], 400);
            }
            // Time in
            $timeLog = TimeLog::create([
                'member_id' => $member->id,
                'time_in' => now()
            ]);

            // Log time-in activity
            SystemLog::log(
                'member_time_in_qr',
                "Member '{$member->first_name} {$member->last_name}' timed in via QR scan",
                Auth::id(),
                [
                    'member_id' => $member->id,
                    'member_name' => trim($member->first_name . ' ' . ($member->middle_name ?? '') . ' ' . $member->last_name),
                    'time_log_id' => $timeLog->id,
                    'time_in' => $timeLog->time_in,
                    'method' => 'qr_scan',
                    'mode' => 'time_in'
                ]
            );

            return response()->json(['message' => '✅ Time-In successful for ' . $member->name]);
        } else {
            // Auto mode: original behavior
            if ($log) {
                // Check if member has pending books to return before time out
                $pendingBooks = Transaction::where('member_id', $member->id)->where('status', 'borrowed')->count();

                if ($pendingBooks > 0) {
                    return response()->json(['message' => '❌ Cannot time out: Member has pending books to return.'], 400);
                }

                // Time out
                $log->update(['time_out' => now()]);

                // Log time-out activity
                SystemLog::log(
                    'member_time_out_qr',
                    "Member '{$member->first_name} {$member->last_name}' timed out via QR scan",
                    Auth::id(),
                    [
                        'member_id' => $member->id,
                        'member_name' => trim($member->first_name . ' ' . ($member->middle_name ?? '') . ' ' . $member->last_name),
                        'time_log_id' => $log->id,
                        'time_in' => $log->time_in,
                        'time_out' => $log->time_out,
                        'method' => 'qr_scan',
                        'mode' => 'auto'
                    ]
                );

                return response()->json(['message' => '✅ Time-Out successful for ' . $member->name]);
            } else {
                // Time in
                $timeLog = TimeLog::create([
                    'member_id' => $member->id,
                    'time_in' => now()
                ]);

                // Log time-in activity
                SystemLog::log(
                    'member_time_in_qr',
                    "Member '{$member->first_name} {$member->last_name}' timed in via QR scan",
                    Auth::id(),
                    [
                        'member_id' => $member->id,
                        'member_name' => trim($member->first_name . ' ' . ($member->middle_name ?? '') . ' ' . $member->last_name),
                        'time_log_id' => $timeLog->id,
                        'time_in' => $timeLog->time_in,
                        'method' => 'qr_scan',
                        'mode' => 'auto'
                    ]
                );

                return response()->json(['message' => '✅ Time-In successful for ' . $member->name]);
            }
        }
    }

    public function scanQR($id)
    {
        // This method is deprecated, use scan() instead
        return $this->scan(request(), $id);
    }

    // ✅ Helper: check if member is timed in
    private function isMemberTimedIn($memberId)
    {
        return TimeLog::where('member_id', $memberId)->whereNull('time_out')->exists();
    }

    // ✅ Helper: time in
    private function logTimeIn($memberId)
    {
        return TimeLog::create([
            'member_id' => $memberId,
            'time_in' => now(),
        ]);
    }

    // ✅ Helper: time out
    private function logoutMember($memberId)
    {
        $log = TimeLog::where('member_id', $memberId)
                      ->whereNull('time_out')
                      ->latest()
                      ->first();

        if ($log) {
            $log->update(['time_out' => now()]);
        }
    }
}
