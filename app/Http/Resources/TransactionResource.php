<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'type'             => $this->type,
            'category'         => $this->category,
            'amount'           => $this->amount,
            'transaction_date' => $this->transaction_date,
            'note'             => $this->note,
            'created_at'       => $this->created_at,
        ];
    }
}
