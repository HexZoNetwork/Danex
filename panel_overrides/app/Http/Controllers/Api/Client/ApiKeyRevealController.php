<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Throwable;
use Pterodactyl\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ApiKeyRevealController extends ClientApiController
{
    public function __invoke(Request $request, string $identifier): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (!Hash::check($validated['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The provided password is invalid.']);
        }

        $key = $request->user()->apiKeys()
            ->where('identifier', $identifier)
            ->where('key_type', ApiKey::TYPE_ACCOUNT)
            ->first();

        if (!$key) {
            throw new NotFoundHttpException('The requested API key could not be found.');
        }

        try {
            $secret = decrypt($key->token);
        } catch (Throwable) {
            throw new UnprocessableEntityHttpException('This API key cannot be revealed. Revoke it and create a new key.');
        }

        return new JsonResponse([
            'object' => 'api_key_token',
            'attributes' => [
                'token' => $key->identifier . $secret,
            ],
        ]);
    }
}
