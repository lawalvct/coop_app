<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Commodity;
use App\Models\CommodityPayment;
use App\Models\CommoditySubscription;
use App\Models\Loan;
use App\Models\LoanGuarantor;
use App\Models\LoanRepayment;
use App\Models\LoanType;
use App\Models\Month;
use App\Models\MonthlySavingsSetting;
use App\Models\Notification;
use App\Models\Resource;
use App\Models\Saving;
use App\Models\SavingType;
use App\Models\Share;
use App\Models\ShareType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\Year;
use App\Notifications\LoanGuarantorRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class MemberController extends Controller
{
    // ================================================================
    //  DASHBOARD
    // ================================================================

    /**
     * GET /member/dashboard
     *
     * Returns: savings balance, monthly contribution, share capital,
     *          recent transactions, active loans summary, pending guarantor count.
     */
    public function dashboard(): JsonResponse
    {
        $user = Auth::user();

        // Savings totals
        $totalSavings   = Saving::where('user_id', $user->id)->sum('amount');
        $totalWithdrawals = Withdrawal::where('user_id', $user->id)
            ->where('status', 'completed')
            ->sum('amount');
        $savingsBalance = $totalSavings - $totalWithdrawals;

        $monthlyContribution = $user->monthly_savings ?? 0;
        $shareCapital        = $user->share_subscription ?? 0;

        // Active loans count & total outstanding
        $activeLoans = Loan::where('user_id', $user->id)
            ->where('status', 'approved')
            ->with('repayments')
            ->get();

        $totalLoanBalance = $activeLoans->sum(function ($loan) {
            return $loan->total_amount - $loan->repayments->sum('amount');
        });

        // Pending guarantor requests count
        $pendingGuarantorCount = LoanGuarantor::where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        // Recent transactions (last 5 savings entries)
        $recentTransactions = Saving::where('user_id', $user->id)
            ->with(['savingType:id,name', 'month:id,name', 'year:id,year'])
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($s) => [
                'id'         => $s->id,
                'type'       => $s->savingType?->name,
                'amount'     => (float) $s->amount,
                'month'      => $s->month?->name,
                'year'       => $s->year?->year,
                'status'     => $s->status,
                'created_at' => $s->created_at?->toIso8601String(),
            ]);

        // Unread notifications count
        $unreadNotifications = Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'savings_balance'         => (float) $savingsBalance,
                'total_savings'           => (float) $totalSavings,
                'total_withdrawals'       => (float) $totalWithdrawals,
                'monthly_contribution'    => (float) $monthlyContribution,
                'share_capital'           => (float) $shareCapital,
                'active_loans_count'      => $activeLoans->count(),
                'total_loan_balance'      => (float) $totalLoanBalance,
                'pending_guarantor_count' => $pendingGuarantorCount,
                'unread_notifications'    => $unreadNotifications,
                'recent_transactions'     => $recentTransactions,
            ],
        ]);
    }

    // ================================================================
    //  SAVINGS
    // ================================================================

    /**
     * GET /member/savings
     *
     * Returns: saving types with balances, current month total,
     *          total savings, total withdrawals, savings balance,
     *          and recent savings entries.
     */
    public function savings(Request $request): JsonResponse
    {
        $user       = Auth::user();
        $savingTypes = SavingType::active()->get();

        // Per-type balances
        $typeBalances = $savingTypes->map(function ($type) use ($user) {
            $amount = Saving::where('user_id', $user->id)
                ->where('saving_type_id', $type->id)
                ->sum('amount');

            return [
                'id'     => $type->id,
                'name'   => $type->name,
                'amount' => (float) $amount,
            ];
        });

        // Current month total
        $yearRecord = Year::where('year', now()->year)->first();
        $currentMonthTotal = 0;
        if ($yearRecord) {
            $currentMonthTotal = Saving::where('user_id', $user->id)
                ->where('month_id', now()->month)
                ->where('year_id', $yearRecord->id)
                ->sum('amount');
        }

        // Totals
        $totalSavings    = (float) $typeBalances->sum('amount');
        $totalWithdrawals = (float) Withdrawal::where('user_id', $user->id)
            ->where('status', 'completed')
            ->sum('amount');
        $savingsBalance  = $totalSavings - $totalWithdrawals;

        // Recent savings (filterable)
        $query = Saving::where('user_id', $user->id)->with('savingType:id,name');

        if ($request->filled('saving_type_id')) {
            $query->where('saving_type_id', $request->saving_type_id);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $recentSavings = $query->latest()->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'type_balances'      => $typeBalances,
                'current_month_total' => (float) $currentMonthTotal,
                'total_savings'      => $totalSavings,
                'total_withdrawals'  => $totalWithdrawals,
                'savings_balance'    => $savingsBalance,
                'recent_savings'     => $recentSavings,
            ],
        ]);
    }

    /**
     * GET /member/savings/monthly-summary?year=2026
     */
    public function savingsMonthlySummary(Request $request): JsonResponse
    {
        $user         = Auth::user();
        $selectedYear = $request->input('year', date('Y'));

        $years     = Year::orderBy('year', 'desc')->get(['id', 'year']);
        $yearRecord = Year::where('year', $selectedYear)->first();

        if (! $yearRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Selected year not found.',
            ], 404);
        }

        $months     = Month::orderBy('id')->get(['id', 'name']);
        $savingTypes = SavingType::all(['id', 'name']);

        $summary = [];
        foreach ($savingTypes as $type) {
            $monthlyData = [];
            $total       = 0;

            foreach ($months as $month) {
                $amount = Saving::where('user_id', $user->id)
                    ->where('saving_type_id', $type->id)
                    ->where('year_id', $yearRecord->id)
                    ->where('month_id', $month->id)
                    ->sum('amount');

                $monthlyData[] = [
                    'month_id' => $month->id,
                    'month'    => $month->name,
                    'amount'   => (float) $amount,
                ];
                $total += $amount;
            }

            $summary[] = [
                'saving_type_id' => $type->id,
                'name'           => $type->name,
                'months'         => $monthlyData,
                'total'          => (float) $total,
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'year'          => $selectedYear,
                'years'         => $years,
                'months'        => $months,
                'summary'       => $summary,
            ],
        ]);
    }

    // ================================================================
    //  SAVINGS SETTINGS (monthly contribution preferences)
    // ================================================================

    /**
     * GET /member/savings-settings
     */
    public function savingsSettings(): JsonResponse
    {
        $user = Auth::user();

        $settings = MonthlySavingsSetting::where('user_id', $user->id)
            ->with(['savingType:id,name', 'month:id,name', 'year:id,year'])
            ->orderBy('year_id', 'desc')
            ->orderBy('month_id', 'desc')
            ->paginate(20);

        $savingTypes = SavingType::all(['id', 'name']);
        $months      = Month::all(['id', 'name']);
        $years       = Year::orderBy('year', 'desc')->get(['id', 'year']);

        return response()->json([
            'success' => true,
            'data'    => [
                'settings'     => $settings,
                'saving_types' => $savingTypes,
                'months'       => $months,
                'years'        => $years,
            ],
        ]);
    }

    /**
     * POST /member/savings-settings
     */
    public function storeSavingsSetting(Request $request): JsonResponse
    {
        $request->validate([
            'saving_type_id' => 'required|exists:saving_types,id',
            'month_id'       => 'required|exists:months,id',
            'year_id'        => 'required|exists:years,id',
            'amount'         => 'required|numeric|min:0',
        ]);

        $existing = MonthlySavingsSetting::where([
            'user_id'        => Auth::id(),
            'saving_type_id' => $request->saving_type_id,
            'month_id'       => $request->month_id,
            'year_id'        => $request->year_id,
        ])->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'A savings setting already exists for this type, month, and year.',
            ], 422);
        }

        $setting = MonthlySavingsSetting::create([
            'user_id'        => Auth::id(),
            'saving_type_id' => $request->saving_type_id,
            'month_id'       => $request->month_id,
            'year_id'        => $request->year_id,
            'amount'         => $request->amount,
            'status'         => 'approved',
            'approved_by'    => Auth::id(),
            'approved_at'    => now(),
        ]);

        $setting->load(['savingType:id,name', 'month:id,name', 'year:id,year']);

        return response()->json([
            'success' => true,
            'message' => 'Monthly savings setting created successfully.',
            'data'    => $setting,
        ], 201);
    }

    /**
     * PUT /member/savings-settings/{setting}
     */
    public function updateSavingsSetting(Request $request, MonthlySavingsSetting $setting): JsonResponse
    {
        if ($setting->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        if ($setting->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending settings can be updated.',
            ], 422);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0',
        ]);

        $setting->update([
            'amount' => $request->amount,
            'status' => 'pending',
        ]);

        $setting->load(['savingType:id,name', 'month:id,name', 'year:id,year']);

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully.',
            'data'    => $setting,
        ]);
    }

    /**
     * DELETE /member/savings-settings/{setting}
     */
    public function destroySavingsSetting(MonthlySavingsSetting $setting): JsonResponse
    {
        if ($setting->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        if ($setting->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending settings can be deleted.',
            ], 422);
        }

        $setting->delete();

        return response()->json([
            'success' => true,
            'message' => 'Setting deleted successfully.',
        ]);
    }

    // ================================================================
    //  SHARES
    // ================================================================

    /**
     * GET /member/shares
     */
    public function shares(): JsonResponse
    {
        $user = Auth::user();

        $shares = Share::where('user_id', $user->id)
            ->with('shareType:id,name')
            ->latest()
            ->get()
            ->map(fn ($s) => [
                'id'                 => $s->id,
                'share_type'         => $s->shareType?->name,
                'certificate_number' => $s->certificate_number,
                'amount_paid'        => (float) $s->amount_paid,
                'status'             => $s->status,
                'created_at'         => $s->created_at?->toIso8601String(),
            ]);

        $totalApproved = Share::where('user_id', $user->id)
            ->where('status', 'approved')
            ->sum('amount_paid');

        $shareTypes = ShareType::where('status', 'active')->get(['id', 'name', 'minimum_amount', 'maximum_amount']);

        return response()->json([
            'success' => true,
            'data'    => [
                'shares'         => $shares,
                'total_approved' => (float) $totalApproved,
                'share_types'    => $shareTypes,
            ],
        ]);
    }

    /**
     * POST /member/shares
     */
    public function storeShare(Request $request): JsonResponse
    {
        $request->validate([
            'share_type_id'   => 'required|exists:share_types,id',
            'number_of_units' => 'required|integer|min:1',
        ]);

        $shareType = ShareType::findOrFail($request->share_type_id);
        $amountPaid = $shareType->minimum_amount * $request->number_of_units;

        $share = Share::create([
            'user_id'            => Auth::id(),
            'share_type_id'      => $request->share_type_id,
            'certificate_number' => 'SHR-' . date('Y') . '-' . Str::random(8),
            'number_of_units'    => $request->number_of_units,
            'amount_paid'        => $amountPaid,
            'unit_price'         => $shareType->minimum_amount,
            'posted_by'          => Auth::id(),
        ]);

        $share->load('shareType:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Share purchase request submitted successfully.',
            'data'    => $share,
        ], 201);
    }

    // ================================================================
    //  LOANS
    // ================================================================

    /**
     * GET /member/loans
     */
    public function loans(): JsonResponse
    {
        $user = Auth::user();

        $loans = Loan::where('user_id', $user->id)
            ->with(['loanType:id,name,interest_rate,duration_months', 'repayments', 'guarantors.user:id,surname,firstname,member_no'])
            ->latest()
            ->get()
            ->map(function ($loan) {
                $paidAmount = $loan->repayments->sum('amount');
                $balance    = $loan->total_amount - $paidAmount;

                return [
                    'id'              => $loan->id,
                    'reference'       => $loan->reference,
                    'loan_type'       => $loan->loanType?->name,
                    'amount'          => (float) $loan->amount,
                    'interest_amount' => (float) $loan->interest_amount,
                    'total_amount'    => (float) $loan->total_amount,
                    'monthly_payment' => (float) $loan->monthly_payment,
                    'paid_amount'     => (float) $paidAmount,
                    'balance'         => (float) $balance,
                    'duration'        => $loan->duration,
                    'purpose'         => $loan->purpose,
                    'status'          => $loan->status,
                    'start_date'      => $loan->start_date?->toDateString(),
                    'end_date'        => $loan->end_date?->toDateString(),
                    'application_fee' => (float) ($loan->application_fee ?? 0),
                    'guarantors'      => $loan->guarantors->map(fn ($g) => [
                        'name'   => $g->user?->surname . ' ' . $g->user?->firstname,
                        'status' => $g->status,
                    ]),
                    'created_at'      => $loan->created_at?->toIso8601String(),
                ];
            });

        $loanTypes = LoanType::where('status', 'active')->get([
            'id', 'name', 'interest_rate', 'duration_months',
            'minimum_amount', 'maximum_amount', 'no_guarantors',
            'application_fee', 'required_active_savings_months',
            'savings_multiplier',
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'loans'      => $loans,
                'loan_types' => $loanTypes,
            ],
        ]);
    }

    /**
     * GET /member/loans/{loan}
     */
    public function showLoan(Loan $loan): JsonResponse
    {
        if ($loan->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $loan->load(['loanType', 'repayments', 'guarantors.user:id,surname,firstname,member_no']);

        $paidAmount = $loan->repayments->sum('amount');

        return response()->json([
            'success' => true,
            'data'    => [
                'id'              => $loan->id,
                'reference'       => $loan->reference,
                'loan_type'       => $loan->loanType?->name,
                'amount'          => (float) $loan->amount,
                'interest_amount' => (float) $loan->interest_amount,
                'total_amount'    => (float) $loan->total_amount,
                'monthly_payment' => (float) $loan->monthly_payment,
                'paid_amount'     => (float) $paidAmount,
                'balance'         => (float) ($loan->total_amount - $paidAmount),
                'duration'        => $loan->duration,
                'purpose'         => $loan->purpose,
                'status'          => $loan->status,
                'start_date'      => $loan->start_date?->toDateString(),
                'end_date'        => $loan->end_date?->toDateString(),
                'application_fee' => (float) ($loan->application_fee ?? 0),
                'guarantors'      => $loan->guarantors->map(fn ($g) => [
                    'id'      => $g->id,
                    'name'    => $g->user?->surname . ' ' . $g->user?->firstname,
                    'member_no' => $g->user?->member_no,
                    'status'  => $g->status,
                    'comment' => $g->comment,
                ]),
                'repayments'      => $loan->repayments->map(fn ($r) => [
                    'id'           => $r->id,
                    'reference'    => $r->reference,
                    'amount'       => (float) $r->amount,
                    'payment_date' => $r->payment_date?->toDateString(),
                    'status'       => $r->status,
                ]),
                'created_at'      => $loan->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /member/loans
     */
    public function storeLoan(Request $request): JsonResponse
    {
        $request->validate([
            'loan_type_id'  => 'required|exists:loan_types,id',
            'amount'        => 'required|numeric|min:1000',
            'duration'      => 'required|integer|min:1',
            'purpose'       => 'required|string|max:500',
            'guarantor_ids' => 'nullable|array',
            'guarantor_ids.*' => 'exists:users,id',
        ]);

        $loanType = LoanType::findOrFail($request->loan_type_id);

        // Validate duration
        if ($request->duration > $loanType->duration_months) {
            return response()->json([
                'success' => false,
                'message' => "Duration cannot exceed {$loanType->duration_months} months.",
            ], 422);
        }

        // Validate guarantors count
        if ($loanType->no_guarantors > 0) {
            $guarantorIds = $request->guarantor_ids ?? [];
            if (count($guarantorIds) !== $loanType->no_guarantors) {
                return response()->json([
                    'success' => false,
                    'message' => "This loan type requires exactly {$loanType->no_guarantors} guarantor(s).",
                ], 422);
            }
        }

        $interestAmount = ($loanType->interest_rate / 100) * $request->amount * $request->duration;
        $totalAmount    = $request->amount + $interestAmount;
        $monthlyPayment = $totalAmount / $request->duration;
        $startDate      = now();
        $endDate        = Carbon::parse($startDate)->addMonths((int) $request->duration);

        $loan = Loan::create([
            'user_id'         => Auth::id(),
            'reference'       => 'LOAN-' . strtoupper(uniqid()),
            'loan_type_id'    => $request->loan_type_id,
            'amount'          => $request->amount,
            'interest_amount' => $interestAmount,
            'total_amount'    => $totalAmount,
            'monthly_payment' => $monthlyPayment,
            'duration'        => $request->duration,
            'purpose'         => $request->purpose,
            'start_date'      => $startDate,
            'end_date'        => $endDate,
            'status'          => 'pending',
            'posted_by'       => Auth::id(),
            'application_fee' => $loanType->application_fee,
        ]);

        // Create guarantors if required
        if ($loanType->no_guarantors > 0 && ! empty($request->guarantor_ids)) {
            foreach ($request->guarantor_ids as $guarantorId) {
                LoanGuarantor::create([
                    'loan_id' => $loan->id,
                    'user_id' => $guarantorId,
                    'status'  => 'pending',
                ]);

                $guarantor = User::find($guarantorId);
                if ($guarantor) {
                    $guarantor->notify(new LoanGuarantorRequest($loan));
                }
            }
        }

        $loan->load(['loanType:id,name', 'guarantors.user:id,surname,firstname']);

        return response()->json([
            'success' => true,
            'message' => 'Loan application submitted successfully.',
            'data'    => $loan,
        ], 201);
    }

    /**
     * POST /member/loan-calculator
     */
    public function loanCalculator(Request $request): JsonResponse
    {
        $request->validate([
            'loan_type_id' => 'required|exists:loan_types,id',
            'amount'       => 'required|numeric|min:0',
            'duration'     => 'required|integer|min:1|max:18',
        ]);

        $user     = Auth::user();
        $loanType = LoanType::findOrFail($request->loan_type_id);

        $eligibility = ['eligible' => true, 'messages' => []];

        // Check savings duration
        $savingsDuration = $user->getSavingsDuration();
        if ($savingsDuration < $loanType->required_active_savings_months) {
            $eligibility['eligible'] = false;
            $eligibility['messages'][] = "You need {$loanType->required_active_savings_months} months of active savings. Current: {$savingsDuration} months.";
        }

        // Check savings multiplier
        $totalActiveSavings = $user->savings()
            ->whereNot('remark', 'used_for_loan')
            ->sum('amount');

        $maxLoanOnSavings = $totalActiveSavings * $loanType->savings_multiplier;
        if ($request->amount > $maxLoanOnSavings) {
            $eligibility['eligible'] = false;
            $eligibility['messages'][] = "Based on savings of ₦" . number_format($totalActiveSavings, 2) . ", max is ₦" . number_format($maxLoanOnSavings, 2) . ".";
        }

        // Check max amount
        if ($request->amount > $loanType->maximum_amount) {
            $eligibility['eligible'] = false;
            $eligibility['messages'][] = "Exceeds maximum of ₦" . number_format($loanType->maximum_amount, 2) . ".";
        }

        $interestRate    = $loanType->interest_rate;
        $totalInterest   = $request->amount * $interestRate / 100;
        $totalAmount     = $request->amount + $totalInterest;
        $monthlyRepayment = $totalAmount / $request->duration;

        return response()->json([
            'success' => true,
            'data'    => [
                'eligibility' => $eligibility,
                'loan_details' => [
                    'principal'         => (float) $request->amount,
                    'interest_rate'     => (float) $interestRate,
                    'total_interest'    => (float) $totalInterest,
                    'total_amount'      => (float) $totalAmount,
                    'monthly_repayment' => (float) $monthlyRepayment,
                    'duration'          => (int) $request->duration,
                ],
                'loan_type' => [
                    'id'   => $loanType->id,
                    'name' => $loanType->name,
                ],
            ],
        ]);
    }

    /**
     * GET /member/loan-types
     */
    public function loanTypes(): JsonResponse
    {
        $types = LoanType::where('status', 'active')->get();

        return response()->json([
            'success' => true,
            'data'    => $types,
        ]);
    }

    /**
     * GET /member/members (for guarantor search)
     */
    public function members(Request $request): JsonResponse
    {
        $query = User::where('is_admin', 0)
            ->where('id', '!=', Auth::id())
            ->where('admin_sign', 'Yes');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('surname', 'like', "%{$search}%")
                  ->orWhere('firstname', 'like', "%{$search}%")
                  ->orWhere('member_no', 'like', "%{$search}%");
            });
        }

        $members = $query->select('id', 'surname', 'firstname', 'member_no')
            ->orderBy('surname')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $members,
        ]);
    }

    // ================================================================
    //  GUARANTOR REQUESTS
    // ================================================================

    /**
     * GET /member/guarantor-requests
     */
    public function guarantorRequests(): JsonResponse
    {
        $requests = LoanGuarantor::where('user_id', Auth::id())
            ->with([
                'loan.user:id,surname,firstname,member_no',
                'loan.loanType:id,name',
            ])
            ->latest()
            ->get()
            ->map(fn ($g) => [
                'id'          => $g->id,
                'loan_id'     => $g->loan_id,
                'borrower'    => $g->loan?->user?->surname . ' ' . $g->loan?->user?->firstname,
                'member_no'   => $g->loan?->user?->member_no,
                'loan_type'   => $g->loan?->loanType?->name,
                'loan_amount' => (float) $g->loan?->amount,
                'status'      => $g->status,
                'comment'     => $g->comment,
                'created_at'  => $g->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data'    => $requests,
        ]);
    }

    /**
     * POST /member/guarantor-requests/{loan}/respond
     */
    public function respondGuarantorRequest(Request $request, Loan $loan): JsonResponse
    {
        $request->validate([
            'response' => 'required|in:approved,rejected',
            'reason'   => 'required_if:response,rejected|string|nullable',
        ]);

        $guarantor = LoanGuarantor::where('loan_id', $loan->id)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $guarantor->update([
            'status'  => $request->response,
            'comment' => $request->reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Response submitted successfully.',
        ]);
    }

    // ================================================================
    //  WITHDRAWALS
    // ================================================================

    /**
     * GET /member/withdrawals
     */
    public function withdrawals(Request $request): JsonResponse
    {
        $user  = Auth::user();
        $query = Withdrawal::where('user_id', $user->id)->with('savingType:id,name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $withdrawals = $query->latest()->paginate($request->input('per_page', 20));

        $totalAmount   = Withdrawal::where('user_id', $user->id)->sum('amount');
        $approvedAmount = Withdrawal::where('user_id', $user->id)->where('status', 'completed')->sum('amount');

        return response()->json([
            'success' => true,
            'data'    => [
                'withdrawals'     => $withdrawals,
                'total_amount'    => (float) $totalAmount,
                'approved_amount' => (float) $approvedAmount,
            ],
        ]);
    }

    /**
     * POST /member/withdrawals
     */
    public function storeWithdrawal(Request $request): JsonResponse
    {
        $request->validate([
            'saving_type_id'  => 'required|exists:saving_types,id',
            'amount'          => 'required|numeric|min:1',
            'reason'          => 'required|string|max:255',
            'bank_name'       => 'required|string|max:255',
            'account_number'  => 'required|string|max:20',
            'account_name'    => 'required|string|max:255',
        ]);

        // Check balance
        $credits = Saving::where('user_id', Auth::id())
            ->where('saving_type_id', $request->saving_type_id)
            ->where('status', 'completed')
            ->sum('amount');

        $debits = Withdrawal::where('user_id', Auth::id())
            ->where('saving_type_id', $request->saving_type_id)
            ->where('status', 'approved')
            ->sum('amount');

        $availableBalance = $credits - $debits;

        if ($request->amount > $availableBalance) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance. Available: ₦' . number_format($availableBalance, 2),
            ], 422);
        }

        $withdrawal = Withdrawal::create([
            'user_id'         => Auth::id(),
            'saving_type_id'  => $request->saving_type_id,
            'amount'          => $request->amount,
            'reason'          => $request->reason,
            'bank_name'       => $request->bank_name,
            'account_number'  => $request->account_number,
            'account_name'    => $request->account_name,
            'reference'       => 'WDR-' . strtoupper(Str::random(8)),
            'status'          => 'pending',
        ]);

        $withdrawal->load('savingType:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal request submitted successfully.',
            'data'    => $withdrawal,
        ], 201);
    }

    /**
     * GET /member/withdrawals/{withdrawal}
     */
    public function showWithdrawal(Withdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $withdrawal->load('savingType:id,name');

        return response()->json([
            'success' => true,
            'data'    => $withdrawal,
        ]);
    }

    // ================================================================
    //  TRANSACTIONS (Passbook)
    // ================================================================

    /**
     * GET /member/transactions
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = Transaction::where('user_id', $user->id)
            ->whereNot('type', 'entrance_fee');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('transaction_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('transaction_date', '<=', $request->end_date);
        }

        $transactions = $query->latest()->paginate($request->input('per_page', 50));

        $totalCredits = Transaction::where('user_id', $user->id)
            ->whereNot('type', 'entrance_fee')
            ->sum('credit_amount');

        $totalDebits = Transaction::where('user_id', $user->id)
            ->whereNot('type', 'entrance_fee')
            ->sum('debit_amount');

        return response()->json([
            'success' => true,
            'data'    => [
                'transactions'  => $transactions,
                'total_credits' => (float) $totalCredits,
                'total_debits'  => (float) $totalDebits,
                'net_balance'   => (float) ($totalCredits - $totalDebits),
            ],
        ]);
    }

    /**
     * GET /member/transactions/{transaction}
     */
    public function showTransaction(Transaction $transaction): JsonResponse
    {
        if ($transaction->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'id'               => $transaction->id,
                'type'             => $transaction->type,
                'credit_amount'    => (float) $transaction->credit_amount,
                'debit_amount'     => (float) $transaction->debit_amount,
                'balance'          => (float) $transaction->balance,
                'reference'        => $transaction->reference,
                'description'      => $transaction->description,
                'transaction_date' => $transaction->transaction_date?->toIso8601String(),
                'status'           => $transaction->status,
            ],
        ]);
    }

    // ================================================================
    //  COMMODITIES
    // ================================================================

    /**
     * GET /member/commodities
     */
    public function commodities(): JsonResponse
    {
        $commodities = Commodity::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', now()))
            ->where('quantity_available', '>', 0)
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $commodities,
        ]);
    }

    /**
     * GET /member/commodities/{commodity}
     */
    public function showCommodity(Commodity $commodity): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'id'                        => $commodity->id,
                'name'                      => $commodity->name,
                'description'               => $commodity->description,
                'price'                     => (float) $commodity->price,
                'quantity_available'         => $commodity->quantity_available,
                'image'                     => $commodity->image ? asset('storage/' . $commodity->image) : null,
                'allow_installment'         => $commodity->allow_installment,
                'max_installment_months'    => $commodity->max_installment_months,
                'initial_deposit_percentage' => (float) $commodity->initial_deposit_percentage,
                'start_date'                => $commodity->start_date?->toDateString(),
                'end_date'                  => $commodity->end_date?->toDateString(),
            ],
        ]);
    }

    /**
     * POST /member/commodities/{commodity}/subscribe
     */
    public function subscribeCommodity(Request $request, Commodity $commodity): JsonResponse
    {
        $rules = [
            'quantity' => 'required|integer|min:1|max:' . $commodity->quantity_available,
            'reason'   => 'nullable|string|max:500',
        ];

        if ($commodity->allow_installment && $request->payment_type === 'installment') {
            $rules['payment_type']    = 'required|in:full,installment';
            $rules['initial_deposit'] = 'required|numeric|min:' . ($commodity->price * $commodity->initial_deposit_percentage / 100);
        }

        $request->validate($rules);

        $totalAmount      = $commodity->price * $request->quantity;
        $paymentType      = $request->payment_type ?? 'full';
        $initialDeposit   = 0;
        $remainingAmount  = 0;
        $installmentMonths = 0;
        $monthlyAmount    = 0;

        if ($paymentType === 'installment' && $commodity->allow_installment) {
            $initialDeposit   = $request->initial_deposit;
            $remainingAmount  = $totalAmount - $initialDeposit;
            $installmentMonths = $commodity->max_installment_months;
            $monthlyAmount    = $installmentMonths > 0 ? $remainingAmount / $installmentMonths : 0;
        }

        $subscription = CommoditySubscription::create([
            'user_id'            => Auth::id(),
            'commodity_id'       => $commodity->id,
            'quantity'           => $request->quantity,
            'status'             => 'pending',
            'unit_price'         => $commodity->price,
            'note'               => $request->reason ?? '',
            'reference'          => 'COM-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8)) . '-' . date('Ymd'),
            'total_amount'       => $totalAmount,
            'payment_type'       => $paymentType,
            'initial_deposit'    => $initialDeposit,
            'remaining_amount'   => $remainingAmount,
            'installment_months' => $installmentMonths,
            'monthly_amount'     => $monthlyAmount,
        ]);

        $commodity->decrement('quantity_available', $request->quantity);

        $subscription->load('commodity:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Subscription submitted successfully.',
            'data'    => $subscription,
        ], 201);
    }

    /**
     * GET /member/commodity-subscriptions
     */
    public function commoditySubscriptions(): JsonResponse
    {
        $subscriptions = CommoditySubscription::where('user_id', Auth::id())
            ->with('commodity:id,name,image,price')
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data'    => $subscriptions,
        ]);
    }

    /**
     * GET /member/commodity-subscriptions/{subscription}
     */
    public function showCommoditySubscription(CommoditySubscription $subscription): JsonResponse
    {
        if ($subscription->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $subscription->load(['commodity', 'payments']);

        $initialDeposit = $subscription->initial_deposit ?? 0;
        $paidAmount     = $initialDeposit + $subscription->payments->sum('amount');
        $remaining      = $subscription->total_amount - $paidAmount;

        return response()->json([
            'success' => true,
            'data'    => [
                'subscription'   => $subscription,
                'total_amount'   => (float) $subscription->total_amount,
                'initial_deposit' => (float) $initialDeposit,
                'paid_amount'    => (float) $paidAmount,
                'remaining'      => (float) max(0, $remaining),
            ],
        ]);
    }

    /**
     * POST /member/commodity-subscriptions/{subscription}/payments
     */
    public function storeCommodityPayment(Request $request, CommoditySubscription $subscription): JsonResponse
    {
        if ($subscription->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $paidAmount  = ($subscription->initial_deposit ?? 0) + $subscription->payments()->sum('amount');
        $remaining   = $subscription->total_amount - $paidAmount;

        if ($remaining <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription is already fully paid.',
            ], 422);
        }

        $request->validate([
            'amount'            => 'required|numeric|min:1|max:' . $remaining,
            'payment_method'    => 'required|in:cash,bank_transfer,deduction',
            'payment_reference' => 'nullable|string|max:255',
            'month_id'          => 'required|exists:months,id',
            'year_id'           => 'required|exists:years,id',
        ]);

        $payment = CommodityPayment::create([
            'commodity_subscription_id' => $subscription->id,
            'amount'                    => $request->amount,
            'payment_method'            => $request->payment_method,
            'payment_reference'         => $request->payment_reference,
            'status'                    => 'pending',
            'month_id'                  => $request->month_id,
            'year_id'                   => $request->year_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment submitted and pending approval.',
            'data'    => $payment,
        ], 201);
    }

    // ================================================================
    //  NOTIFICATIONS
    // ================================================================

    /**
     * GET /member/notifications
     */
    public function notifications(): JsonResponse
    {
        $notifications = Notification::where('user_id', Auth::id())
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $notifications,
        ]);
    }

    /**
     * POST /member/notifications/{notification}/read
     */
    public function markNotificationRead(Notification $notification): JsonResponse
    {
        if ($notification->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $notification->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
        ]);
    }

    /**
     * POST /member/notifications/read-all
     */
    public function markAllNotificationsRead(): JsonResponse
    {
        Notification::where('user_id', Auth::id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
        ]);
    }

    // ================================================================
    //  FINANCIAL SUMMARY
    // ================================================================

    /**
     * GET /member/financial-summary?year=2026
     */
    public function financialSummary(Request $request): JsonResponse
    {
        $user         = Auth::user();
        $selectedYear = $request->input('year', date('Y'));

        $years     = Year::orderBy('year', 'desc')->get(['id', 'year']);
        $yearRecord = Year::where('year', $selectedYear)->first();

        if (! $yearRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Selected year not found.',
            ], 404);
        }

        $months     = Month::orderBy('id')->get(['id', 'name']);
        $savingTypes = SavingType::all(['id', 'name']);

        // --- Savings ---
        $savingsSummary = [];
        foreach ($savingTypes as $type) {
            $monthlyData = [];
            $total       = 0;
            foreach ($months as $month) {
                $amount = Saving::where('user_id', $user->id)
                    ->where('saving_type_id', $type->id)
                    ->where('year_id', $yearRecord->id)
                    ->where('month_id', $month->id)
                    ->sum('amount');
                $monthlyData[] = ['month_id' => $month->id, 'amount' => (float) $amount];
                $total += $amount;
            }
            $savingsSummary[] = ['id' => $type->id, 'name' => $type->name, 'months' => $monthlyData, 'total' => (float) $total];
        }

        // --- Shares ---
        $shareMonthly = [];
        $shareTotal   = 0;
        foreach ($months as $month) {
            $amount = Share::where('user_id', $user->id)
                ->where('year_id', $yearRecord->id)
                ->where('status', 'approved')
                ->where('month_id', $month->id)
                ->sum('amount_paid');
            $shareMonthly[] = ['month_id' => $month->id, 'amount' => (float) $amount];
            $shareTotal += $amount;
        }

        // --- Loan repayments ---
        $loansSummary = [];
        $activeLoans  = Loan::where('user_id', $user->id)->where('status', 'approved')->with('loanType:id,name')->get();
        foreach ($activeLoans as $loan) {
            $monthlyData = [];
            $total       = 0;
            foreach ($months as $month) {
                $amount = LoanRepayment::where('loan_id', $loan->id)
                    ->where('year_id', $yearRecord->id)
                    ->where('month_id', $month->id)
                    ->sum('amount');
                $monthlyData[] = ['month_id' => $month->id, 'amount' => (float) $amount];
                $total += $amount;
            }
            $loansSummary[] = [
                'id'        => $loan->id,
                'name'      => $loan->loanType?->name . ' (' . $loan->reference . ')',
                'months'    => $monthlyData,
                'total'     => (float) $total,
            ];
        }

        // --- Commodities ---
        $commoditySummary = [];
        $subs = CommoditySubscription::where('user_id', $user->id)
            ->where('status', 'approved')
            ->with('commodity:id,name')
            ->get();

        foreach ($subs as $sub) {
            $monthlyData = [];
            $total       = 0;
            foreach ($months as $month) {
                $amount = CommodityPayment::where('commodity_subscription_id', $sub->id)
                    ->where('year_id', $yearRecord->id)
                    ->where('status', 'approved')
                    ->where('month_id', $month->id)
                    ->sum('amount');
                $monthlyData[] = ['month_id' => $month->id, 'amount' => (float) $amount];
                $total += $amount;
            }
            $commoditySummary[] = [
                'id'   => $sub->id,
                'name' => $sub->commodity?->name . ' (' . $sub->reference . ')',
                'months' => $monthlyData,
                'total'  => (float) $total,
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'year'        => $selectedYear,
                'years'       => $years,
                'months'      => $months,
                'savings'     => $savingsSummary,
                'shares'      => ['months' => $shareMonthly, 'total' => (float) $shareTotal],
                'loans'       => $loansSummary,
                'commodities' => $commoditySummary,
            ],
        ]);
    }

    // ================================================================
    //  PROFILE UPDATE
    // ================================================================

    /**
     * PUT /member/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = Auth::user();

        $request->validate([
            'phone_number'  => 'nullable|string',
            'home_address'  => 'nullable|string',
            'nok'           => 'nullable|string',
            'nok_phone'     => 'nullable|string',
            'nok_address'   => 'nullable|string',
            'nok_relationship' => 'nullable|string',
            'bank_name'     => 'nullable|string',
            'account_number' => 'nullable|string',
            'account_name'  => 'nullable|string',
        ]);

        $user->update($request->only([
            'phone_number', 'home_address',
            'nok', 'nok_phone', 'nok_address', 'nok_relationship',
            'bank_name', 'account_number', 'account_name',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data'    => $user->fresh(),
        ]);
    }

    // ================================================================
    //  RESOURCES
    // ================================================================

    /**
     * GET /member/resources
     */
    public function resources(): JsonResponse
    {
        $resources = Resource::where('status', 'active')
            ->latest()
            ->paginate(12)
            ->through(fn ($r) => [
                'id'            => $r->id,
                'title'         => $r->title,
                'description'   => $r->description,
                'file_type'     => $r->file_type,
                'file_size'     => $r->file_size,
                'download_url'  => url("/api/v1/member/resources/{$r->id}/download"),
                'created_at'    => $r->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data'    => $resources,
        ]);
    }

    /**
     * GET /member/resources/{resource}/download
     */
    public function downloadResource(Resource $resource): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $resource->increment('download_count');

        return \Illuminate\Support\Facades\Storage::download($resource->file_path);
    }
}
