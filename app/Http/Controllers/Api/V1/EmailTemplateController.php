<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\DuplicateEmailTemplate;
use App\Actions\UpsertEmailTemplate;
use App\Enums\EmailTemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;
use App\Models\Store;
use App\Support\EmailTemplates\EmailBlockSchema;
use App\Support\EmailTemplates\EmailDocumentRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class EmailTemplateController extends Controller
{
    private const DEFAULT_LIMIT = 25;

    public function index(Request $request, Store $store): AnonymousResourceCollection
    {
        $this->authorize('manageAudience', $store);

        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'nullable', Rule::enum(EmailTemplateStatus::class)],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $store->emailTemplates()->orderByDesc('updated_at')->orderByDesc('id');

        if (! empty($data['q'])) {
            $query->where('name', 'like', '%'.$data['q'].'%');
        }
        if (! empty($data['category'])) {
            $query->where('category', $data['category']);
        }
        if (! empty($data['status'])) {
            $status = $data['status'] instanceof EmailTemplateStatus
                ? $data['status']
                : EmailTemplateStatus::from($data['status']);
            $query->where('status', $status);
        }

        $templates = $query
            ->cursorPaginate((int) ($data['limit'] ?? self::DEFAULT_LIMIT))
            ->withQueryString();

        return EmailTemplateResource::collection($templates);
    }

    public function store(Request $request, Store $store, UpsertEmailTemplate $upsert): JsonResponse
    {
        $data = $this->validatedWrite($request, creating: true);
        $this->authorize('manageAudience', $store);

        $template = $upsert->create($store, $request->user(), $data);

        return (new EmailTemplateResource($template))->response()->setStatusCode(201);
    }

    public function show(EmailTemplate $emailTemplate): EmailTemplateResource
    {
        $this->authorize('view', $emailTemplate);

        return new EmailTemplateResource($emailTemplate);
    }

    public function update(
        Request $request,
        EmailTemplate $emailTemplate,
        UpsertEmailTemplate $upsert,
    ): EmailTemplateResource {
        $this->authorize('update', $emailTemplate);
        $data = $this->validatedWrite($request, creating: false);

        return new EmailTemplateResource($upsert->update($emailTemplate, $data));
    }

    public function destroy(EmailTemplate $emailTemplate): Response
    {
        $this->authorize('delete', $emailTemplate);
        $emailTemplate->delete();

        return response()->noContent();
    }

    public function duplicate(
        EmailTemplate $emailTemplate,
        DuplicateEmailTemplate $duplicate,
    ): JsonResponse {
        $this->authorize('duplicate', $emailTemplate);
        $copy = $duplicate->handle($emailTemplate, request()->user());

        return (new EmailTemplateResource($copy))->response()->setStatusCode(201);
    }

    public function preview(Request $request, Store $store): JsonResponse
    {
        $this->authorize('manageAudience', $store);

        $data = $request->validate([
            'document' => ['required', 'array'],
        ]);

        $errors = EmailBlockSchema::validate($data['document']);
        if ($errors !== []) {
            return response()->json([
                'message' => 'The document is invalid.',
                'errors' => ['document' => $errors],
            ], 422);
        }

        $html = app(EmailDocumentRenderer::class)
            ->render($store, $data['document']);

        return response()->json(['html' => $html]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedWrite(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', Rule::enum(EmailTemplateStatus::class)],
            'document' => [$creating ? 'required' : 'sometimes', 'required', 'array'],
        ]);
    }
}
