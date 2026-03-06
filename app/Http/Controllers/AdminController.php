<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Book;
use App\Models\Borrower;
use App\Models\Transaction;
use App\Models\TimeLog;
use App\Models\Member;
use App\Models\SystemLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class AdminController extends Controller
{
    public function dashboard()
    {
        // Redirect assistants to timelog page
        if (Auth::user()->isAssistant()) {
            return redirect()->route('timelog.index');
        }

        \Log::info('Dashboard method called - starting data collection');

        $booksCount = Book::count();
        $membersCount = DB::table('members')->count();

        $today = Carbon::today();
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        $dailyCount = Transaction::whereDate('borrowed_at', $today)->count();
        $weeklyCount = Transaction::whereBetween('borrowed_at', [$startOfWeek, $endOfWeek])->count();
        $lifetimeCount = Transaction::count();

        \Log::info("Dashboard stats - Books: {$booksCount}, Members: {$membersCount}, Daily borrows: {$dailyCount}, Weekly borrows: {$weeklyCount}, Lifetime: {$lifetimeCount}");

    // Books added
    $booksToday = Book::whereDate('created_at', $today)->count();
    $booksThisWeek = Book::whereBetween('created_at', [$startOfWeek, $endOfWeek])->count();

    // Members registered
    $membersToday = DB::table('members')->whereDate('created_at', $today)->count();
    $membersThisWeek = DB::table('members')->whereBetween('created_at', [$startOfWeek, $endOfWeek])->count();
    
    // Additional member statistics
    $julitaMembers = DB::table('members')->where('municipality', 'Julita')->count();
    $activeMembers = DB::table('members')
        ->whereExists(function ($query) {
            $query->select(DB::raw(1))
                  ->from('transactions')
                  ->whereColumn('transactions.member_id', 'members.id')
                  ->whereNull('transactions.returned_at');
        })
        ->count();

    // Weekly data for the last 4 weeks
    $weeklyData = collect();
    foreach (range(3, 0) as $i) {
        $weekStart = Carbon::now()->subWeeks($i)->startOfWeek();
        $weekEnd = Carbon::now()->subWeeks($i)->endOfWeek();
        $count = Transaction::whereBetween('borrowed_at', [$weekStart, $weekEnd])->count();
        $weekLabel = $weekStart->format('M d') . ' - ' . $weekEnd->format('M d');
        $weeklyData->push(['week' => $weekLabel, 'count' => $count]);
    }

    // Visits per last 7 days (from timelogs)
    $visitsData = collect();
    foreach (range(6, 0) as $i) {
        $date = Carbon::today()->subDays($i)->toDateString();
        $count = \App\Models\TimeLog::whereDate('created_at', $date)->count();
        $visitsData->push(['date' => $date, 'count' => $count]);
    }

    // Get all borrowers with their book and member information
    $borrowers = Transaction::where('status', 'borrowed')
        ->with(['book', 'member'])
        ->orderBy('borrowed_at', 'desc')
        ->get();

        // Analytics data
        \Log::info('Fetching analytics data...');
        $analytics = $this->getAnalyticsData();
        \Log::info('Analytics data fetched - Top books count: ' . count($analytics['topBooks'] ?? []));

        // Monthly borrows data
        \Log::info('Fetching monthly borrows data...');
        try {
            $monthlyBorrows = $this->getMonthlyBorrowsData();
            \Log::info('Monthly borrows data fetched - Total borrows this year: ' . array_sum($monthlyBorrows['data']));
        } catch (\Exception $e) {
            \Log::error('Error getting monthly borrows data: ' . $e->getMessage());
            $monthlyBorrows = ['labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], 'data' => [0,0,0,0,0,0,0,0,0,0,0,0]];
        }

        // Active areas data
        \Log::info('Fetching active areas data...');
        try {
            $activeAreas = $this->getActiveAreasData();
            \Log::info('Active areas data fetched - Areas count: ' . count($activeAreas['labels']));
        } catch (\Exception $e) {
            \Log::error('Error getting active areas data: ' . $e->getMessage());
            $activeAreas = ['labels' => [], 'data' => []];
        }

        \Log::info('Dashboard data prepared - Books: ' . $booksCount . ', Members: ' . $membersCount . ', Daily: ' . $dailyCount . ', Weekly: ' . $weeklyCount);

        return view('dashboard', [
            'booksCount' => $booksCount,
            'membersCount' => $membersCount,
            'dailyCount' => $dailyCount,
            'weeklyCount' => $weeklyCount,
            'lifetimeCount' => $lifetimeCount,
            'booksToday' => $booksToday,
            'booksThisWeek' => $booksThisWeek,
            'membersToday' => $membersToday,
            'membersThisWeek' => $membersThisWeek,
            'julitaMembers' => $julitaMembers,
            'activeMembers' => $activeMembers,
            'weeklyData' => $weeklyData,
            'visitsData' => $visitsData,
            'borrowers' => $borrowers,
            'analytics' => $analytics,
            'monthlyBorrows' => $monthlyBorrows,
            'activeAreas' => $activeAreas,
        ]);
}

public function getBooksData()
{
    $books = Book::select('id', 'title', 'author', 'genre', 'published_year', 'availability', 'created_at')
        ->orderBy('created_at', 'desc')
        ->get();

    return response()->json($books);
}

public function getMembersData()
{
    $members = Member::select('id', 'first_name', 'middle_name', 'last_name', 'age', 'barangay', 'contactnumber', 'created_at')
        ->orderBy('created_at', 'desc')
        ->get();

    return response()->json($members);
}

public function getBorrowersData(Request $request)
{
    $filter = $request->get('filter', 'all'); // all, today, weekly
    
    $query = Transaction::with(['book', 'member']);
    
    if ($filter === 'today') {
        $query->whereDate('borrowed_at', Carbon::today());
    } elseif ($filter === 'weekly') {
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();
        $query->whereBetween('borrowed_at', [$startOfWeek, $endOfWeek]);
    }
    
    $borrowers = $query->orderBy('borrowed_at', 'desc')->get();
    
    return response()->json($borrowers);
}

public function getWeeklyData(Request $request)
{
    $month = $request->get('month', Carbon::now()->month);
    $year = $request->get('year', Carbon::now()->year);
    
    $startOfMonth = Carbon::create($year, $month, 1)->startOfMonth();
    $endOfMonth = Carbon::create($year, $month, 1)->endOfMonth();
    
    $weeklyData = collect();
    $currentWeek = $startOfMonth->copy()->startOfWeek();
    
    while ($currentWeek->lte($endOfMonth)) {
        $weekEnd = $currentWeek->copy()->endOfWeek();
        if ($weekEnd->gt($endOfMonth)) {
            $weekEnd = $endOfMonth;
        }
        
        $count = Transaction::whereBetween('borrowed_at', [$currentWeek, $weekEnd])->count();
        $weekLabel = $currentWeek->format('M d') . ' - ' . $weekEnd->format('M d');
        $weeklyData->push(['week' => $weekLabel, 'count' => $count]);
        
        $currentWeek->addWeek();
    }
    
    return response()->json($weeklyData);
}

public function getRecentMembers()
{
    $members = Member::select('id', 'first_name', 'middle_name', 'last_name', 'barangay', 'created_at')
        ->orderBy('created_at', 'desc')
        ->limit(5)
        ->get();

    return response()->json($members);
}

private function getAnalyticsData()
{
    \Log::info('Starting analytics data collection');

    // Book popularity by genre
    $bookGenres = DB::table('books')
        ->select('genre', DB::raw('COUNT(*) as count'))
        ->whereNotNull('genre')
        ->groupBy('genre')
        ->orderBy('count', 'desc')
        ->get();

    \Log::info('Book genres collected: ' . $bookGenres->count());

    // Julita barangay distribution with coordinates
    $julitaBarangays = DB::table('members')
        ->select('barangay', DB::raw('COUNT(*) as count'))
        ->where('municipality', 'Julita')
        ->whereNotNull('barangay')
        ->groupBy('barangay')
        ->orderBy('barangay')
        ->get();

    \Log::info('Julita barangays collected: ' . $julitaBarangays->count());

    // Add coordinates for each barangay in Julita
    $barangayCoordinates = [
        'Alegria' => [10.9365, 124.9496],
        'Anibong' => [11.0154, 124.9806],
        'Aslum' => [11.0164, 124.9526],
        'Balante' => [10.9369, 124.9444],
        'Bongdo' => [11.0105, 124.9661],
        'Bonifacio' => [10.9688, 124.9572],
        'Bugho' => [10.9467, 124.9418],
        'Calbasag' => [10.9906, 124.9531],
        'Caridad' => [10.9515, 124.9463],
        'Cuya-e' => [10.9861, 124.9646],
        'Dita' => [10.9756, 124.9490],
        'Gitabla' => [10.9968, 124.9607],
        'Hindang' => [10.9974, 124.9730],
        'Inawangan' => [11.0035, 124.9740],
        'Jurao' => [10.9574, 124.9253],
        'Poblacion District I' => [10.9730251,124.9584698],
        'Poblacion District II' => [10.962516,124.9664024],
        'Poblacion District III' => [10.9789252,124.9475884],
        'Poblacion District IV' => [10.974231,124.961458],
        'San Andres' => [10.9580, 124.9358],
        'San Pablo' => [11.0019, 124.9683],
        'Santa Cruz' => [11.0073, 124.9530],
        'Santo Niño' => [10.9278, 124.9580],
        'Tagkip' => [10.9500, 124.9573],
        'Tolosahay' => [10.9403, 124.9627],
        'Villa Hermosa' => [11.0130, 124.9745],
    ];

    // Add coordinates and member details to barangay data
    $julitaBarangaysWithCoords = $julitaBarangays->map(function($barangay) use ($barangayCoordinates) {
        $coords = $barangayCoordinates[$barangay->barangay] ?? [11.0667, 124.5167]; // Default to Julita center

        // Get member details for this barangay
        $members = DB::table('members')
            ->select('first_name', 'middle_name', 'last_name', 'age')
            ->where('municipality', 'Julita')
            ->where('barangay', $barangay->barangay)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function($member) {
                return [
                    'name' => trim($member->first_name . ' ' . ($member->middle_name ? $member->middle_name . ' ' : '') . $member->last_name),
                    'age' => $member->age
                ];
            });

        return [
            'barangay' => $barangay->barangay,
            'count' => $barangay->count,
            'lat' => $coords[0],
            'lng' => $coords[1],
            'members' => $members
        ];
    });

    // Non-Julita municipality distribution
    $otherMunicipalities = DB::table('members')
        ->select('municipality', DB::raw('COUNT(*) as count'))
        ->where('municipality', '!=', 'Julita')
        ->whereNotNull('municipality')
        ->groupBy('municipality')
        ->orderBy('count', 'desc')
        ->get();

    \Log::info('Other municipalities collected: ' . $otherMunicipalities->count());

    // Age distribution
    $ageDistribution = DB::table('members')
        ->select(
            DB::raw("CASE
                WHEN age BETWEEN 0 AND 12 THEN '0-12'
                WHEN age BETWEEN 13 AND 18 THEN '13-18'
                WHEN age BETWEEN 19 AND 25 THEN '19-25'
                WHEN age BETWEEN 26 AND 35 THEN '26-35'
                WHEN age BETWEEN 36 AND 50 THEN '36-50'
                WHEN age BETWEEN 51 AND 65 THEN '51-65'
                ELSE '65+'
            END as age_group"),
            DB::raw('COUNT(*) as count')
        )
        ->groupBy('age_group')
        ->orderByRaw("FIELD(age_group, '0-12', '13-18', '19-25', '26-35', '36-50', '51-65', '65+')")
        ->get();

    // Top 10 most borrowed books
    $topBooks = DB::table('transactions')
        ->join('books', 'transactions.book_id', '=', 'books.id')
        ->select('books.title', 'books.author', DB::raw('COUNT(*) as borrow_count'))
        ->groupBy('books.id', 'books.title', 'books.author')
        ->orderBy('borrow_count', 'desc')
        ->limit(10)
        ->get();

    // Most active members based on borrowing frequency
    $mostActiveMembers = DB::table('transactions')
        ->join('members', 'transactions.member_id', '=', 'members.id')
        ->select(
            'members.first_name',
            'members.middle_name',
            'members.last_name',
            'members.barangay',
            DB::raw('COUNT(*) as borrow_count'),
            DB::raw('MAX(transactions.created_at) as last_borrow')
        )
        ->groupBy('members.id', 'members.first_name', 'members.middle_name', 'members.last_name', 'members.barangay')
        ->orderBy('borrow_count', 'desc')
        ->limit(10)
        ->get()
        ->map(function($member) {
            return [
                'name' => trim($member->first_name . ' ' . ($member->middle_name ? $member->middle_name . ' ' : '') . $member->last_name),
                'barangay' => $member->barangay,
                'borrow_count' => $member->borrow_count,
                'last_borrow' => $member->last_borrow
            ];
        });

    // Most active members based on time-in/out frequency
    $mostActiveTimeLogMembers = DB::table('time_logs')
        ->join('members', 'time_logs.member_id', '=', 'members.id')
        ->select(
            'members.first_name',
            'members.middle_name',
            'members.last_name',
            'members.barangay',
            DB::raw('COUNT(*) as visit_count'),
            DB::raw('MAX(time_logs.created_at) as last_visit')
        )
        ->groupBy('members.id', 'members.first_name', 'members.middle_name', 'members.last_name', 'members.barangay')
        ->orderBy('visit_count', 'desc')
        ->limit(10)
        ->get()
        ->map(function($member) {
            return [
                'name' => trim($member->first_name . ' ' . ($member->middle_name ? $member->middle_name . ' ' : '') . $member->last_name),
                'barangay' => $member->barangay,
                'visit_count' => $member->visit_count,
                'last_visit' => $member->last_visit
            ];
        });

    $analyticsData = [
        'bookGenres' => $bookGenres,
        'julitaBarangays' => $julitaBarangaysWithCoords,
        'otherMunicipalities' => $otherMunicipalities,
        'ageDistribution' => $ageDistribution,
        'topBooks' => $topBooks,
        'mostActiveMembers' => $mostActiveMembers,
        'mostActiveTimeLogMembers' => $mostActiveTimeLogMembers,
    ];

    \Log::info('Analytics data prepared:', [
        'bookGenresCount' => $bookGenres->count(),
        'julitaBarangaysCount' => $julitaBarangaysWithCoords->count(),
        'otherMunicipalitiesCount' => $otherMunicipalities->count(),
        'ageDistributionCount' => $ageDistribution->count(),
        'topBooksCount' => $topBooks->count(),
        'mostActiveMembersCount' => $mostActiveMembers->count(),
        'mostActiveTimeLogMembersCount' => $mostActiveTimeLogMembers->count(),
    ]);

    return $analyticsData;
}

public function getAudioFiles()
{
    $audioPath = public_path('audio');
    $audioFiles = [];

    if (is_dir($audioPath)) {
        $files = scandir($audioPath);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && is_file($audioPath . '/' . $file)) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($extension, ['mp3', 'wav', 'ogg', 'm4a', 'aac'])) {
                    $audioFiles[] = [
                        'filename' => $file,
                        'title' => pathinfo($file, PATHINFO_FILENAME),
                        'url' => asset('audio/' . $file),
                        'size' => filesize($audioPath . '/' . $file)
                    ];
                }
            }
        }
    }

    return response()->json($audioFiles);
}

public function getMonthlyBorrowsApi()
{
    return response()->json($this->getMonthlyBorrowsData());
}

private function getMonthlyBorrowsData()
{
    $currentYear = Carbon::now()->year;

    \Log::info("Generating monthly borrows data for year: {$currentYear}");

    // Count all borrow transactions initiated in each month for borrowing trends
    $monthlyData = DB::table('transactions')
        ->selectRaw('MONTH(borrowed_at) as month, COUNT(*) as count')
        ->whereYear('borrowed_at', $currentYear)
        ->groupBy('month')
        ->orderBy('month')
        ->pluck('count', 'month')
        ->toArray();

    $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $data = [];

    for ($i = 1; $i <= 12; $i++) {
        $data[] = $monthlyData[$i] ?? 0;
    }

    $result = [
        'labels' => $labels,
        'data' => $data
    ];

    \Log::info('Monthly borrows data generated:', $result);

    return $result;
}

private function getMonthlyBorrowedBooksData()
{
    $currentYear = Carbon::now()->year;

    \Log::info("Generating monthly borrowed books data for year: {$currentYear}");

    // Count total books borrowed in each month (sum of quantities)
    $monthlyData = DB::table('transactions')
        ->selectRaw('MONTH(borrowed_at) as month, SUM(quantity) as total_books')
        ->whereYear('borrowed_at', $currentYear)
        ->groupBy('month')
        ->orderBy('month')
        ->pluck('total_books', 'month')
        ->toArray();

    $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $data = [];

    for ($i = 1; $i <= 12; $i++) {
        $data[] = $monthlyData[$i] ?? 0;
    }

    $result = [
        'labels' => $labels,
        'data' => $data
    ];

    \Log::info('Monthly borrowed books data generated:', $result);

    return $result;
}

public function getMonthlyBorrowedBooksApi()
{
    return response()->json($this->getMonthlyBorrowedBooksData());
}

public function getMonthlyBooksComparativeApi()
{
    return response()->json($this->getMonthlyBooksComparativeData());
}

private function getMonthlyBooksComparativeData()
{
    $currentYear = Carbon::now()->year;

    \Log::info("Generating monthly books comparative data for year: {$currentYear}");

    // Get borrows data
    $borrowsData = DB::table('transactions')
        ->selectRaw('MONTH(borrowed_at) as month, COUNT(*) as borrows')
        ->whereYear('borrowed_at', $currentYear)
        ->groupBy('month')
        ->orderBy('month')
        ->pluck('borrows', 'month')
        ->toArray();

    // Get returns data
    $returnsData = DB::table('transactions')
        ->selectRaw('MONTH(returned_at) as month, COUNT(*) as returns')
        ->whereYear('returned_at', $currentYear)
        ->whereNotNull('returned_at')
        ->groupBy('month')
        ->orderBy('month')
        ->pluck('returns', 'month')
        ->toArray();

    $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $borrows = [];
    $returns = [];

    for ($i = 1; $i <= 12; $i++) {
        $borrows[] = $borrowsData[$i] ?? 0;
        $returns[] = $returnsData[$i] ?? 0;
    }

    $result = [
        'labels' => $labels,
        'borrows' => $borrows,
        'returns' => $returns
    ];

    \Log::info('Monthly books comparative data generated:', $result);

    return $result;
}

public function getActiveAreasApi()
{
    return response()->json($this->getActiveAreasData());
}

public function getBooksTrendApi(Request $request)
{
    $period = $request->get('period', 'current'); // current, last
    return response()->json($this->getBooksTrendData($period));
}

public function getBookBorrowingFrequencyApi(Request $request)
{
    $month = $request->get('month');
    return response()->json($this->getBookBorrowingFrequencyData($month));
}

private function getBookBorrowingFrequencyData($month = null)
{
    \Log::info('Fetching book borrowing frequency data for comparative chart', ['month' => $month]);

    // Build query for book borrowing frequency
    $query = DB::table('transactions')
        ->join('books', 'transactions.book_id', '=', 'books.id')
        ->select('books.id', 'books.title', 'books.author', DB::raw('COUNT(*) as borrow_count'))
        ->groupBy('books.id', 'books.title', 'books.author')
        ->orderBy('borrow_count', 'desc');

    // Filter by month if specified
    if ($month) {
        $currentYear = Carbon::now()->year;
        $currentMonth = Carbon::now()->month;
        $year = $currentYear;

        // If requesting December and current month is January, use previous year
        if ($month == 12 && $currentMonth == 1) {
            $year = $currentYear - 1;
        }

        $query->whereYear('transactions.borrowed_at', $year)
              ->whereMonth('transactions.borrowed_at', $month);
    }

    $books = $query->get();

    $totalBorrows = $books->sum('borrow_count');

    \Log::info('Book borrowing frequency data fetched', [
        'total_books' => $books->count(),
        'total_borrows' => $totalBorrows,
        'month_filter' => $month
    ]);

    return [
        'books' => $books,
        'totalBorrows' => $totalBorrows
    ];
}

private function getBooksTrendData($period = 'current')
{
    $now = Carbon::now();
    
    // Determine the month to query
    if ($period === 'last') {
        $targetMonth = $now->copy()->subMonth();
    } else {
        $targetMonth = $now;
    }
    
    $year = $targetMonth->year;
    $month = $targetMonth->month;
    $daysInMonth = $targetMonth->daysInMonth;
    
    \Log::info("Generating books trend data for: {$targetMonth->format('F Y')}");
    
    // Get top 5 most borrowed books for the selected month
    $topBooks = DB::table('transactions')
        ->join('books', 'transactions.book_id', '=', 'books.id')
        ->select('books.id', 'books.title', DB::raw('COUNT(*) as borrow_count'))
        ->whereYear('transactions.borrowed_at', $year)
        ->whereMonth('transactions.borrowed_at', $month)
        ->groupBy('books.id', 'books.title')
        ->orderBy('borrow_count', 'desc')
        ->limit(5)
        ->get();
    
    // If no data for the month, return empty structure
    if ($topBooks->isEmpty()) {
        return [
            'labels' => [],
            'datasets' => [],
            'monthLabel' => $targetMonth->format('F Y')
        ];
    }
    
    // Generate day labels for the month
    $labels = [];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $labels[] = $day;
    }
    
    // Color palette for the lines
    $colors = [
        ['border' => 'rgba(99, 102, 241, 1)', 'background' => 'rgba(99, 102, 241, 0.1)'],
        ['border' => 'rgba(139, 92, 246, 1)', 'background' => 'rgba(139, 92, 246, 0.1)'],
        ['border' => 'rgba(34, 197, 94, 1)', 'background' => 'rgba(34, 197, 94, 0.1)'],
        ['border' => 'rgba(245, 158, 11, 1)', 'background' => 'rgba(245, 158, 11, 0.1)'],
        ['border' => 'rgba(239, 68, 68, 1)', 'background' => 'rgba(239, 68, 68, 0.1)'],
    ];
    
    $datasets = [];
    
    foreach ($topBooks as $index => $book) {
        // Get daily borrow counts for this book
        $dailyData = DB::table('transactions')
            ->selectRaw('DAY(borrowed_at) as day, COUNT(*) as count')
            ->where('book_id', $book->id)
            ->whereYear('borrowed_at', $year)
            ->whereMonth('borrowed_at', $month)
            ->groupBy('day')
            ->pluck('count', 'day')
            ->toArray();
        
        // Fill in data for each day
        $data = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $data[] = $dailyData[$day] ?? 0;
        }
        
        $colorIndex = $index % count($colors);
        
        // Truncate title if too long
        $displayTitle = strlen($book->title) > 25
            ? substr($book->title, 0, 22) . '...'
            : $book->title;
        
        $datasets[] = [
            'label' => $displayTitle,
            'data' => $data,
            'borderColor' => $colors[$colorIndex]['border'],
            'backgroundColor' => $colors[$colorIndex]['background'],
            'borderWidth' => 2,
            'fill' => false,
            'tension' => 0.4,
            'pointBackgroundColor' => $colors[$colorIndex]['border'],
            'pointBorderColor' => '#ffffff',
            'pointBorderWidth' => 2,
            'pointRadius' => 4,
            'pointHoverRadius' => 6
        ];
    }
    
    return [
        'labels' => $labels,
        'datasets' => $datasets,
        'monthLabel' => $targetMonth->format('F Y')
    ];
}

private function getActiveAreasData()
{
    // Get current month and year for filtering
    $now = Carbon::now();
    $currentYear = $now->year;
    $currentMonth = $now->month;

    try {
        // Query borrower activity by area for the current month
        $activeAreasQuery = DB::table('transactions')
            ->join('members', 'transactions.member_id', '=', 'members.id')
            ->select([
                'members.barangay',
                'members.municipality',
                DB::raw('COUNT(transactions.id) as activity_count')
            ])
            ->whereNotNull('members.barangay')
            ->whereNotNull('members.municipality')
            ->whereYear('transactions.borrowed_at', $currentYear)
            ->whereMonth('transactions.borrowed_at', $currentMonth)
            ->groupBy('members.barangay', 'members.municipality')
            ->orderBy('activity_count', 'desc')
            ->limit(10);

        $activeAreas = $activeAreasQuery->get();

        // Handle empty results
        if ($activeAreas->isEmpty()) {
            return [
                'labels' => [],
                'data' => []
            ];
        }

        // Format data for frontend chart
        $chartData = $activeAreas->map(function ($area) {
            // Create appropriate label based on municipality
            $label = ($area->municipality === 'Julita')
                ? $area->barangay
                : $area->barangay . ', ' . $area->municipality;

            return [
                'area' => $label,
                'count' => (int) $area->activity_count
            ];
        });

        return [
            'labels' => $chartData->pluck('area')->values()->toArray(),
            'data' => $chartData->pluck('count')->values()->toArray()
        ];

    } catch (\Exception $e) {
        \Log::error('Failed to retrieve active areas data: ' . $e->getMessage());
        // Return empty data structure to prevent frontend errors
        return [
            'labels' => [],
            'data' => []
        ];
    }
}

    public function getPeakHoursApi(Request $request)
    {
        $period = $request->get('period', 'week');
        return response()->json($this->getPeakHoursData($period));
    }

    public function getAgeActivityApi(Request $request)
    {
        $period = $request->get('period', 'week');
        return response()->json($this->getAgeActivityData($period));
    }

    private function getPeakHoursData($period = 'week')
    {
        try {
            $query = DB::table('time_logs')
                ->selectRaw('HOUR(created_at) as hour, COUNT(*) as count');

            // Apply period filter
            $now = Carbon::now();
            switch ($period) {
                case 'today':
                    $query->whereDate('created_at', $now->toDateString());
                    break;
                case 'week':
                    $query->whereBetween('created_at', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]);
                    break;
                case 'month':
                    $query->whereYear('created_at', $now->year)
                          ->whereMonth('created_at', $now->month);
                    break;
                default:
                    $query->whereBetween('created_at', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]);
            }

            $hourlyData = $query->groupBy('hour')
                                ->orderBy('hour')
                                ->pluck('count', 'hour')
                                ->toArray();

            // Create labels for all 24 hours
            $labels = [];
            $data = [];
            for ($hour = 0; $hour < 24; $hour++) {
                $labels[] = sprintf('%02d:00', $hour);
                $data[] = $hourlyData[$hour] ?? 0;
            }

            \Log::info("Peak hours data generated for period: {$period}", [
                'total_visits' => array_sum($data),
                'peak_hour' => array_keys($data, max($data))[0] ?? 'N/A'
            ]);

            return [
                'labels' => $labels,
                'data' => $data
            ];

        } catch (\Exception $e) {
            \Log::error('Error generating peak hours data: ' . $e->getMessage());
            // Return sample bell curve data for demonstration
            return [
                'labels' => array_map(fn($h) => sprintf('%02d:00', $h), range(0, 23)),
                'data' => [1, 0, 0, 0, 0, 0, 0, 2, 5, 8, 12, 15, 18, 22, 25, 20, 15, 8, 5, 2, 1, 0, 0, 0]
            ];
        }
    }

    private function getAgeActivityData($period = 'week')
    {
        try {
            // Define age groups
            $ageGroups = [
                '18-25' => [18, 25],
                '26-35' => [26, 35],
                '36-50' => [36, 50],
                '50+' => [51, 150]
            ];

            $datasets = [];

            // Get visit counts by age group
            $visitQuery = DB::table('time_logs')
                ->join('members', 'time_logs.member_id', '=', 'members.id')
                ->selectRaw('
                    CASE
                        WHEN members.age BETWEEN 18 AND 25 THEN "18-25"
                        WHEN members.age BETWEEN 26 AND 35 THEN "26-35"
                        WHEN members.age BETWEEN 36 AND 50 THEN "36-50"
                        ELSE "50+"
                    END as age_group,
                    COUNT(*) as visit_count
                ');

            // Apply period filter
            $now = Carbon::now();
            switch ($period) {
                case 'week':
                    $visitQuery->whereBetween('time_logs.created_at', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]);
                    break;
                case 'month':
                    $visitQuery->whereYear('time_logs.created_at', $now->year)
                              ->whereMonth('time_logs.created_at', $now->month);
                    break;
                case 'quarter':
                    $visitQuery->whereBetween('time_logs.created_at', [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()]);
                    break;
                default:
                    $visitQuery->whereBetween('time_logs.created_at', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]);
            }

            $visitData = $visitQuery->groupBy('age_group')
                                   ->pluck('visit_count', 'age_group')
                                   ->toArray();

            // Get average duration by age group
            $durationQuery = DB::table('time_logs')
                ->join('members', 'time_logs.member_id', '=', 'members.id')
                ->selectRaw('
                    CASE
                        WHEN members.age BETWEEN 18 AND 25 THEN "18-25"
                        WHEN members.age BETWEEN 26 AND 35 THEN "26-35"
                        WHEN members.age BETWEEN 36 AND 50 THEN "36-50"
                        ELSE "50+"
                    END as age_group,
                    AVG(TIMESTAMPDIFF(MINUTE, time_logs.created_at,
                        COALESCE(time_logs.time_out, NOW()))) as avg_duration
                ');

            // Apply same period filter
            switch ($period) {
                case 'week':
                    $durationQuery->whereBetween('time_logs.created_at', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]);
                    break;
                case 'month':
                    $durationQuery->whereYear('time_logs.created_at', $now->year)
                                 ->whereMonth('time_logs.created_at', $now->month);
                    break;
                case 'quarter':
                    $durationQuery->whereBetween('time_logs.created_at', [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()]);
                    break;
                default:
                    $durationQuery->whereBetween('time_logs.created_at', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()]);
            }

            $durationData = $durationQuery->whereNotNull('time_logs.time_out')
                                         ->groupBy('age_group')
                                         ->pluck('avg_duration', 'age_group')
                                         ->toArray();

            // Prepare datasets
            $labels = array_keys($ageGroups);

            // Dataset 1: Visit counts
            $visitCounts = [];
            foreach ($labels as $label) {
                $visitCounts[] = $visitData[$label] ?? 0;
            }

            // Dataset 2: Average duration (in minutes, rounded)
            $avgDurations = [];
            foreach ($labels as $label) {
                $avgDurations[] = round($durationData[$label] ?? 0, 1);
            }

            $datasets = [
                [
                    'label' => 'Visit Count',
                    'data' => $visitCounts
                ],
                [
                    'label' => 'Avg Duration (min)',
                    'data' => $avgDurations
                ]
            ];

            \Log::info("Age activity data generated for period: {$period}", [
                'total_visits' => array_sum($visitCounts),
                'age_groups' => count($labels)
            ]);

            return [
                'labels' => $labels,
                'datasets' => $datasets
            ];

        } catch (\Exception $e) {
            \Log::error('Error generating age activity data: ' . $e->getMessage());
            return [
                'labels' => ['18-25', '26-35', '36-50', '50+'],
                'datasets' => [
                    ['label' => 'Visit Count', 'data' => [0, 0, 0, 0]],
                    ['label' => 'Avg Duration (min)', 'data' => [0, 0, 0, 0]]
                ]
            ];
        }
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        
        $request->validate([
            'username' => 'required|string|max:255|unique:users,username,' . $user->id,
            'current_password' => 'required_with:new_password',
            'new_password' => 'nullable|required_with:current_password|min:4|confirmed',
        ]);
        
        // Verify current password if trying to change password
        if ($request->filled('new_password')) {
            if (!Hash::check($request->current_password, $user->password)) {
                SystemLog::log(
                    'profile_update_failed',
                    'Failed profile update attempt - incorrect current password',
                    $user->id,
                    ['reason' => 'incorrect_current_password']
                );
                
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect.'
                ], 400);
            }
            
            $user->password = Hash::make($request->new_password);
        }
        
        // Update username only (email and name cannot be edited by users)
        $user->username = $request->username;
        $user->save();
        
        // Log successful profile update
        SystemLog::log(
            'profile_updated',
            'User profile was successfully updated',
            $user->id,
            ['action' => 'profile_update', 'fields' => ['username', 'password']]
        );
        
        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully!'
        ]);
    }
}
