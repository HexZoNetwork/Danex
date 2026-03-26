<?php

namespace Pterodactyl\Repositories\Wings;

use Illuminate\Support\Arr;
use Webmozart\Assert\Assert;
use Pterodactyl\Models\Server;
use Pterodactyl\Exceptions\Http\HttpForbiddenException;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\TransferException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * @method \Pterodactyl\Repositories\Wings\DaemonFileRepository setNode(\Pterodactyl\Models\Node $node)
 * @method \Pterodactyl\Repositories\Wings\DaemonFileRepository setServer(\Pterodactyl\Models\Server $server)
 */
class DaemonFileRepository extends DaemonRepository
{
    private const QUARANTINE_SEGMENT = '.dann_quarantine';

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
        if ($rebuilt !== '/' && str_ends_with($normalized, '/')) {
            $rebuilt = rtrim($rebuilt, '/');
        }

        return $rebuilt === '' ? '/' : $rebuilt;
    }

    private function isProtectedPath(?string $path): bool
    {
        return preg_match('#(^|/)' . preg_quote(self::QUARANTINE_SEGMENT, '#') . '($|/)#', $this->normalizePath($path)) === 1;
    }

    private function assertAllowedPath(?string $path): void
    {
        if ($this->isProtectedPath($path)) {
            throw new HttpForbiddenException('Protected quarantine files cannot be accessed from the panel.');
        }
    }

    /**
     * Return the contents of a given file.
     *
     * @param int|null $notLargerThan the maximum content length in bytes
     *
     * @throws TransferException
     * @throws FileSizeTooLargeException
     * @throws DaemonConnectionException
     */
    public function getContent(string $path, ?int $notLargerThan = null): string
    {
        Assert::isInstanceOf($this->server, Server::class);
        $path = $this->normalizePath($path);
        $this->assertAllowedPath($path);

        try {
            $response = $this->getHttpClient()->get(
                sprintf('/api/servers/%s/files/contents', $this->server->uuid),
                [
                    'query' => ['file' => $path],
                ]
            );
        } catch (ClientException|TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }

        $length = (int) Arr::get($response->getHeader('Content-Length'), 0, 0);
        if ($notLargerThan && $length > $notLargerThan) {
            throw new FileSizeTooLargeException();
        }

        return $response->getBody()->__toString();
    }

    /**
     * Save new contents to a given file. This works for both creating and updating
     * a file.
     *
     * @throws DaemonConnectionException
     */
    public function putContent(string $path, string $content): ResponseInterface
    {
        Assert::isInstanceOf($this->server, Server::class);
        $path = $this->normalizePath($path);
        $this->assertAllowedPath($path);

        try {
            return $this->getHttpClient()->post(
                sprintf('/api/servers/%s/files/write', $this->server->uuid),
                [
                    'query' => ['file' => $path],
                    'body' => $content,
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }

    /**
     * Return a directory listing for a given path.
     *
     * @throws DaemonConnectionException
     */
    public function getDirectory(string $path): array
    {
        Assert::isInstanceOf($this->server, Server::class);
        $path = $this->normalizePath($path);
        $this->assertAllowedPath($path);

        try {
            $response = $this->getHttpClient()->get(
                sprintf('/api/servers/%s/files/list-directory', $this->server->uuid),
                [
                    'query' => ['directory' => $path],
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }

        $listing = json_decode($response->getBody(), true);

        return array_values(array_filter($listing, function (array $entry): bool {
            return !$this->isProtectedPath($entry['path'] ?? $entry['name'] ?? null);
        }));
    }

    /**
     * Creates a new directory for the server in the given $path.
     *
     * @throws DaemonConnectionException
     */
    public function createDirectory(string $name, string $path): ResponseInterface
    {
        Assert::isInstanceOf($this->server, Server::class);
        $path = $this->normalizePath($path);
        $this->assertAllowedPath($path);
        $this->assertAllowedPath($name);

        try {
            return $this->getHttpClient()->post(
                sprintf('/api/servers/%s/files/create-directory', $this->server->uuid),
                [
                    'json' => [
                        'name' => $name,
                        'path' => $path,
                    ],
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }

    /**
     * Renames or moves a file on the remote machine.
     *
     * @throws DaemonConnectionException
     */
    public function renameFiles(?string $root, array $files): ResponseInterface
    {
        Assert::isInstanceOf($this->server, Server::class);
        $root = $this->normalizePath($root);
        $this->assertAllowedPath($root);

        try {
            return $this->getHttpClient()->put(
                sprintf('/api/servers/%s/files/rename', $this->server->uuid),
                [
                    'json' => [
                        'root' => $root ?? '/',
                        'files' => $files,
                    ],
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }

    /**
     * Copy a given file and give it a unique name.
     *
     * @throws DaemonConnectionException
     */
    public function copyFile(string $location): ResponseInterface
    {
        Assert::isInstanceOf($this->server, Server::class);
        $location = $this->normalizePath($location);
        $this->assertAllowedPath($location);

        try {
            return $this->getHttpClient()->post(
                sprintf('/api/servers/%s/files/copy', $this->server->uuid),
                [
                    'json' => [
                        'location' => $location,
                    ],
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }

    /**
     * Delete a file or folder for the server.
     *
     * @throws DaemonConnectionException
     */
    public function deleteFiles(?string $root, array $files): ResponseInterface
    {
        Assert::isInstanceOf($this->server, Server::class);
        $root = $this->normalizePath($root);
        $this->assertAllowedPath($root);

        try {
            return $this->getHttpClient()->post(
                sprintf('/api/servers/%s/files/delete', $this->server->uuid),
                [
                    'json' => [
                        'root' => $root ?? '/',
                        'files' => $files,
                    ],
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }

    /**
     * Compress the given files or folders in the given root.
     *
     * @throws DaemonConnectionException
     */
    public function compressFiles(?string $root, array $files): array
    {
        Assert::isInstanceOf($this->server, Server::class);
        $root = $this->normalizePath($root);
        $this->assertAllowedPath($root);

        try {
            $response = $this->getHttpClient()->post(
                sprintf('/api/servers/%s/files/compress', $this->server->uuid),
                [
                    'json' => [
                        'root' => $root ?? '/',
                        'files' => $files,
                    ],
                    'timeout' => 60 * 15,
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }

        return json_decode($response->getBody(), true);
    }

    /**
     * Decompresses a given archive file.
     *
     * @throws DaemonConnectionException
     */
    public function decompressFile(?string $root, string $file): ResponseInterface
    {
        Assert::isInstanceOf($this->server, Server::class);
        $root = $this->normalizePath($root);
        $file = $this->normalizePath($file);
        $this->assertAllowedPath($root);
        $this->assertAllowedPath($file);

        try {
            return $this->getHttpClient()->post(
                sprintf('/api/servers/%s/files/decompress', $this->server->uuid),
                [
                    'json' => [
                        'root' => $root ?? '/',
                        'file' => $file,
                    ],
                    'timeout' => 60 * 15,
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }

    /**
     * Chmods the given files.
     *
     * @throws DaemonConnectionException
     */
    public function chmodFiles(?string $root, array $files): ResponseInterface
    {
        Assert::isInstanceOf($this->server, Server::class);
        $root = $this->normalizePath($root);
        $this->assertAllowedPath($root);

        try {
            return $this->getHttpClient()->post(
                sprintf('/api/servers/%s/files/chmod', $this->server->uuid),
                [
                    'json' => [
                        'root' => $root ?? '/',
                        'files' => $files,
                    ],
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }

    /**
     * Pulls a file from the given URL and saves it to the disk.
     *
     * @throws DaemonConnectionException
     */
    public function pull(string $url, ?string $directory, array $params = []): ResponseInterface
    {
        Assert::isInstanceOf($this->server, Server::class);
        $directory = $this->normalizePath($directory);
        $this->assertAllowedPath($directory);
        if (array_key_exists('filename', $params) && is_string($params['filename'])) {
            $params['filename'] = ltrim($this->normalizePath($params['filename']), '/');
        }
        $this->assertAllowedPath($params['filename'] ?? null);

        $attributes = [
            'url' => $url,
            'root' => $directory ?? '/',
            'file_name' => $params['filename'] ?? null,
            'use_header' => $params['use_header'] ?? null,
            'foreground' => $params['foreground'] ?? null,
        ];

        try {
            return $this->getHttpClient()->post(
                sprintf('/api/servers/%s/files/pull', $this->server->uuid),
                [
                    'json' => array_filter($attributes, fn ($value) => !is_null($value)),
                ]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }
}
