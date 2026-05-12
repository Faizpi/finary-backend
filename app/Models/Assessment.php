<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'monthly_income',
        'monthly_expense',
        'actual_savings',
        'budget_goal',
        'emergency_fund',
        'loan_payment',
        'classification',
        'ml_score',
        'ml_explanation',
        'metadata',
        // Legacy fields (kept nullable for backward compat)
        'financial_status',
        'economic_condition',
        'income_sources',
        'financial_goal',
        'available_hours_per_week',
        'skills',
    ];

    protected $casts = [
        'monthly_income'  => 'float',
        'monthly_expense' => 'float',
        'actual_savings'  => 'float',
        'budget_goal'     => 'float',
        'emergency_fund'  => 'float',
        'loan_payment'    => 'float',
        'ml_score'        => 'float',
        'income_sources'  => 'array',
        'skills'          => 'array',
        'metadata'        => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
