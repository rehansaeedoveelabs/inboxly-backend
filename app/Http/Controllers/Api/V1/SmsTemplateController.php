<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\DuplicateSmsTemplate;
use App\Actions\UpsertSmsTemplate;
use App\Enums\SmsTemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SmsTemplateResource;
use App\Models\SmsTemplate;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class SmsTemplateController extends Controller
{
    private const DEFAULT_LIMIT = 25;

    public function index(Request $request, Store $store): AnonymousResourceCollection
    {
        $this->authorize('manageAudience', $store);

        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'nullable', Rule::enum(SmsTemplateStatus::class)],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $store->smsTemplates()->orderByDesc('updated_at')->orderByDesc('id');

        if (! empty($data['q'])) {
            $query->where(function ($q) use ($data) {
                $q->where('name', 'like', '%'.$data['q'].'%')
                    ->orWhere('body', 'like', '%'.$data['q'].'%');
            });
        }
        if (! empty($data['category'])) {
            $query->where('category', $data['category']);
        }
        if (! empty($data['status'])) {
            $status = $data['status'] instanceof SmsTemplateStatus
                ? $data['status']
                : SmsTemplateStatus::from($data['status']);
            $query->where('status', $status);
        }

        $templates = $query
            ->cursorPaginate((int) ($data['limit'] ?? self::DEFAULT_LIMIT))
            ->withQueryString();

        return SmsTemplateResource::collection($templates);
    }

    public function store(Request $request, Store $store, UpsertSmsTemplate $upsert): JsonResponse
    {
        $this->authorize('manageAudience', $store);
        $data = $this->validatedWrite($request, creating: true);
        $template = $upsert->create($store, $request->user(), $data);

        return (new SmsTemplateResource($template))->response()->setStatusCode(201);
    }

    public function show(SmsTemplate $smsTemplate): SmsTemplateResource
    {
        $this->authorize('view', $smsTemplate);

        return new SmsTemplateResource($smsTemplate);
    }

    public function update(
        Request $request,
        SmsTemplate $smsTemplate,
        UpsertSmsTemplate $upsert,
    ): SmsTemplateResource {
        $this->authorize('update', $smsTemplate);
        $data = $this->validatedWrite($request, creating: false);

        return new SmsTemplateResource($upsert->update($smsTemplate, $data));
    }

    public function destroy(SmsTemplate $smsTemplate): Response
    {
        $this->authorize('delete', $smsTemplate);
        $smsTemplate->delete();

        return response()->noContent();
    }

    public function duplicate(
        SmsTemplate $smsTemplate,
        DuplicateSmsTemplate $duplicate,
    ): JsonResponse {
        $this->authorize('duplicate', $smsTemplate);
        $copy = $duplicate->handle($smsTemplate, request()->user());

        return (new SmsTemplateResource($copy))->response()->setStatusCode(201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedWrite(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'nullable', Rule::enum(SmsTemplateStatus::class)],
            'body' => [$creating ? 'required' : 'sometimes', 'string', 'max:640'],
        ]);
    }
}
