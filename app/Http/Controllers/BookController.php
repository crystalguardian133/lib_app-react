<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Inertia\Inertia;

class BookController extends Controller{
    public function index(Request $request)
    {
        // Check if user has permission to view books
        if (!Auth::check() || !Auth::user()->hasPermission('manage-books')) {
            abort(403, 'Unauthorized. You do not have permission to view books.');
        }
        
        // Get per_page parameter from request, default to 10
        $perPage = $request->get('per_page', 10);
        
        $books = Book::paginate($perPage);

        foreach ($books as $book) {
            // Always regenerate QR codes regardless of whether they exist or not
            $this->generateQrFile($book);

            $qrFileName = 'book-' . $book->id . '.png';
            $book->qr_url = asset('qrcode/books/' . $qrFileName);
        }

        return inertia('books/books', compact('books', 'perPage'));
        
    }

public function store(Request $request)
{
    // Check if user has permission to create books
    if (!Auth::check() || !Auth::user()->hasPermission('manage-books')) {
        return response()->json(['error' => 'Unauthorized. You do not have permission to create books.'], 403);
    }
    
    // Debug: Log the request data
    \Log::info('Book creation request data:', $request->all());
    \Log::info('Files in request:', $request->allFiles());

    $validated = $request->validate([
        'title' => 'required|string|max:255',
        'author' => 'required|string|max:255',
        'genre' => 'nullable|string|max:50',
        'published_year' => 'required|integer|min:1000|max:3000',
        'availability' => 'required|integer|min:0',
        'cover' => 'nullable|image|mimes:jpeg,jpg,png,gif|max:5120', // 5MB to match your JS, consistent mimes order
        'temp_image' => 'nullable|string' // For temp uploaded images
    ]);

    // Remove cover and temp_image from validated data to avoid mass assignment issues
    $bookData = collect($validated)->except(['cover', 'temp_image'])->toArray();

    try {
        // Create the book record first
        $book = Book::create($bookData);
        \Log::info('Book created with ID: ' . $book->id);

        // Handle cover upload - check for temp image first, then regular upload
        if ($request->has('temp_image') && $request->temp_image) {
            \Log::info('Temp image specified: ' . $request->temp_image);

            // Parse temp image data (should be JSON)
            $tempImageData = json_decode($request->temp_image, true);
            if ($tempImageData && isset($tempImageData['temp_name'])) {
                $tempPath = public_path('temp_uploads/' . $tempImageData['temp_name']);
                $coverPath = public_path('cover');

                if (file_exists($tempPath)) {
                    // Generate new filename for cover
                    $ext = pathinfo($tempImageData['temp_name'], PATHINFO_EXTENSION);
                    $fileName = 'book-' . $book->id . '-' . time() . '.' . $ext;

                    // Ensure cover directory exists
                    if (!file_exists($coverPath)) {
                        mkdir($coverPath, 0755, true);
                    }

                    // Move temp file to cover directory
                    if (rename($tempPath, $coverPath . '/' . $fileName)) {
                        $book->cover_image = 'cover/' . $fileName;
                        $book->save();
                        \Log::info('Temp image moved to cover successfully: ' . $fileName);
                    } else {
                        \Log::error('Failed to move temp image to cover directory');
                    }
                } else {
                    \Log::error('Temp image file not found: ' . $tempPath);
                }
            }
        } elseif ($request->hasFile('cover')) {
            \Log::info('Cover file detected');

            $file = $request->file('cover');

            // Additional file validation
            if (!$file->isValid()) {
                \Log::error('Invalid file upload');
                return response()->json(['error' => 'Invalid file upload'], 400);
            }

            $originalName = $file->getClientOriginalName();
            $ext = $file->getClientOriginalExtension();
            $fileName = 'book-' . $book->id . '-' . time() . '.' . $ext;
            $destination = public_path('cover');

            \Log::info('Attempting to upload: ' . $originalName . ' as ' . $fileName);

            // Make sure directory exists
            if (!file_exists($destination)) {
                if (!mkdir($destination, 0755, true)) {
                    \Log::error('Failed to create cover directory');
                    return response()->json(['error' => 'Failed to create upload directory'], 500);
                }
                \Log::info('Created cover directory');
            }

            // Check if directory is writable
            if (!is_writable($destination)) {
                \Log::error('Cover directory is not writable: ' . $destination);
                return response()->json(['error' => 'Upload directory is not writable'], 500);
            }

            // Move file to destination
            if ($file->move($destination, $fileName)) {
                // Verify the file was actually moved
                $fullPath = $destination . '/' . $fileName;
                if (file_exists($fullPath)) {
                    // Save the relative path in the database
                    $book->cover_image = 'cover/' . $fileName;
                    if ($book->save()) {
                        \Log::info('Cover uploaded and saved successfully: ' . $fileName);
                        \Log::info('Database updated with cover_image: ' . $book->cover_image);
                    } else {
                        \Log::error('Failed to save cover_image to database');
                    }
                } else {
                    \Log::error('File was not found after move operation');
                }
            } else {
                \Log::error('Failed to move uploaded file to: ' . $destination . '/' . $fileName);
                return response()->json(['error' => 'Failed to save uploaded file'], 500);
            }
        } else {
            \Log::info('No cover file in request');
        }

        // Generate QR code
        $this->generateQrFile($book);

        // Refresh the model to get all updated data
        $book = $book->fresh();

        // Log book creation
        SystemLog::log(
            'book_created',
            "Book '{$book->title}' by {$book->author} was added to the library",
            Auth::id(),
            [
                'book_id' => $book->id,
                'book_title' => $book->title,
                'book_author' => $book->author,
                'book_genre' => $book->genre,
                'published_year' => $book->published_year,
                'availability' => $book->availability
            ]
        );

        return response()->json([
            'message' => 'Book added successfully!',
            'book' => $book
        ], 201);

    } catch (\Exception $e) {
        \Log::error('Error creating book: ' . $e->getMessage());
        \Log::error('Stack trace: ' . $e->getTraceAsString());
        
        return response()->json([
            'error' => 'Failed to create book: ' . $e->getMessage()
        ], 500);
    }
}

    public function show($id)
    {
        return response()->json(Book::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        // Check if user has permission to update books
        if (!Auth::check() || !Auth::user()->hasPermission('manage-books')) {
            return response()->json(['error' => 'Unauthorized. You do not have permission to update books.'], 403);
        }
        
        $book = Book::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'genre' => 'nullable|string|max:50',
            'published_year' => 'required|integer|min:1000|max:3000',
            'availability' => 'required|integer|min:0',
            'cover' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        if ($request->hasFile('cover')) {
            $file = $request->file('cover');
            $filename = 'cover-' . time() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('cover'), $filename);
            $validated['cover_image'] = 'cover/' . $filename;
        }

        // Get old values before update
        $oldValues = $book->only(array_keys($validated));

        $book->update($validated);
        $this->generateQrFile($book);

        // Log book update
        SystemLog::log(
            'book_updated',
            "Book '{$book->title}' by {$book->author} was updated",
            Auth::id(),
            [
                'book_id' => $book->id,
                'book_title' => $book->title,
                'book_author' => $book->author,
                'old_values' => $oldValues,
                'new_values' => $validated
            ]
        );

        return response()->json(['success' => true, 'message' => 'Book updated']);
    }

    public function destroy($id)
    {
        // Check if user has permission to delete books
        if (!Auth::check() || !Auth::user()->hasPermission('manage-books')) {
            return response()->json(['error' => 'Unauthorized. You do not have permission to delete books.'], 403);
        }
        
        try {
            $book = Book::findOrFail($id);

            // Check if the book is currently borrowed
            $activeTransactions = \DB::table('transactions')
                ->where('book_id', $id)
                ->where('status', 'borrowed')
                ->count();

            if ($activeTransactions > 0) {
                return response()->json([
                    'error' => 'Cannot delete book: It is currently borrowed by ' . $activeTransactions . ' member(s).'
                ], 400);
            }

            $bookData = [
                'book_id' => $book->id,
                'book_title' => $book->title,
                'book_author' => $book->author
            ];

            // Delete related records manually to avoid cascade issues
            \DB::table('returns')->whereIn('transaction_id', function ($query) use ($id) {
                $query->select('id')->from('transactions')->where('book_id', $id);
            })->delete();

            \DB::table('transactions')->where('book_id', $id)->delete();

            Book::destroy($id);

            // Log book deletion (optional, don't fail if logging fails)
            try {
                SystemLog::log(
                    'book_deleted',
                    "Book '{$bookData['book_title']}' by {$bookData['book_author']} was deleted from the library",
                    Auth::id()
                );
            } catch (\Exception $e) {
                \Log::warning('Failed to log book deletion: ' . $e->getMessage());
            }

            return response()->json(['success' => true, 'message' => 'Book deleted']);
        } catch (\Exception $e) {
            \Log::error('Error deleting book: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'error' => 'Failed to delete book: ' . $e->getMessage()
            ], 500);
        }
    }

    public function uploadTempImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120' // 5MB max
        ]);

        if ($request->hasFile('image')) {
            $file = $request->file('image');

            // Create temp directory if it doesn't exist
            $tempDir = public_path('temp_uploads');
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // Generate unique filename with temp_ prefix
            $filename = 'temp_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move($tempDir, $filename);

            $tempPath = $tempDir . '/' . $filename;

            return response()->json([
                'success' => true,
                'image' => [
                    'name' => $file->getClientOriginalName(),
                    'temp_name' => $filename,
                    'path' => 'temp_uploads/' . $filename,
                    'url' => asset('temp_uploads/' . $filename),
                    'size' => filesize($tempPath),
                    'modified' => date('Y-m-d H:i:s', filemtime($tempPath)),
                    'is_temp' => true
                ]
            ]);
        }

        return response()->json(['success' => false, 'error' => 'No image uploaded'], 400);
    }

    public function getMediaImages()
    {
        $imageDirectories = [
            public_path('images'),
            public_path('cover'),
            public_path('qrcode/books'),
            public_path('resource/member_images'),
            public_path('temp_uploads') // Include temp uploads
        ];

        $images = [];

        foreach ($imageDirectories as $directory) {
            if (file_exists($directory) && is_dir($directory)) {
                $files = scandir($directory);

                foreach ($files as $file) {
                    $filePath = $directory . '/' . $file;
                    $fileName = basename($file);

                    // Skip directories and hidden files
                    if (is_file($filePath) && !str_starts_with($fileName, '.')) {
                        // Check if it's an image file
                        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                        if (in_array($extension, $imageExtensions)) {
                            $isTemp = strpos($directory, 'temp_uploads') !== false;
                            $images[] = [
                                'name' => $isTemp ? str_replace('temp_' . preg_replace('/^temp_\d+_/', '', $fileName), '', $fileName) : $fileName,
                                'temp_name' => $isTemp ? $fileName : null,
                                'path' => str_replace(public_path(), '', $filePath),
                                'url' => asset(ltrim(str_replace(public_path(), '', $filePath), '/')),
                                'size' => filesize($filePath),
                                'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
                                'is_temp' => $isTemp
                            ];
                        }
                    }
                }
            }
        }

        // Sort by modified date (newest first)
        usort($images, function($a, $b) {
            return strtotime($b['modified']) - strtotime($a['modified']);
        });

        return response()->json($images);
    }

    public function cleanupTempImages()
    {
        $tempDir = public_path('temp_uploads');
        if (!file_exists($tempDir)) {
            return response()->json(['message' => 'No temp directory found']);
        }

        $files = scandir($tempDir);
        $deletedCount = 0;
        $cutoffTime = time() - (24 * 60 * 60); // 24 hours ago

        foreach ($files as $file) {
            $filePath = $tempDir . '/' . $file;

            // Skip directories and hidden files
            if (is_file($filePath) && !str_starts_with($file, '.')) {
                // Delete files older than 24 hours
                if (filemtime($filePath) < $cutoffTime) {
                    if (unlink($filePath)) {
                        $deletedCount++;
                    }
                }
            }
        }

        return response()->json([
            'message' => "Cleaned up {$deletedCount} old temp files"
        ]);
    }

    private function generateQrFile(Book $book)
    {
        $qrFileName = 'book-' . $book->id . '.png';
        $qrPath = public_path('qrcode/books/' . $qrFileName);

        if (!file_exists(dirname($qrPath))) {
            mkdir(dirname($qrPath), 0755, true);
        }

        // Always regenerate QR code - delete existing file if it exists
        if (file_exists($qrPath)) {
            unlink($qrPath);
        }

        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel' => QRCode::ECC_H,
            'scale' => 10,
        ]);

        $qrData = route('books.show', $book->id); // QR code links to book details
        (new QRCode($options))->render($qrData, $qrPath);

        $book->qr_url = asset('qrcode/books/' . $qrFileName);
        $book->save();
    }
}
