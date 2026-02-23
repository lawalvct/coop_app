<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Helpers\TransactionHelper;
use App\Mail\AccountActivatedEmail;
use App\Models\Commodity;
use App\Models\CommodityPayment;
use App\Models\CommoditySubscription;
use App\Models\Department;
use App\Models\EntranceFee;
use App\Models\Faculty;
use App\Models\Faq;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\LoanType;
use App\Models\Month;
use App\Models\MonthlySavingsSetting;
use App\Models\Permission;
use App\Models\ProfileUpdateRequest;
use App\Models\Resource;
use App\Models\Role;
use App\Models\Saving;
use App\Models\SavingType;
use App\Models\Share;
use App\Models\ShareType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\Year;
use App\Notifications\CommoditySubscriptionStatusUpdated;
use App\Notifications\LoanStatusNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    // ═══════════════════════════════════════════════════════════
    //  DASHBOARD
    // ═══════════════════════════════════════════════════════════

    public function dashboard()
    {
        $totalMembers       = User::where('is_admin', false)->where('admin_sign', 'Yes')->count();
        $newMembersThisMonth = User::where('is_admin', false)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $totalAdmins = User::where('is_admin', true)->count();

        $totalSavings = Saving::sum('amount');
        $monthlySavings = Saving::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)->sum('amount');

        $totalShares     = Share::where('status', 'approved')->sum('amount_paid');
        $totalShareUnits = Share::where('status', 'approved')->count();

        $activeLoans     = Loan::where('status', 'approved')
            ->whereRaw('amount > COALESCE(paid_amount, 0)')->count();
        $totalLoanAmount = Loan::where('status', 'approved')->sum('amount');
        $totalRepayments = Loan::where('status', 'approved')->sum('paid_amount');
        $outstandingLoans = $totalLoanAmount - $totalRepayments;

        $totalWithdrawals   = Withdrawal::where('status', 'completed')->sum('amount');
        $pendingWithdrawals = Withdrawal::where('status', 'pending')->count();

        $totalCommodities   = Commodity::where('is_active', true)->count();
        $totalResources     = Resource::where('status', 'active')->count();

        $savingBalance = $totalSavings - $totalWithdrawals;

        // Monthly chart data
        $monthlyData = collect(range(1, 12))->map(function ($month) {
            return [
                'month'       => Carbon::create()->month($month)->format('M'),
                'savings'     => (float) Saving::whereMonth('created_at', $month)->whereYear('created_at', now()->year)->sum('amount'),
                'loans'       => (float) Loan::whereMonth('created_at', $month)->whereYear('created_at', now()->year)->where('status', 'approved')->sum('amount'),
                'shares'      => (float) Share::whereMonth('created_at', $month)->whereYear('created_at', now()->year)->where('status', 'approved')->sum('amount_paid'),
                'withdrawals' => (float) Withdrawal::whereMonth('created_at', $month)->whereYear('created_at', now()->year)->where('status', 'approved')->sum('amount'),
            ];
        });

        $recentMembers = User::where('is_admin', false)->where('admin_sign', 'Yes')
            ->latest()->take(5)->get(['id', 'surname', 'firstname', 'member_no', 'email', 'member_image', 'created_at']);

        $recentTransactions = Transaction::with('user:id,surname,firstname,member_no')
            ->latest()->take(5)->get();

        $pendingLoans = Loan::where('status', 'pending')
            ->with(['user:id,surname,firstname,member_no', 'loanType:id,name'])
            ->latest()->take(5)->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_members'          => $totalMembers,
                'new_members_this_month' => $newMembersThisMonth,
                'total_admins'           => $totalAdmins,
                'total_savings'          => (float) $totalSavings,
                'monthly_savings'        => (float) $monthlySavings,
                'saving_balance'         => (float) $savingBalance,
                'total_shares'           => (float) $totalShares,
                'total_share_units'      => $totalShareUnits,
                'active_loans'           => $activeLoans,
                'total_loan_amount'      => (float) $totalLoanAmount,
                'total_repayments'       => (float) $totalRepayments,
                'outstanding_loans'      => (float) $outstandingLoans,
                'total_withdrawals'      => (float) $totalWithdrawals,
                'pending_withdrawals'    => $pendingWithdrawals,
                'total_commodities'      => $totalCommodities,
                'total_resources'        => $totalResources,
                'monthly_data'           => $monthlyData,
                'recent_members'         => $recentMembers,
                'recent_transactions'    => $recentTransactions,
                'pending_loans'          => $pendingLoans,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    //  MEMBERS MANAGEMENT
    // ═══════════════════════════════════════════════════════════

    public function members(Request $request)
    {
        $query = User::where('is_admin', false);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('firstname', 'like', "%{$s}%")
                    ->orWhere('surname', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhere('member_no', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('admin_sign', $request->status === 'approved' ? 'Yes' : 'No');
        }

        $members = $query->with('department:id,name')->latest()->paginate($request->per_page ?? 15);

        $totalMembers = User::where('is_admin', false)->count();
        $approvedThisMonth = User::where('is_admin', false)->where('is_approved', 1)
            ->whereMonth('approved_at', now()->month)->whereYear('approved_at', now()->year)->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'members'              => $members,
                'total_members'        => $totalMembers,
                'approved_this_month'  => $approvedThisMonth,
            ],
        ]);
    }

    public function showMember(User $member)
    {
        $member->load(['department:id,name', 'faculty:id,name', 'state:id,name', 'lga:id,name']);

        return response()->json(['success' => true, 'data' => $member]);
    }

    public function storeMember(Request $request)
    {
        $validated = $request->validate([
            'surname'          => 'required|string|max:255',
            'firstname'        => 'required|string|max:255',
            'staff_no'         => 'required|string|max:50|unique:users',
            'email'            => 'required|email|unique:users,email',
            'phone_number'     => 'required|string|max:20',
            'faculty_id'       => 'required|exists:faculties,id',
            'department_id'    => 'required|exists:departments,id',
            'state_id'         => 'required|exists:states,id',
            'lga_id'           => 'required|exists:lgas,id',
            'date_join'        => 'required|date',
            'monthly_savings'  => 'required|numeric|min:0',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $validated['password']    = bcrypt($validated['password']);
        $validated['is_admin']    = false;
        $validated['admin_sign']  = 'Yes';
        $validated['is_approved'] = 1;
        $validated['member_no']   = TransactionHelper::generateUniqueMemberNo();
        $validated['approved_at'] = now();

        $member = User::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Member created successfully',
            'data'    => $member,
        ], 201);
    }

    public function updateMember(Request $request, User $member)
    {
        $validated = $request->validate([
            'title'              => 'sometimes|required',
            'firstname'          => 'sometimes|required',
            'surname'            => 'sometimes|required',
            'email'              => 'sometimes|required|email|unique:users,email,' . $member->id,
            'phone_number'       => 'sometimes|required',
            'staff_no'           => 'sometimes|required',
            'faculty_id'         => 'sometimes|required',
            'department_id'      => 'sometimes|required',
            'monthly_savings'    => 'sometimes|required|numeric',
            'share_subscription' => 'sometimes|numeric',
        ]);

        $member->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Member updated successfully',
            'data'    => $member->fresh(),
        ]);
    }

    public function approveMember(User $member)
    {
        $member->update([
            'admin_sign'  => 'Yes',
            'member_no'   => TransactionHelper::generateUniqueMemberNo(),
            'is_approved' => 1,
            'approved_at' => now(),
        ]);

        Mail::to($member->email)->send(new AccountActivatedEmail($member));

        return response()->json([
            'success' => true,
            'message' => 'Member approved successfully',
            'data'    => $member->fresh(),
        ]);
    }

    public function rejectMember(User $member)
    {
        $member->update(['admin_sign' => 'No']);

        return response()->json(['success' => true, 'message' => 'Member rejected successfully']);
    }

    public function suspendMember(User $member)
    {
        $member->update(['admin_sign' => 'No']);

        return response()->json(['success' => true, 'message' => 'Member suspended successfully']);
    }

    public function activateMember(User $member)
    {
        $member->update(['status' => 'active']);

        return response()->json(['success' => true, 'message' => 'Member activated successfully']);
    }

    public function destroyMember(User $member)
    {
        if ($member->loans()->where('status', 'approved')->where('balance', '>', 0)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete member with active loans',
            ], 422);
        }

        $member->update([
            'is_approved' => 0,
            'email'       => $member->email . '_deleted_' . time(),
            'is_active'   => false,
        ]);
        $member->delete();

        return response()->json(['success' => true, 'message' => 'Member deleted successfully']);
    }

    // ═══════════════════════════════════════════════════════════
    //  ENTRANCE FEES
    // ═══════════════════════════════════════════════════════════

    public function entranceFees(Request $request)
    {
        $query = EntranceFee::with(['user:id,surname,firstname,member_no', 'month:id,name', 'year:id,year']);

        if ($request->filled('month_id')) $query->where('month_id', $request->month_id);
        if ($request->filled('year_id'))  $query->where('year_id', $request->year_id);

        $totalAmount = (clone $query)->sum('amount');
        $entranceFees = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data'    => [
                'entrance_fees' => $entranceFees,
                'total_amount'  => (float) $totalAmount,
                'months'        => Month::all(),
                'years'         => Year::all(),
            ],
        ]);
    }

    public function storeEntranceFee(Request $request)
    {
        $validated = $request->validate([
            'user_id'        => 'required|exists:users,id',
            'amount'         => 'required|numeric|min:0',
            'month_id'       => 'required|exists:months,id',
            'year_id'        => 'required|exists:years,id',
            'remark'         => 'nullable|string',
            'approve_member' => 'nullable|boolean',
        ]);

        $validated['posted_by'] = auth()->id();
        $fee = EntranceFee::create($validated);

        if ($request->boolean('approve_member')) {
            User::where('id', $request->user_id)->update([
                'admin_sign'  => 'Yes',
                'member_no'   => TransactionHelper::generateUniqueMemberNo(),
                'is_approved' => 1,
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);

            TransactionHelper::recordTransaction($request->user_id, 'entrance_fee', 0, $request->amount);

            $member = User::find($request->user_id);
            Mail::to($member->email)->send(new AccountActivatedEmail($member));
        }

        return response()->json([
            'success' => true,
            'message' => 'Entrance fee recorded successfully',
            'data'    => $fee->load(['user:id,surname,firstname,member_no']),
        ], 201);
    }

    public function updateEntranceFee(Request $request, EntranceFee $entranceFee)
    {
        $validated = $request->validate([
            'user_id'  => 'required|exists:users,id',
            'amount'   => 'required|numeric|min:0',
            'month_id' => 'required|exists:months,id',
            'year_id'  => 'required|exists:years,id',
            'remark'   => 'nullable|string',
        ]);

        $entranceFee->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Entrance fee updated successfully',
            'data'    => $entranceFee->fresh(),
        ]);
    }

    public function destroyEntranceFee(EntranceFee $entranceFee)
    {
        DB::beginTransaction();
        try {
            Transaction::where('user_id', $entranceFee->user_id)
                ->where('type', 'entrance_fee')
                ->where('credit_amount', $entranceFee->amount)
                ->first()?->delete();

            $entranceFee->delete();
            DB::commit();

            return response()->json(['success' => true, 'message' => 'Entrance fee deleted']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  SAVING TYPES
    // ═══════════════════════════════════════════════════════════

    public function savingTypes()
    {
        return response()->json([
            'success' => true,
            'data'    => SavingType::latest()->get(),
        ]);
    }

    public function storeSavingType(Request $request)
    {
        $validated = $request->validate([
            'name'                       => 'required|string|max:255',
            'description'                => 'nullable|string',
            'interest_rate'              => 'required|numeric|min:0|max:100',
            'minimum_balance'            => 'required|numeric|min:0',
            'is_mandatory'               => 'boolean',
            'allow_withdrawal'           => 'boolean',
            'withdrawal_restriction_days' => 'required|integer|min:0',
        ]);

        $validated['code'] = Str::upper(Str::slug($request->name, '_'));
        $type = SavingType::create($validated);

        return response()->json(['success' => true, 'message' => 'Saving type created', 'data' => $type], 201);
    }

    public function updateSavingType(Request $request, SavingType $savingType)
    {
        $validated = $request->validate([
            'name'                       => 'required|string|max:255',
            'description'                => 'nullable|string',
            'interest_rate'              => 'required|numeric|min:0|max:100',
            'minimum_balance'            => 'required|numeric|min:0',
            'is_mandatory'               => 'boolean',
            'allow_withdrawal'           => 'boolean',
            'withdrawal_restriction_days' => 'required|integer|min:0',
            'status'                     => 'sometimes|in:active,inactive',
        ]);

        $savingType->update($validated);

        return response()->json(['success' => true, 'message' => 'Saving type updated', 'data' => $savingType->fresh()]);
    }

    // ═══════════════════════════════════════════════════════════
    //  SAVINGS
    // ═══════════════════════════════════════════════════════════

    public function savings(Request $request)
    {
        $query = Saving::with(['user:id,surname,firstname,member_no', 'savingType:id,name', 'month:id,name', 'year:id,year']);

        if ($request->filled('month'))  $query->where('month_id', $request->month);
        if ($request->filled('year'))   $query->where('year_id', $request->year);
        if ($request->filled('type'))   $query->where('saving_type_id', $request->type);
        if ($request->filled('user_id')) $query->where('user_id', $request->user_id);

        $totalSavings = (clone $query)->sum('amount');
        $savings = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data'    => [
                'savings'       => $savings,
                'total_savings' => (float) $totalSavings,
                'saving_types'  => SavingType::where('status', 'active')->get(),
                'months'        => Month::all(),
                'years'         => Year::all(),
            ],
        ]);
    }

    public function storeSaving(Request $request)
    {
        $validated = $request->validate([
            'user_id'        => 'required|exists:users,id',
            'saving_type_id' => 'required|exists:saving_types,id',
            'month_id'       => 'required|exists:months,id',
            'year_id'        => 'required|exists:years,id',
            'amount'         => 'nullable|numeric|min:0',
            'remark'         => 'nullable|string',
        ]);

        $user       = User::find($request->user_id);
        $savingType = SavingType::find($request->saving_type_id);
        $amount     = $validated['amount'] ?? $user->monthly_savings;

        $interestAmount = ($amount * $savingType->interest_rate) / 100;
        $totalAmount    = $amount + $interestAmount;

        $saving = Saving::create([
            'user_id'        => $user->id,
            'saving_type_id' => $savingType->id,
            'amount'         => $totalAmount,
            'month_id'       => $validated['month_id'],
            'year_id'        => $validated['year_id'],
            'reference'      => 'SAV-' . date('Y') . '-' . Str::random(8),
            'remark'         => $validated['remark'] ?? null,
            'posted_by'      => auth()->id(),
        ]);

        TransactionHelper::recordTransaction(
            $user->id, 'savings', 0, $totalAmount, 'completed',
            'Monthly Savings Contribution (Interest: ' . $savingType->interest_rate . '%)'
        );

        return response()->json([
            'success' => true,
            'message' => 'Savings entry created successfully',
            'data'    => $saving->load(['user:id,surname,firstname,member_no', 'savingType:id,name']),
        ], 201);
    }

    public function updateSaving(Request $request, Saving $saving)
    {
        $validated = $request->validate([
            'saving_type_id' => 'required|exists:saving_types,id',
            'month_id'       => 'required|exists:months,id',
            'year_id'        => 'required|exists:years,id',
            'amount'         => 'required|numeric|min:0',
            'remark'         => 'nullable|string',
        ]);

        $saving->update($validated);

        Transaction::where('user_id', $saving->user_id)
            ->where('type', 'savings')
            ->where('created_at', $saving->created_at)
            ->update(['credit_amount' => $validated['amount']]);

        return response()->json(['success' => true, 'message' => 'Savings entry updated', 'data' => $saving->fresh()]);
    }

    public function destroySaving(Saving $saving)
    {
        Transaction::where('user_id', $saving->user_id)
            ->where('type', 'savings')
            ->where('credit_amount', $saving->amount)
            ->where('created_at', $saving->created_at)
            ->delete();

        $saving->delete();

        return response()->json(['success' => true, 'message' => 'Savings entry deleted']);
    }

    // Savings Settings (admin approve/reject)
    public function savingsSettings(Request $request)
    {
        $settings = MonthlySavingsSetting::with(['user:id,surname,firstname,member_no', 'savingType:id,name', 'month:id,name', 'year:id,year'])
            ->orderBy('year_id', 'desc')
            ->orderBy('month_id', 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json(['success' => true, 'data' => $settings]);
    }

    public function approveSavingsSetting(MonthlySavingsSetting $setting)
    {
        $setting->update([
            'status'      => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        // Update user's monthly_savings if current month/year
        if ($setting->month->id == now()->month && $setting->year->year == now()->year) {
            $setting->user->update(['monthly_savings' => $setting->amount]);
        }

        return response()->json(['success' => true, 'message' => 'Savings setting approved', 'data' => $setting->fresh()]);
    }

    public function rejectSavingsSetting(Request $request, MonthlySavingsSetting $setting)
    {
        $validated = $request->validate(['admin_notes' => 'required|string|max:500']);

        $setting->update([
            'status'      => 'rejected',
            'admin_notes' => $validated['admin_notes'],
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Savings setting rejected']);
    }

    // ═══════════════════════════════════════════════════════════
    //  SHARE TYPES
    // ═══════════════════════════════════════════════════════════

    public function shareTypes()
    {
        return response()->json(['success' => true, 'data' => ShareType::latest()->get()]);
    }

    public function storeShareType(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'minimum_amount'   => 'required|numeric|min:0',
            'maximum_amount'   => 'required|numeric|min:0',
            'dividend_rate'    => 'required|numeric|min:0',
            'is_transferable'  => 'boolean',
            'has_voting_rights' => 'boolean',
            'description'      => 'nullable|string',
        ]);

        $type = ShareType::create($validated);

        return response()->json(['success' => true, 'message' => 'Share type created', 'data' => $type], 201);
    }

    public function updateShareType(Request $request, ShareType $shareType)
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'minimum_amount' => 'required|numeric|min:0',
            'maximum_amount' => 'required|numeric|min:0',
            'dividend_rate'  => 'required|numeric|min:0',
            'status'         => 'sometimes|in:active,inactive',
            'description'    => 'nullable|string',
        ]);

        $shareType->update($validated);

        return response()->json(['success' => true, 'message' => 'Share type updated', 'data' => $shareType->fresh()]);
    }

    public function destroyShareType(ShareType $shareType)
    {
        if ($shareType->shares()->exists()) {
            return response()->json(['success' => false, 'message' => 'Cannot delete share type in use'], 422);
        }

        $shareType->delete();

        return response()->json(['success' => true, 'message' => 'Share type deleted']);
    }

    // ═══════════════════════════════════════════════════════════
    //  SHARES
    // ═══════════════════════════════════════════════════════════

    public function shares(Request $request)
    {
        $query = Share::with(['user:id,surname,firstname,member_no', 'shareType:id,name', 'month:id,name', 'year:id,year']);

        if ($request->filled('share_type_id')) $query->where('share_type_id', $request->share_type_id);
        if ($request->filled('month_id'))      $query->where('month_id', $request->month_id);
        if ($request->filled('year_id'))       $query->where('year_id', $request->year_id);
        if ($request->filled('status'))        $query->where('status', $request->status);
        if ($request->filled('user_id'))       $query->where('user_id', $request->user_id);

        $totalShares = (clone $query)->sum('amount_paid');
        $shares = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data'    => [
                'shares'       => $shares,
                'total_shares' => (float) $totalShares,
                'share_types'  => ShareType::where('status', 'active')->get(),
                'months'       => Month::all(),
                'years'        => Year::all(),
            ],
        ]);
    }

    public function storeShare(Request $request)
    {
        $validated = $request->validate([
            'user_id'       => 'required|exists:users,id',
            'share_type_id' => 'required|exists:share_types,id',
            'amount_paid'   => 'required|numeric|min:0.01',
            'month_id'      => 'required|exists:months,id',
            'year_id'       => 'required|exists:years,id',
            'remark'        => 'nullable|string',
        ]);

        $share = Share::create([
            'user_id'            => $validated['user_id'],
            'share_type_id'      => $validated['share_type_id'],
            'certificate_number' => 'SHR-' . date('Y') . '-' . Str::random(8),
            'amount_paid'        => $validated['amount_paid'],
            'month_id'           => $validated['month_id'],
            'year_id'            => $validated['year_id'],
            'posted_by'          => auth()->id(),
            'status'             => 'approved',
            'remark'             => $validated['remark'] ?? null,
        ]);

        TransactionHelper::recordTransaction(
            $validated['user_id'], 'share_purchase', 0, $validated['amount_paid'],
            'completed', 'Share Purchase - ' . $share->certificate_number
        );

        return response()->json(['success' => true, 'message' => 'Share recorded', 'data' => $share], 201);
    }

    public function approveShare(Share $share)
    {
        $share->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);

        TransactionHelper::updateTransactionStatus(
            $share->user_id, 'share_purchase', $share->amount_paid,
            'completed', 'Share Purchase Approved - ' . $share->certificate_number
        );

        return response()->json(['success' => true, 'message' => 'Share approved']);
    }

    public function rejectShare(Share $share)
    {
        $share->update(['status' => 'rejected', 'approved_by' => auth()->id(), 'approved_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Share rejected']);
    }

    public function destroyShare(Share $share)
    {
        if ($share->status === 'approved') {
            return response()->json(['success' => false, 'message' => 'Cannot delete approved share'], 422);
        }

        $share->delete();

        return response()->json(['success' => true, 'message' => 'Share deleted']);
    }

    // ═══════════════════════════════════════════════════════════
    //  LOAN TYPES
    // ═══════════════════════════════════════════════════════════

    public function loanTypes()
    {
        return response()->json(['success' => true, 'data' => LoanType::all()]);
    }

    public function storeLoanType(Request $request)
    {
        $validated = $request->validate([
            'name'                          => 'required|string|max:255',
            'required_active_savings_months' => 'required|integer|min:3',
            'savings_multiplier'            => 'required|numeric|min:1',
            'interest_rate'                 => 'required|numeric|min:0|max:100',
            'duration_months'               => 'required|integer|min:1',
            'minimum_amount'                => 'required|numeric|min:0',
            'maximum_amount'                => 'required|numeric|gt:minimum_amount',
            'allow_early_payment'           => 'boolean',
            'no_guarantors'                 => 'required|integer|min:0',
            'application_fee'               => 'required|numeric|min:0',
        ]);

        $type = LoanType::create($validated);

        return response()->json(['success' => true, 'message' => 'Loan type created', 'data' => $type], 201);
    }

    public function updateLoanType(Request $request, LoanType $loanType)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'interest_rate'     => 'required|numeric|min:0|max:100',
            'duration_months'   => 'required|integer|min:1',
            'minimum_amount'    => 'required|numeric|min:0',
            'maximum_amount'    => 'required|numeric|gt:minimum_amount',
            'status'            => 'sometimes|in:active,inactive',
            'no_guarantors'     => 'required|integer|min:0',
            'application_fee'   => 'required|numeric|min:0',
        ]);

        $loanType->update($validated);

        return response()->json(['success' => true, 'message' => 'Loan type updated', 'data' => $loanType->fresh()]);
    }

    public function destroyLoanType(LoanType $loanType)
    {
        $loanType->delete();

        return response()->json(['success' => true, 'message' => 'Loan type deleted']);
    }

    // ═══════════════════════════════════════════════════════════
    //  LOANS
    // ═══════════════════════════════════════════════════════════

    public function loans(Request $request)
    {
        $query = Loan::with(['user:id,surname,firstname,member_no', 'loanType:id,name']);

        if ($request->filled('status'))    $query->where('status', $request->status);
        if ($request->filled('reference')) $query->where('reference', $request->reference);
        if ($request->filled('user_id'))   $query->where('user_id', $request->user_id);

        $totalLoanAmount = (clone $query)->sum('amount');
        $loans = $query->latest()->paginate($request->per_page ?? 20);

        $statusCounts = Loan::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')->pluck('count', 'status');

        return response()->json([
            'success' => true,
            'data'    => [
                'loans'             => $loans,
                'total_loan_amount' => (float) $totalLoanAmount,
                'status_counts'     => $statusCounts,
            ],
        ]);
    }

    public function showLoan(Loan $loan)
    {
        $loan->load(['user:id,surname,firstname,member_no', 'loanType', 'guarantors.guarantor:id,surname,firstname,member_no', 'repayments']);

        return response()->json(['success' => true, 'data' => $loan]);
    }

    public function storeLoan(Request $request)
    {
        $validated = $request->validate([
            'user_id'      => 'required|exists:users,id',
            'loan_type_id' => 'required|exists:loan_types,id',
            'amount'       => 'required|numeric|min:0',
            'duration'     => 'required|integer|min:1',
            'start_date'   => 'required|date',
            'purpose'      => 'required|string',
        ]);

        $loanType = LoanType::find($request->loan_type_id);
        $rate     = $loanType ? $loanType->interest_rate : 10;

        $interestAmount = ($validated['amount'] * ($rate / 100) * ($validated['duration'] / 12));
        $totalAmount    = $validated['amount'] + $interestAmount;
        $monthlyPayment = $totalAmount / $validated['duration'];

        $loan = Loan::create([
            'user_id'        => $validated['user_id'],
            'loan_type_id'   => $validated['loan_type_id'],
            'reference'      => 'LOAN-' . date('Y') . '-' . Str::random(8),
            'amount'         => $validated['amount'],
            'interest_amount' => $interestAmount,
            'total_amount'   => $totalAmount,
            'duration'       => $validated['duration'],
            'monthly_payment' => $monthlyPayment,
            'start_date'     => $validated['start_date'],
            'end_date'       => Carbon::parse($validated['start_date'])->addMonths((int) $validated['duration']),
            'purpose'        => $validated['purpose'],
            'posted_by'      => auth()->id(),
        ]);

        $user = User::find($validated['user_id']);
        $user->notify(new LoanStatusNotification($loan));

        return response()->json(['success' => true, 'message' => 'Loan created', 'data' => $loan], 201);
    }

    public function approveLoan(Loan $loan)
    {
        $loan->update([
            'status'      => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        TransactionHelper::recordTransaction(
            $loan->user_id, 'loan_disbursement', 0, $loan->amount,
            'completed', 'Loan Disbursement - ' . $loan->reference
        );

        if ($loan->application_fee > 0) {
            TransactionHelper::recordTransaction(
                $loan->user_id, 'application_fee', $loan->application_fee, 0,
                'completed', 'Loan Application Fee - ' . $loan->reference
            );
        }

        $loan->user->notify(new LoanStatusNotification($loan));

        return response()->json(['success' => true, 'message' => 'Loan approved', 'data' => $loan->fresh()]);
    }

    public function rejectLoan(Loan $loan)
    {
        $loan->update([
            'status'      => 'rejected',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Loan rejected']);
    }

    // ═══════════════════════════════════════════════════════════
    //  LOAN REPAYMENTS
    // ═══════════════════════════════════════════════════════════

    public function loanRepayments()
    {
        $loans = Loan::with(['user:id,surname,firstname,member_no', 'loanType:id,name', 'repayments'])
            ->whereIn('status', ['approved', 'active', 'disbursed'])
            ->where('balance', '>', 0)
            ->latest()
            ->get()
            ->map(function ($loan) {
                $start   = Carbon::parse($loan->start_date);
                $end     = Carbon::parse($loan->end_date);
                $now     = Carbon::now();
                $elapsed = $start->diffInMonths($now);

                $loan->remaining_months = max(0, $loan->duration - $elapsed);
                $loan->is_overdue       = $now->gt($end) && $loan->balance > 0;

                return $loan;
            });

        return response()->json(['success' => true, 'data' => $loans]);
    }

    public function storeLoanRepayment(Request $request, Loan $loan)
    {
        $validated = $request->validate([
            'amount'         => 'required|numeric|min:0',
            'payment_date'   => 'required|date',
            'payment_method' => 'required|string',
            'notes'          => 'nullable|string',
            'month_id'       => 'required|exists:months,id',
            'year_id'        => 'required|exists:years,id',
        ]);

        $existing = LoanRepayment::where('loan_id', $loan->id)
            ->where('month_id', $validated['month_id'])
            ->where('year_id', $validated['year_id'])
            ->exists();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Repayment already exists for this period',
            ], 422);
        }

        $repayment = LoanRepayment::create([
            'loan_id'        => $loan->id,
            'reference'      => 'REP-' . date('Y') . '-' . Str::random(8),
            'amount'         => $validated['amount'],
            'payment_date'   => $validated['payment_date'],
            'payment_method' => $validated['payment_method'],
            'notes'          => $validated['notes'],
            'posted_by'      => auth()->id(),
            'month_id'       => $validated['month_id'],
            'year_id'        => $validated['year_id'],
        ]);

        $month = Month::find($validated['month_id']);
        $year  = Year::find($validated['year_id']);

        TransactionHelper::recordTransaction(
            $loan->user_id, 'loan_repayment', $validated['amount'], 0,
            'completed', 'Loan Repayment - ' . $repayment->reference . ' (' . $month->name . ' ' . $year->year . ')'
        );

        $totalRepaid    = $loan->repayments->sum('amount') + $validated['amount'];
        $remaining      = $loan->total_amount - $totalRepaid;
        $loan->amount_paid = $totalRepaid;
        $loan->balance     = $remaining;
        if ($remaining <= 1) $loan->status = 'completed';
        $loan->save();

        return response()->json([
            'success' => true,
            'message' => 'Repayment recorded',
            'data'    => $repayment,
        ], 201);
    }

    // ═══════════════════════════════════════════════════════════
    //  WITHDRAWALS
    // ═══════════════════════════════════════════════════════════

    public function withdrawals(Request $request)
    {
        $query = Withdrawal::with(['user:id,surname,firstname,member_no', 'savingType:id,name']);

        if ($request->filled('user_id'))        $query->where('user_id', $request->user_id);
        if ($request->filled('status'))         $query->where('status', $request->status);
        if ($request->filled('saving_type_id')) $query->where('saving_type_id', $request->saving_type_id);
        if ($request->filled('month_id'))       $query->where('month_id', $request->month_id);
        if ($request->filled('year_id'))        $query->where('year_id', $request->year_id);

        $total     = (clone $query)->sum('amount');
        $pending   = (clone $query)->where('status', 'pending')->sum('amount');
        $approved  = (clone $query)->where('status', 'approved')->sum('amount');
        $withdrawals = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data'    => [
                'withdrawals'      => $withdrawals,
                'total_amount'     => (float) $total,
                'pending_amount'   => (float) $pending,
                'approved_amount'  => (float) $approved,
            ],
        ]);
    }

    public function storeWithdrawal(Request $request)
    {
        $validated = $request->validate([
            'user_id'        => 'required|exists:users,id',
            'saving_type_id' => 'required|exists:saving_types,id',
            'amount'         => 'required|numeric|min:0',
            'bank_name'      => 'required|string',
            'account_number' => 'required|string',
            'account_name'   => 'required|string',
            'reason'         => 'required|string',
            'month_id'       => 'required|exists:months,id',
            'year_id'        => 'required|exists:years,id',
        ]);

        $withdrawal = Withdrawal::create([
            'user_id'        => $validated['user_id'],
            'saving_type_id' => $validated['saving_type_id'],
            'reference'      => 'WTH-' . Str::random(10),
            'amount'         => $validated['amount'],
            'bank_name'      => $validated['bank_name'],
            'account_number' => $validated['account_number'],
            'account_name'   => $validated['account_name'],
            'reason'         => $validated['reason'],
            'status'         => 'completed',
            'approved_at'    => now(),
            'approved_by'    => auth()->id(),
            'month_id'       => $validated['month_id'],
            'year_id'        => $validated['year_id'],
        ]);

        TransactionHelper::recordTransaction(
            $validated['user_id'], 'withdrawal', 0, $validated['amount'],
            'completed', 'Savings Withdrawal - ' . $withdrawal->reference
        );

        return response()->json(['success' => true, 'message' => 'Withdrawal recorded', 'data' => $withdrawal], 201);
    }

    public function approveWithdrawal(Withdrawal $withdrawal)
    {
        if ($withdrawal->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Already processed'], 422);
        }

        DB::beginTransaction();
        try {
            $withdrawal->update([
                'status'      => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            Transaction::create([
                'user_id'     => $withdrawal->user_id,
                'type'        => 'withdrawal',
                'amount'      => $withdrawal->amount,
                'reference'   => $withdrawal->reference,
                'status'      => 'completed',
                'description' => 'Withdrawal - ' . $withdrawal->purpose,
            ]);

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Withdrawal approved']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function rejectWithdrawal(Request $request, Withdrawal $withdrawal)
    {
        if ($withdrawal->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Already processed'], 422);
        }

        $request->validate(['rejection_reason' => 'required|string|max:500']);

        $withdrawal->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->rejection_reason,
            'approved_by'      => auth()->id(),
            'approved_at'      => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Withdrawal rejected']);
    }

    // ═══════════════════════════════════════════════════════════
    //  TRANSACTIONS
    // ═══════════════════════════════════════════════════════════

    public function transactions(Request $request)
    {
        $query = Transaction::with('user:id,surname,firstname,member_no');

        if ($request->filled('user_id'))    $query->where('user_id', $request->user_id);
        if ($request->filled('type'))       $query->where('type', $request->type);
        if ($request->filled('start_date')) $query->whereDate('created_at', '>=', $request->start_date);
        if ($request->filled('end_date'))   $query->whereDate('created_at', '<=', $request->end_date);

        $totalCredits = (clone $query)->whereIn('type', ['savings', 'entrance_fee'])->sum('credit_amount');
        $totalDebits  = (clone $query)->where('type', 'withdraw')->sum('debit_amount');
        $transactions = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data'    => [
                'transactions'  => $transactions,
                'total_credits' => (float) $totalCredits,
                'total_debits'  => (float) $totalDebits,
            ],
        ]);
    }

    public function showTransaction(Transaction $transaction)
    {
        $transaction->load('user:id,surname,firstname,member_no');

        return response()->json(['success' => true, 'data' => $transaction]);
    }

    public function destroyTransaction(Transaction $transaction)
    {
        $transaction->delete();

        return response()->json(['success' => true, 'message' => 'Transaction deleted']);
    }

    // ═══════════════════════════════════════════════════════════
    //  COMMODITIES
    // ═══════════════════════════════════════════════════════════

    public function commodities(Request $request)
    {
        $query = Commodity::query();

        if ($request->has('status') && $request->status !== '') {
            $query->where('is_active', $request->status);
        }

        $commodities = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json(['success' => true, 'data' => $commodities]);
    }

    public function showCommodity(Commodity $commodity)
    {
        return response()->json(['success' => true, 'data' => $commodity]);
    }

    public function storeCommodity(Request $request)
    {
        $validated = $request->validate([
            'name'                       => 'required|string|max:255',
            'description'                => 'nullable|string',
            'price'                      => 'required|numeric|min:0',
            'quantity_available'         => 'required|integer|min:0',
            'is_active'                  => 'boolean',
            'start_date'                 => 'nullable|date',
            'end_date'                   => 'nullable|date|after_or_equal:start_date',
            'purchase_amount'            => 'nullable|numeric|min:0',
            'target_sales_amount'        => 'nullable|numeric|min:0',
            'profit_amount'              => 'nullable|numeric',
            'allow_installment'          => 'boolean',
            'max_installment_months'     => 'nullable|required_if:allow_installment,1|integer|min:1|max:36',
            'initial_deposit_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($request->allow_installment) {
            $price    = $validated['price'];
            $months   = $validated['max_installment_months'];
            $deposit  = ($price * ($validated['initial_deposit_percentage'] ?? 0)) / 100;
            $validated['monthly_installment_amount'] = round(($price - $deposit) / $months, 2);
        }

        // Handle image upload
        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('commodities', 'public');
        }

        $commodity = Commodity::create($validated);

        return response()->json(['success' => true, 'message' => 'Commodity created', 'data' => $commodity], 201);
    }

    public function updateCommodity(Request $request, Commodity $commodity)
    {
        $validated = $request->validate([
            'name'                       => 'required|string|max:255',
            'description'                => 'nullable|string',
            'price'                      => 'required|numeric|min:0',
            'quantity_available'         => 'required|integer|min:0',
            'is_active'                  => 'boolean',
            'start_date'                 => 'nullable|date',
            'end_date'                   => 'nullable|date|after_or_equal:start_date',
            'purchase_amount'            => 'nullable|numeric|min:0',
            'target_sales_amount'        => 'nullable|numeric|min:0',
            'profit_amount'              => 'nullable|numeric',
            'allow_installment'          => 'boolean',
            'max_installment_months'     => 'nullable|required_if:allow_installment,1|integer|min:1|max:36',
            'initial_deposit_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($request->allow_installment) {
            $price   = $validated['price'];
            $months  = $validated['max_installment_months'];
            $deposit = ($price * ($validated['initial_deposit_percentage'] ?? 0)) / 100;
            $validated['monthly_installment_amount'] = round(($price - $deposit) / $months, 2);
        } else {
            $validated['max_installment_months']     = null;
            $validated['monthly_installment_amount'] = null;
            $validated['initial_deposit_percentage']  = 0;
        }

        if ($request->hasFile('image')) {
            if ($commodity->image) Storage::disk('public')->delete($commodity->image);
            $validated['image'] = $request->file('image')->store('commodities', 'public');
        }

        $commodity->update($validated);

        return response()->json(['success' => true, 'message' => 'Commodity updated', 'data' => $commodity->fresh()]);
    }

    public function destroyCommodity(Commodity $commodity)
    {
        if ($commodity->image) Storage::disk('public')->delete($commodity->image);
        $commodity->delete();

        return response()->json(['success' => true, 'message' => 'Commodity deleted']);
    }

    // ═══════════════════════════════════════════════════════════
    //  COMMODITY SUBSCRIPTIONS
    // ═══════════════════════════════════════════════════════════

    public function commoditySubscriptions(Request $request)
    {
        $subscriptions = CommoditySubscription::with(['user:id,surname,firstname,member_no', 'commodity:id,name,price'])
            ->latest()->paginate($request->per_page ?? 15);

        return response()->json(['success' => true, 'data' => $subscriptions]);
    }

    public function showCommoditySubscription(CommoditySubscription $subscription)
    {
        $subscription->load(['commodity', 'user:id,surname,firstname,member_no', 'payments']);

        return response()->json(['success' => true, 'data' => $subscription]);
    }

    public function approveCommoditySubscription(CommoditySubscription $subscription)
    {
        if ($subscription->commodity->quantity_available < $subscription->quantity) {
            return response()->json(['success' => false, 'message' => 'Not enough quantity available'], 422);
        }

        $subscription->commodity->decrement('quantity_available', $subscription->quantity);
        $subscription->update(['status' => 'approved', 'approved_at' => now()]);

        $subscription->user->notify(new CommoditySubscriptionStatusUpdated($subscription));

        return response()->json(['success' => true, 'message' => 'Subscription approved']);
    }

    public function rejectCommoditySubscription(Request $request, CommoditySubscription $subscription)
    {
        $request->validate(['admin_notes' => 'required|string|max:500']);

        $subscription->update([
            'status'      => 'rejected',
            'rejected_at' => now(),
            'admin_notes' => $request->admin_notes,
        ]);

        $subscription->user->notify(new CommoditySubscriptionStatusUpdated($subscription));

        return response()->json(['success' => true, 'message' => 'Subscription rejected']);
    }

    // ═══════════════════════════════════════════════════════════
    //  COMMODITY PAYMENTS
    // ═══════════════════════════════════════════════════════════

    public function commodityPayments(Request $request)
    {
        $query = CommodityPayment::with(['commoditySubscription.user:id,surname,firstname,member_no', 'commoditySubscription.commodity:id,name']);

        if ($request->filled('subscription_id')) $query->where('commodity_subscription_id', $request->subscription_id);
        if ($request->filled('status'))          $query->where('status', $request->status);
        if ($request->filled('month_id'))        $query->where('month_id', $request->month_id);
        if ($request->filled('year_id'))         $query->where('year_id', $request->year_id);

        $payments = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json(['success' => true, 'data' => $payments]);
    }

    public function storeCommodityPayment(Request $request, CommoditySubscription $subscription)
    {
        $validated = $request->validate([
            'amount'            => 'required|numeric|min:1',
            'payment_method'    => 'required|in:cash,bank_transfer,deduction',
            'payment_reference' => 'nullable|string|max:255',
            'notes'             => 'nullable|string|max:500',
            'month_id'          => 'required|exists:months,id',
            'year_id'           => 'required|exists:years,id',
        ]);

        $paidAmount      = $subscription->initial_deposit + ($subscription->payments->sum('amount') ?? 0);
        $remainingAmount = $subscription->total_amount - $paidAmount;

        if ($validated['amount'] > $remainingAmount) {
            return response()->json(['success' => false, 'message' => 'Amount exceeds remaining balance'], 422);
        }

        $payment = CommodityPayment::create([
            'commodity_subscription_id' => $subscription->id,
            'amount'                    => $validated['amount'],
            'payment_method'            => $validated['payment_method'],
            'payment_reference'         => $validated['payment_reference'],
            'status'                    => 'approved',
            'approved_by'               => auth()->id(),
            'approved_at'               => now(),
            'notes'                     => $validated['notes'],
            'month_id'                  => $validated['month_id'],
            'year_id'                   => $validated['year_id'],
        ]);

        Transaction::recordCommodityPayment(
            $subscription->user_id, $validated['amount'],
            $subscription->commodity->name, $subscription->commodity_id,
            $payment->payment_reference ?? ('COM-PAY-' . $payment->id)
        );

        return response()->json(['success' => true, 'message' => 'Payment recorded', 'data' => $payment], 201);
    }

    public function approveCommodityPayment(CommodityPayment $payment)
    {
        $payment->update([
            'status'      => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $sub = $payment->commoditySubscription;
        Transaction::recordCommodityPayment(
            $sub->user_id, $payment->amount, $sub->commodity->name,
            $sub->commodity_id, $payment->payment_reference ?? ('COM-PAY-' . $payment->id)
        );

        return response()->json(['success' => true, 'message' => 'Payment approved']);
    }

    public function rejectCommodityPayment(Request $request, CommodityPayment $payment)
    {
        $request->validate(['notes' => 'required|string|max:500']);

        $payment->update([
            'status'      => 'rejected',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'notes'       => $request->notes,
        ]);

        return response()->json(['success' => true, 'message' => 'Payment rejected']);
    }

    // ═══════════════════════════════════════════════════════════
    //  PROFILE UPDATE REQUESTS
    // ═══════════════════════════════════════════════════════════

    public function profileUpdateRequests()
    {
        $requests = ProfileUpdateRequest::with('user:id,surname,firstname,member_no')
            ->latest()->paginate(15);

        return response()->json(['success' => true, 'data' => $requests]);
    }

    public function showProfileUpdateRequest(ProfileUpdateRequest $profileRequest)
    {
        $profileRequest->load('user');

        return response()->json(['success' => true, 'data' => $profileRequest]);
    }

    public function approveProfileUpdate(ProfileUpdateRequest $profileRequest)
    {
        $data = $profileRequest->getAttributes();
        unset($data['id'], $data['user_id'], $data['created_at'], $data['updated_at'], $data['status'], $data['admin_remarks']);

        $updateData = array_filter($data, fn($v) => !is_null($v));
        $profileRequest->user->update($updateData);
        $profileRequest->update(['status' => 'approved']);

        return response()->json(['success' => true, 'message' => 'Profile update approved']);
    }

    public function rejectProfileUpdate(Request $request, ProfileUpdateRequest $profileRequest)
    {
        $request->validate(['reason' => 'required|string']);

        $profileRequest->update([
            'status'        => 'rejected',
            'admin_remarks' => $request->reason,
        ]);

        return response()->json(['success' => true, 'message' => 'Profile update rejected']);
    }

    // ═══════════════════════════════════════════════════════════
    //  RESOURCES
    // ═══════════════════════════════════════════════════════════

    public function resources()
    {
        return response()->json([
            'success' => true,
            'data'    => Resource::latest()->paginate(15),
        ]);
    }

    public function storeResource(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'file'        => 'required|file|max:10240',
        ]);

        $file = $request->file('file');

        $resource = Resource::create([
            'title'       => $validated['title'],
            'description' => $validated['description'],
            'file_path'   => $file->store('resources'),
            'file_type'   => $file->getClientMimeType(),
            'file_size'   => $file->getSize(),
            'uploaded_by' => auth()->id(),
        ]);

        return response()->json(['success' => true, 'message' => 'Resource uploaded', 'data' => $resource], 201);
    }

    public function destroyResource(Resource $resource)
    {
        Storage::delete($resource->file_path);
        $resource->delete();

        return response()->json(['success' => true, 'message' => 'Resource deleted']);
    }

    // ═══════════════════════════════════════════════════════════
    //  FAQs
    // ═══════════════════════════════════════════════════════════

    public function faqs()
    {
        return response()->json(['success' => true, 'data' => Faq::orderBy('order')->get()]);
    }

    public function storeFaq(Request $request)
    {
        $validated = $request->validate([
            'question' => 'required|string|max:255',
            'answer'   => 'required|string',
            'order'    => 'nullable|integer',
        ]);

        $faq = Faq::create($validated);

        return response()->json(['success' => true, 'message' => 'FAQ created', 'data' => $faq], 201);
    }

    public function updateFaq(Request $request, Faq $faq)
    {
        $validated = $request->validate([
            'question' => 'required|string|max:255',
            'answer'   => 'required|string',
            'order'    => 'nullable|integer',
        ]);

        $faq->update($validated);

        return response()->json(['success' => true, 'message' => 'FAQ updated', 'data' => $faq->fresh()]);
    }

    public function destroyFaq(Faq $faq)
    {
        $faq->delete();

        return response()->json(['success' => true, 'message' => 'FAQ deleted']);
    }

    // ═══════════════════════════════════════════════════════════
    //  ADMIN USERS & ROLES
    // ═══════════════════════════════════════════════════════════

    public function admins()
    {
        $admins = User::where('is_admin', true)->with('roles')->get();

        return response()->json(['success' => true, 'data' => $admins]);
    }

    public function storeAdmin(Request $request)
    {
        $validated = $request->validate([
            'title'        => 'required',
            'surname'      => 'required',
            'firstname'    => 'required',
            'email'        => 'required|email|unique:users',
            'phone_number' => 'required',
            'password'     => 'required|min:6|confirmed',
            'roles'        => 'required|array',
        ]);

        $admin = User::create([
            'title'                       => $validated['title'],
            'surname'                     => $validated['surname'],
            'firstname'                   => $validated['firstname'],
            'email'                       => $validated['email'],
            'phone_number'                => $validated['phone_number'],
            'password'                    => bcrypt($validated['password']),
            'is_admin'                    => true,
            'member_no'                   => 'ADM' . rand(1000, 9999),
            'admin_sign'                  => 'Yes',
            'department_id'               => 1,
            'faculty_id'                  => 1,
            'state_id'                    => 1,
            'lga_id'                      => 1,
            'is_approved'                 => 1,
            'salary_deduction_agreement'  => 1,
            'member_declaration'          => 1,
        ]);

        $admin->roles()->sync($request->roles);

        return response()->json(['success' => true, 'message' => 'Admin created', 'data' => $admin->load('roles')], 201);
    }

    public function roles()
    {
        return response()->json(['success' => true, 'data' => Role::with('permissions')->paginate(15)]);
    }

    public function storeRole(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|unique:roles',
            'description' => 'nullable',
            'permissions' => 'required|array',
        ]);

        $role = Role::create($validated);
        $role->permissions()->sync($request->permissions);

        return response()->json(['success' => true, 'message' => 'Role created', 'data' => $role->load('permissions')], 201);
    }

    public function updateRole(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:roles,name,' . $role->id,
            'permissions' => 'required|array',
        ]);

        $role->update(['name' => $validated['name']]);
        $role->permissions()->sync($validated['permissions']);

        return response()->json(['success' => true, 'message' => 'Role updated', 'data' => $role->load('permissions')]);
    }

    public function permissions()
    {
        return response()->json(['success' => true, 'data' => Permission::all()]);
    }

    // ═══════════════════════════════════════════════════════════
    //  FINANCIAL SUMMARY
    // ═══════════════════════════════════════════════════════════

    public function financialSummary(Request $request)
    {
        $selectedYear = $request->input('year', date('Y'));
        $yearRecord   = Year::where('year', $selectedYear)->first();
        $months       = Month::all();

        $summary = [
            'savings'     => ['months' => [], 'total' => 0],
            'loans'       => ['months' => [], 'total' => 0],
            'shares'      => ['months' => [], 'total' => 0],
            'commodities' => ['months' => [], 'total' => 0],
            'members'     => [
                'total' => User::where('is_admin', 0)->count(),
                'active' => 0,
            ],
        ];

        foreach ($months as $month) {
            $summary['savings']['months'][$month->id]     = 0;
            $summary['loans']['months'][$month->id]       = 0;
            $summary['shares']['months'][$month->id]      = 0;
            $summary['commodities']['months'][$month->id] = 0;
        }

        if ($yearRecord) {
            foreach (Saving::where('year_id', $yearRecord->id)->where('status', 'completed')->get() as $s) {
                $summary['savings']['months'][$s->month_id] += $s->amount;
                $summary['savings']['total'] += $s->amount;
            }

            $summary['members']['active'] = Saving::where('year_id', $yearRecord->id)
                ->where('status', 'completed')->distinct('user_id')->count('user_id');

            foreach (LoanRepayment::where('year_id', $yearRecord->id)->get() as $r) {
                $summary['loans']['months'][$r->month_id] += $r->amount;
                $summary['loans']['total'] += $r->amount;
            }

            foreach (Share::where('year_id', $yearRecord->id)->where('status', 'completed')->get() as $s) {
                $summary['shares']['months'][$s->month_id] += $s->amount;
                $summary['shares']['total'] += $s->amount;
            }

            foreach (CommodityPayment::where('status', 'approved')->get() as $p) {
                $summary['commodities']['months'][$p->month_id] += $p->amount;
                $summary['commodities']['total'] += $p->amount;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'summary'       => $summary,
                'selected_year' => $selectedYear,
                'years'         => Year::orderBy('year', 'desc')->get(),
                'months'        => $months,
            ],
        ]);
    }

    public function memberFinancialSummary(Request $request, User $member)
    {
        $selectedYear = $request->input('year', date('Y'));
        $yearRecord   = Year::where('year', $selectedYear)->first();
        $months       = Month::all();

        $summary = [
            'savings'     => [],
            'loans'       => [],
            'shares'      => ['name' => 'Share Subscriptions', 'months' => [], 'total' => 0],
            'commodities' => [],
        ];

        foreach ($months as $m) {
            $summary['shares']['months'][$m->id] = 0;
        }

        if ($yearRecord) {
            // Savings by type
            foreach (SavingType::all() as $type) {
                $summary['savings'][$type->id] = ['name' => $type->name, 'months' => [], 'total' => 0];
                foreach ($months as $m) $summary['savings'][$type->id]['months'][$m->id] = 0;
            }

            foreach (Saving::where('user_id', $member->id)->where('year_id', $yearRecord->id)->where('status', 'completed')->get() as $s) {
                if (isset($summary['savings'][$s->saving_type_id])) {
                    $summary['savings'][$s->saving_type_id]['months'][$s->month_id] += $s->amount;
                    $summary['savings'][$s->saving_type_id]['total'] += $s->amount;
                }
            }

            // Loan repayments
            $memberLoans = Loan::where('user_id', $member->id)->where('status', 'approved')->get();
            foreach ($memberLoans as $loan) {
                $summary['loans'][$loan->id] = [
                    'name'   => $loan->loanType->name . ' (' . $loan->reference . ')',
                    'months' => [],
                    'total'  => 0,
                ];
                foreach ($months as $m) $summary['loans'][$loan->id]['months'][$m->id] = 0;
            }

            foreach (LoanRepayment::whereIn('loan_id', $memberLoans->pluck('id'))->where('year_id', $yearRecord->id)->get() as $r) {
                if (isset($summary['loans'][$r->loan_id])) {
                    $summary['loans'][$r->loan_id]['months'][$r->month_id] += $r->amount;
                    $summary['loans'][$r->loan_id]['total'] += $r->amount;
                }
            }

            // Shares
            foreach (Share::where('user_id', $member->id)->where('year_id', $yearRecord->id)->where('status', 'completed')->get() as $s) {
                $summary['shares']['months'][$s->month_id] += $s->amount;
                $summary['shares']['total'] += $s->amount;
            }

            // Commodity payments
            $subs = CommoditySubscription::where('user_id', $member->id)->where('status', 'approved')->with('commodity')->get();
            foreach ($subs as $sub) {
                $summary['commodities'][$sub->id] = [
                    'name'   => $sub->commodity->name . ' (' . $sub->reference . ')',
                    'months' => [],
                    'total'  => 0,
                ];
                foreach ($months as $m) $summary['commodities'][$sub->id]['months'][$m->id] = 0;
            }

            foreach (CommodityPayment::whereIn('commodity_subscription_id', $subs->pluck('id'))->where('status', 'approved')->get() as $p) {
                if (isset($summary['commodities'][$p->commodity_subscription_id])) {
                    $summary['commodities'][$p->commodity_subscription_id]['months'][$p->month_id] += $p->amount;
                    $summary['commodities'][$p->commodity_subscription_id]['total'] += $p->amount;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'member'        => $member->only(['id', 'surname', 'firstname', 'member_no']),
                'summary'       => $summary,
                'selected_year' => $selectedYear,
                'years'         => Year::orderBy('year', 'desc')->get(),
                'months'        => $months,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    //  REPORTS
    // ═══════════════════════════════════════════════════════════

    public function reportMembers(Request $request)
    {
        $members = User::where('is_admin', false)
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->date_to, fn($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->withCount(['shares', 'loans', 'transactions'])
            ->paginate(50);

        return response()->json(['success' => true, 'data' => $members]);
    }

    public function reportSavings(Request $request)
    {
        $query = Saving::with(['user:id,surname,firstname,member_no', 'savingType:id,name'])
            ->when($request->member_id, fn($q, $id) => $q->where('user_id', $id))
            ->when($request->saving_type, fn($q, $t) => $q->where('saving_type_id', $t))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->date_to, fn($q, $d) => $q->whereDate('created_at', '<=', $d));

        $totalSavings = (clone $query)->sum('amount');
        $activeSavers = (clone $query)->distinct('user_id')->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'savings'       => $query->latest()->paginate(50),
                'total_savings' => (float) $totalSavings,
                'active_savers' => $activeSavers,
                'saving_types'  => SavingType::all(),
            ],
        ]);
    }

    public function reportShares(Request $request)
    {
        $query = Share::with(['user:id,surname,firstname,member_no', 'shareType:id,name']);

        if ($request->filled('share_type_id')) $query->where('share_type_id', $request->share_type_id);
        if ($request->filled('month_id'))      $query->where('month_id', $request->month_id);
        if ($request->filled('year_id'))       $query->where('year_id', $request->year_id);

        $totalAmount = (clone $query)->sum('amount_paid');

        return response()->json([
            'success' => true,
            'data'    => [
                'shares'       => $query->latest()->paginate(50),
                'total_amount' => (float) $totalAmount,
                'share_types'  => ShareType::all(),
                'months'       => Month::all(),
                'years'        => Year::all(),
            ],
        ]);
    }

    public function reportLoans(Request $request)
    {
        $query = Loan::with(['user:id,surname,firstname,member_no', 'loanType:id,name']);

        if ($request->filled('loan_type_id')) $query->where('loan_type_id', $request->loan_type_id);
        if ($request->filled('status'))       $query->where('status', $request->status);

        $totalLoans      = Loan::where('status', 'approved')->sum('amount');
        $totalRepayments = Loan::where('status', 'approved')->sum('amount_paid');

        return response()->json([
            'success' => true,
            'data'    => [
                'loans'              => $query->latest()->paginate(50),
                'total_loans'        => (float) $totalLoans,
                'total_repayments'   => (float) $totalRepayments,
                'outstanding_balance' => (float) ($totalLoans - $totalRepayments),
                'loan_types'         => LoanType::all(),
            ],
        ]);
    }

    public function reportTransactions(Request $request)
    {
        $transactions = Transaction::with('user:id,surname,firstname,member_no')
            ->when($request->type, fn($q, $t) => $q->where('type', $t))
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->date_from, fn($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->date_to, fn($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->paginate(50);

        return response()->json(['success' => true, 'data' => $transactions]);
    }

    public function reportSavingsSummary(Request $request)
    {
        $query = User::where('is_admin', false)
            ->when($request->search, function ($q, $s) {
                $q->where(function ($q) use ($s) {
                    $q->where('member_no', 'like', "%{$s}%")
                        ->orWhere('firstname', 'like', "%{$s}%")
                        ->orWhere('surname', 'like', "%{$s}%");
                });
            });

        $members = $query->withSum('savings', 'amount')
            ->withSum(['withdrawals' => fn($q) => $q->where('status', 'completed')], 'amount')
            ->paginate(50);

        $formatted = $members->through(function ($m) {
            $m->total_saved    = $m->savings_sum_amount ?? 0;
            $m->total_withdrawn = $m->withdrawals_sum_amount ?? 0;
            $m->balance        = $m->total_saved - $m->total_withdrawn;
            return $m;
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'members' => $formatted,
                'overall' => [
                    'total_saved'     => $formatted->sum('total_saved'),
                    'total_withdrawn' => $formatted->sum('total_withdrawn'),
                    'total_balance'   => $formatted->sum('balance'),
                ],
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    //  LOOKUP HELPERS (months, years, members dropdown)
    // ═══════════════════════════════════════════════════════════

    public function lookupMonths()
    {
        return response()->json(['success' => true, 'data' => Month::all()]);
    }

    public function lookupYears()
    {
        return response()->json(['success' => true, 'data' => Year::orderBy('year', 'desc')->get()]);
    }

    public function lookupMembers(Request $request)
    {
        $query = User::where('is_admin', false)->where('admin_sign', 'Yes');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('firstname', 'like', "%{$s}%")
                    ->orWhere('surname', 'like', "%{$s}%")
                    ->orWhere('member_no', 'like', "%{$s}%");
            });
        }

        return response()->json([
            'success' => true,
            'data'    => $query->orderBy('surname')->get(['id', 'surname', 'firstname', 'member_no', 'monthly_savings']),
        ]);
    }
}
