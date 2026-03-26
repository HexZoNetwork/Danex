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
    private const CONTAINER_HOME = '/home/container';

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

        $segments = explode('/', $normalized);
        $resolved = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($resolved !== []) {
                    array_pop($resolved);
                }
                continue;
            }
            $resolved[] = $segment;
        }

        $rebuilt = '/' . implode('/', $resolved);

        return $rebuilt === '' ? '/' : $rebuilt;
    }

    private function toDaemonPath(?string $path): string
    {
        $normalized = $this->normalizePath($path);
        if ($normalized === self::CONTAINER_HOME) {
            return '/';
        }
        if (str_starts_with($normalized, self::CONTAINER_HOME . '/')) {
            $stripped = substr($normalized, strlen(self::CONTAINER_HOME));
            return $stripped === '' ? '/' : $stripped;
        }

        return $normalized;
    }

    private function isHostRestrictedPath(string $normalized): bool
    {
        return preg_match('#^/(?:var/lib/pterodactyl|etc|proc|sys|root|dev)(?:/|$)#i', $normalized) === 1;
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
        $normalized = $this->normalizePath($path);
        if ($this->isHostRestrictedPath($normalized)) {
            throw new HttpForbiddenException('Akses dibatasi hanya di /home/container.');
        }
        if ($this->touchesQuarantine($normalized)) {
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
        $directory = $this->toDaemonPath($request->get('directory') ?? '/');

        $contents = $this->fileRepository
            ->setServer($server)
            ->getDirectory($directory);

        return $this->fractal->collection($contents)
            ->transformWith($this->getTransformer(FileObjectTransformer::class))
            ->toArray();
    }

    public function contents(GetFileContentsRequest $request, Server $server): Response
    {
        $this->assertAllowedPath($request->get('file'));
        $file = $this->toDaemonPath($request->get('file'));

        $response = $this->fileRepository->setServer($server)->getContent(
            $file,
            config('pterodactyl.files.max_edit_size')
        );

        Activity::event('server:file.read')->property('file', $file)->log();

        return new Response($response, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    public function download(GetFileContentsRequest $request, Server $server): array
    {
        $this->assertAllowedPath($request->get('file'));
        $file = $this->toDaemonPath($request->get('file'));

        $token = $this->jwtService
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
            ->setUser($request->user())
            ->setClaims([
                'file_path' => rawurldecode($file),
                'server_uuid' => $server->uuid,
            ])
            ->handle($server->node, $request->user()->id . $server->uuid);

        Activity::event('server:file.download')->property('file', $file)->log();

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
        $file = $this->toDaemonPath($request->get('file'));

        $this->fileRepository->setServer($server)->putContent($file, $request->getContent());

        Activity::event('server:file.write')->property('file', $file)->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function create(CreateFolderRequest $request, Server $server): JsonResponse
    {
        $this->assertAllowedPath($request->input('root', '/'));
        $this->assertAllowedPath($this->joinPath($request->input('root', '/'), $request->input('name')));
        $root = $this->toDaemonPath($request->input('root', '/'));

        $this->fileRepository
            ->setServer($server)
            ->createDirectory($request->input('name'), $root);

        Activity::event('server:file.create-directory')
            ->property('name', $request->input('name'))
            ->property('directory', $root)
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function rename(RenameFileRequest $request, Server $server): JsonResponse
    {
        $this->assertAllowedFiles($request->input('root'), $request->input('files'));
        $root = $this->toDaemonPath($request->input('root'));

        $this->fileRepository
            ->setServer($server)
            ->renameFiles($root, $request->input('files'));

        Activity::event('server:file.rename')
            ->property('directory', $root)
            ->property('files', $request->input('files'))
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function copy(CopyFileRequest $request, Server $server): JsonResponse
    {
        $this->assertAllowedPath($request->input('location'));
        $location = $this->toDaemonPath($request->input('location'));

        $this->fileRepository
            ->setServer($server)
            ->copyFile($location);

        Activity::event('server:file.copy')->property('file', $location)->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function compress(CompressFilesRequest $request, Server $server): array
    {
        $this->assertAllowedFiles($request->input('root'), $request->input('files'));
        $root = $this->toDaemonPath($request->input('root'));

        $file = $this->fileRepository->setServer($server)->compressFiles(
            $root,
            $request->input('files')
        );

        Activity::event('server:file.compress')
            ->property('directory', $root)
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
        $root = $this->toDaemonPath($request->input('root'));
        $filePath = ltrim($this->toDaemonPath($request->input('file')), '/');

        $this->fileRepository->setServer($server)->decompressFile(
            $root,
            $filePath
        );

        Activity::event('server:file.decompress')
            ->property('directory', $root)
            ->property('files', $filePath)
            ->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    public function delete(DeleteFileRequest $request, Server $server): JsonResponse
    {
        $this->assertAllowedFiles($request->input('root'), $request->input('files'));
        $root = $this->toDaemonPath($request->input('root'));

        $this->fileRepository->setServer($server)->deleteFiles(
            $root,
            $request->input('files')
        );

        Activity::event('server:file.delete')
            ->property('directory', $root)
            ->property('files', $request->input('files'))
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function chmod(ChmodFilesRequest $request, Server $server): JsonResponse
    {
        $this->assertAllowedFiles($request->input('root'), $request->input('files'));
        $root = $this->toDaemonPath($request->input('root'));

        $this->fileRepository->setServer($server)->chmodFiles(
            $root,
            $request->input('files')
        );

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    public function pull(PullFileRequest $request, Server $server): JsonResponse
    {
        $this->assertAllowedPath($request->input('directory'));
        $this->assertAllowedPath($this->joinPath($request->input('directory'), $request->input('filename')));
        $directory = $this->toDaemonPath($request->input('directory'));
        $filename = ltrim($this->toDaemonPath($request->input('filename')), '/');

        $this->fileRepository->setServer($server)->pull(
            $request->input('url'),
            $directory,
            array_merge($request->safe(['use_header', 'foreground']), ['filename' => $filename])
        );

        Activity::event('server:file.pull')
            ->property('directory', $directory)
            ->property('url', $request->input('url'))
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }
}
