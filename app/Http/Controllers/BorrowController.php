<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Book;
use App\Models\Transaction;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class BorrowController extends Controller
{
    /**
     * Main borrow method - handles the borrow/process endpoint
     */
    public function borrow(Request $request)
    {
        try {
            Log::info('Borrow request received', [
                'method' => $request->method(),
                'url' => $request->url(),
                'all_data' => $request->all()
            ]);

            // Get form data
            $memberName = $request->input('member_name');
            $memberId = $request->input('member_id');
            $dueDate = $request->input('due_date');
            $dueTime = $request->input('due_time', '23:59');
            $bookIds = $request->input('book_ids');

            // Validate required fields
            if (!$memberName || !$dueDate || !$bookIds) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required fields'
                ], 400);
            }

            // Parse book IDs (handle both string and array formats)
            if (is_string($bookIds)) {
                $bookIds = json_decode($bookIds, true);
            }

            if (!is_array($bookIds) || empty($bookIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid book IDs provided'
                ], 400);
            }

            // Find member - try by ID first, then by name
            $member = null;
            
            if ($memberId) {
                $member = Member::find($memberId);
            }
            
            if (!$member) {
                // Search by name using flexible matching
                $member = Member::where(function ($query) use ($memberName) {
                    // Try exact full name match first
                    $query->whereRaw("TRIM(CONCAT_WS(' ', first_name, COALESCE(middle_name, ''), last_name)) = ?", [$memberName])
                        // If not found, try partial match
                        ->orWhere(function ($q) use ($memberName) {
                            $q->where('first_name', 'LIKE', '%' . $memberName . '%')
                              ->orWhere('last_name', 'LIKE', '%' . $memberName . '%');
                        });
                })->first();
            }

            if (!$member) {
                Log::warning('Member not found', ['name' => $memberName, 'id' => $memberId]);
                return response()->json([
                    'success' => false,
                    'message' => 'Member not found'
                ], 404);
            }

            // Combine date and time
            $dueDateTime = $dueDate . ' ' . $dueTime;
            $borrowedBooks = [];
            $errors = [];

            // Start transaction for database consistency
            DB::beginTransaction();

            try {
                foreach ($bookIds as $bookId) {
                    $book = Book::find($bookId);

                    if (!$book) {
                        $errors[] = "Book ID {$bookId} not found";
                        continue;
                    }

                    if ($book->availability <= 0) {
                        $errors[] = "'{$book->title}' is not available";
                        continue;
                    }

                    // Create transaction record
                    $transaction = Transaction::create([
                        'member_id' => $member->id,
                        'book_id' => $book->id,
                        'borrowed_at' => now(),
                        'due_date' => $dueDateTime,
                        'status' => 'borrowed',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Log the borrow action
                    SystemLog::log(
                        'book_borrowed',
                        "Book '{$book->title}' borrowed by {$member->first_name} {$member->last_name}",
                        Auth::id(),
                        [
                            'transaction_id' => $transaction->id,
                            'book_id' => $book->id,
                            'member_id' => $member->id,
                            'borrowed_at' => $transaction->borrowed_at,
                            'due_date' => $transaction->due_date,
                        ]
                    );

                    // Decrease book availability
                    $book->decrement('availability');
                    $borrowedBooks[] = $book->title;
                }

                DB::commit();

                // Return appropriate response
                if (count($errors) > 0 && count($borrowedBooks) === 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Borrowing failed: ' . implode(', ', $errors)
                    ], 400);
                }

                $message = count($borrowedBooks) > 0 
                    ? 'Successfully borrowed: ' . implode(', ', $borrowedBooks)
                    : 'No books were borrowed';

                $response = [
                    'success' => true,
                    'message' => $message,
                    'borrowed_books' => $borrowedBooks
                ];

                if (count($errors) > 0) {
                    $response['warnings'] = $errors;
                }

                Log::info('Borrow successful', [
                    'member_id' => $member->id,
                    'books_count' => count($borrowedBooks)
                ]);

                return response()->json($response);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Borrow error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Legacy store method for API compatibility
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'member_id' => 'required|integer|exists:members,id',
            'book_ids'  => 'required|array',
            'due_date'  => 'required|date|after_or_equal:today',
        ]);

        $member = Member::find($validated['member_id']);

        foreach ($validated['book_ids'] as $bookId) {
            $book = Book::find($bookId);

            if (!$book || $book->availability <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Book with ID $bookId is unavailable.",
                ], 400);
            }

            Transaction::create([
                'member_id'   => $member->id,
                'book_id'     => $bookId,
                'borrow_date' => now(),
                'due_date'    => $validated['due_date'],
                'status' => 'borrowed',
            ]);

            $book->decrement('availability');
        }

        return response()->json([
            'success' => true,
            'message' => 'Books successfully borrowed!',
        ]);
    }

    /**
     * Get member by ID - returns member details as JSON
     */
    public function getMemberById($id)
    {
        $member = Member::find($id);
        if (!$member) {
            return response()->json(['error' => 'Member not found'], 404);
        }

        $fullName = trim("{$member->first_name} {$member->middle_name} {$member->last_name}");
        $fullName = str_replace([' null', 'null '], '', $fullName);

        return response()->json([
            'id' => $member->id,
            'name' => $fullName
        ]);
    }

    /**
     * Search members by query string
     */
    public function search(Request $request)
    {
        $query = $request->input('query');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $members = Member::select(
            'id',
            DB::raw("TRIM(CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name)) as name")
        )
        ->where(DB::raw("TRIM(CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name))"), 'LIKE', '%' . $query . '%')
        ->limit(10)
        ->get();

        return response()->json($members);
    }

    /**
     * Show member details - used by QR scanner to get member info
     */
    public function show($id)
    {
        $member = Member::find($id);
        if (!$member) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json([
            'id' => $member->id,
            'first_name' => $member->first_name,
            'middle_name' => $member->middle_name,
            'last_name' => $member->last_name,
        ]);
    }

    /**
     * Suggest members based on search query
     */
    public function suggestMembers(Request $request)
    {
        $query = $request->input('query');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $members = Member::where('first_name', 'LIKE', '%' . $query . '%')
            ->orWhere('last_name', 'LIKE', '%' . $query . '%')
            ->select('id', 'first_name', 'middle_name', 'last_name')
            ->limit(5)
            ->get();

        return response()->json($members);
    }

    /**
     * Get overdue and due soon transactions
     */
    public function getOverdueAndDueSoon()
    {
        $now = now();
        $inThreeDays = now()->addDays(3)->endOfDay();

        $activeTransactions = Transaction::with(['member', 'book'])
            ->where('status', 'borrowed')
            ->get();

        $overdue = [];
        $dueSoon = [];

        foreach ($activeTransactions as $t) {
            try {
                $dueDate = \Illuminate\Support\Carbon::parse($t->due_date);
            } catch (\Exception $e) {
                continue;
            }

            if ($dueDate->lessThan($now)) {
                $overdue[] = $t;
            } elseif ($dueDate->lessThanOrEqualTo($inThreeDays)) {
                $dueSoon[] = $t;
            }
        }

        $format = function ($items) {
            return collect($items)->map(function ($t) {
                $m = $t->member;
                $name = $m ? trim("$m->first_name $m->middle_name $m->last_name") : 'Unknown Member';
                $title = $t->book?->title ?? 'Unknown Title';

                return [
                    'member' => $name,
                    'title' => $title,
                    'due_date' => $t->due_date
                ];
            });
        };

        return response()->json([
            'overdue' => $format($overdue),
            'dueSoon' => $format($dueSoon),
        ]);
    }
}