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
        $settings = is_array($document['settings'] ?? null) ? $document['settings'] : [];

        // Inbox subject is required to schedule/send. Prefer template studio subject,
        // then template name, when the campaign subject is still empty.
        $subject = is_string($campaign->subject) ? trim($campaign->subject) : '';
        if ($subject === '') {
            $fromSettings = is_string($settings['subject'] ?? null) ? trim($settings['subject']) : '';
            $subject = $fromSettings !== ''
                ? $fromSettings
                : (is_string($template->name) ? trim($template->name) : '');
        }

        $previewText = is_string($campaign->preview_text) ? trim($campaign->preview_text) : '';
        if ($previewText === '') {
            $fromPreheader = is_string($settings['preheader'] ?? null) ? trim($settings['preheader']) : '';
            if ($fromPreheader !== '') {
                $previewText = $fromPreheader;
            }
        }

        $campaign->forceFill([
            'email_template_id' => $template->getKey(),
            'content_format' => CampaignContentFormat::Html,
            'content_document' => $document,
            'html_body' => $template->html,
            'body' => $this->plainTextFallback($template->html),
            'subject' => $subject !== '' ? $subject : $campaign->subject,
            'preview_text' => $previewText !== '' ? $previewText : $campaign->preview_text,
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
