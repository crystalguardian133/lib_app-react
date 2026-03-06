<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Book;
use App\Models\Member;
use App\Models\BookReturn;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Auth;

class TransactionController extends Controller
{
public function index()
{
    // Check if user has permission to view transactions
    if (!Auth::check() || !Auth::user()->hasPermission('view-transactions')) {
        abort(403, 'Unauthorized. You do not have permission to view transactions.');
    }
    
    $borrowed = Transaction::where('status', 'borrowed')
        ->with(['member', 'book'])
        ->orderBy('due_date')
        ->get();

    // Group borrowed transactions by member, date, and time
    $groupedBorrowed = $borrowed->groupBy(function ($transaction) {
        return $transaction->member_id . '_' . \Carbon\Carbon::parse($transaction->borrowed_at)->format('Y-m-d_H:i');
    })->map(function ($group) {
        return [
            'member' => $group->first()->member,
            'borrowed_at' => $group->first()->borrowed_at,
            'due_date' => $group->first()->due_date,
            'transactions' => $group
        ];
    })->values();

    $returned = Transaction::where('status', 'returned')
        ->with(['member', 'book'])
        ->orderByDesc('returned_at')
        ->get();

    return view('transactions.index', [
        'groupedBorrowed' => $groupedBorrowed,
        'returned' => $returned,
    ]);
}

    public function history(Request $request)
    {
        // Check if user has permission to view transactions
        if (!Auth::check() || !Auth::user()->hasPermission('view-transactions')) {
            abort(403, 'Unauthorized. You do not have permission to view transactions.');
        }
        
        $year = $request->input('year');
        $month = $request->input('month');
        $status = $request->input('status');
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);

        $query = Transaction::with(['member', 'book'])
            ->whereNotNull('borrowed_at')
            ->orderBy('borrowed_at', 'desc');

        if ($year) {
            $query->whereYear('borrowed_at', $year);
        }

        if ($month) {
            $query->whereMonth('borrowed_at', $month);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('member', function ($memberQuery) use ($search) {
                    $memberQuery->where('first_name', 'like', '%' . $search . '%')
                                ->orWhere('last_name', 'like', '%' . $search . '%');
                })
                ->orWhereHas('book', function ($bookQuery) use ($search) {
                    $bookQuery->where('title', 'like', '%' . $search . '%');
                });
            });
        }

        $transactions = $query->paginate($perPage);

        // Get distinct years for filter
        $years = Transaction::selectRaw('YEAR(borrowed_at) as year')
            ->whereNotNull('borrowed_at')
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year');

        return view('borrow-history.index', [
            'transactions' => $transactions,
            'years' => $years,
            'selectedYear' => $year,
            'selectedMonth' => $month,
            'selectedStatus' => $status,
            'search' => $search,
        ]);
    }

    public function borrow(Request $request)
    {
        // Check if user has permission to borrow books
        if (!Auth::check() || !Auth::user()->hasPermission('borrow-books')) {
            return back()->with('error', 'Unauthorized. You do not have permission to borrow books.');
        }
        
        $validated = $request->validate([
            'member_id' => 'required|exists:members,id',
            'book_ids' => 'required|array',
            'book_ids.*' => 'exists:books,id',
        ]);

        $borrowedBooks = [];

        foreach ($validated['book_ids'] as $bookId) {
            $book = Book::find($bookId);

            if ($book->availability > 0) {
                Transaction::create([
                    'book_id' => $bookId,
                    'member_id' => $validated['member_id'],
                ]);

                $book->decrement('availability');
                $borrowedBooks[] = $book->title;
            }
        }

        if (count($borrowedBooks) === 0) {
            return back()->with('error', 'No books were available to borrow.');
        }

        return back()->with('success', 'Borrowed books: ' . implode(', ', $borrowedBooks));
    }
public function returnBook($id)
{
    // Check if user has permission to return books
    if (!Auth::check() || !Auth::user()->hasPermission('return-books')) {
        return redirect()->route('dashboard')->with('error', 'Unauthorized. You do not have permission to return books.');
    }
    
    $transaction = Transaction::findOrFail($id);
    $transaction->status = 'returned';
    $transaction->returned_at = now();
    $transaction->save();

    $transaction->book->increment('availability');

    // Log the return action
    SystemLog::log(
        'book_returned',
        "Book '{$transaction->book->title}' returned by {$transaction->member->first_name} {$transaction->member->last_name}",
        Auth::id(),
        [
            'transaction_id' => $transaction->id,
            'book_id' => $transaction->book_id,
            'member_id' => $transaction->member_id,
            'borrowed_at' => $transaction->borrowed_at,
            'returned_at' => $transaction->returned_at,
            'due_date' => $transaction->due_date,
        ]
    );

    return redirect()->route('dashboard')->with('returned', 'success');
}

public function bulkReturn(Request $request)
{
    // Check if user has permission to return books
    if (!Auth::check() || !Auth::user()->hasPermission('return-books')) {
        return response()->json(['message' => 'Unauthorized. You do not have permission to return books.'], 403);
    }
    
    $validated = $request->validate([
        'member_id' => 'required|exists:members,id',
        'book_ids' => 'required|array',
        'book_ids.*' => 'exists:books,id',
    ]);

    $returnedBooks = [];
    $errors = [];

    foreach ($validated['book_ids'] as $bookId) {
        // Find the transaction for this book and member
        $transaction = Transaction::where('book_id', $bookId)
            ->where('member_id', $validated['member_id'])
            ->where('status', 'borrowed')
            ->first();

        if ($transaction) {
            $transaction->status = 'returned';
            $transaction->returned_at = now();
            $transaction->save();

            $transaction->book->increment('availability');
            $returnedBooks[] = $transaction->book->title;

            // Log the return action
            SystemLog::log(
                'book_returned',
                "Book '{$transaction->book->title}' returned by {$transaction->member->first_name} {$transaction->member->last_name}",
                Auth::id(),
                [
                    'transaction_id' => $transaction->id,
                    'book_id' => $transaction->book_id,
                    'member_id' => $transaction->member_id,
                    'borrowed_at' => $transaction->borrowed_at,
                    'returned_at' => $transaction->returned_at,
                    'due_date' => $transaction->due_date,
                ]
            );
        } else {
            $book = Book::find($bookId);
            $errors[] = $book ? $book->title : "Book ID {$bookId}";
        }
    }

    if (count($returnedBooks) === 0) {
        return response()->json([
            'success' => false,
            'message' => 'No books were found to return for this member.'
        ]);
    }

    $message = 'Successfully returned: ' . implode(', ', $returnedBooks);
    if (count($errors) > 0) {
        $message .= '. Could not return: ' . implode(', ', $errors);
    }

    return response()->json([
        'success' => true,
        'message' => $message,
        'returned_count' => count($returnedBooks),
        'errors' => $errors
    ]);
}

public function overdue()
{
    // Check if user has permission to view overdue books
    if (!Auth::check() || !Auth::user()->hasPermission('view-overdue')) {
        return response()->json(['message' => 'Unauthorized. You do not have permission to view overdue books.'], 403);
    }
    
    $overdue = Transaction::where('status', 'borrowed')
        ->where('due_date', '<', now())
        ->with(['member', 'book'])
        ->get();

    return response()->json([
        'books' => $overdue->map(fn($t) => [
            'title' => $t->book->title ?? 'Unknown',
            'due_date' => $t->due_date->format('Y-m-d'),
            'member' => $t->member->name ?? 'Unknown'
        ])
    ]);
}



}
