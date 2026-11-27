<?php

declare(strict_types=1);

namespace AchievementBattle;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

final class ConsoleAchievementService
{
    private const PLATFORMS = ['xbox', 'playstation'];

    public function __construct(private readonly AppConfig $config)
    {
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function enrich(array $snapshot): array
    {
        $players = $snapshot['players'] ?? null;

        if (!is_array($players)) {
            return $snapshot;
        }

        $import = $this->loadImport();
        $month = $snapshot['monthly_competition'] ?? null;
        $monthStart = is_array($month) ? $this->timestamp($month['starts_at'] ?? null) : null;
        $monthEnd = is_array($month) ? $this->timestamp($month['ends_at'] ?? null) : null;
        $competition = is_array($snapshot['competition'] ?? null) ? $snapshot['competition'] : [];
        $buckets = [];

        foreach ($players as $index => $player) {
            if (!is_array($player) || !is_string($player['key'] ?? null)) {
                continue;
            }

            $key = $player['key'];
            $playerImports = is_array($import[$key] ?? null) ? $import[$key] : [];
            $sources = ['pc' => $this->pcScore($player)];
            $start = is_int($competition['timestamp'] ?? null) ? $competition['timestamp'] : null;

            if (
                ($competition['mode'] ?? null) === 'player_created'
                && ($competition['source_player'] ?? null) === $key
            ) {
                $start = null;
            }

            foreach (self::PLATFORMS as $platform) {
                $games = $playerImports[$platform] ?? null;
                $score = $this->consoleScore(
                    is_array($games) ? $games : [],
                    $platform,
                    $start,
                    $monthStart,
                    $monthEnd,
                );
                $buckets[$platform][$key] = $score['month_buckets'];
                unset($score['month_buckets']);
                $score['imported'] = is_array($games);
                $sources[$platform] = $score;
            }

            $player['platform_scores'] = $sources;
            $players[$index] = $player;
        }

        $snapshot['players'] = $players;
        $history = is_array($snapshot['monthly_history'] ?? null) ? $snapshot['monthly_history'] : [];
        $snapshot['platform_monthly_histories'] = ['pc' => $history];

        foreach (self::PLATFORMS as $platform) {
            $rows = [];

            foreach ($history as $row) {
                if (!is_array($row) || !is_string($row['starts_at'] ?? null)) {
                    continue;
                }

                $bucket = $this->timestamp($row['starts_at']);

                if ($bucket === null) {
                    continue;
                }

                $scores = [];

                foreach ($players as $player) {
                    if (!is_array($player) || !is_string($player['key'] ?? null)) {
                        continue;
                    }

                    $key = $player['key'];
                    $scores[$key] = $buckets[$platform][$key][$bucket] ?? 0;
                }

                $rows[] = [
                    'starts_at' => $row['starts_at'],
                    'ends_at' => $row['ends_at'] ?? '',
                    'players' => $scores,
                ];
            }

            $snapshot['platform_monthly_histories'][$platform] = $rows;
        }

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadImport(): array
    {
        $path = $this->config->rootDirectory() . '/var/import/console-achievements.json';

        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            error_log('Unable to read console achievement import.');
            return [];
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            error_log('Invalid console achievement import: ' . $exception->getMessage());
            return [];
        }

        if (!is_array($data)) {
            return [];
        }

        $players = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && is_array($value)) {
                $players[$key] = $value;
            }
        }

        return $players;
    }

    /**
     * @param array<string, mixed> $player
     * @return array<string, mixed>
     */
    private function pcScore(array $player): array
    {
        return [
            'achievement_count' => $player['achievement_count'] ?? 0,
            'lifetime_achievement_count' => $player['lifetime_achievement_count'] ?? 0,
            'completed_games' => $player['completed_games'] ?? 0,
            'lifetime_completed_games' => $player['lifetime_completed_games'] ?? 0,
            'games_with_achievements' => $player['games_with_achievements'] ?? 0,
            'hours_played' => $player['hours_played'] ?? 0,
            'monthly_achievement_count' => $player['monthly_achievement_count'] ?? 0,
            'rare_achievements' => $player['rare_achievements'] ?? [],
            'recent_games' => $player['recent_games'] ?? [],
            'imported' => true,
        ];
    }

    /**
     * @param array<mixed> $games
     * @return array{
     *     achievement_count: int,
     *     lifetime_achievement_count: int,
     *     completed_games: int,
     *     lifetime_completed_games: int,
     *     games_with_achievements: int,
     *     hours_played: int,
     *     monthly_achievement_count: int,
     *     month_buckets: array<int, int>,
     *     rare_achievements: list<array<string, mixed>>,
     *     recent_games: list<array<string, mixed>>
     * }
     */
    private function consoleScore(
        array $games,
        string $platform,
        ?int $start,
        ?int $monthStart,
        ?int $monthEnd,
    ): array {
        $score = [
            'achievement_count' => 0,
            'lifetime_achievement_count' => 0,
            'completed_games' => 0,
            'lifetime_completed_games' => 0,
            'games_with_achievements' => 0,
            'hours_played' => 0,
            'monthly_achievement_count' => 0,
            'month_buckets' => [],
            'rare_achievements' => [],
            'recent_games' => [],
        ];
        $seen = [];
        $seenGames = [];
        $now = time();
        $timezone = new DateTimeZone('Europe/Amsterdam');

        foreach ($games as $game) {
            if (!is_array($game)) {
                continue;
            }

            $gameId = $game['id'] ?? null;

            if (!is_string($gameId) || $gameId === '' || isset($seenGames[$gameId])) {
                continue;
            }

            $seenGames[$gameId] = true;
            $gameName = is_string($game['name'] ?? null) ? $game['name'] : 'Unknown game';
            $image = $this->safeImage($game['image'] ?? null);
            $total = is_int($game['total'] ?? null) ? max(0, $game['total']) : 0;
            $achievements = is_array($game['achievements'] ?? null) ? $game['achievements'] : [];
            $unlocked = 0;
            $battleUnlocked = 0;
            $lastUnlocked = 0;

            foreach ($achievements as $achievement) {
                if (!is_array($achievement)) {
                    continue;
                }

                $achievementId = $achievement['id'] ?? null;
                $unlockedAt = $this->timestamp($achievement['unlocked_at'] ?? null);

                if (
                    !is_string($achievementId)
                    || $achievementId === ''
                    || $unlockedAt === null
                    || $unlockedAt <= 0
                    || $unlockedAt > $now
                ) {
                    continue;
                }

                $identity = $gameId . ':' . $achievementId;

                if (isset($seen[$identity])) {
                    continue;
                }

                $seen[$identity] = true;
                $unlocked++;
                $lastUnlocked = max($lastUnlocked, $unlockedAt);
                $score['lifetime_achievement_count']++;
                $countsForBattle = $start === null || $unlockedAt >= $start;
                $battleUnlocked += $countsForBattle ? 1 : 0;
                $score['achievement_count'] += $countsForBattle ? 1 : 0;

                if ($monthStart !== null && $monthEnd !== null && $unlockedAt >= $monthStart && $unlockedAt < $monthEnd) {
                    $score['monthly_achievement_count']++;
                }

                $date = (new DateTimeImmutable('@' . $unlockedAt))->setTimezone($timezone);
                $bucket = $date->modify('first day of this month')->setTime(0, 0, 0)->getTimestamp();
                $score['month_buckets'][$bucket] = ($score['month_buckets'][$bucket] ?? 0) + 1;
                $rarity = $achievement['rarity_percentage'] ?? null;

                if ((is_int($rarity) || is_float($rarity)) && $rarity >= 0 && $rarity <= 100) {
                    $score['rare_achievements'][] = [
                        'game_name' => $gameName,
                        'game_image' => $image,
                        'name' => is_string($achievement['name'] ?? null) ? $achievement['name'] : $achievementId,
                        'percentage' => (float) $rarity,
                        'unlock_time' => $unlockedAt,
                        'platform' => $platform,
                    ];
                }
            }

            if ($total > 0 || $unlocked > 0) {
                $score['games_with_achievements']++;
            }

            if ($total > 0 && $unlocked === $total) {
                $score['lifetime_completed_games']++;

                if ($start === null || $battleUnlocked > 0) {
                    $score['completed_games']++;
                }
            }

            if ($lastUnlocked > 0) {
                $score['recent_games'][] = [
                    'name' => $gameName,
                    'image' => $image,
                    'icon' => '',
                    'hours' => null,
                    'last_played' => $lastUnlocked,
                    'platform' => $platform,
                ];
            }
        }

        usort(
            $score['recent_games'],
            static fn (array $a, array $b): int => $b['last_played'] <=> $a['last_played'],
        );
        $score['recent_games'] = array_slice($score['recent_games'], 0, $this->config->recentGameCount());
        usort(
            $score['rare_achievements'],
            static fn (array $a, array $b): int => ($a['percentage'] <=> $b['percentage'])
                ?: ($b['unlock_time'] <=> $a['unlock_time']),
        );
        $score['rare_achievements'] = array_slice(
            $score['rare_achievements'],
            0,
            $this->config->rareAchievementCount(),
        );

        return $score;
    }

    private function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '' || !preg_match('/(Z|[+-]\d{2}:\d{2})$/', $value)) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    private function safeImage(mixed $image): string
    {
        if (!is_string($image) || !filter_var($image, FILTER_VALIDATE_URL)) {
            return '';
        }

        return parse_url($image, PHP_URL_SCHEME) === 'https' ? $image : '';
    }
}
