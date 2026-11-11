<?php

namespace App\Actions;

use App\Enums\CampaignAudienceType;
use App\Enums\CampaignChannel;
use App\Enums\CampaignContentFormat;
use App\Models\Campaign;
use App\Support\EmailTemplates\EmailBlockSchema;
use App\Support\EmailTemplates\EmailDocumentRenderer;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UpdateCampaign
{
    public function __construct(private EmailDocumentRenderer $renderer) {}

    /**
     * @param  array{
     *     name?: string,
     *     subject?: ?string,
     *     preview_text?: ?string,
     *     body?: ?string,
     *     content_document?: ?array<string, mixed>,
     *     audience_type?: CampaignAudienceType|string,
     *     segment_id?: ?string
     * }  $data
     */
    public function handle(Campaign $campaign, array $data): Campaign
    {
        if ($campaign->isScheduled()) {
            throw new ConflictHttpException('Scheduled campaigns cannot be edited. Unschedule first.');
        }

        if (array_key_exists('name', $data)) {
            $campaign->name = $data['name'];
        }

        if (array_key_exists('body', $data)) {
            $campaign->body = $data['body'];
        }

        if (array_key_exists('content_document', $data)) {
            if ($campaign->channel !== CampaignChannel::Email) {
                throw ValidationException::withMessages([
                    'content_document' => ['HTML documents are only supported on email campaigns.'],
                ]);
            }

            $document = $data['content_document'];
            if ($document === null) {
                $campaign->content_document = null;
                $campaign->html_body = null;
                $campaign->content_format = CampaignContentFormat::Plain;
                $campaign->email_template_id = null;
            } else {
                $errors = EmailBlockSchema::validate($document);
                if ($errors !== []) {
                    throw ValidationException::withMessages(['content_document' => $errors]);
                }

                $html = $this->renderer->render($campaign->store, $document);
                $campaign->content_document = $document;
                $campaign->html_body = $html;
                $campaign->content_format = CampaignContentFormat::Html;
                $campaign->email_template_id = null;
                $campaign->body = $this->plainTextFallback($html);
            }
        }

        if (array_key_exists('audience_type', $data)) {
            $audienceType = $data['audience_type'] instanceof CampaignAudienceType
                ? $data['audience_type']
                : CampaignAudienceType::from($data['audience_type']);
            $campaign->audience_type = $audienceType;

            if ($audienceType === CampaignAudienceType::AllEligible) {
                $campaign->segment_id = null;
            } elseif (array_key_exists('segment_id', $data)) {
                $campaign->segment_id = $data['segment_id'];
            }
        } elseif (array_key_exists('segment_id', $data)
            && $campaign->audience_type === CampaignAudienceType::Segment) {
            $campaign->segment_id = $data['segment_id'];
        }

        if ($campaign->channel === CampaignChannel::Sms) {
            $campaign->subject = null;
            $campaign->preview_text = null;
        } else {
            if (array_key_exists('subject', $data)) {
                $campaign->subject = $data['subject'];
            }
            if (array_key_exists('preview_text', $data)) {
                $campaign->preview_text = $data['preview_text'];
            }
        }

        $campaign->save();

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
