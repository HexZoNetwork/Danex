<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\Users\UserUpdateService;
use Pterodactyl\Transformers\Api\Client\AccountTransformer;
use Pterodactyl\Http\Requests\Api\Client\Account\UpdateEmailRequest;
use Pterodactyl\Http\Requests\Api\Client\Account\UpdateProfileRequest;
use Pterodactyl\Http\Requests\Api\Client\Account\UpdatePasswordRequest;

class AccountController extends ClientApiController
{
    /**
     * AccountController constructor.
     */
    public function __construct(private AuthManager $manager, private UserUpdateService $updateService)
    {
        parent::__construct();
    }

    public function index(Request $request): array
    {
        return $this->fractal->item($request->user())
            ->transformWith($this->getTransformer(AccountTransformer::class))
            ->toArray();
    }

    /**
     * Update the authenticated user's email address.
     */
    public function updateEmail(UpdateEmailRequest $request): JsonResponse
    {
        $original = $request->user()->email;
        $this->updateService->handle($request->user(), $request->validated());

        if ($original !== $request->input('email')) {
            Activity::event('user:account.email-changed')
                ->property(['old' => $original, 'new' => $request->input('email')])
                ->log();
        }

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Update profile fields without forcing a session logout.
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $original = [
            'username' => $user->username,
            'email' => $user->email,
            'name_first' => $user->name_first,
            'name_last' => $user->name_last,
            'avatar_url' => $user->avatar_url,
            'birthday' => $user->birthday,
        ];

        $updated = $this->updateService->handle($user, $request->validated());

        Activity::event('user:account.profile-updated')
            ->property([
                'old' => $original,
                'new' => [
                    'username' => $updated->username,
                    'email' => $updated->email,
                    'name_first' => $updated->name_first,
                    'name_last' => $updated->name_last,
                    'avatar_url' => $updated->avatar_url,
                    'birthday' => $updated->birthday,
                ],
            ])
            ->log();

        return new JsonResponse(
            $this->fractal->item($updated)
                ->transformWith($this->getTransformer(AccountTransformer::class))
                ->toArray(),
            Response::HTTP_OK
        );
    }

    /**
     * Upload avatar image with strict sanitization to prevent payload abuse.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => [
                'required',
                'file',
                'max:5120',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif',
            ],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('avatar');
        $raw = (string) file_get_contents($file->getRealPath());
        if ($raw === '' || strlen($raw) > (5 * 1024 * 1024)) {
            return new JsonResponse(['error' => 'Invalid avatar payload.'], 422);
        }

        $imageInfo = @getimagesizefromstring($raw);
        $imageType = (int) ($imageInfo[2] ?? 0);
        if (!$imageInfo || !in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            return new JsonResponse(['error' => 'Unsupported image type.'], 422);
        }

        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return new JsonResponse(['error' => 'Image processing extension is required on server.'], 500);
        }

        $resource = @imagecreatefromstring($raw);
        if (!$resource) {
            return new JsonResponse(['error' => 'Failed to process image.'], 422);
        }

        $maxEdge = 1024;
        $width = imagesx($resource);
        $height = imagesy($resource);
        $ratio = min(1, $maxEdge / max(1, max($width, $height)));
        $targetWidth = max(1, (int) floor($width * $ratio));
        $targetHeight = max(1, (int) floor($height * $ratio));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 18, 27, 43));
        imagecopyresampled($canvas, $resource, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $tmpPath = tempnam(sys_get_temp_dir(), 'avatar_');
        imagejpeg($canvas, $tmpPath, 86);
        imagedestroy($canvas);
        imagedestroy($resource);

        $path = 'profile-avatars/' . (string) $request->user()->uuid . '/' . Str::random(40) . '.jpg';
        Storage::disk('public')->put($path, (string) file_get_contents($tmpPath), ['visibility' => 'public']);
        @unlink($tmpPath);

        $user = $request->user();
        $old = trim((string) ($user->avatar_url ?? ''));
        $newUrl = Storage::disk('public')->url($path);
        $user->forceFill(['avatar_url' => $newUrl])->saveOrFail();

        if ($old !== '' && preg_match('#/storage/(profile-avatars/.+)$#', $old, $matches) === 1) {
            Storage::disk('public')->delete($matches[1]);
        }

        Activity::event('user:account.avatar-uploaded')
            ->property(['path' => $path])
            ->log();

        return new JsonResponse([
            'data' => [
                'avatar_url' => $newUrl,
            ],
        ], 201);
    }

    /**
     * Update the authenticated user's password. All existing sessions will be logged
     * out immediately.
     *
     * @throws \Throwable
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = Activity::event('user:account.password-changed')->transaction(function () use ($request) {
            return $this->updateService->handle($request->user(), $request->validated());
        });

        $guard = $this->manager->guard();
        // If you do not update the user in the session you'll end up working with a
        // cached copy of the user that does not include the updated password. Do this
        // to correctly store the new user details in the guard and allow the logout
        // other devices functionality to work.
        $guard->setUser($user);

        // This method doesn't exist in the stateless Sanctum world.
        if (method_exists($guard, 'logoutOtherDevices')) { // @phpstan-ignore function.alreadyNarrowedType
            $guard->logoutOtherDevices($request->input('password'));
        }

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }
}
