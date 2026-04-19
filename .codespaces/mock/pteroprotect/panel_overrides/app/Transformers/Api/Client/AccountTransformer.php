<?php

namespace Pterodactyl\Transformers\Api\Client;

use Illuminate\Support\Str;
use Pterodactyl\Models\User;

class AccountTransformer extends BaseClientTransformer
{
    /**
     * Return the resource name for the JSONAPI output.
     */
    public function getResourceName(): string
    {
        return 'user';
    }

    /**
     * Return basic information about the currently logged-in user.
     */
    public function transform(User $model): array
    {
        $birthday = $model->birthday;
        if ($birthday instanceof \DateTimeInterface) {
            $birthday = $birthday->format('Y-m-d');
        } elseif (empty($birthday) && $model->created_at !== null) {
            $birthday = $model->created_at->toDateString();
        }

        $avatar = !empty($model->avatar_url)
            ? (string) $model->avatar_url
            : ('https://gravatar.com/avatar/' . md5(Str::lower($model->email)));

        return [
            'id' => $model->id,
            'admin' => $model->root_admin,
            'username' => $model->username,
            'email' => $model->email,
            'first_name' => $model->name_first,
            'last_name' => $model->name_last,
            'language' => $model->language,
            'avatar_url' => $avatar,
            'birthday' => $birthday,
        ];
    }
}
