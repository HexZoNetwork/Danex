<?php

namespace Pterodactyl\Http\Requests\Api\Client\Account;

use Illuminate\Support\Arr;
use Pterodactyl\Models\User;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class UpdateProfileRequest extends ClientApiRequest
{
    protected function prepareForValidation(): void
    {
        if (!$this->has('avatar_url')) {
            return;
        }

        $avatar = trim((string) $this->input('avatar_url', ''));
        if ($avatar === '') {
            return;
        }

        if (str_starts_with($avatar, '//')) {
            $avatar = 'https:' . $avatar;
        } elseif (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $avatar)
            && preg_match('#^[A-Za-z0-9.-]+\.[A-Za-z]{2,}(/|$)#', $avatar)
        ) {
            $avatar = 'https://' . $avatar;
        }

        $this->merge(['avatar_url' => $avatar]);
    }

    public function authorize(): bool
    {
        return parent::authorize();
    }

    public function rules(): array
    {
        $rules = User::getRulesForUpdate($this->user());

        return [
            'username' => ['sometimes', ...Arr::wrap($rules['username'])],
            'email' => ['sometimes', ...Arr::wrap($rules['email'])],
            'name_first' => ['sometimes', ...Arr::wrap($rules['name_first'])],
            'name_last' => ['sometimes', ...Arr::wrap($rules['name_last'])],
            'avatar_url' => 'sometimes|nullable|url|max:2048',
            'birthday' => 'sometimes|nullable|date_format:Y-m-d|before_or_equal:today',
        ];
    }
}
