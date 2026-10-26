<?php

declare(strict_types=1);

namespace AchievementBattle;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class WeeklyBattleService
{
    private const TIMEZONE = 'Europe/Amsterdam';
    private const CACHE_KEY = 'weekly-battle-summary';

    private readonly DateTimeZone $timezone;
    private readonly CacheRepository $cache;

    public function __construct(private readonly AppConfig $config)
    {
        $this->timezone = new DateTimeZone(self::TIMEZONE);
        $this->cache = new CacheRepository($config->rootDirectory() . '/var/cache');
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function enrich(array $snapshot): array
    {
        $window = $this->weekWindow();
        $counts = $this->weeklyCounts($snapshot, $window);
        $players = $snapshot['players'] ?? null;

        if (is_array($players)) {
            foreach ($players as $index => $player) {
                if (!is_array($player)) {
                    continue;
                }

                $playerKey = $player['key'] ?? null;

                if (!is_string($playerKey) || $playerKey === '') {
                    continue;
                }

                $player['weekly_achievement_count'] = $counts[$playerKey] ?? 0;
                $players[$index] = $player;
            }

            $snapshot['players'] = $players;
        }

        $snapshot['weekly_competition'] = [
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'timezone' => self::TIMEZONE,
        ];

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array{start_timestamp: int, end_timestamp: int, starts_at: string, ends_at: string} $window
     * @return array<string, int>
     */
    private function weeklyCounts(array $snapshot, array $window): array
    {
        $signature = $this->sourceSignature($window['start_timestamp']);
        $cached = $this->cache->read(self::CACHE_KEY);
        $cachedCounts = is_array($cached['counts'] ?? null) ? $cached['counts'] : null;

        if (($cached['signature'] ?? null) === $signature && is_array($cachedCounts)) {
            return $this->normalizeCounts($cachedCounts);
        }

        $players = is_array($snapshot['players'] ?? null) ? $snapshot['players'] : [];
        $counts = [];

        foreach ($players as $player) {
            if (!is_array($player)) {
                continue;
            }

            $playerKey = $player['key'] ?? null;

            if (!is_string($playerKey) || $playerKey === '') {
                continue;
            }

            $counts[$playerKey] = $this->countAchievements(
                $playerKey,
                $window['start_timestamp'],
                $window['end_timestamp'],
            );
        }

        $this->cache->write(self::CACHE_KEY, [
            'signature' => $signature,
            'counts' => $counts,
        ]);

        return $counts;
    }

    /**
     * @return array{start_timestamp: int, end_timestamp: int, starts_at: string, ends_at: string}
     */
    private function weekWindow(): array
    {
        $now = new DateTimeImmutable('now', $this->timezone);
        $start = $now->modify('monday this week')->setTime(0, 0, 0);
        $end = $start->modify('+7 days');

        return [
            'start_timestamp' => $start->getTimestamp(),
            'end_timestamp' => $end->getTimestamp(),
            'starts_at' => $start->format(DATE_ATOM),
            'ends_at' => $end->format(DATE_ATOM),
        ];
    }

    private function sourceSignature(int $weekStartTimestamp): string
    {
        $files = glob($this->config->rootDirectory() . '/var/cache/achievements-*.json', GLOB_NOSORT);
        $parts = [
            (string) $weekStartTimestamp,
            implode(',', array_keys($this->config->excludedAppIds())),
        ];

        if ($files === false) {
            return hash('sha256', implode('|', $parts));
        }

        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $modifiedAt = filemtime($file);
            $size = filesize($file);
            $parts[] = sprintf(
                '%s:%d:%d',
                basename($file),
                $modifiedAt === false ? 0 : $modifiedAt,
                $size === false ? 0 : $size,
            );
        }

        return hash('sha256', implode('|', $parts));
    }

    private function countAchievements(string $playerKey, int $startTimestamp, int $endTimestamp): int
    {
        $safePlayerKey = preg_replace('/[^a-zA-Z0-9._-]/', '_', $playerKey);

        if (!is_string($safePlayerKey) || $safePlayerKey === '') {
            return 0;
        }

        $pattern = sprintf(
            '%s/var/cache/achievements-%s-*.json',
            rtrim($this->config->rootDirectory(), '/'),
            $safePlayerKey,
        );
        $files = glob($pattern, GLOB_NOSORT);

        if ($files === false) {
            return 0;
        }

        $excludedAppIds = $this->config->excludedAppIds();
        $count = 0;

        foreach ($files as $file) {
            $appId = $this->appIdFromCacheFile($file, $safePlayerKey);

            if ($appId === null || isset($excludedAppIds[$appId])) {
                continue;
            }

            $cache = $this->readCacheFile($file);
            $data = is_array($cache['data'] ?? null) ? $cache['data'] : null;
            $achievements = is_array($data) && is_array($data['achievements'] ?? null)
                ? $data['achievements']
                : [];

            foreach ($achievements as $achievement) {
                if (!is_array($achievement) || ($achievement['achieved'] ?? false) !== true) {
                    continue;
                }

                $unlockTime = $achievement['unlock_time'] ?? null;

                if (
                    is_int($unlockTime)
                    && $unlockTime >= $startTimestamp
                    && $unlockTime < $endTimestamp
                ) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function appIdFromCacheFile(string $file, string $safePlayerKey): ?int
    {
        $pattern = sprintf(
            '/^achievements-%s-(\d+)\.json$/',
            preg_quote($safePlayerKey, '/'),
        );
        $matches = [];

        if (preg_match($pattern, basename($file), $matches) !== 1) {
            return null;
        }

        $appId = filter_var($matches[1] ?? null, FILTER_VALIDATE_INT);

        return is_int($appId) ? $appId : null;
    }

    /** @return array<string, mixed> */
    private function readCacheFile(string $file): array
    {
        $contents = file_get_contents($file);

        if (!is_string($contents)) {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed> $counts
     * @return array<string, int>
     */
    private function normalizeCounts(array $counts): array
    {
        $normalized = [];

        foreach ($counts as $playerKey => $count) {
            if (is_string($playerKey) && is_int($count)) {
                $normalized[$playerKey] = $count;
            }
        }

        return $normalized;
    }
}
