<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if (is_string($this->email)) {
            $merge['email'] = mb_strtolower(trim($this->email));
        }
        if (is_string($this->phone)) {
            $merge['phone'] = trim($this->phone);
        }
        if (is_string($this->shop_name)) {
            $merge['shop_name'] = trim($this->shop_name);
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:32'],
            'shop_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'workspace_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
