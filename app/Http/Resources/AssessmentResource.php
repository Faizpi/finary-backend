<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                       => $this->id,
            'monthly_income'           => $this->monthly_income,
            'monthly_expense'          => $this->monthly_expense,
            'actual_savings'           => $this->actual_savings,
            'budget_goal'              => $this->budget_goal,
            'emergency_fund'           => $this->emergency_fund,
            'loan_payment'             => $this->loan_payment,
            'available_hours_per_week' => $this->available_hours_per_week,
            'skills'                   => $this->skills ?? [],
            'classification'           => $this->classification,
            'ml_score'                 => $this->ml_score,
            'ml_explanation'           => $this->ml_explanation,
            'metadata'                 => $this->metadata,
            'created_at'               => $this->created_at,
        ];
    }
}
