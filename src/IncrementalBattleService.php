<?php

declare(strict_types=1);

namespace AchievementBattle;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class IncrementalBattleService
{
    private const SNAPSHOT_KEY = 'battle-snapshot';
    private const RATE_LIMIT_KEY = 'steam-rate-limit';

    public function __construct(
        private readonly AppConfig $config,
        private readonly SteamApiClient $steam,
        private readonly CacheRepository $cache,
        private readonly BattleService $battle,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function cachedSnapshot(): ?array
    {
        return $this->cache->read(self::SNAPSHOT_KEY);
    }

    /** @return array<string, mixed> */
    public function refresh(): array
    {
        $snapshot = $this->cachedSnapshot();
        $cooldownUntil = $this->cooldownUntil();

        if ($cooldownUntil !== null && $snapshot !== null) {
            $snapshot['sync'] = $this->withCooldown($snapshot['sync'] ?? null, $cooldownUntil);
            $this->cache->write(self::SNAPSHOT_KEY, $snapshot);

            return $snapshot;
        }

        $contexts = $this->contexts();
        $queue = $this->queue($contexts);
        $processed = 0;
        $rateLimited = false;

        foreach (array_slice($queue, 0, $this->maxGamesPerRun()) as $item) {
            $result = $this->fetchAchievements($item['steam_id'], $item['app_id']);

            if ($result['rate_limited']) {
                $cooldownUntil = $this->startCooldown();
                $rateLimited = true;
                break;
            }

            if ($result['private']) {
                throw new RuntimeException(sprintf(
                    'Steam achievement data for %s is private. Set Game details to Public on Steam.',
                    $item['player_key'],
                ));
            }

            if ($result['data'] !== null) {
                $this->cache->write(
                    $this->achievementCacheKey($item['player_key'], $item['app_id']),
                    ['game_fingerprint' => $item['fingerprint'], 'data' => $result['data']],
                );
                $processed++;
            }

            $delay = $this->config->steamBatchDelayMilliseconds();

            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }

        $status = $this->status($contexts);
        $sync = [
            'complete' => $status['pending'] === 0,
            'initial_complete' => $status['missing'] === 0,
            'pending_games' => $status['pending'],
            'missing_games' => $status['missing'],
            'processed_this_run' => $processed,
            'rate_limited' => $rateLimited,
            'rate_limited_until' => $cooldownUntil,
        ];

        if ($status['pending'] === 0) {
            $snapshot = $this->battle->refresh();
            $snapshot['sync'] = $sync;
            $this->cache->write(self::SNAPSHOT_KEY, $snapshot);

            return $snapshot;
        }

        if ($snapshot !== null && $this->wasInitiallyComplete($snapshot)) {
            $snapshot['sync'] = $sync;
            $this->cache->write(self::SNAPSHOT_KEY, $snapshot);

            return $snapshot;
        }

        $snapshot = $this->partialSnapshot($contexts, $status, $sync);
        $this->cache->write(self::SNAPSHOT_KEY, $snapshot);

        return $snapshot;
    }

    /**
     * @return array<string, array{
     *     config: array<string, string>,
     *     steam_id: string,
     *     summary: array<string, mixed>,
     *     games: array<int, array<string, mixed>>
     * }>
     */
    private function contexts(): array
    {
        $contexts = [];
        $excluded = $this->config->excludedAppIds();

        foreach ($this->config->players() as $player) {
            $steamId = $player['profile_type'] === 'vanity'
                ? $this->steam->resolveVanityUrl($player['profile'])
                : $player['profile'];
            $games = [];

            foreach ($this->steam->ownedGames($steamId) as $game) {
                $appId = $this->int($game['appid'] ?? null);

                if ($appId !== null && !isset($excluded[$appId])) {
                    $games[$appId] = $game;
                }
            }

            $contexts[$player['key']] = [
                'config' => $player,
                'steam_id' => $steamId,
                'summary' => $this->steam->playerSummary($steamId),
                'games' => $games,
            ];
        }

        return $contexts;
    }

    /**
     * @param array<string, array{config: array<string, string>, steam_id: string, summary: array<string, mixed>, games: array<int, array<string, mixed>>}> $contexts
     * @return list<array{player_key: string, steam_id: string, app_id: int, fingerprint: string, last_played: int}>
     */
    private function queue(array $contexts): array
    {
        $queue = [];

        foreach ($contexts as $playerKey => $context) {
            foreach ($context['games'] as $appId => $game) {
                if (($this->int($game['playtime_forever'] ?? null) ?? 0) <= 0) {
                    continue;
                }

                $fingerprint = $this->fingerprint($game);
                $cached = $this->cache->read($this->achievementCacheKey($playerKey, $appId));

                if ($this->cacheMatches($cached, $fingerprint)) {
                    continue;
                }

                $queue[] = [
                    'player_key' => $playerKey,
                    'steam_id' => $context['steam_id'],
                    'app_id' => $appId,
                    'fingerprint' => $fingerprint,
                    'last_played' => $this->int($game['rtime_last_played'] ?? null) ?? 0,
                ];
            }
        }

        usort($queue, static fn (array $a, array $b): int => $b['last_played'] <=> $a['last_played']);

        return $queue;
    }

    /**
     * @param array<string, array{config: array<string, string>, steam_id: string, summary: array<string, mixed>, games: array<int, array<string, mixed>>}> $contexts
     * @return array{pending: int, missing: int, players: array<string, array{pending: int, missing: int}>}
     */
    private function status(array $contexts): array
    {
        $pending = 0;
        $missing = 0;
        $players = [];

        foreach ($contexts as $playerKey => $context) {
            $playerPending = 0;
            $playerMissing = 0;

            foreach ($context['games'] as $appId => $game) {
                if (($this->int($game['playtime_forever'] ?? null) ?? 0) <= 0) {
                    continue;
                }

                $fingerprint = $this->fingerprint($game);
                $cached = $this->cache->read($this->achievementCacheKey($playerKey, $appId));

                if ($this->cacheMatches($cached, $fingerprint)) {
                    continue;
                }

                $playerPending++;

                if (!is_array($cached) || !is_array($cached['data'] ?? null)) {
                    $playerMissing++;
                }
            }

            $players[$playerKey] = ['pending' => $playerPending, 'missing' => $playerMissing];
            $pending += $playerPending;
            $missing += $playerMissing;
        }

        return ['pending' => $pending, 'missing' => $missing, 'players' => $players];
    }

    /** @return array{data: array<string, mixed>|null, rate_limited: bool, private: bool} */
    private function fetchAchievements(string $steamId, int $appId): array
    {
        $url = 'https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v1/?' . http_build_query([
            'key' => $this->config->steamApiKey(),
            'steamid' => $steamId,
            'appid' => $appId,
            'l' => $this->config->steamLanguage(),
            'format' => 'json',
        ], '', '&', PHP_QUERY_RFC3986);

        for ($attempt = 1; $attempt <= $this->config->steamRetryCount(); $attempt++) {
            [$status, $response] = $this->request($url);
            $error = $this->playerStatsError($response);

            if ($status === 401) {
                throw new RuntimeException('Steam rejected STEAM_API_KEY while loading achievements.');
            }

            if ($status === 429 || ($status === 403 && $error === '')) {
                return ['data' => null, 'rate_limited' => true, 'private' => false];
            }

            if ($status === 403 && str_contains($error, 'profile is not public')) {
                return ['data' => null, 'rate_limited' => false, 'private' => true];
            }

            $normalized = $this->normalizeAchievements($response);

            if ($normalized !== null) {
                return ['data' => $normalized, 'rate_limited' => false, 'private' => false];
            }

            if (in_array($status, [400, 404], true)) {
                return ['data' => $this->emptyAchievements(), 'rate_limited' => false, 'private' => false];
            }

            if (($status > 0 && $status < 500) || $attempt === $this->config->steamRetryCount()) {
                break;
            }

            usleep(min(8000, 500 * (2 ** ($attempt - 1))) * 1000);
        }

        return ['data' => null, 'rate_limited' => false, 'private' => false];
    }

    /** @return array{0: int, 1: array<string, mixed>|null} */
    private function request(string $url): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'AchievementBattle/1.3',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return [$status, is_array($decoded) ? $decoded : null];
    }

    /** @param array<string, mixed>|null $response */
    private function normalizeAchievements(?array $response): ?array
    {
        $stats = is_array($response['playerstats'] ?? null) ? $response['playerstats'] : null;

        if (!is_array($stats)) {
            return null;
        }

        if (($stats['success'] ?? null) !== true) {
            $error = $this->playerStatsError($response);

            return str_contains($error, 'no stats') || str_contains($error, 'has no achievements')
                ? $this->emptyAchievements()
                : null;
        }

        $items = is_array($stats['achievements'] ?? null) ? $stats['achievements'] : [];
        $achievements = [];
        $unlocked = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $achieved = ($this->int($item['achieved'] ?? null) ?? 0) === 1;
            $unlocked += $achieved ? 1 : 0;
            $apiName = is_string($item['apiname'] ?? null) ? $item['apiname'] : '';
            $achievements[] = [
                'api_name' => $apiName,
                'name' => is_string($item['name'] ?? null) ? $item['name'] : ($apiName !== '' ? $apiName : 'Unknown achievement'),
                'description' => is_string($item['description'] ?? null) ? $item['description'] : '',
                'achieved' => $achieved,
                'unlock_time' => $this->int($item['unlocktime'] ?? null) ?? 0,
            ];
        }

        return ['total' => count($achievements), 'unlocked' => $unlocked, 'achievements' => $achievements];
    }

    /** @return array{total: int, unlocked: int, achievements: array<int, never>} */
    private function emptyAchievements(): array
    {
        return ['total' => 0, 'unlocked' => 0, 'achievements' => []];
    }

    /**
     * @param array<string, array{config: array<string, string>, steam_id: string, summary: array<string, mixed>, games: array<int, array<string, mixed>>}> $contexts
     * @param array{pending: int, missing: int, players: array<string, array{pending: int, missing: int}>} $status
     * @param array<string, mixed> $sync
     * @return array<string, mixed>
     */
    private function partialSnapshot(array $contexts, array $status, array $sync): array
    {
        $competition = $this->competition($contexts);
        $players = [];

        foreach ($contexts as $key => $context) {
            $players[] = $this->partialPlayer($key, $context, $competition, $status['players'][$key]);
        }

        return [
            'generated_at' => gmdate(DATE_ATOM),
            'competition' => $competition,
            'sync' => $sync,
            'players' => $players,
            'shared_games' => [],
            'excluded_games' => array_map(
                static fn (string $name, int $appId): array => ['app_id' => $appId, 'name' => $name],
                array_values($this->config->excludedAppIds()),
                array_keys($this->config->excludedAppIds()),
            ),
        ];
    }

    /**
     * @param array{config: array<string, string>, steam_id: string, summary: array<string, mixed>, games: array<int, array<string, mixed>>} $context
     * @param array<string, mixed> $competition
     * @param array{pending: int, missing: int} $status
     * @return array<string, mixed>
     */
    private function partialPlayer(string $key, array $context, array $competition, array $status): array
    {
        $lifetime = 0;
        $battle = 0;
        $perfect = 0;
        $battlePerfect = 0;
        $gamesWithAchievements = 0;
        $minutes = 0;
        $start = $this->int($competition['timestamp'] ?? null);
        $sourcePlayer = ($competition['source_player'] ?? null) === $key;

        foreach ($context['games'] as $appId => $game) {
            $minutes += $this->int($game['playtime_forever'] ?? null) ?? 0;
            $cached = $this->cache->read($this->achievementCacheKey($key, $appId));
            $data = is_array($cached) && is_array($cached['data'] ?? null) ? $cached['data'] : null;
            $total = is_array($data) ? ($this->int($data['total'] ?? null) ?? 0) : 0;

            if ($total <= 0 || !is_array($data)) {
                continue;
            }

            $gamesWithAchievements++;
            $unlocked = $this->int($data['unlocked'] ?? null) ?? 0;
            $isPerfect = $unlocked === $total;
            $lifetime += $unlocked;
            $perfect += $isPerfect ? 1 : 0;

            if ($start === null || $sourcePlayer) {
                $battle += $unlocked;
                $battlePerfect += $isPerfect ? 1 : 0;
                continue;
            }

            $latestUnlock = 0;

            foreach (($data['achievements'] ?? []) as $achievement) {
                if (!is_array($achievement) || ($achievement['achieved'] ?? false) !== true) {
                    continue;
                }

                $unlockTime = $this->int($achievement['unlock_time'] ?? null) ?? 0;
                $latestUnlock = max($latestUnlock, $unlockTime);
                $battle += $unlockTime >= $start ? 1 : 0;
            }

            $battlePerfect += $isPerfect && $latestUnlock >= $start ? 1 : 0;
        }

        return [
            'key' => $key,
            'name' => $context['config']['display_name'],
            'steam_name' => is_string($context['summary']['personaname'] ?? null) ? $context['summary']['personaname'] : $context['config']['display_name'],
            'steam_id' => $context['steam_id'],
            'avatar' => is_string($context['summary']['avatarfull'] ?? null) ? $context['summary']['avatarfull'] : '',
            'fighter_image' => $context['config']['fighter_image'],
            'profile_url' => is_string($context['summary']['profileurl'] ?? null) ? $context['summary']['profileurl'] : '',
            'achievement_count' => $battle,
            'lifetime_achievement_count' => $lifetime,
            'completed_games' => $battlePerfect,
            'lifetime_completed_games' => $perfect,
            'games_with_achievements' => $gamesWithAchievements,
            'games_included' => count($context['games']),
            'hours_played' => round($minutes / 60, 1),
            'recent_games' => $this->recentGames($context['games']),
            'rare_achievements' => [],
            'sync' => ['pending_games' => $status['pending'], 'missing_games' => $status['missing']],
        ];
    }

    /** @param array<int, array<string, mixed>> $games */
    private function recentGames(array $games): array
    {
        uasort($games, fn (array $a, array $b): int => ($this->int($b['rtime_last_played'] ?? null) ?? 0) <=> ($this->int($a['rtime_last_played'] ?? null) ?? 0));
        $result = [];

        foreach ($games as $appId => $game) {
            if (($this->int($game['playtime_forever'] ?? null) ?? 0) <= 0) {
                continue;
            }

            $icon = is_string($game['img_icon_url'] ?? null) ? $game['img_icon_url'] : '';
            $result[] = [
                'app_id' => $appId,
                'name' => is_string($game['name'] ?? null) ? $game['name'] : sprintf('App %d', $appId),
                'hours' => round(($this->int($game['playtime_forever'] ?? null) ?? 0) / 60, 1),
                'last_played' => $this->int($game['rtime_last_played'] ?? null) ?? 0,
                'image' => sprintf('https://cdn.akamai.steamstatic.com/steam/apps/%d/header.jpg', $appId),
                'icon' => $icon === '' ? '' : sprintf('https://media.steampowered.com/steamcommunity/public/images/apps/%d/%s.jpg', $appId, rawurlencode($icon)),
            ];

            if (count($result) >= $this->config->recentGameCount()) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param array<string, array{config: array<string, string>, steam_id: string, summary: array<string, mixed>, games: array<int, array<string, mixed>>}> $contexts
     * @return array{mode: string, timestamp: int|null, starts_at: string|null, source_player: string|null}
     */
    private function competition(array $contexts): array
    {
        $mode = $this->config->achievementStartMode();

        if ($mode === 'all_time') {
            return ['mode' => $mode, 'timestamp' => null, 'starts_at' => null, 'source_player' => null];
        }

        if ($mode === 'date') {
            $date = $this->config->achievementStartDate();
            $value = $date !== null ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC')) : false;

            if ($value === false) {
                throw new RuntimeException('Unable to parse achievement_start.date.');
            }

            return ['mode' => $mode, 'timestamp' => $value->getTimestamp(), 'starts_at' => $value->format(DATE_ATOM), 'source_player' => null];
        }

        $playerKey = $this->config->achievementStartPlayerKey();
        $timestamp = $playerKey !== null && isset($contexts[$playerKey])
            ? $this->int($contexts[$playerKey]['summary']['timecreated'] ?? null)
            : null;

        if ($playerKey === null || $timestamp === null || $timestamp <= 0) {
            throw new RuntimeException('Unable to determine the configured player account creation date.');
        }

        return ['mode' => $mode, 'timestamp' => $timestamp, 'starts_at' => gmdate(DATE_ATOM, $timestamp), 'source_player' => $playerKey];
    }

    /** @param array<string, mixed>|null $response */
    private function playerStatsError(?array $response): string
    {
        $stats = is_array($response['playerstats'] ?? null) ? $response['playerstats'] : null;

        return is_array($stats) && is_string($stats['error'] ?? null) ? strtolower($stats['error']) : '';
    }

    /** @param array<string, mixed>|null $cached */
    private function cacheMatches(?array $cached, string $fingerprint): bool
    {
        return is_array($cached)
            && is_array($cached['data'] ?? null)
            && ($cached['game_fingerprint'] ?? null) === $fingerprint;
    }

    /** @param array<string, mixed> $game */
    private function fingerprint(array $game): string
    {
        return sprintf('%d:%d', $this->int($game['playtime_forever'] ?? null) ?? 0, $this->int($game['rtime_last_played'] ?? null) ?? 0);
    }

    private function achievementCacheKey(string $playerKey, int $appId): string
    {
        return sprintf('achievements-%s-%d', $playerKey, $appId);
    }

    private function maxGamesPerRun(): int
    {
        return max(1, min(100, $this->envInt('STEAM_MAX_GAMES_PER_RUN', 20)));
    }

    private function cooldownSeconds(): int
    {
        return max(60, min(86400, $this->envInt('STEAM_RATE_LIMIT_COOLDOWN_SECONDS', 1800)));
    }

    private function envInt(string $name, int $default): int
    {
        $runtime = getenv($name);

        if (is_string($runtime) && filter_var($runtime, FILTER_VALIDATE_INT) !== false) {
            return (int) $runtime;
        }

        $path = $this->config->rootDirectory() . '/.env';
        $values = is_file($path) ? parse_ini_file($path, false, INI_SCANNER_RAW) : false;
        $value = is_array($values) ? ($values[$name] ?? null) : null;

        return is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : $default;
    }

    private function cooldownUntil(): ?int
    {
        $state = $this->cache->read(self::RATE_LIMIT_KEY);
        $until = is_array($state) ? $this->int($state['until'] ?? null) : null;

        return $until !== null && $until > time() ? $until : null;
    }

    private function startCooldown(): int
    {
        $until = time() + $this->cooldownSeconds();
        $this->cache->write(self::RATE_LIMIT_KEY, ['until' => $until]);

        return $until;
    }

    /** @param array<string, mixed> $snapshot */
    private function wasInitiallyComplete(array $snapshot): bool
    {
        $sync = $snapshot['sync'] ?? null;

        return !is_array($sync) || ($sync['initial_complete'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    private function withCooldown(mixed $sync, int $until): array
    {
        $result = is_array($sync) ? $sync : [];
        $result['rate_limited'] = true;
        $result['rate_limited_until'] = $until;

        return $result;
    }

    private function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
