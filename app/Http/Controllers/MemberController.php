<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\TimeLog;
use App\Models\SystemLog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MemberController extends Controller
{
    public function index()
    {
        // Check if user has permission to view members
        if (!Auth::check() || !Auth::user()->hasPermission('manage-members')) {
            abort(403, 'Unauthorized. You do not have permission to view members.');
        }
        
         $members = Member::latest()->get(); // or paginate()

        foreach ($members as $member) {
            $qrFile = 'member-' . $member->id . '.png';
            $qrPath = public_path('qrcode/members/' . $qrFile);

            if (!file_exists($qrPath)) {
                $this->generateQrFile($member);
            }

            $member->qr_url = asset('qrcode/members/' . $qrFile);
        }

        return view('members.index', compact('members'));
    }

    public function store(Request $request)
    {
        // Check if user has permission to create members
        if (!Auth::check() || !Auth::user()->hasPermission('manage-members')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to create members.'], 403);
        }
        
        $validated = $request->validate([
            'firstName'     => 'required|string|max:100',
            'middleName'    => 'nullable|string|max:100',
            'lastName'      => 'required|string|max:100',
            'age'           => 'required|integer|min:1',
            'houseNumber'   => 'required|string|max:50',
            'street'        => 'required|string|max:100',
            'barangay'      => 'required|string|max:100',
            'municipality'  => 'required|string|max:100',
            'province'      => 'required|string|max:100',
            'contactNumber' => 'required|string|max:15',
            'email'         => 'required|email|unique:members,email',
            'school'        => 'required|string|max:255',
            'memberdate'    => 'required|date',
            'member_time'   => 'required|integer',
            'photo'         => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
        ]);

        $data = [
            'first_name'    => $validated['firstName'],
            'middle_name'   => $validated['middleName'] ?? null,
            'last_name'     => $validated['lastName'],
            'age'           => $validated['age'],
            'house_number'  => $validated['houseNumber'],
            'street'        => $validated['street'],
            'barangay'      => $validated['barangay'],
            'municipality'  => $validated['municipality'],
            'province'      => $validated['province'],
            'contactnumber' => $validated['contactNumber'],
            'email'         => $validated['email'],
            'email_verified' => Cache::pull("email_verified_registration_{$validated['email']}", false),
            'school'        => $validated['school'],
            'memberdate'    => $validated['memberdate'],
            'member_time'   => $validated['member_time'],
        ];

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = time() . '_' . $file->getClientOriginalName();
            $uploadPath = public_path('resource/member_images');

            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            $file->move($uploadPath, $filename);
            $data['photo'] = $filename;
        }

        $member = Member::create($data);

        // Generate QR and Card
        $this->generateQrFile($member);

        // Log member creation
        SystemLog::log(
            'member_created',
            "Member '{$member->first_name} {$member->last_name}' was registered",
            Auth::id(),
            [
                'member_id' => $member->id,
                'member_name' => trim($member->first_name . ' ' . ($member->middle_name ?? '') . ' ' . $member->last_name),
                'member_email' => $member->email,
                'member_barangay' => $member->barangay,
                'member_municipality' => $member->municipality
            ]
        );

        return response()->json([
    'success' => true,
    'message' => '✅ Member registered successfully!',
    'member_id' => $member->id,
    'cardUrl' => asset("card/member_{$member->id}.pdf")
]);

    }

public function update(Request $request, $id)
{
    // Check if user has permission to update members
    if (!Auth::check() || !Auth::user()->hasPermission('manage-members')) {
        return response()->json(['message' => 'Unauthorized. You do not have permission to update members.'], 403);
    }
    
    $member = Member::findOrFail($id);

    // validate camelCase inputs
    $validated = $request->validate([
        'firstName'     => 'required|string|max:255',
        'middleName'    => 'nullable|string|max:255',
        'lastName'      => 'required|string|max:255',
        'age'           => 'required|integer|min:1|max:150',
        'houseNumber'   => 'nullable|string|max:255',
        'street'        => 'nullable|string|max:255',
        'barangay'      => 'required|string|max:255',
        'municipality'  => 'required|string|max:255',
        'province'      => 'required|string|max:255',
        'contactNumber' => 'required|string|max:20',
        'email'         => 'required|email|unique:members,email,' . $id,
        'school'        => 'nullable|string|max:255',
        'photo'         => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
    ]);

    // map camelCase → snake_case
    $data = [
        'first_name'   => $validated['firstName'],
        'middle_name'  => $validated['middleName'] ?? null,
        'last_name'    => $validated['lastName'],
        'age'          => $validated['age'],
        'house_number' => $validated['houseNumber'] ?? null,
        'street'       => $validated['street'] ?? null,
        'barangay'     => $validated['barangay'],
        'municipality' => $validated['municipality'],
        'province'     => $validated['province'],
        'contactnumber'=> $validated['contactNumber'],
        'email'        => $validated['email'],
        'school'       => $validated['school'] ?? null,
    ];

    // handle photo
    if ($request->hasFile('photo')) {
        if ($member->photo && file_exists(storage_path('app/public/member_photos/' . $member->photo))) {
            unlink(storage_path('app/public/member_photos/' . $member->photo));
        }

        $photoName = time() . '.' . $request->photo->extension();
        $request->photo->storeAs('public/member_photos', $photoName);
        $data['photo'] = $photoName;
    }

    // Get old values before update
    $oldValues = $member->only(array_keys($data));

    $member->update($data);

    // Log member update
    SystemLog::log(
        'member_updated',
        "Member '{$member->first_name} {$member->last_name}' was updated",
        Auth::id(),
        [
            'member_id' => $member->id,
            'member_name' => trim($member->first_name . ' ' . ($member->middle_name ?? '') . ' ' . $member->last_name),
            'old_values' => $oldValues,
            'new_values' => $data
        ]
    );

    return response()->json([
        'success' => true,
        'message' => 'Member updated successfully!',
        'member'  => $member
    ]);
}


    public function destroy($id)
    {
        // Check if user has permission to delete members
        if (!Auth::check() || !Auth::user()->hasPermission('manage-members')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to delete members.'], 403);
        }
        
        $member = Member::find($id);

        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'Member not found.'
            ], 404);
        }

        if ($member->photo) {
            $photoPath = public_path('resource/member_images/' . $member->photo);
            if (File::exists($photoPath)) {
                File::delete($photoPath);
            }
        }

        $qrPath = public_path('qrcode/members/member-' . $member->id . '.png');
        if (File::exists($qrPath)) {
            File::delete($qrPath);
        }

        $memberData = [
            'member_id' => $member->id,
            'member_name' => trim($member->first_name . ' ' . ($member->middle_name ?? '') . ' ' . $member->last_name),
            'member_email' => $member->email
        ];

        $member->delete();

        // Log member deletion
        SystemLog::log(
            'member_deleted',
            "Member '{$memberData['member_name']}' was deleted from the system",
            Auth::id(),
            $memberData
        );

        return response()->json([
            'success' => true,
            'message' => '🗑️ Member deleted successfully.'
        ]);
    }

    private function generateQrFile(Member $member)
    {
        $dir = public_path('qrcode/members/');
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        $qrPath = $dir . 'member-' . $member->id . '.png';

        if (!file_exists($qrPath)) {
            $options = new QROptions([
                'outputType' => QRCode::OUTPUT_IMAGE_PNG,
                'eccLevel'   => QRCode::ECC_H,
                'scale'      => 8,
                'margin'     => 10,
            ]);

            $qrData = route('members.show', $member->id);
            (new QRCode($options))->render($qrData, $qrPath);
        }

        return $qrPath;
    }


    public function jsonShow($id)
    {
        $member = Member::find($id);

        if (!$member) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'id'           => $member->id,
            'firstName'    => $member->firstName,
            'middleName'   => $member->middleName,
            'lastName'     => $member->lastName,
            'age'          => $member->age,
            'barangay'     => $member->barangay,
            'municipality' => $member->municipality,
            'province'     => $member->province,
            'contactNumber'=> $member->contactNumber,
            'memberdate'   => $member->memberdate,

            // ✅ Photo URL or null
            'photo' => $member->photo
                ? URL::to('/resource/member_images/' . $member->photo)
                : null,

            // ✅ QR code URL (format: member-{id}.png)
            'qr' => URL::to('/qrcode/members/member-' . $member->id . '.png'),

            // ✅ Preformatted full name
            'fullName' => trim(implode(' ', array_filter([
                $member->firstName,
                $member->middleName !== "null" ? $member->middleName : null,
                $member->lastName,
            ]))),
        ]);
    }

    public function apiShow($id)
    {
        return $this->show($id);
    }

    public function show($id)
    {
        $member = Member::findOrFail($id);

        if (!$member) {
            return response()->json(['error' => 'Member not found'], 404);
        }

        // Ensure null values are replaced with empty strings
        $first = $member->first_name ?? '';
        $middle = $member->middle_name ?? '';
        $last = $member->last_name ?? '';

        // Remove extra spaces if middle name is empty
        $fullName = trim("{$first} {$middle} {$last}");

        return response()->json($member);
    }

    public function getBorrowingHistory($memberId)
    {
        $member = Member::findOrFail($memberId);

        // Get borrowing history from transactions table
        $borrowingHistory = Transaction::where('member_id', $memberId)
            ->with('book')
            ->orderBy('borrowed_at', 'desc')
            ->take(10)
            ->get()
            ->map(function ($transaction) {
                $status = $transaction->status;

                // If status is still 'borrowed' but due date has passed, mark as overdue
                if ($status === 'borrowed' && $transaction->due_date && now()->isAfter($transaction->due_date)) {
                    $status = 'overdue';
                }

                return [
                    'book_title' => $transaction->book ? $transaction->book->title : 'Unknown Book',
                    'borrowed_date' => $transaction->borrowed_at,
                    'due_date' => $transaction->due_date,
                    'returned_at' => $transaction->returned_at,
                    'status' => $status
                ];
            });

        return response()->json($borrowingHistory);
    }

    public function getTimelogHistory($memberId)
    {
        $member = Member::findOrFail($memberId);

        // Get timelog history
        $timelogHistory = TimeLog::where('member_id', $memberId)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->map(function ($timelog) {
                return [
                    'action' => $timelog->action,
                    'created_at' => $timelog->created_at
                ];
            });

        return response()->json($timelogHistory);
    }

    /**
     * Get demographics data for dashboard stratification
     */
    public function getDemographicsData()
    {
        // Get Julita members
        $julitaMembers = Member::whereRaw('LOWER(TRIM(municipality)) = ?', ['julita'])
            ->select('barangay', 'age')
            ->get()
            ->map(function($member) {
                return [
                    'barangay' => trim($member->barangay ?? ''),
                    'age' => (int) $member->age
                ];
            });

        // Get non-Julita members
        $nonJulitaMembers = Member::whereRaw('LOWER(TRIM(municipality)) != ? OR municipality IS NULL', ['julita'])
            ->select('municipality', 'province', 'age')
            ->get()
            ->map(function($member) {
                return [
                    'municipality' => trim($member->municipality ?? ''),
                    'province' => trim($member->province ?? ''),
                    'age' => (int) $member->age
                ];
            });

        // Get total counts
        $totalMembers = Member::count();
        $julitaCount = $julitaMembers->count();
        $nonJulitaCount = $nonJulitaMembers->count();
        return response()->json([
            'julitaMembers' => $julitaMembers->toArray(),
            'nonJulitaMembers' => $nonJulitaMembers->toArray(),
            'totalMembers' => $totalMembers,
            'julitaCount' => $julitaCount,
            'nonJulitaCount' => $nonJulitaCount
        ]);
    }

    public function sendEmailCode(Request $request, $memberId)
    {
        $member = Member::findOrFail($memberId);

        if (!$member->email) {
            return response()->json(['success' => false, 'message' => 'No email address found for this member.'], 400);
        }

        if ($member->email_verified) {
            return response()->json(['success' => false, 'message' => 'Email is already verified.'], 400);
        }

        // Generate 6-digit code
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store code in cache for 10 minutes
        Cache::put("email_verification_{$memberId}", $code, now()->addMinutes(10));

        try {
            Mail::raw("Hi {$member->first_name},\n\n" .
                "Your email verification code is: {$code}", function ($message) use ($member) {
                $message->to($member->email)
                        ->subject('Email Verification Code - Julita Public Library');
            });

            Log::info("Email verification code sent to {$member->email} for member ID {$memberId}");

            $response = ['success' => true, 'message' => 'Verification code sent to your email.'];

            // In development/debug mode, include code for testing
            if (config('app.debug') && config('mail.default') === 'log') {
                $response['debug_code'] = $code;
                $response['message'] .= ' (Check logs for code in debug mode)';
            }
            
            return response()->json($response);
        } catch (\Exception $e) {
            Log::error("Failed to send email verification code to {$member->email}: " . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false, 
                'message' => 'Failed to send email: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verifyEmailCode(Request $request, $memberId)
    {
        $request->validate([
            'code' => 'required|string|size:6'
        ]);

        $member = Member::findOrFail($memberId);
        $cachedCode = Cache::get("email_verification_{$memberId}");

        if (!$cachedCode || $cachedCode !== $request->code) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired verification code.'], 400);
        }

        // Mark email as verified
        $member->update(['email_verified' => true]);

        // Clear the cache
        Cache::forget("email_verification_{$memberId}");

        return response()->json(['success' => true, 'message' => 'Email verified successfully!']);
    }

    public function sendEmailCodeForRegistration(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $email = $request->email;

        // Check if email already exists
        if (Member::where('email', $email)->exists()) {
            return response()->json(['success' => false, 'message' => 'Email already registered.']);
        }

        // Generate 6-digit code
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store in cache for 10 minutes
        Cache::put("email_verification_registration_{$email}", $code, now()->addMinutes(10));

        try {
            Mail::raw("Your email verification code is: {$code}", function ($message) use ($email) {
                $message->to($email)->subject('Email Verification Code - Julita Public Library');
            });

            \Log::info("Email verification code sent to {$email} for registration");
            
            $response = ['success' => true, 'message' => 'Verification code sent to your email.'];
            
            // In development/debug mode, include code for testing
            if (config('app.debug') && config('mail.default') === 'log') {
                $response['debug_code'] = $code;
                $response['message'] .= ' (Check logs for code in debug mode)';
            }
            
            return response()->json($response);
        } catch (\Exception $e) {
            Log::error("Failed to send email verification code to {$email}: " . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false, 
                'message' => 'Failed to send email: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function verifyEmailCodeForRegistration(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6'
        ]);

        $email = $request->email;
        $cachedCode = Cache::get("email_verification_registration_{$email}");

        if (!$cachedCode || $cachedCode !== $request->code) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired verification code.'], 400);
        }

        // Mark as verified for registration
        Cache::put("email_verified_registration_{$email}", true, now()->addMinutes(10));
        Cache::forget("email_verification_registration_{$email}");

        return response()->json(['success' => true, 'message' => 'Email verified successfully!']);
    }
}