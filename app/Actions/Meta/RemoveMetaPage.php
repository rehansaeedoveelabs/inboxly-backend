<?php

namespace App\Actions\Meta;

use App\Models\MetaPage;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RemoveMetaPage
{
    public function handle(Store $store, MetaPage $page): void
    {
        if ($page->store_id !== $store->getKey()) {
            throw new NotFoundHttpException('Facebook Page not found for this store.');
        }

        DB::transaction(function () use ($store, $page) {
            $wasDefault = $page->is_default;
            $page->delete();

            if ($wasDefault) {
                $next = MetaPage::query()
                    ->where('store_id', $store->getKey())
                    ->orderBy('selected_at')
                    ->orderBy('id')
                    ->first();
                if ($next !== null) {
                    $next->forceFill(['is_default' => true])->save();
                }
            }
        });
    }
}
