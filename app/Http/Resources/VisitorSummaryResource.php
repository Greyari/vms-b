<?php

namespace App\Http\Resources;

use App\Models\Visitor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A visitor as the check-in search and the visitor lists show one: identity,
 * number, type and blacklist state, plus the birth date and gender the gate
 * uses to tell namesakes apart. The rest of the profile (phone, nationality,
 * crew documents, ID validity) belongs to the profile screen only.
 *
 * @mixin Visitor
 */
class VisitorSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'visitor_no' => $this->visitor_no,
            'name' => $this->name,
            'id_type' => $this->id_type,
            'id_number' => $this->id_number,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'gender' => $this->gender,
            'company' => $this->company,
            'visitor_type' => $this->visitor_type,
            'is_blacklisted' => $this->is_blacklisted,
            'blacklist_reason' => $this->blacklist_reason,
            'badge_code' => $this->badge_code,
            'offences' => $this->offences ?? $this->blacklist_reason,
            'date' => $this->blacklisted_at?->format('Y-m-d') ?? $this->created_at?->format('Y-m-d'),
            'blacklisted_at' => $this->blacklisted_at?->format('Y-m-d') ?? $this->created_at?->format('Y-m-d'),
            'remark' => $this->remark,
            'visits_count' => $this->whenCounted('visits'),
        ];
    }
}
