<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use Illuminate\Http\Request;

class StoreChannelsController extends Controller
{
    public function show(Store $store): StoreResource
    {
        $this->authorize('manageAudience', $store);

        return new StoreResource($store);
    }

    public function update(Request $request, Store $store): StoreResource
    {
        $this->authorize('manageAudience', $store);

        $data = $request->validate([
            'email' => ['sometimes', 'array'],
            'email.from_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email.from_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'email.reply_to' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'email.footer_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sms' => ['sometimes', 'array'],
            'sms.sender_id' => ['sometimes', 'nullable', 'string', 'max:32'],
            'sms.compliance_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sms.enabled' => ['sometimes', 'boolean'],
            'web_push' => ['sometimes', 'array'],
            'web_push.enabled' => ['sometimes', 'boolean'],
            'web_push.prompt_delay_seconds' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'web_push.icon_label' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $metadata = is_array($store->metadata) ? $store->metadata : [];
        $channels = is_array($metadata['channels'] ?? null) ? $metadata['channels'] : [];

        foreach (['email', 'sms', 'web_push'] as $key) {
            if (array_key_exists($key, $data) && is_array($data[$key])) {
                $existing = is_array($channels[$key] ?? null) ? $channels[$key] : [];
                $channels[$key] = array_merge($existing, $data[$key]);
            }
        }

        $metadata['channels'] = $channels;
        $store->metadata = $metadata;
        $store->save();

        return new StoreResource($store->refresh());
    }
}
