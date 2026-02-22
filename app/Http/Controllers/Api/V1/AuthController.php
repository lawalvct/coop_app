<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeEmail;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login for members and admins.
     *
     * Accepts email or member_no + password.
     * Returns a Sanctum token, user profile, and role.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|string',
            'password' => 'required|string',
        ]);

        // Determine if login is via email or member number
        $field = filter_var($request->email, FILTER_VALIDATE_EMAIL) ? 'email' : 'member_no';

        $user = User::where($field, $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        // Check admin approval
        if ($user->admin_sign === 'No') {
            return response()->json([
                'success' => false,
                'message' => 'Your account is pending admin approval.',
            ], 403);
        }

        // Determine role / abilities
        $role = $user->is_admin ? 'admin' : 'member';
        $abilities = $user->is_admin
            ? ['admin', 'member']
            : ['member'];

        // Revoke previous tokens from the same device name (optional: keeps one active per device)
        $deviceName = $request->input('device_name', 'mobile');
        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName, $abilities)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data'    => [
                'token' => $token,
                'role'  => $role,
                'user'  => $this->formatUser($user),
            ],
        ]);
    }

    /**
     * Register a new member (multi-step data sent in one request for API).
     *
     * Files (member_image, signature_image) should be sent as multipart/form-data.
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            // Personal
            'title'       => 'required|string',
            'surname'     => 'required|string|max:100',
            'firstname'   => 'required|string|max:100',
            'othername'   => 'nullable|string|max:100',
            'dob'         => 'required|date',
            'nationality' => 'required|string',

            // Contact
            'home_address' => 'required|string',
            'phone_number' => 'required|string',
            'email'        => 'required|email|unique:users,email',
            'state_id'     => 'required|exists:states,id',
            'lga_id'       => 'required|exists:lgas,id',

            // Employment
            'staff_no'      => 'required|string|unique:users,staff_no',
            'faculty_id'    => 'required|exists:faculties,id',
            'department_id' => 'required|exists:departments,id',

            // Next of Kin
            'nok'              => 'required|string',
            'nok_relationship' => 'required|string',
            'nok_address'      => 'required|string',
            'nok_phone'        => 'required|string',

            // Financial
            'monthly_savings'      => 'required|numeric|min:0',
            'share_subscription'   => 'required|numeric|min:0',
            'month_commence'       => 'required|string',

            // Documents & credentials
            'member_image'    => 'required|image|max:2048',
            'signature_image' => 'required|image|max:2048',
            'password'        => 'required|string|min:8|confirmed',

            // Declarations
            'salary_deduction_agreement' => 'required|accepted',
            'membership_declaration'     => 'required|accepted',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Handle file uploads
        if ($request->hasFile('member_image')) {
            $data['member_image'] = $request->file('member_image')->store('members', 'public');
        }
        if ($request->hasFile('signature_image')) {
            $data['signature_image'] = $request->file('signature_image')->store('signatures', 'public');
        }

        // Hash password
        $data['password'] = Hash::make($data['password']);

        // Generate member number
        $data['member_no']  = 'OASCMS-Form-' . rand(1, 9999);
        $data['date_join']  = now();
        $data['admin_sign'] = 'No'; // Awaiting admin approval
        $data['salary_deduction_agreement'] = true;
        $data['membership_declaration']     = true;

        try {
            $user = User::create($data);

            Mail::to($user->email)->send(new WelcomeEmail($user));

            return response()->json([
                'success' => true,
                'message' => 'Registration completed successfully. Please check your email. Your account is pending admin approval.',
                'data'    => [
                    'user' => $this->formatUser($user),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get the authenticated user's profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['faculty', 'department', 'state', 'lga']);

        return response()->json([
            'success' => true,
            'data'    => $this->formatUser($user),
        ]);
    }

    /**
     * Logout – revoke the current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Logout from all devices – revoke all tokens.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out from all devices.',
        ]);
    }

    /**
     * Change password for authenticated user.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $user->update(['password' => Hash::make($request->password)]);

        // Revoke all tokens so user must re-login
        $user->tokens()->delete();

        $token = $user->createToken($request->input('device_name', 'mobile'), $user->is_admin ? ['admin', 'member'] : ['member'])->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
            'data'    => ['token' => $token],
        ]);
    }

    /**
     * Forgot password – send reset link.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        // Generate a simple token and send reset email
        $token = \Illuminate\Support\Str::random(64);

        \DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            [
                'token'      => Hash::make($token),
                'created_at' => now(),
            ]
        );

        // Send reset email using existing mail class
        Mail::to($user->email)->send(new \App\Mail\ResetPasswordMail($token));

        return response()->json([
            'success' => true,
            'message' => 'Password reset link sent to your email.',
        ]);
    }

    // ────────────────────────────────────────────
    // Helper: lookup endpoints for registration form
    // ────────────────────────────────────────────

    /**
     * List all active states.
     */
    public function states(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => State::where('status', 'active')->get(['id', 'name']),
        ]);
    }

    /**
     * List LGAs for a given state.
     */
    public function lgas(int $stateId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => Lga::where('state_id', $stateId)->where('status', 'active')->get(['id', 'name']),
        ]);
    }

    /**
     * List all active faculties.
     */
    public function faculties(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => Faculty::where('status', 'active')->get(['id', 'name']),
        ]);
    }

    /**
     * List departments for a given faculty.
     */
    public function departments(int $facultyId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => Department::where('faculty_id', $facultyId)->get(['id', 'name']),
        ]);
    }

    // ────────────────────────────────────────────
    //  Private helpers
    // ────────────────────────────────────────────

    private function formatUser(User $user): array
    {
        return [
            'id'               => $user->id,
            'title'            => $user->title,
            'surname'          => $user->surname,
            'firstname'        => $user->firstname,
            'othername'        => $user->othername,
            'email'            => $user->email,
            'phone_number'     => $user->phone_number,
            'member_no'        => $user->member_no,
            'staff_no'         => $user->staff_no,
            'dob'              => $user->dob,
            'nationality'      => $user->nationality,
            'home_address'     => $user->home_address,
            'state'            => $user->state?->name,
            'lga'              => $user->lga?->name,
            'faculty'          => $user->faculty?->name,
            'department'       => $user->department?->name,
            'nok'              => $user->nok,
            'nok_relationship' => $user->nok_relationship,
            'nok_phone'        => $user->nok_phone,
            'nok_address'      => $user->nok_address,
            'monthly_savings'  => $user->monthly_savings,
            'share_subscription' => $user->share_subscription,
            'month_commence'   => $user->month_commence,
            'member_image'     => $user->member_image ? asset('storage/' . $user->member_image) : null,
            'signature_image'  => $user->signature_image ? asset('storage/' . $user->signature_image) : null,
            'is_admin'         => (bool) $user->is_admin,
            'admin_sign'       => $user->admin_sign,
            'status'           => $user->status,
            'date_join'        => $user->date_join ?? $user->created_at?->toDateString(),
            'created_at'       => $user->created_at?->toIso8601String(),
        ];
    }
}
