<?php

declare(strict_types=1);

namespace AchievementBattle;

use JsonException;
use RuntimeException;

final class CacheRepository
{
    public function __construct(private readonly string $cacheDirectory)
    {
        if (!is_dir($cacheDirectory) && !mkdir($cacheDirectory, 0775, true) && !is_dir($cacheDirectory)) {
            throw new RuntimeException(sprintf('Unable to create cache directory "%s".', $cacheDirectory));
        }
    }

    /** @return array<string, mixed>|null */
    public function read(string $key, ?int $maxAgeSeconds = null): ?array
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        if ($maxAgeSeconds !== null) {
            $modifiedAt = filemtime($path);

            if ($modifiedAt === false || time() - $modifiedAt > $maxAgeSeconds) {
                return null;
            }
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $data */
    public function write(string $key, array $data): void
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode cache data.', 0, $exception);
        }

        $path = $this->path($key);
        $temporaryPath = $path . '.tmp';

        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write cache file "%s".', $temporaryPath));
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException(sprintf('Unable to replace cache file "%s".', $path));
        }
    }

    public function modifiedAt(string $key): ?int
    {
        $modifiedAt = filemtime($this->path($key));

        return $modifiedAt === false ? null : $modifiedAt;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException(sprintf('Unable to delete cache file "%s".', $path));
        }
    }

    private function path(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9._-]/', '_', $key);

        if ($safeKey === null || $safeKey === '') {
            throw new RuntimeException('Invalid cache key.');
        }

        return rtrim($this->cacheDirectory, '/') . '/' . $safeKey . '.json';
    }
}
