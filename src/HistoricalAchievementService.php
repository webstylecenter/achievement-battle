<?php

declare(strict_types=1);

namespace AchievementBattle;

final class HistoricalAchievementService
{
    public function __construct(
        private readonly AppConfig $config,
        private readonly CacheRepository $cache,
    ) {
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public function enrich(array $snapshot): array
    {
        $players = $snapshot['players'] ?? null;
        $competition = $snapshot['competition'] ?? null;

        if (!is_array($players) || !is_array($competition)) {
            return $snapshot;
        }

        foreach ($players as $index => $player) {
            if (!is_array($player)) {
                continue;
            }

            $playerKey = $player['key'] ?? null;

            if (!is_string($playerKey) || $playerKey === '') {
                continue;
            }

            $score = $this->score($playerKey, $competition);

            if ($score['cache_files'] === 0) {
                continue;
            }

            $player['achievement_count'] = $score['battle'];
            $player['lifetime_achievement_count'] = $score['lifetime'];
            $player['completed_games'] = $score['battle_perfect'];
            $player['lifetime_completed_games'] = $score['lifetime_perfect'];
            $player['games_with_achievements'] = $score['games_with_achievements'];
            $player['historical_cache_games'] = $score['cache_files'];
            $players[$index] = $player;
        }

        $snapshot['players'] = $players;

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $competition
     * @return array{
     *     battle: int,
     *     lifetime: int,
     *     battle_perfect: int,
     *     lifetime_perfect: int,
     *     games_with_achievements: int,
     *     cache_files: int
     * }
     */
    private function score(string $playerKey, array $competition): array
    {
        $lifetime = 0;
        $battle = 0;
        $lifetimePerfect = 0;
        $battlePerfect = 0;
        $gamesWithAchievements = 0;
        $cacheFiles = 0;
        $start = is_int($competition['timestamp'] ?? null) ? $competition['timestamp'] : null;
        $isSourcePlayer = ($competition['mode'] ?? null) === 'player_created'
            && ($competition['source_player'] ?? null) === $playerKey;
        $excluded = $this->config->excludedAppIds();

        foreach ($this->cachedAchievementAppIds($playerKey) as $appId) {
            if (isset($excluded[$appId])) {
                continue;
            }

            $cached = $this->cache->read(sprintf('achievements-%s-%d', $playerKey, $appId));
            $stats = is_array($cached) && is_array($cached['data'] ?? null)
                ? $cached['data']
                : null;

            if (!is_array($stats)) {
                continue;
            }

            $cacheFiles++;
            $total = is_int($stats['total'] ?? null) ? $stats['total'] : 0;

            if ($total <= 0) {
                continue;
            }

            $gamesWithAchievements++;
            $unlocked = is_int($stats['unlocked'] ?? null) ? $stats['unlocked'] : 0;
            $lifetime += $unlocked;
            $isPerfect = $unlocked === $total;
            $lifetimePerfect += $isPerfect ? 1 : 0;

            if ($start === null || $isSourcePlayer) {
                $battle += $unlocked;
                $battlePerfect += $isPerfect ? 1 : 0;
                continue;
            }

            $latestUnlock = 0;
            $achievements = is_array($stats['achievements'] ?? null) ? $stats['achievements'] : [];

            foreach ($achievements as $achievement) {
                if (!is_array($achievement) || ($achievement['achieved'] ?? false) !== true) {
                    continue;
                }

                $unlockTime = is_int($achievement['unlock_time'] ?? null)
                    ? $achievement['unlock_time']
                    : 0;
                $latestUnlock = max($latestUnlock, $unlockTime);
                $battle += $unlockTime >= $start ? 1 : 0;
            }

            $battlePerfect += $isPerfect && $latestUnlock >= $start ? 1 : 0;
        }

        return [
            'battle' => $battle,
            'lifetime' => $lifetime,
            'battle_perfect' => $battlePerfect,
            'lifetime_perfect' => $lifetimePerfect,
            'games_with_achievements' => $gamesWithAchievements,
            'cache_files' => $cacheFiles,
        ];
    }

    /** @return list<int> */
    private function cachedAchievementAppIds(string $playerKey): array
    {
        $safePlayerKey = preg_replace('/[^a-zA-Z0-9._-]/', '_', $playerKey);

        if (!is_string($safePlayerKey) || $safePlayerKey === '') {
            return [];
        }

        $pattern = sprintf(
            '%s/var/cache/achievements-%s-*.json',
            rtrim($this->config->rootDirectory(), '/'),
            $safePlayerKey,
        );
        $files = glob($pattern, GLOB_NOSORT);

        if ($files === false) {
            return [];
        }

        $appIds = [];
        $regex = sprintf('/^achievements-%s-(\d+)\.json$/', preg_quote($safePlayerKey, '/'));

        foreach ($files as $file) {
            $matches = [];

            if (preg_match($regex, basename($file), $matches) !== 1) {
                continue;
            }

            $appId = filter_var($matches[1] ?? null, FILTER_VALIDATE_INT);

            if (is_int($appId)) {
                $appIds[] = $appId;
            }
        }

        sort($appIds, SORT_NUMERIC);

        return array_values(array_unique($appIds));
    }
}
