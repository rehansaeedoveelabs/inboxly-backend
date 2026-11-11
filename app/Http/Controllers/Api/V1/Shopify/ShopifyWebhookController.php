<?php

namespace App\Http\Controllers\Api\V1\Shopify;

use App\Actions\Shopify\HandleShopifyWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopifyWebhookController extends Controller
{
    public function __invoke(Request $request, HandleShopifyWebhook $handler): Response
    {
        $headers = [];
        foreach ([
            'x-shopify-hmac-sha256',
            'x-shopify-topic',
            'x-shopify-shop-domain',
            'x-shopify-webhook-id',
            'x-shopify-api-version',
            'x-shopify-event-id',
        ] as $header) {
            $value = $request->header($header);
            $headers[$header] = is_string($value) ? $value : null;
        }

        $result = $handler->handle($request->getContent(), $headers);

        return response('', $result['status']);
    }
}
