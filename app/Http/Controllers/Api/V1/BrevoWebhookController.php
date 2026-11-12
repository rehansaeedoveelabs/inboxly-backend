<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ProcessBrevoWebhookEvent;
use App\Http\Controllers\Controller;
use App\Support\Messaging\BrevoWebhookAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrevoWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        BrevoWebhookAuthenticator $authenticator,
        ProcessBrevoWebhookEvent $processor,
    ): JsonResponse {
        if (! $authenticator->authenticate($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $payload = $request->all();
        $events = array_is_list($payload) ? $payload : [$payload];
        $results = [
            'processed' => 0,
            'duplicates' => 0,
            'ignored' => 0,
        ];

        foreach ($events as $event) {
            if (! is_array($event)) {
                $results['ignored']++;

                continue;
            }

            $outcome = $processor->handle($event);
            if ($outcome['duplicate']) {
                $results['duplicates']++;
            } elseif ($outcome['ignored']) {
                $results['ignored']++;
            } elseif ($outcome['processed']) {
                $results['processed']++;
            }
        }

        return response()->json(['data' => $results]);
    }
}
