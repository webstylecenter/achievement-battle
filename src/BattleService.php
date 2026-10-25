<?php

declare(strict_types=1);

namespace AchievementBattle;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class BattleService
{
    private const SNAPSHOT_CACHE_KEY = 'battle-snapshot';

    public function __construct(
        private readonly AppConfig $config,
        private readonly SteamApiClient $steam,
        private readonly CacheRepository $cache,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function cachedSnapshot(): ?array
    {
        return $this->cache->read(self::SNAPSHOT_CACHE_KEY);
    }

    /** @return array<string, mixed> */
    public function refresh(): array
    {
        $players = [];
        $gamesByPlayer = [];
        $achievementsByPlayer = [];
        $syncByPlayer = [];
        $excluded = $this->config->excludedAppIds();

        foreach ($this->config->players() as $playerConfig) {
            $steamId = $playerConfig['profile_type'] === 'vanity'
                ? $this->steam->resolveVanityUrl($playerConfig['profile'])
                : $playerConfig['profile'];
            $summary = $this->steam->playerSummary($steamId);
            $games = [];

            foreach ($this->steam->ownedGames($steamId) as $game) {
                $appId = $this->intValue($game['appid'] ?? null);

                if ($appId === null || isset($excluded[$appId])) {
                    continue;
                }

                $games[$appId] = $this->normalizeGame($game);
            }

            $playerKey = $playerConfig['key'];
            $achievementLoad = $this->loadAchievements($playerKey, $steamId, $games);
            $players[$playerKey] = [
                'key' => $playerKey,
                'name' => $playerConfig['display_name'],
                'fighter_image' => $playerConfig['fighter_image'],
                'steam_id' => $steamId,
                'steam_name' => $this->stringValue($summary['personaname'] ?? null) ?? $playerConfig['display_name'],
                'avatar' => $this->stringValue($summary['avatarfull'] ?? null) ?? '',
                'profile_url' => $this->stringValue($summary['profileurl'] ?? null) ?? '',
                'account_created' => $this->intValue($summary['timecreated'] ?? null),
                'recent_raw' => $this->steam->recentlyPlayedGames(
                    $steamId,
                    max(10, $this->config->recentGameCount()),
                ),
            ];
            $gamesByPlayer[$playerKey] = $games;
            $achievementsByPlayer[$playerKey] = $achievementLoad['data'];
            $syncByPlayer[$playerKey] = $achievementLoad['sync'];
        }

        $this->warmRarityCache($achievementsByPlayer);
        $competition = $this->competitionStart($players);
        $output = [];

        foreach ($players as $playerKey => $player) {
            $output[$playerKey] = $this->buildPlayer(
                $player,
                $gamesByPlayer[$playerKey],
                $achievementsByPlayer[$playerKey],
                $syncByPlayer[$playerKey],
                $competition,
            );
        }

        $keys = array_keys($output);

        if (count($keys) !== 2) {
            throw new RuntimeException('Exactly two player results are required.');
        }

        $snapshot = [
            'generated_at' => gmdate(DATE_ATOM),
            'competition' => $competition,
            'players' => [$output[$keys[0]], $output[$keys[1]]],
            'shared_games' => $this->sharedGames(
                $keys[0],
                $keys[1],
                $gamesByPlayer,
                $achievementsByPlayer,
            ),
            'excluded_games' => array_map(
                static fn (string $name, int $appId): array => ['app_id' => $appId, 'name' => $name],
                array_values($excluded),
                array_keys($excluded),
            ),
        ];

        $this->cache->write(self::SNAPSHOT_CACHE_KEY, $snapshot);

        return $snapshot;
    }

    /**
     * @param array<int, array<string, mixed>> $games
     * @return array{data: array<int, array<string, mixed>>, sync: array<string, int>}
     */
    private function loadAchievements(string $playerKey, string $steamId, array $games): array
    {
        $data = [];
        $requests = [];
        $stale = [];
        $sync = [
            'requested_games' => 0,
            'cached_games' => 0,
            'refreshed_games' => 0,
            'stale_cache_games' => 0,
            'no_achievement_games' => 0,
        ];

        foreach ($games as $appId => $game) {
            $playtime = $this->intValue($game['playtime_forever'] ?? null) ?? 0;

            if ($playtime <= 0) {
                continue;
            }

            $fingerprint = sprintf(
                '%d:%d',
                $playtime,
                $this->intValue($game['rtime_last_played'] ?? null) ?? 0,
            );
            $cacheKey = sprintf('achievements-%s-%d', $playerKey, $appId);
            $cached = $this->cache->read($cacheKey);
            $cachedData = is_array($cached) && is_array($cached['data'] ?? null)
                ? $cached['data']
                : null;

            if (
                is_array($cachedData)
                && $this->stringValue($cached['game_fingerprint'] ?? null) === $fingerprint
            ) {
                $data[$appId] = $cachedData;
                $sync['cached_games']++;
                $sync['no_achievement_games'] += ($cachedData['total'] ?? 0) === 0 ? 1 : 0;
                continue;
            }

            if (is_array($cachedData)) {
                $stale[$appId] = $cachedData;
            }

            $requests[] = ['key' => (string) $appId, 'steam_id' => $steamId, 'app_id' => $appId];
        }

        $sync['requested_games'] = count($requests);
        $responses = $this->steam->playerAchievementsBatch($requests);
        $failed = [];

        foreach ($requests as $request) {
            $appId = $request['app_id'];
            $achievementData = $this->achievementData($responses[$request['key']] ?? null);

            if ($achievementData === null) {
                if (isset($stale[$appId])) {
                    $data[$appId] = $stale[$appId];
                    $sync['stale_cache_games']++;
                    continue;
                }

                $failed[] = sprintf('%s (%d)', $games[$appId]['name'] ?? 'Unknown game', $appId);
                continue;
            }

            $data[$appId] = $achievementData;
            $sync['refreshed_games']++;
            $sync['no_achievement_games'] += $achievementData['total'] === 0 ? 1 : 0;
            $this->cache->write(sprintf('achievements-%s-%d', $playerKey, $appId), [
                'game_fingerprint' => sprintf(
                    '%d:%d',
                    $this->intValue($games[$appId]['playtime_forever'] ?? null) ?? 0,
                    $this->intValue($games[$appId]['rtime_last_played'] ?? null) ?? 0,
                ),
                'data' => $achievementData,
            ]);
        }

        if ($failed !== []) {
            throw new RuntimeException(sprintf(
                'Achievement sync for %s is incomplete after retries. Previous snapshot kept. Failed: %s',
                $playerKey,
                implode(', ', $failed),
            ));
        }

        return ['data' => $data, 'sync' => $sync];
    }

    /**
     * @param array<string, mixed>|null $response
     * @return array{total: int, unlocked: int, achievements: list<array<string, mixed>>}|null
     */
    private function achievementData(?array $response): ?array
    {
        if ($response === null) {
            return null;
        }

        $stats = $response['playerstats'] ?? null;

        if (!is_array($stats)) {
            return null;
        }

        if (($stats['success'] ?? null) !== true) {
            $error = strtolower($this->stringValue($stats['error'] ?? null) ?? '');

            return str_contains($error, 'no stats') || str_contains($error, 'has no achievements')
                ? ['total' => 0, 'unlocked' => 0, 'achievements' => []]
                : null;
        }

        $items = $stats['achievements'] ?? [];

        if (!is_array($items)) {
            return ['total' => 0, 'unlocked' => 0, 'achievements' => []];
        }

        $achievements = [];
        $unlocked = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $achieved = $this->intValue($item['achieved'] ?? null) === 1;
            $unlocked += $achieved ? 1 : 0;
            $achievements[] = [
                'api_name' => $this->stringValue($item['apiname'] ?? null) ?? '',
                'name' => $this->stringValue($item['name'] ?? null)
                    ?? $this->stringValue($item['apiname'] ?? null)
                    ?? 'Unknown achievement',
                'description' => $this->stringValue($item['description'] ?? null) ?? '',
                'achieved' => $achieved,
                'unlock_time' => $this->intValue($item['unlocktime'] ?? null) ?? 0,
            ];
        }

        return ['total' => count($achievements), 'unlocked' => $unlocked, 'achievements' => $achievements];
    }

    /**
     * @param array<string, array<string, mixed>> $players
     * @return array{mode: string, timestamp: int|null, starts_at: string|null, source_player: string|null}
     */
    private function competitionStart(array $players): array
    {
        $mode = $this->config->achievementStartMode();

        if ($mode === 'all_time') {
            return ['mode' => $mode, 'timestamp' => null, 'starts_at' => null, 'source_player' => null];
        }

        if ($mode === 'date') {
            $date = $this->config->achievementStartDate();

            if ($date === null) {
                throw new RuntimeException('achievement_start.date is required when mode is date.');
            }

            $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

            if ($dateTime === false) {
                throw new RuntimeException('Unable to parse achievement_start.date.');
            }

            return [
                'mode' => $mode,
                'timestamp' => $dateTime->getTimestamp(),
                'starts_at' => $dateTime->format(DATE_ATOM),
                'source_player' => null,
            ];
        }

        $playerKey = $this->config->achievementStartPlayerKey();
        $timestamp = $playerKey !== null && isset($players[$playerKey])
            ? $this->intValue($players[$playerKey]['account_created'] ?? null)
            : null;

        if ($playerKey === null || $timestamp === null || $timestamp <= 0) {
            throw new RuntimeException('Unable to determine the configured player account creation date.');
        }

        return [
            'mode' => $mode,
            'timestamp' => $timestamp,
            'starts_at' => gmdate(DATE_ATOM, $timestamp),
            'source_player' => $playerKey,
        ];
    }

    /**
     * @param array<string, mixed> $player
     * @param array<int, array<string, mixed>> $games
     * @param array<int, array<string, mixed>> $achievementData
     * @param array<string, int> $sync
     * @param array<string, mixed> $competition
     * @return array<string, mixed>
     */
    private function buildPlayer(
        array $player,
        array $games,
        array $achievementData,
        array $sync,
        array $competition,
    ): array {
        $lifetime = 0;
        $battle = 0;
        $lifetimePerfect = 0;
        $battlePerfect = 0;
        $gamesWithAchievements = 0;
        $playtime = 0;
        $start = $this->intValue($competition['timestamp'] ?? null);
        $isSourcePlayer = ($competition['mode'] ?? null) === 'player_created'
            && ($competition['source_player'] ?? null) === ($player['key'] ?? null);

        foreach ($games as $appId => $game) {
            $playtime += $this->intValue($game['playtime_forever'] ?? null) ?? 0;
            $stats = $achievementData[$appId] ?? null;

            if (!is_array($stats) || ($stats['total'] ?? 0) <= 0) {
                continue;
            }

            $gamesWithAchievements++;
            $unlocked = is_int($stats['unlocked'] ?? null) ? $stats['unlocked'] : 0;
            $total = is_int($stats['total'] ?? null) ? $stats['total'] : 0;
            $lifetime += $unlocked;
            $isPerfect = $total > 0 && $unlocked === $total;
            $lifetimePerfect += $isPerfect ? 1 : 0;

            if ($start === null || $isSourcePlayer) {
                $battle += $unlocked;
                $battlePerfect += $isPerfect ? 1 : 0;
                continue;
            }

            $latestUnlock = 0;

            foreach (($stats['achievements'] ?? []) as $achievement) {
                if (!is_array($achievement) || ($achievement['achieved'] ?? false) !== true) {
                    continue;
                }

                $unlockTime = $this->intValue($achievement['unlock_time'] ?? null) ?? 0;
                $latestUnlock = max($latestUnlock, $unlockTime);
                $battle += $unlockTime >= $start ? 1 : 0;
            }

            $battlePerfect += $isPerfect && $latestUnlock >= $start ? 1 : 0;
        }

        return [
            'key' => $player['key'],
            'name' => $player['name'],
            'steam_name' => $player['steam_name'],
            'steam_id' => $player['steam_id'],
            'avatar' => $player['avatar'],
            'fighter_image' => $player['fighter_image'],
            'profile_url' => $player['profile_url'],
            'achievement_count' => $battle,
            'lifetime_achievement_count' => $lifetime,
            'completed_games' => $battlePerfect,
            'lifetime_completed_games' => $lifetimePerfect,
            'games_with_achievements' => $gamesWithAchievements,
            'games_included' => count($games),
            'hours_played' => round($playtime / 60, 1),
            'recent_games' => $this->recentGames($games, $player['recent_raw'] ?? []),
            'rare_achievements' => $this->rareAchievements($games, $achievementData),
            'sync' => $sync,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $games
     * @return list<array<string, mixed>>
     */
    private function recentGames(array $games, mixed $recentRaw): array
    {
        $recent = [];

        if (is_array($recentRaw)) {
            foreach ($recentRaw as $rawGame) {
                if (!is_array($rawGame)) {
                    continue;
                }

                $appId = $this->intValue($rawGame['appid'] ?? null);

                if ($appId !== null && isset($games[$appId])) {
                    $recent[$appId] = $this->publicGame($games[$appId]);
                }
            }
        }

        $fallback = $games;
        uasort($fallback, fn (array $a, array $b): int =>
            ($this->intValue($b['rtime_last_played'] ?? null) ?? 0)
            <=> ($this->intValue($a['rtime_last_played'] ?? null) ?? 0));

        foreach ($fallback as $appId => $game) {
            if (!isset($recent[$appId])) {
                $recent[$appId] = $this->publicGame($game);
            }

            if (count($recent) >= $this->config->recentGameCount()) {
                break;
            }
        }

        return array_slice(array_values($recent), 0, $this->config->recentGameCount());
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $achievementData
     */
    private function warmRarityCache(array $achievementData): void
    {
        if ($this->config->rareAchievementCount() <= 0) {
            return;
        }

        $missing = [];

        foreach ($achievementData as $byGame) {
            foreach ($byGame as $appId => $stats) {
                if (($stats['unlocked'] ?? 0) > 0
                    && $this->cache->read(
                        sprintf('global-percentages-%d', $appId),
                        $this->config->rarityCacheMaxAgeSeconds(),
                    ) === null
                ) {
                    $missing[$appId] = $appId;
                }
            }
        }

        foreach ($this->steam->globalAchievementPercentagesBatch(array_values($missing)) as $appId => $response) {
            if ($response !== null) {
                $this->cache->write(sprintf('global-percentages-%d', $appId), $response);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $games
     * @param array<int, array<string, mixed>> $achievementData
     * @return list<array<string, mixed>>
     */
    private function rareAchievements(array $games, array $achievementData): array
    {
        $rare = [];

        foreach ($achievementData as $appId => $stats) {
            $percentages = $this->globalPercentages($appId);

            if ($percentages === []) {
                continue;
            }

            foreach (($stats['achievements'] ?? []) as $achievement) {
                if (!is_array($achievement) || ($achievement['achieved'] ?? false) !== true) {
                    continue;
                }

                $apiName = $this->stringValue($achievement['api_name'] ?? null);

                if ($apiName === null || !isset($percentages[$apiName])) {
                    continue;
                }

                $rare[] = [
                    'game_name' => $games[$appId]['name'] ?? 'Unknown game',
                    'game_image' => $this->headerImage($appId),
                    'name' => $achievement['name'] ?? $apiName,
                    'percentage' => $percentages[$apiName],
                    'unlock_time' => $achievement['unlock_time'] ?? 0,
                ];
            }
        }

        usort($rare, static fn (array $a, array $b): int =>
            ($a['percentage'] <=> $b['percentage']) ?: ($b['unlock_time'] <=> $a['unlock_time']));

        return array_slice($rare, 0, $this->config->rareAchievementCount());
    }

    /** @return array<string, float> */
    private function globalPercentages(int $appId): array
    {
        $data = $this->cache->read(
            sprintf('global-percentages-%d', $appId),
            $this->config->rarityCacheMaxAgeSeconds(),
        );
        $container = is_array($data) ? ($data['achievementpercentages'] ?? null) : null;
        $items = is_array($container) ? ($container['achievements'] ?? null) : null;
        $result = [];

        if (!is_array($items)) {
            return $result;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = $this->stringValue($item['name'] ?? null);
            $percent = $this->floatValue($item['percent'] ?? null);

            if ($name !== null && $percent !== null) {
                $result[$name] = $percent;
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $gamesByPlayer
     * @param array<string, array<int, array<string, mixed>>> $achievementsByPlayer
     * @return list<array<string, mixed>>
     */
    private function sharedGames(
        string $leftKey,
        string $rightKey,
        array $gamesByPlayer,
        array $achievementsByPlayer,
    ): array {
        $leftGames = $gamesByPlayer[$leftKey] ?? [];
        $rightGames = $gamesByPlayer[$rightKey] ?? [];
        $leftAchievements = $achievementsByPlayer[$leftKey] ?? [];
        $rightAchievements = $achievementsByPlayer[$rightKey] ?? [];
        $rows = [];

        foreach (array_intersect(array_keys($leftGames), array_keys($rightGames)) as $appId) {
            $leftMinutes = $this->intValue($leftGames[$appId]['playtime_forever'] ?? null) ?? 0;
            $rightMinutes = $this->intValue($rightGames[$appId]['playtime_forever'] ?? null) ?? 0;

            if ($this->config->sharedGamesRequireBothPlayed() && ($leftMinutes <= 0 || $rightMinutes <= 0)) {
                continue;
            }

            $left = $leftAchievements[$appId] ?? null;
            $right = $rightAchievements[$appId] ?? null;

            if (!is_array($left) || !is_array($right)) {
                continue;
            }

            $total = max((int) ($left['total'] ?? 0), (int) ($right['total'] ?? 0));
            $leftUnlocked = (int) ($left['unlocked'] ?? 0);
            $rightUnlocked = (int) ($right['unlocked'] ?? 0);

            if ($total <= 0) {
                continue;
            }

            if ($this->config->sharedGamesExcludeCompletedByEither()
                && ($leftUnlocked === (int) ($left['total'] ?? 0) || $rightUnlocked === (int) ($right['total'] ?? 0))
            ) {
                continue;
            }

            $lastPlayed = max(
                $this->intValue($leftGames[$appId]['rtime_last_played'] ?? null) ?? 0,
                $this->intValue($rightGames[$appId]['rtime_last_played'] ?? null) ?? 0,
            );
            $game = $leftGames[$appId];
            $rows[] = [
                'app_id' => $appId,
                'name' => $game['name'] ?? 'Unknown game',
                'image' => $this->headerImage($appId),
                'icon' => $this->iconImage($appId, $this->stringValue($game['img_icon_url'] ?? null) ?? ''),
                'achievement_total' => $total,
                'last_played' => $lastPlayed,
                'players' => [
                    $leftKey => [
                        'unlocked' => $leftUnlocked,
                        'percentage' => round(($leftUnlocked / $total) * 100, 1),
                        'hours' => round($leftMinutes / 60, 1),
                    ],
                    $rightKey => [
                        'unlocked' => $rightUnlocked,
                        'percentage' => round(($rightUnlocked / $total) * 100, 1),
                        'hours' => round($rightMinutes / 60, 1),
                    ],
                ],
                'winner' => $leftUnlocked === $rightUnlocked
                    ? null
                    : ($leftUnlocked > $rightUnlocked ? $leftKey : $rightKey),
            ];
        }

        usort($rows, static fn (array $a, array $b): int =>
            ($b['last_played'] <=> $a['last_played']) ?: strcmp((string) $a['name'], (string) $b['name']));

        return array_slice($rows, 0, $this->config->sharedGameLimit());
    }

    /** @param array<string, mixed> $game @return array<string, mixed> */
    private function normalizeGame(array $game): array
    {
        $appId = $this->intValue($game['appid'] ?? null) ?? 0;
        $iconHash = $this->stringValue($game['img_icon_url'] ?? null) ?? '';

        return [
            'appid' => $appId,
            'name' => $this->stringValue($game['name'] ?? null) ?? sprintf('App %d', $appId),
            'playtime_forever' => $this->intValue($game['playtime_forever'] ?? null) ?? 0,
            'playtime_2weeks' => $this->intValue($game['playtime_2weeks'] ?? null) ?? 0,
            'rtime_last_played' => $this->intValue($game['rtime_last_played'] ?? null) ?? 0,
            'img_icon_url' => $iconHash,
            'image' => $this->headerImage($appId),
            'icon' => $this->iconImage($appId, $iconHash),
        ];
    }

    /** @param array<string, mixed> $game @return array<string, mixed> */
    private function publicGame(array $game): array
    {
        return [
            'app_id' => $this->intValue($game['appid'] ?? null) ?? 0,
            'name' => $this->stringValue($game['name'] ?? null) ?? 'Unknown game',
            'hours' => round(($this->intValue($game['playtime_forever'] ?? null) ?? 0) / 60, 1),
            'last_played' => $this->intValue($game['rtime_last_played'] ?? null) ?? 0,
            'image' => $this->stringValue($game['image'] ?? null) ?? '',
            'icon' => $this->stringValue($game['icon'] ?? null) ?? '',
        ];
    }

    private function headerImage(int $appId): string
    {
        return sprintf('https://cdn.akamai.steamstatic.com/steam/apps/%d/header.jpg', $appId);
    }

    private function iconImage(int $appId, string $hash): string
    {
        return $hash === '' ? '' : sprintf(
            'https://media.steampowered.com/steamcommunity/public/images/apps/%d/%s.jpg',
            $appId,
            rawurlencode($hash),
        );
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function intValue(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function floatValue(mixed $value): ?float
    {
        return is_float($value) || is_int($value)
            ? (float) $value
            : (is_string($value) && is_numeric($value) ? (float) $value : null);
    }
}
