<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\BorrowController;
use App\Http\Controllers\TimeLogController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\SystemLogsController;
use App\Models\Member;

Route::get('/', function() {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    
});

Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('books', [BookController::class, 'index'])->name('books');
    Route::get('books/{id}', [BookController::class, 'show'])->whereNumber('id')->name('books.show');
    // CRUD endpoints used by the JavaScript UI
    Route::post('books', [BookController::class, 'store'])->name('books.store');
    Route::put('books/{id}', [BookController::class, 'update'])->whereNumber('id')->name('books.update');
    Route::delete('books/{id}', [BookController::class, 'destroy'])->whereNumber('id')->name('books.destroy');
    Route::inertia('members', 'members')->name('members');
    Route::inertia('timeInOut', 'timeInOut')->name('timeInOut');
    Route::get('/dashboard/books-data', [AdminController::class, 'getBooksData'])->name('dashboard.books-data');
    Route::get('/dashboard/members-data', [AdminController::class, 'getMembersData'])->name('dashboard.members-data');
    Route::get('/dashboard/borrowers-data', [AdminController::class, 'getBorrowersData'])->name('dashboard.borrowers-data');
    Route::get('/dashboard/weekly-data', [AdminController::class, 'getWeeklyData'])->name('dashboard.weekly-data');
    Route::get('/dashboard/recent-members', [AdminController::class, 'getRecentMembers'])->name('dashboard.recent-members');
});

require __DIR__.'/settings.php';
