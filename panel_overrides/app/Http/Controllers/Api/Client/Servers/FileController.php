<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Exceptions\Http\HttpForbiddenException;
use Pterodactyl\Services\Nodes\NodeJWTService;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Transformers\Api\Client\FileObjectTransformer;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\CopyFileRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\PullFileRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\ListFilesRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\ChmodFilesRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\DeleteFileRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\RenameFileRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\CreateFolderRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\CompressFilesRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\DecompressFilesRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\GetFileContentsRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\WriteFileContentRequest;

class FileController extends ClientApiController
{
    private const QUARANTINE_SEGMENT = '.dann_quarantine';

    public function __construct(
        private NodeJWTService $jwtService,
        private DaemonFileRepository $fileRepository,
    ) {
        parent::__construct();
    }

    private function normalizePath(?string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path ?? ''));
        if ($normalized === '') {
            return '/';
        }

        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        if (!str_starts_with($normalized, '/')) {
            $normalized = '/' . $normalized;
        }

        if (strlen($normalized) > 1) {
            $normalized = rtrim($normalized, '/');
        }

        return $normalized;
    }

    private function joinPath(?string $root, ?string $child = null): string
    {
        $base = $this->normalizePath($root);
        $leaf = trim(str_replace('\\', '/', $child ?? ''));
        if ($leaf === '') {
            return $base;
        }

        if (str_starts_with($leaf, '/')) {
            return $this->normalizePath($leaf);
        }

        return $this->normalizePath(($base === '/' ? '' : $base) . '/' . $leaf);
    }

    private function touchesQuarantine(?string $path): bool
    {
        return preg_match('#(^|/)' . preg_quote(self::QUARANTINE_SEGMENT, '#') . '($|/)#', $this->normalizePath($path)) === 1;
    }

    private function assertAllowedPath(?string $path): void
    {
        if ($this->touchesQuarantine($path)) {
            throw new HttpForbiddenException('Protected quarantine files cannot be managed from the panel.');
        }
    }

    private function assertAllowedFiles(?string $root, array $files): void
    {
        $this->assertAllowedPath($root);

        foreach ($files as $file) {
            if (is_string($file)) {
                $this->assertAllowedPath($this->joinPath($root, $file));
                continue;
            }

            if (!is_array($file)) {
                continue;
            }

            foreach (['from', 'to', 'name', 'file', 'path'] as $key) {
                if (array_key_exists($key, $file) && is_string($file[$key])) {
                    $candidate = in_array($key, ['from', 'name', 'file'], true)
                        ? $this->joinPath($root, $file[$key])
                        : $file[$key];
                    $this->assertAllowedPath($candidate);
                }
            }
        }
    }

    public function directory(ListFilesRequest $request, Server $server): array
    {
        $this->assertAllowedPath($request->get('directory') ?? '/');

        $contents = $this->fileRepository
            ->setServer($server)
            ->getDirectory($request->get('directory') ?? '/');

        return $this->fractal->collection($contents)
            ->transformWith($this->getTransformer(FileObjectTransformer::class))
            ->toArray();
    }

    public function contents(GetFileContentsRequest $request, Server $server): Response
    {
        $this->assertAllowedPath($request->get('file'));

        $response = $this->fileRepository->setServer($server)->getContent(
            $request->get('file'),
            config('pterodactyl.files.max_edit_size')
        );

        Activity::event('server:file.read')->property('file', $request->get('file'))->log();

        return new Response($response, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    public function download(GetFileContentsRequest $request, Server $server): array
    {
        $this->assertAllowedPath($request->get('file'));

        $token = $this->jwtService
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
            ->setUser($request->user())
            ->setClaims([
                'file_path' => rawurldecode($request->get('file')),
                'server_uuid' => $server->uuid,
            ])
            ->handle($server->node, $request->user()->id . $server->uuid);

        Activity::event('server:file.download')->property('file', $request->get('file'))->log();

        return [
            'object' => 'signed_url',
            'attributes' => [
                'url' => sprintf(
                    '%s/download/file?token=%s',
                    $server->node->getConnectionAddress(),
                    $token->toString()
                ),
            ],
        ];
    }

    public function write(WriteFileContentRequest $request, Server $server): JsonResponse
    {
        $this->assertAllowedPath($request->get('file'));

        $this->fileRepository->setServer($server)->putContent($request->get('file'), $request->getContent());

        Activity::event('server:file.write')->property('file', $request->get('file'))->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function create(CreateFolderRequest $request, Server $server): JsonResponse
    {
        $this->assertAllowedPath($request->input('root', '/'));
        $this->assertAllowedPath($this->joinPath($request->input('root', '/'), $request->input('name')));

        $this->fileRepository
            ->setServer($server)
            ->createDirectory($request->input('name'), $request->input('root', '/'));

        Activity::event('server:file.create-directory')
            ->property('name', $request->input('name'))
            ->property('directory', $request->input('root'))
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function rename(RenameFileRequest $request, Server $server): JsonResponse
    {
        $this->assertAllowedFiles($request->input('root'), $request->input('files'));

        $this->fileRepository
            ->setServer($server)
            ->renameFiles($request->input('root'), $request->input('files'));

        Activity::event('server:file.rename')
            ->property('directory', $request->input('root'))
            ->property('files', $request->input('files'))
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function copy(CopyFileRequest $request, Server $server): JsonResponse
    {
        $this->assertAllowedPath($request->input('location'));

        $this->fileRepository
            ->setServer($server)
            ->copyFile($request->input('location'));

        Activity::event('server:file.copy')->property('file', $request->input('location'))->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function compress(CompressFilesRequest $request, Server $server): array
    {
        $this->assertAllowedFiles($request->input('root'), $request->input('files'));

        $file = $this->fileRepository->setServer($server)->compressFiles(
            $request->input('root'),
            $request->input('files')
        );

        Activity::event('server:file.compress')
            ->property('directory', $request->input('root'))
            ->property('files', $request->input('files'))
            ->log();

        return $this->fractal->item($file)
            ->transformWith($this->getTransformer(FileObjectTransformer::class))
            ->toArray();
    }

    public function decompress(DecompressFilesRequest $request, Server $server): JsonResponse
    {
        set_time_limit(300);
        $this->assertAllowedPath($this->joinPath($request->input('root'), $request->input('file')));

        $this->fileRepository->setServer($server)->decompressFile(
            $request->input('root'),
            $request->input('file')
        );

        Activity::event('server:file.decompress')
            ->property('directory', $request->input('root'))
            ->property('files', $request->input('file'))
            ->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    public function delete(DeleteFileRequest $request, Server $server): JsonResponse
    {
        $this->assertAllowedFiles($request->input('root'), $request->input('files'));

        $this->fileRepository->setServer($server)->deleteFiles(
            $request->input('root'),
            $request->input('files')
        );

        Activity::event('server:file.delete')
            ->property('directory', $request->input('root'))
            ->property('files', $request->input('files'))
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function chmod(ChmodFilesRequest $request, Server $server): JsonResponse
    {
        $this->assertAllowedFiles($request->input('root'), $request->input('files'));

        $this->fileRepository->setServer($server)->chmodFiles(
            $request->input('root'),
            $request->input('files')
        );

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function pull(PullFileRequest $request, Server $server): JsonResponse
    {
        $this->assertAllowedPath($request->input('directory'));
        $this->assertAllowedPath($this->joinPath($request->input('directory'), $request->input('filename')));

        $this->fileRepository->setServer($server)->pull(
            $request->input('url'),
            $request->input('directory'),
            $request->safe(['filename', 'use_header', 'foreground'])
        );

        Activity::event('server:file.pull')
            ->property('directory', $request->input('directory'))
            ->property('url', $request->input('url'))
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }
}
