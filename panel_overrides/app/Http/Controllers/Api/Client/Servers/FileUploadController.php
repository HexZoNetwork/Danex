<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Pterodactyl\Enum\JwtScope;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Pterodactyl\Services\Nodes\NodeJWTService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\UploadFileRequest;

class FileUploadController extends ClientApiController
{
    /**
     * FileUploadController constructor.
     */
    public function __construct(
        private NodeJWTService $jwtService,
    ) {
        parent::__construct();
    }

    /**
     * Returns an url where files can be uploaded to.
     */
    public function __invoke(UploadFileRequest $request, Server $server): JsonResponse
    {
        return new JsonResponse([
            'object' => 'signed_url',
            'attributes' => [
                'url' => $this->getUploadUrl($server, $request->user()),
            ],
        ]);
    }

    public function store(UploadFileRequest $request, Server $server): JsonResponse
    {
        $files = $request->file('files');
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        if (!is_array($files) || $files === []) {
            return new JsonResponse(['errors' => [['code' => 'NoFiles', 'status' => '422', 'detail' => 'No files were uploaded.']]], 422);
        }

        $multipart = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                return new JsonResponse(['errors' => [['code' => 'InvalidUpload', 'status' => '422', 'detail' => 'One uploaded file is invalid.']]], 422);
            }

            $stream = @fopen($file->getRealPath(), 'rb');
            if ($stream === false) {
                return new JsonResponse(['errors' => [['code' => 'UnreadableUpload', 'status' => '422', 'detail' => 'Uploaded file could not be read.']]], 422);
            }

            $multipart[] = [
                'name' => 'files',
                'contents' => $stream,
                'filename' => $file->getClientOriginalName(),
            ];
        }

        $directory = (string) ($request->query('directory') ?? $request->input('directory') ?? '/');
        $uploadUrl = $this->getUploadUrl($server, $request->user());
        $uploadUrl .= (str_contains($uploadUrl, '?') ? '&' : '?') . http_build_query(['directory' => $directory]);

        $response = (new Client([
            'http_errors' => false,
            'timeout' => 0,
            'connect_timeout' => 10,
            'verify' => false,
        ]))->post($uploadUrl, ['multipart' => $multipart]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return new JsonResponse([
                'errors' => [[
                    'code' => 'WingsUploadFailed',
                    'status' => (string) $status,
                    'detail' => 'Wings rejected the proxied upload.',
                ]],
            ], $status >= 400 && $status < 600 ? $status : 502);
        }

        return new JsonResponse(['object' => 'upload', 'attributes' => ['proxied' => true]], 201);
    }

    /**
     * Returns an url where files can be uploaded to.
     */
    protected function getUploadUrl(Server $server, User $user): string
    {
        $token = $this->jwtService
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
            ->setUser($user)
            ->setScopes(JwtScope::FileUpload)
            ->setClaims(['server_uuid' => $server->uuid])
            ->handle($server->node, $user->id . $server->uuid);

        return sprintf(
            '%s/upload/file?token=%s',
            $server->node->getConnectionAddress(),
            $token->toString()
        );
    }
}
