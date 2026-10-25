<?php

declare(strict_types=1);

namespace AchievementBattle;

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
        $playerGames = [];
        $playerAchievements = [];
        $excluded = $this->config->excludedAppIds();

        foreach ($this->config->players() as $playerConfig) {
            $steamId = $playerConfig['profile_type'] === 'vanity'
                ? $this->steam->resolveVanityUrl($playerConfig['profile'])
                : $playerConfig['profile'];

            $summary = $this->steam->playerSummary($steamId);
            $ownedGames = $this->steam->ownedGames($steamId);
            $recentGames = $this->steam->recentlyPlayedGames(
                $steamId,
                max(10, $this->config->recentGameCount()),
            );

            $games = [];

            foreach ($ownedGames as $game) {
                $appId = $this->intValue($game['appid'] ?? null);

                if ($appId === null || isset($excluded[$appId])) {
                    continue;
                }

                $games[$appId] = $this->normalizeGame($game);
            }

            $players[$playerConfig['key']] = [
                'key' => $playerConfig['key'],
                'configured_name' => $playerConfig['display_name'],
                'steam_id' => $steamId,
                'steam_name' => $this->stringValue($summary['personaname'] ?? null) ?? $playerConfig['display_name'],
                'avatar' => $this->stringValue($summary['avatarfull'] ?? null) ?? '',
                'profile_url' => $this->stringValue($summary['profileurl'] ?? null) ?? '',
                'recent_games_raw' => $recentGames,
            ];
            $playerGames[$playerConfig['key']] = $games;
            $playerAchievements[$playerConfig['key']] = $this->loadAchievements(
                $playerConfig['key'],
                $steamId,
                $games,
            );
        }

        $this->warmGlobalPercentageCache($playerAchievements);

        $playerOutput = [];

        foreach ($players as $playerKey => $player) {
            $games = $playerGames[$playerKey];
            $achievements = $playerAchievements[$playerKey];
            $playerOutput[$playerKey] = $this->buildPlayerOutput($player, $games, $achievements);
        }

        $playerKeys = array_keys($playerOutput);

        if (count($playerKeys) !== 2) {
            throw new RuntimeException('Exactly two player results are required.');
        }

        $snapshot = [
            'generated_at' => gmdate(DATE_ATOM),
            'players' => [
                $playerOutput[$playerKeys[0]],
                $playerOutput[$playerKeys[1]],
            ],
            'shared_games' => $this->buildSharedGames(
                $playerKeys[0],
                $playerKeys[1],
                $playerGames,
                $playerAchievements,
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
     * @return array<int, array{total: int, unlocked: int, achievements: list<array<string, mixed>>}>
     */
    private function loadAchievements(string $playerKey, string $steamId, array $games): array
    {
        $result = [];
        $requests = [];

        foreach ($games as $appId => $game) {
            $playtimeMinutes = $this->intValue($game['playtime_forever'] ?? null) ?? 0;
            $lastPlayed = $this->intValue($game['rtime_last_played'] ?? null) ?? 0;
            $fingerprint = sprintf('%d:%d', $playtimeMinutes, $lastPlayed);
            $cacheKey = sprintf('achievements-%s-%d', $playerKey, $appId);
            $cached = $this->cache->read($cacheKey);
            $cachedFingerprint = is_array($cached)
                ? $this->stringValue($cached['game_fingerprint'] ?? null)
                : null;
            $cachedData = is_array($cached) && is_array($cached['data'] ?? null) ? $cached['data'] : null;

            if ($cachedFingerprint === $fingerprint && is_array($cachedData)) {
                $result[$appId] = $this->normalizeAchievementPayload($cachedData);
                continue;
            }

            if ($playtimeMinutes <= 0) {
                continue;
            }

            $requests[] = [
                'key' => (string) $appId,
                'steam_id' => $steamId,
                'app_id' => $appId,
            ];
        }

        $responses = $this->steam->playerAchievementsBatch($requests);

        foreach ($requests as $request) {
            $appId = $request['app_id'];
            $response = $responses[$request['key']] ?? null;
            $playerStats = is_array($response) ? ($response['playerstats'] ?? null) : null;
            $success = is_array($playerStats) && ($playerStats['success'] ?? null) === true;
            $achievementList = is_array($playerStats) ? ($playerStats['achievements'] ?? null) : null;

            if ($response === null) {
                continue;
            }

            $data = [
                'success' => $success,
                'achievements' => is_array($achievementList)
                    ? array_values(array_filter($achievementList, 'is_array'))
                    : [],
            ];
            $normalized = $this->normalizeAchievementPayload($data);
            $result[$appId] = $normalized;
            $this->cache->write(sprintf('achievements-%s-%d', $playerKey, $appId), [
                'game_fingerprint' => sprintf(
                    '%d:%d',
                    $this->intValue($games[$appId]['playtime_forever'] ?? null) ?? 0,
                    $this->intValue($games[$appId]['rtime_last_played'] ?? null) ?? 0,
                ),
                'data' => $data,
            ]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{total: int, unlocked: int, achievements: list<array<string, mixed>>}
     */
    private function normalizeAchievementPayload(array $data): ?array
    {
        $achievementList = $data['achievements'] ?? null;

        if (!is_array($achievementList)) {
            $achievementList = [];
        }

        $achievements = [];
        $unlocked = 0;

        foreach ($achievementList as $achievement) {
            if (!is_array($achievement)) {
                continue;
            }

            $achieved = $this->intValue($achievement['achieved'] ?? null) === 1;

            if ($achieved) {
                $unlocked++;
            }

            $achievements[] = [
                'api_name' => $this->stringValue($achievement['apiname'] ?? null) ?? '',
                'name' => $this->stringValue($achievement['name'] ?? null)
                    ?? $this->stringValue($achievement['apiname'] ?? null)
                    ?? 'Unknown achievement',
                'description' => $this->stringValue($achievement['description'] ?? null) ?? '',
                'achieved' => $achieved,
                'unlock_time' => $this->intValue($achievement['unlocktime'] ?? null) ?? 0,
            ];
        }

        return [
            'total' => count($achievements),
            'unlocked' => $unlocked,
            'achievements' => $achievements,
        ];
    }

    /**
     * @param array<string, mixed> $player
     * @param array<int, array<string, mixed>> $games
     * @param array<int, array{total: int, unlocked: int, achievements: list<array<string, mixed>>}> $achievements
     * @return array<string, mixed>
     */
    private function buildPlayerOutput(array $player, array $games, array $achievements): array
    {
        $achievementTotal = 0;
        $completedGames = 0;
        $gamesWithAchievements = 0;
        $playtimeMinutes = 0;

        foreach ($games as $appId => $game) {
            $playtimeMinutes += $this->intValue($game['playtime_forever'] ?? null) ?? 0;
            $gameAchievements = $achievements[$appId] ?? null;

            if ($gameAchievements === null || $gameAchievements['total'] <= 0) {
                continue;
            }

            $gamesWithAchievements++;
            $achievementTotal += $gameAchievements['unlocked'];

            if ($gameAchievements['total'] > 0 && $gameAchievements['unlocked'] === $gameAchievements['total']) {
                $completedGames++;
            }
        }

        return [
            'key' => $player['key'],
            'name' => $player['configured_name'],
            'steam_name' => $player['steam_name'],
            'steam_id' => $player['steam_id'],
            'avatar' => $player['avatar'],
            'profile_url' => $player['profile_url'],
            'achievement_count' => $achievementTotal,
            'completed_games' => $completedGames,
            'games_with_achievements' => $gamesWithAchievements,
            'games_included' => count($games),
            'hours_played' => round($playtimeMinutes / 60, 1),
            'recent_games' => $this->recentGames($games, $player['recent_games_raw']),
            'rare_achievements' => $this->rareAchievements($games, $achievements),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $games
     * @param mixed $recentGamesRaw
     * @return list<array<string, mixed>>
     */
    private function recentGames(array $games, mixed $recentGamesRaw): array
    {
        $recent = [];

        if (is_array($recentGamesRaw)) {
            foreach ($recentGamesRaw as $game) {
                if (!is_array($game)) {
                    continue;
                }

                $appId = $this->intValue($game['appid'] ?? null);

                if ($appId === null || !isset($games[$appId])) {
                    continue;
                }

                $recent[$appId] = $this->publicGame($games[$appId]);
            }
        }

        if (count($recent) < $this->config->recentGameCount()) {
            $fallback = $games;
            uasort(
                $fallback,
                fn (array $left, array $right): int => ($this->intValue($right['rtime_last_played'] ?? null) ?? 0)
                    <=> ($this->intValue($left['rtime_last_played'] ?? null) ?? 0),
            );

            foreach ($fallback as $appId => $game) {
                if (isset($recent[$appId])) {
                    continue;
                }

                $recent[$appId] = $this->publicGame($game);

                if (count($recent) >= $this->config->recentGameCount()) {
                    break;
                }
            }
        }

        return array_slice(array_values($recent), 0, $this->config->recentGameCount());
    }

    /**
     * @param array<int, array<string, mixed>> $games
     * @param array<int, array{total: int, unlocked: int, achievements: list<array<string, mixed>>}> $achievements
     * @return list<array<string, mixed>>
     */
    private function rareAchievements(array $games, array $achievements): array
    {
        $rare = [];

        foreach ($achievements as $appId => $gameAchievements) {
            $unlocked = array_values(array_filter(
                $gameAchievements['achievements'],
                static fn (array $achievement): bool => ($achievement['achieved'] ?? false) === true,
            ));

            if ($unlocked === []) {
                continue;
            }

            $percentages = $this->globalPercentages($appId);

            if ($percentages === []) {
                continue;
            }

            foreach ($unlocked as $achievement) {
                $apiName = $this->stringValue($achievement['api_name'] ?? null);

                if ($apiName === null || !isset($percentages[$apiName])) {
                    continue;
                }

                $game = $games[$appId] ?? [];
                $rare[] = [
                    'app_id' => $appId,
                    'game_name' => $this->stringValue($game['name'] ?? null) ?? 'Unknown game',
                    'game_image' => $this->headerImage($appId),
                    'name' => $this->stringValue($achievement['name'] ?? null) ?? $apiName,
                    'description' => $this->stringValue($achievement['description'] ?? null) ?? '',
                    'percentage' => $percentages[$apiName],
                    'unlock_time' => $this->intValue($achievement['unlock_time'] ?? null) ?? 0,
                ];
            }
        }

        usort(
            $rare,
            static fn (array $left, array $right): int => ($left['percentage'] <=> $right['percentage'])
                ?: (($right['unlock_time'] ?? 0) <=> ($left['unlock_time'] ?? 0)),
        );

        return array_slice($rare, 0, $this->config->rareAchievementCount());
    }

    /**
     * @param array<string, array<int, array{total: int, unlocked: int, achievements: list<array<string, mixed>>}>> $playerAchievements
     */
    private function warmGlobalPercentageCache(array $playerAchievements): void
    {
        $missingAppIds = [];

        foreach ($playerAchievements as $achievementsByGame) {
            foreach ($achievementsByGame as $appId => $gameAchievements) {
                if ($gameAchievements['unlocked'] <= 0) {
                    continue;
                }

                $cacheKey = sprintf('global-percentages-%d', $appId);

                if ($this->cache->read($cacheKey, $this->config->rarityCacheMaxAgeSeconds()) === null) {
                    $missingAppIds[$appId] = $appId;
                }
            }
        }

        if ($missingAppIds === []) {
            return;
        }

        $responses = $this->steam->globalAchievementPercentagesBatch(array_values($missingAppIds));

        foreach ($responses as $appId => $response) {
            if ($response !== null) {
                $this->cache->write(sprintf('global-percentages-%d', $appId), $response);
            }
        }
    }

    /** @return array<string, float> */
    private function globalPercentages(int $appId): array
    {
        $cacheKey = sprintf('global-percentages-%d', $appId);
        $data = $this->cache->read($cacheKey, $this->config->rarityCacheMaxAgeSeconds());

        if ($data === null) {
            return [];
        }

        $achievementPercentages = $data['achievementpercentages'] ?? null;
        $items = is_array($achievementPercentages) ? ($achievementPercentages['achievements'] ?? null) : null;

        if (!is_array($items)) {
            return [];
        }

        $percentages = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = $this->stringValue($item['name'] ?? null);
            $percent = $item['percent'] ?? null;

            if ($name !== null && (is_float($percent) || is_int($percent))) {
                $percentages[$name] = (float) $percent;
            }
        }

        return $percentages;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $playerGames
     * @param array<string, array<int, array{total: int, unlocked: int, achievements: list<array<string, mixed>>}>> $playerAchievements
     * @return list<array<string, mixed>>
     */
    private function buildSharedGames(
        string $leftKey,
        string $rightKey,
        array $playerGames,
        array $playerAchievements,
    ): array {
        $leftGames = $playerGames[$leftKey] ?? [];
        $rightGames = $playerGames[$rightKey] ?? [];
        $sharedAppIds = array_intersect(array_keys($leftGames), array_keys($rightGames));
        $rows = [];

        foreach ($sharedAppIds as $appId) {
            $leftAchievements = $playerAchievements[$leftKey][$appId] ?? null;
            $rightAchievements = $playerAchievements[$rightKey][$appId] ?? null;

            if ($leftAchievements === null && $rightAchievements === null) {
                continue;
            }

            $total = max($leftAchievements['total'] ?? 0, $rightAchievements['total'] ?? 0);

            if ($total <= 0) {
                continue;
            }
            $leftUnlocked = $leftAchievements['unlocked'] ?? 0;
            $rightUnlocked = $rightAchievements['unlocked'] ?? 0;
            $game = $leftGames[$appId];

            $rows[] = [
                'app_id' => $appId,
                'name' => $this->stringValue($game['name'] ?? null) ?? 'Unknown game',
                'image' => $this->headerImage($appId),
                'icon' => $this->iconImage($appId, $this->stringValue($game['img_icon_url'] ?? null) ?? ''),
                'achievement_total' => $total,
                'players' => [
                    $leftKey => [
                        'unlocked' => $leftUnlocked,
                        'percentage' => $total > 0 ? round(($leftUnlocked / $total) * 100, 1) : 0.0,
                        'hours' => round(($this->intValue($leftGames[$appId]['playtime_forever'] ?? null) ?? 0) / 60, 1),
                    ],
                    $rightKey => [
                        'unlocked' => $rightUnlocked,
                        'percentage' => $total > 0 ? round(($rightUnlocked / $total) * 100, 1) : 0.0,
                        'hours' => round(($this->intValue($rightGames[$appId]['playtime_forever'] ?? null) ?? 0) / 60, 1),
                    ],
                ],
                'winner' => $leftUnlocked === $rightUnlocked
                    ? null
                    : ($leftUnlocked > $rightUnlocked ? $leftKey : $rightKey),
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => ($right['achievement_total'] <=> $left['achievement_total'])
                ?: strcmp((string) $left['name'], (string) $right['name']),
        );

        return array_slice($rows, 0, $this->config->sharedGameLimit());
    }

    /** @param array<string, mixed> $game */
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

    /** @param array<string, mixed> $game */
    private function publicGame(array $game): array
    {
        $playtime = $this->intValue($game['playtime_forever'] ?? null) ?? 0;

        return [
            'app_id' => $this->intValue($game['appid'] ?? null) ?? 0,
            'name' => $this->stringValue($game['name'] ?? null) ?? 'Unknown game',
            'hours' => round($playtime / 60, 1),
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
        if ($hash === '') {
            return $this->headerImage($appId);
        }

        return sprintf(
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
}
