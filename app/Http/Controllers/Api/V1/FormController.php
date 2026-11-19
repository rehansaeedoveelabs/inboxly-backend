<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\UpsertForm;
use App\Enums\FormStatus;
use App\Enums\FormType;
use App\Http\Controllers\Controller;
use App\Http\Resources\FormResource;
use App\Models\Form;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class FormController extends Controller
{
    private const DEFAULT_LIMIT = 25;

    public function index(Request $request, Store $store): AnonymousResourceCollection
    {
        $this->authorize('manageAudience', $store);

        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', Rule::enum(FormType::class)],
            'status' => ['sometimes', 'nullable', Rule::enum(FormStatus::class)],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $store->forms()->orderByDesc('updated_at')->orderByDesc('id');

        if (! empty($data['q'])) {
            $query->where('name', 'like', '%'.$data['q'].'%');
        }
        if (! empty($data['type'])) {
            $type = $data['type'] instanceof FormType ? $data['type'] : FormType::from($data['type']);
            $query->where('type', $type);
        }
        if (! empty($data['status'])) {
            $status = $data['status'] instanceof FormStatus
                ? $data['status']
                : FormStatus::from($data['status']);
            $query->where('status', $status);
        }

        $forms = $query
            ->cursorPaginate((int) ($data['limit'] ?? self::DEFAULT_LIMIT))
            ->withQueryString();

        return FormResource::collection($forms);
    }

    public function summary(Store $store): JsonResponse
    {
        $this->authorize('manageAudience', $store);

        $base = $store->forms();

        return response()->json([
            'data' => [
                'total' => (clone $base)->count(),
                'enabled' => (clone $base)->where('status', FormStatus::Enabled)->count(),
                'draft' => (clone $base)->where('status', FormStatus::Draft)->count(),
            ],
        ]);
    }

    public function store(Request $request, Store $store, UpsertForm $upsert): JsonResponse
    {
        $this->authorize('manageAudience', $store);
        $data = $this->validatedWrite($request, creating: true);
        $form = $upsert->create($store, $request->user(), $data);

        return (new FormResource($form))->response()->setStatusCode(201);
    }

    public function show(Form $form): FormResource
    {
        $this->authorize('view', $form);

        return new FormResource($form);
    }

    public function update(Request $request, Form $form, UpsertForm $upsert): FormResource
    {
        $this->authorize('update', $form);
        $data = $this->validatedWrite($request, creating: false);

        return new FormResource($upsert->update($form, $data));
    }

    public function destroy(Form $form): Response
    {
        $this->authorize('delete', $form);
        $form->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedWrite(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'type' => [$creating ? 'sometimes' : 'sometimes', 'nullable', Rule::enum(FormType::class)],
            'status' => ['sometimes', 'nullable', Rule::enum(FormStatus::class)],
            'definition' => [$creating ? 'sometimes' : 'sometimes', 'nullable', 'array'],
        ]);
    }
}
