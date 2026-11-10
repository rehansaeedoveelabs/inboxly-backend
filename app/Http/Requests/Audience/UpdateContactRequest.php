<?php

namespace App\Http\Requests\Audience;

use App\Models\Contact;
use App\Models\Store;
use Illuminate\Validation\Validator;

class UpdateContactRequest extends ContactRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('contact'));
    }

    protected function store(): Store
    {
        return $this->route('contact')->store;
    }

    protected function ignoredContact(): ?Contact
    {
        return $this->route('contact');
    }

    public function rules(): array
    {
        return $this->contactRules(partial: true);
    }

    /** A contact must keep at least one way to reach it. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $contact = $this->route('contact');
            $email = $this->has('email') ? $this->input('email') : $contact->email;
            $phone = $this->has('phone') ? $this->input('phone') : $contact->phone;

            if (blank($email) && blank($phone)) {
                $validator->errors()->add('email', 'A contact needs an email or a phone number.');
            }
        }];
    }
}
