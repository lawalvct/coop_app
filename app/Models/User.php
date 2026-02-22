<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $fillable = [
        'title',
        'firstname',
        'surname',
        'othername',
        'email',
        'password',
        'phone_number',
        'staff_no',
        'dob',
        'nationality',
        'home_address',
        'state_id',
        'lga_id',
        'faculty_id',
        'department_id',
        'nok',
        'nok_relationship',
        'nok_phone',
        'nok_address',
        'monthly_savings',
        'share_subscription',
        'month_commence',
        'member_image',
        'signature_image',
        'admin_sign',
        'status',
        'is_admin',
        'member_no',
        'salary_deduction_agreement',
        'membership_declaration',
        'religion',
        'marital_status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
    protected $dates = ['deleted_at'];
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }

    public function notifications()
    {
        return $this->morphMany(\Illuminate\Notifications\DatabaseNotification::class, 'notifiable')
            ->orderBy('created_at', 'desc');
    }


    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function lga()
    {
        return $this->belongsTo(Lga::class);
    }
    public function profileUpdateRequests()
    {
        return $this->hasMany(ProfileUpdateRequest::class);
    }
    public function getSavingsDuration()
    {
        // Get the latest loan date if exists
        $lastLoanDate = $this->loans()
            ->where('status', 'active')
            ->orWhere('status', 'completed')
            ->latest()
            ->first()?->created_at;

        // Query active savings (not used for loan)
        $activeSavings = $this->savings()
            ->whereNot('remark', 'used_for_loan')
            ->when($lastLoanDate, function ($query) use ($lastLoanDate) {
                return $query->where('created_at', '>', $lastLoanDate);
            })
            ->select('month_id')
            ->distinct()
            ->get();

        return $activeSavings->count();
    }

    public function loans()
    {
        return $this->hasMany(Loan::class);
    }

    public function savings()
    {
        return $this->hasMany(Saving::class);
    }
    public function roles()
    {
        return $this->belongsToMany(\App\Models\Role::class);
    }

    public function hasPermission($permission)
    {
        return $this->roles->some(function ($role) use ($permission) {
            return $role->hasPermission($permission);
        });
    }


    public function pendingGuarantorRequests()
    {
        return $this->hasMany(LoanGuarantor::class, 'user_id')
        ->where('status', 'pending')
        ->with('loan');
    }

    public function guarantorRequests()
    {
        return $this->hasMany(LoanGuarantor::class, 'user_id');
    }
    public function shares()
    {
        return $this->hasMany(Share::class);
    }


    public function approvedShares()
    {
        return $this->hasMany(Share::class)->where('status', 'approved');
    }

    public function approvedLoans()
    {
        return $this->hasMany(Loan::class)->where('status', 'approved');
    }


public function monthlySavingsSettings()
{
    return $this->hasMany(MonthlySavingsSetting::class);
}

public function withdrawals()
{
    return $this->hasMany(Withdrawal::class);
}

public function getSavingsBalance()
{
    $totalSaved = $this->savings()->sum('amount');
    $totalWithdrawn = $this->withdrawals()->where('status', 'approved')->sum('amount');
    return $totalSaved - $totalWithdrawn;
}
}




