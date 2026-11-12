<?php

namespace App\Actions;

use App\Enums\CampaignChannel;
use App\Enums\CampaignContentFormat;
use App\Models\Campaign;
use App\Models\EmailTemplate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Copies a template snapshot onto a draft email campaign.
 * Later template edits do not mutate the campaign copy.
 */
class ApplyEmailTemplateToCampaign
{
    public function handle(Campaign $campaign, EmailTemplate $template): Campaign
    {
        if ($campaign->isLocked()) {
            throw new ConflictHttpException('Only draft campaigns can be edited.');
        }

        if ($campaign->channel !== CampaignChannel::Email) {
            throw ValidationException::withMessages([
                'channel' => ['Templates can only be applied to email campaigns.'],
            ]);
        }

        if ($template->store_id !== $campaign->store_id) {
            throw ValidationException::withMessages([
                'email_template_id' => ['Template must belong to the same store.'],
            ]);
        }

        $document = $template->document ?? ['version' => 1, 'blocks' => []];

        $campaign->forceFill([
            'email_template_id' => $template->getKey(),
            'content_format' => CampaignContentFormat::Html,
            'content_document' => $document,
            'html_body' => $template->html,
            'body' => $this->plainTextFallback($template->html),
        ])->save();

        return $campaign->refresh();
    }

    private function plainTextFallback(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
