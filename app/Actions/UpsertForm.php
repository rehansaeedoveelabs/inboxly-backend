<?php

namespace App\Actions;

use App\Enums\FormStatus;
use App\Enums\FormType;
use App\Models\Form;
use App\Models\Store;
use App\Models\User;
use App\Support\Forms\FormDefinitionSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpsertForm
{
    /**
     * @param  array{
     *     name: string,
     *     type?: string|FormType,
     *     status?: string|FormStatus,
     *     definition?: array<string, mixed>
     * }  $data
     */
    public function create(Store $store, User $user, array $data): Form
    {
        $definition = $this->validatedDefinition(
            $data['definition'] ?? FormDefinitionSchema::defaultDefinition()
        );

        return $store->forms()->create([
            'created_by' => $user->getKey(),
            'public_token' => Str::random(40),
            'name' => $data['name'],
            'type' => $this->resolveType($data['type'] ?? FormType::Popup),
            'status' => $this->resolveStatus($data['status'] ?? FormStatus::Draft),
            'definition' => $definition,
            'submissions_count' => 0,
        ]);
    }

    /**
     * @param  array{
     *     name?: string,
     *     type?: string|FormType,
     *     status?: string|FormStatus,
     *     definition?: array<string, mixed>
     * }  $data
     */
    public function update(Form $form, array $data): Form
    {
        if (array_key_exists('name', $data)) {
            $form->name = $data['name'];
        }
        if (array_key_exists('type', $data)) {
            $form->type = $this->resolveType($data['type']);
        }
        if (array_key_exists('status', $data)) {
            $form->status = $this->resolveStatus($data['status']);
        }
        if (array_key_exists('definition', $data)) {
            $form->definition = $this->validatedDefinition($data['definition']);
        }
        if (! is_string($form->public_token) || $form->public_token === '') {
            $form->public_token = Str::random(40);
        }

        $form->save();

        return $form->refresh();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validatedDefinition(array $definition): array
    {
        $errors = FormDefinitionSchema::validate($definition);
        if ($errors !== []) {
            throw ValidationException::withMessages(['definition' => $errors]);
        }

        return $definition;
    }

    private function resolveType(string|FormType $type): FormType
    {
        return $type instanceof FormType ? $type : FormType::from($type);
    }

    private function resolveStatus(string|FormStatus $status): FormStatus
    {
        return $status instanceof FormStatus ? $status : FormStatus::from($status);
    }
}
