<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

class RegisterUser
{
    public function __construct(private readonly CreateWorkspace $createWorkspace) {}

    /**
     * @param  array{name: string, email: string, password: string, workspace_name?: string|null}  $data
     */
    public function handle(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $this->createWorkspace->handle(
                $user,
                $data['workspace_name'] ?? $user->name."'s Workspace",
            );

            return $user;
        });

        // Sends the verification email. Fired after commit on purpose.
        event(new Registered($user));

        return $user;
    }
}
