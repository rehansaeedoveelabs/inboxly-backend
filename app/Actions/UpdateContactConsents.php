<?php

namespace App\Actions;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

class UpdateContactConsents
{
    /**
     * Sets the current consent per channel and appends an immutable consent_events row
     * for every real change (new row, or status differs). Re-sending the same status is a no-op.
     *
     * @param  list<array{channel: string, status: string, source?: ?string}>  $consents
     */
    public function handle(Contact $contact, array $consents, ?string $defaultSource = 'api'): Contact
    {
        DB::transaction(function () use ($contact, $consents, $defaultSource) {
            $current = $contact->consents()->lockForUpdate()->get()->keyBy(fn ($c) => $c->channel->value);

            // If a channel is repeated in one request the last entry wins.
            $latest = [];
            foreach ($consents as $item) {
                $latest[$item['channel']] = $item;
            }

            foreach ($latest as $channelValue => $item) {
                $channel = ConsentChannel::from($channelValue);
                $status = ConsentStatus::from($item['status']);
                $source = $item['source'] ?? $defaultSource;
                $existing = $current->get($channelValue);

                if ($existing !== null && $existing->status === $status) {
                    continue;
                }

                $attributes = [
                    'status' => $status,
                    'source' => $source,
                    'consented_at' => $status === ConsentStatus::Subscribed ? now() : null,
                ];

                if ($existing === null) {
                    $contact->consents()->create(['channel' => $channel] + $attributes);
                } else {
                    $existing->update($attributes);
                }

                $contact->consentEvents()->create([
                    'channel' => $channel,
                    'status' => $status,
                    'source' => $source,
                ]);
            }
        });

        return $contact->load('consents');
    }
}
