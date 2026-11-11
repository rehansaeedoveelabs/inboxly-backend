<?php

namespace App\Http\Resources;

use App\Enums\ConsentChannel;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contact */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'email' => $this->email,
            'phone' => $this->phone,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'source' => $this->source,
            'metadata' => $this->metadata,
            'consent_review_required' => $this->requiresConsentReview(),
            'consent_review_required_at' => $this->consent_review_required_at,
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            // Keyed by channel for easy lookup: { email: {status, ...}, sms: {...}, push: {...} }
            'consents' => $this->whenLoaded('consents', fn () => $this->consents->mapWithKeys(fn ($c) => [
                $c->channel->value => [
                    'status' => $c->status->value,
                    'source' => $c->source,
                    'consented_at' => $c->consented_at,
                ],
            ])),
            'marketing_eligible' => $this->whenLoaded('consents', fn () => collect(ConsentChannel::cases())
                ->mapWithKeys(fn (ConsentChannel $channel) => [
                    $channel->value => $this->isMarketingEligible($channel),
                ])
                ->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
