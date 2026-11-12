<?php

namespace App\Support\Meta;

final class MetaPublishRequest
{
    public function __construct(
        public readonly string $pageId,
        public readonly string $pageAccessToken,
        public readonly string $message,
        public readonly ?string $link = null,
        public readonly ?string $imageAbsolutePath = null,
        public readonly ?string $imageMimeType = null,
    ) {}
}
