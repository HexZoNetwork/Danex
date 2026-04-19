<?php

namespace Pterodactyl\Http\Requests\Api\Client\Account;

use Illuminate\Support\Arr;
use Pterodactyl\Models\User;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class UpdateProfileRequest extends ClientApiRequest
{
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
