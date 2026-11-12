<?php

namespace App\Actions\Meta;

use App\Models\MetaPage;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SetDefaultMetaPage
{
    public function handle(Store $store, MetaPage $page): MetaPage
    {
        if ($page->store_id !== $store->getKey()) {
            throw new NotFoundHttpException('Facebook Page not found for this store.');
        }

        return DB::transaction(function () use ($store, $page) {
            /** @var MetaPage|null $locked */
            $locked = MetaPage::query()
                ->where('store_id', $store->getKey())
                ->whereKey($page->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'page' => ['Facebook Page not found.'],
                ]);
            }

            MetaPage::query()
                ->where('store_id', $store->getKey())
                ->whereKeyNot($locked->getKey())
                ->update(['is_default' => false]);

            $locked->forceFill(['is_default' => true])->save();

            return $locked->fresh();
        });
    }
}
