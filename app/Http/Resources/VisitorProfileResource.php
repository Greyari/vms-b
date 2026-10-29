<?php

namespace App\Http\Resources;

use App\Models\Visitor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The whole visitor profile, for the profile screen and its edit form only.
 * Lists and the check-in search use VisitorSummaryResource.
 *
 * @mixin Visitor
 */
class VisitorProfileResource extends JsonResource
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
            'id_issued_at' => $this->id_issued_at?->format('Y-m-d'),
            'id_expires_at' => $this->id_expires_at?->format('Y-m-d'),
            'id_lifetime' => $this->id_lifetime,
            'visitor_type' => $this->visitor_type,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'birth_place' => $this->birth_place,
            'nationality' => $this->nationality,
            'occupation' => $this->occupation,
            'company' => $this->company,
            'vessel_id' => $this->vessel_id,
            'vessel' => $this->whenLoaded('vessel', fn (): ?array => $this->vessel === null ? null : [
                'id' => $this->vessel->id,
                'code' => $this->vessel->code,
                'name' => $this->vessel->name,
            ]),
            'certificate' => $this->certificate,
            'certificate_no' => $this->certificate_no,
            'seaman_book_no' => $this->seaman_book_no,
            'seaman_book_expires_at' => $this->seaman_book_expires_at?->format('Y-m-d'),
            'is_blacklisted' => $this->is_blacklisted,
            'blacklist_reason' => $this->blacklist_reason,
            'blacklists' => $this->whenLoaded('blacklists', fn () => $this->blacklists->map(fn ($b) => [
                'id' => $b->id,
                'offences' => $b->offences,
                'date' => $b->blacklisted_at?->format('Y-m-d'),
                'blacklisted_at' => $b->blacklisted_at?->format('Y-m-d'),
                'badge_code' => $b->badge_code,
                'remark' => $b->remark,
                'is_active' => $b->is_active,
                'unblacklisted_at' => $b->unblacklisted_at?->format('Y-m-d H:i:s'),
            ])),
            'visits' => VisitResource::collection($this->whenLoaded('visits')),
        ];
    }
}
