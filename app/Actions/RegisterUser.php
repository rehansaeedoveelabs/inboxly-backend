<?php

namespace App\Actions;

use App\Actions\Auth\IssueEmailOtp;
use App\Models\Store;
use App\Models\User;
use App\Models\UserWorkspaceContext;
use Illuminate\Support\Facades\DB;

class RegisterUser
{
    public function __construct(
        private readonly CreateWorkspace $createWorkspace,
        private readonly CreateStore $createStore,
        private readonly IssueEmailOtp $issueEmailOtp,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     email: string,
     *     password: string,
     *     shop_name: string,
     *     phone?: string|null,
     *     workspace_name?: string|null
     * }  $data
     */
    public function handle(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);

            $shopName = trim((string) $data['shop_name']);
            $workspaceName = trim((string) ($data['workspace_name'] ?? '')) !== ''
                ? (string) $data['workspace_name']
                : $shopName;

            $workspace = $this->createWorkspace->handle($user, $workspaceName);

            /** @var Store $store */
            $store = $this->createStore->handle($workspace, [
                'name' => $shopName,
                'domain' => null,
            ]);

            UserWorkspaceContext::query()
                ->where('user_id', $user->getKey())
                ->where('workspace_id', $workspace->getKey())
                ->update(['active_store_id' => $store->getKey()]);

            return $user;
        });

        // Onboarding uses email OTP instead of the signed-link Registered mail.
        $this->issueEmailOtp->handle($user);

        return $user->fresh(['workspaces', 'workspaceContexts']) ?? $user;
    }
}
