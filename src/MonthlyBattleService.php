<?php

declare(strict_types=1);

namespace AchievementBattle;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

final class MonthlyBattleService
{
    private const TIMEZONE = 'Europe/Amsterdam';
    private const CACHE_KEY = 'monthly-battle-summary';

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
        $month = $this->monthWindow();
        $scores = $this->monthlyScores($snapshot, $month);
        $players = $snapshot['players'] ?? null;

        if (is_array($players)) {
            foreach ($players as $index => $player) {
                if (!is_array($player)) {
                    continue;
                }

                $key = $player['key'] ?? null;

                if (!is_string($key) || $key === '') {
                    continue;
                }

                $player['monthly_achievement_count'] = $scores['counts'][$key] ?? 0;
                $players[$index] = $player;
            }

            $snapshot['players'] = $players;
        }

        $snapshot['monthly_competition'] = [
            'starts_at' => $month['starts_at'],
            'ends_at' => $month['ends_at'],
            'timezone' => self::TIMEZONE,
        ];
        $snapshot['monthly_history'] = $scores['history'];

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array{start_timestamp: int, end_timestamp: int, starts_at: string, ends_at: string} $month
     * @return array{
     *     counts: array<string, int>,
     *     history: list<array{starts_at: string, ends_at: string, players: array<string, int>}>
     * }
     */
    private function monthlyScores(array $snapshot, array $month): array
    {
        $players = is_array($snapshot['players'] ?? null) ? $snapshot['players'] : [];
        $createdAt = $this->florianCreatedAt($players);
        $signature = $this->sourceSignature($month['start_timestamp'], $createdAt);
        $cached = $this->cache->read(self::CACHE_KEY);
        $cachedCounts = is_array($cached['counts'] ?? null) ? $cached['counts'] : null;
        $cachedHistory = is_array($cached['history'] ?? null) ? $cached['history'] : null;

        if (($cached['signature'] ?? null) === $signature && $cachedCounts !== null && $cachedHistory !== null) {
            return [
                'counts' => $this->normalizeCounts($cachedCounts),
                'history' => $this->normalizeHistory($cachedHistory),
            ];
        }

        $counts = [];
        $buckets = [];
        $firstFlorianUnlock = null;

        foreach ($players as $player) {
            if (!is_array($player)) {
                continue;
            }

            $playerKey = $player['key'] ?? null;

            if (!is_string($playerKey) || $playerKey === '') {
                continue;
            }

            $stats = $this->countAchievements(
                $playerKey,
                $month['start_timestamp'],
                $month['end_timestamp'],
            );

            $counts[$playerKey] = $stats['monthly'];
            $buckets[$playerKey] = $stats['buckets'];

            if ($playerKey === 'florian') {
                $firstFlorianUnlock = $stats['earliest_unlock'];
            }
        }

        $history = $this->historyRows(
            $createdAt ?? $firstFlorianUnlock,
            $month['start_timestamp'],
            $buckets,
        );

        try {
            $this->cache->write(self::CACHE_KEY, [
                'signature' => $signature,
                'counts' => $counts,
                'history' => $history,
            ]);
        } catch (RuntimeException $exception) {
            // A read-only web server can still return up-to-date monthly scores.
            error_log('Could not cache monthly battle scores: ' . $exception->getMessage());
        }

        return ['counts' => $counts, 'history' => $history];
    }

    /**
     * @return array{start_timestamp: int, end_timestamp: int, starts_at: string, ends_at: string}
     */
    private function monthWindow(): array
    {
        $start = (new DateTimeImmutable('now', $this->timezone))
            ->modify('first day of this month')
            ->setTime(0, 0, 0);
        $end = $start->modify('+1 month');

        return [
            'start_timestamp' => $start->getTimestamp(),
            'end_timestamp' => $end->getTimestamp(),
            'starts_at' => $start->format(DATE_ATOM),
            'ends_at' => $end->format(DATE_ATOM),
        ];
    }

    private function sourceSignature(int $monthStartTimestamp, ?int $createdAt): string
    {
        $files = glob($this->config->rootDirectory() . '/var/cache/achievements-*.json', GLOB_NOSORT);
        $parts = [
            (string) $monthStartTimestamp,
            (string) ($createdAt ?? 0),
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

    /**
     * @return array{monthly: int, buckets: array<int, int>, earliest_unlock: int|null}
     */
    private function countAchievements(string $playerKey, int $monthStart, int $monthEnd): array
    {
        $counts = ['monthly' => 0, 'buckets' => [], 'earliest_unlock' => null];
        $safePlayerKey = preg_replace('/[^a-zA-Z0-9._-]/', '_', $playerKey);

        if (!is_string($safePlayerKey) || $safePlayerKey === '') {
            return $counts;
        }

        $pattern = sprintf(
            '%s/var/cache/achievements-%s-*.json',
            rtrim($this->config->rootDirectory(), '/'),
            $safePlayerKey,
        );
        $files = glob($pattern, GLOB_NOSORT);

        if ($files === false) {
            return $counts;
        }

        $excluded = $this->config->excludedAppIds();
        $now = time();

        foreach ($files as $file) {
            $appId = $this->appIdFromCacheFile($file, $safePlayerKey);

            if ($appId === null || isset($excluded[$appId])) {
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

                if (!is_int($unlockTime) || $unlockTime <= 0 || $unlockTime > $now) {
                    continue;
                }

                if ($counts['earliest_unlock'] === null || $unlockTime < $counts['earliest_unlock']) {
                    $counts['earliest_unlock'] = $unlockTime;
                }

                if ($unlockTime >= $monthStart && $unlockTime < $monthEnd) {
                    $counts['monthly']++;
                }

                $date = (new DateTimeImmutable('@' . $unlockTime))->setTimezone($this->timezone);
                $bucket = $date->modify('first day of this month')->setTime(0, 0, 0)->getTimestamp();
                $counts['buckets'][$bucket] = ($counts['buckets'][$bucket] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param array<mixed> $players
     */
    private function florianCreatedAt(array $players): ?int
    {
        foreach ($players as $player) {
            if (!is_array($player) || ($player['key'] ?? null) !== 'florian') {
                continue;
            }

            $createdAt = $player['account_created'] ?? null;

            return is_int($createdAt) && $createdAt > 0 && $createdAt <= time() ? $createdAt : null;
        }

        return null;
    }

    /**
     * @param array<string, array<int, int>> $buckets
     * @return list<array{starts_at: string, ends_at: string, players: array<string, int>}>
     */
    private function historyRows(?int $origin, int $currentStart, array $buckets): array
    {
        if ($origin === null || $origin <= 0) {
            return [];
        }

        $originDate = (new DateTimeImmutable('@' . $origin))->setTimezone($this->timezone);
        $first = $originDate->modify('first day of this month')->setTime(0, 0, 0);
        $current = (new DateTimeImmutable('@' . $currentStart))->setTimezone($this->timezone);
        $rows = [];

        for ($cursor = $current->modify('-1 month'); $cursor >= $first; $cursor = $cursor->modify('-1 month')) {
            $scores = [];

            foreach ($this->config->players() as $player) {
                $key = $player['key'];
                $scores[$key] = $buckets[$key][$cursor->getTimestamp()] ?? 0;
            }

            $rows[] = [
                'starts_at' => $cursor->format(DATE_ATOM),
                'ends_at' => $cursor->modify('+1 month')->format(DATE_ATOM),
                'players' => $scores,
            ];
        }

        return $rows;
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

    /**
     * @param array<mixed> $rows
     * @return list<array{starts_at: string, ends_at: string, players: array<string, int>}>
     */
    private function normalizeHistory(array $rows): array
    {
        $history = [];

        foreach ($rows as $row) {
            if (
                !is_array($row)
                || !is_string($row['starts_at'] ?? null)
                || !is_string($row['ends_at'] ?? null)
                || !is_array($row['players'] ?? null)
            ) {
                continue;
            }

            $history[] = [
                'starts_at' => $row['starts_at'],
                'ends_at' => $row['ends_at'],
                'players' => $this->normalizeCounts($row['players']),
            ];
        }

        return $history;
    }
}
