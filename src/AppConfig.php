<?php

declare(strict_types=1);

namespace AchievementBattle;

use DateTimeImmutable;
use RuntimeException;

final class AppConfig
{
    /** @var array<string, mixed> */
    private array $settings;

    /** @var array<string, string> */
    private array $env;

    public function __construct(private readonly string $rootDirectory)
    {
        $settings = require $rootDirectory . '/config/config.php';

        if (!is_array($settings)) {
            throw new RuntimeException('config/config.php must return an array.');
        }

        /** @var array<string, mixed> $settings */
        $this->settings = $settings;
        $this->env = $this->loadEnv($rootDirectory . '/.env');
    }

    public function rootDirectory(): string
    {
        return $this->rootDirectory;
    }

    public function steamApiKey(): string
    {
        return $this->envString('STEAM_API_KEY');
    }

    public function steamLanguage(): string
    {
        return $this->envString('STEAM_LANGUAGE', 'english');
    }

    public function steamConcurrency(): int
    {
        return max(1, min(6, $this->envInt('STEAM_CONCURRENCY', 2)));
    }

    public function steamBatchDelayMilliseconds(): int
    {
        return max(0, min(5000, $this->envInt('STEAM_BATCH_DELAY_MS', 350)));
    }

    public function steamRetryCount(): int
    {
        return max(1, min(8, $this->envInt('STEAM_RETRY_COUNT', 5)));
    }

    public function rarityCacheMaxAgeSeconds(): int
    {
        return max(3600, $this->envInt('RARITY_CACHE_MAX_AGE_SECONDS', 604800));
    }

    /**
     * @return list<array{key: string, display_name: string, profile: string, profile_type: string, fighter_image: string}>
     */
    public function players(): array
    {
        $players = $this->settings['players'] ?? null;

        if (!is_array($players) || count($players) !== 2) {
            throw new RuntimeException('Exactly two players must be configured.');
        }

        $normalizedPlayers = [];

        foreach ($players as $player) {
            if (!is_array($player)) {
                throw new RuntimeException('Each player configuration must be an array.');
            }

            $key = $player['key'] ?? null;
            $displayName = $player['display_name'] ?? null;
            $profile = $player['profile'] ?? null;
            $profileType = $player['profile_type'] ?? null;
            $fighterImage = $player['fighter_image'] ?? null;

            if (
                !is_string($key)
                || !is_string($displayName)
                || !is_string($profile)
                || !is_string($profileType)
                || !is_string($fighterImage)
            ) {
                throw new RuntimeException('Invalid player configuration.');
            }

            $normalizedPlayers[] = [
                'key' => $key,
                'display_name' => $displayName,
                'profile' => $profile,
                'profile_type' => $profileType,
                'fighter_image' => $fighterImage,
            ];
        }

        return $normalizedPlayers;
    }

    /** @return array<int, string> */
    public function excludedAppIds(): array
    {
        $excluded = $this->settings['excluded_app_ids'] ?? [];

        if (!is_array($excluded)) {
            throw new RuntimeException('excluded_app_ids must be an array.');
        }

        $normalized = [];

        foreach ($excluded as $appId => $name) {
            if (!is_int($appId) || !is_string($name)) {
                throw new RuntimeException('Excluded games must use integer AppIDs and string names.');
            }

            $normalized[$appId] = $name;
        }

        return $normalized;
    }

    public function achievementStartMode(): string
    {
        $settings = $this->achievementStartSettings();
        $mode = $settings['mode'] ?? 'all_time';

        if (!is_string($mode) || !in_array($mode, ['all_time', 'date', 'player_created'], true)) {
            throw new RuntimeException('achievement_start.mode must be all_time, date or player_created.');
        }

        return $mode;
    }

    public function achievementStartPlayerKey(): ?string
    {
        $settings = $this->achievementStartSettings();
        $value = $settings['player_key'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) || $value === '') {
            throw new RuntimeException('achievement_start.player_key must be a non-empty string or null.');
        }

        return $value;
    }

    public function achievementStartDate(): ?string
    {
        $settings = $this->achievementStartSettings();
        $value = $settings['date'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value) || $value === '') {
            throw new RuntimeException('achievement_start.date must be YYYY-MM-DD or null.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('achievement_start.date must use the YYYY-MM-DD format.');
        }

        return $value;
    }

    public function rareAchievementCount(): int
    {
        return max(0, $this->settingInt('rare_achievement_count', 10));
    }

    public function recentGameCount(): int
    {
        return max(1, $this->settingInt('recent_game_count', 4));
    }

    public function sharedGameLimit(): int
    {
        return max(1, $this->settingInt('shared_game_limit', 16));
    }

    public function sharedGamesRequireBothPlayed(): bool
    {
        return $this->settingBool('shared_games_require_both_played', true);
    }

    public function sharedGamesExcludeCompletedByEither(): bool
    {
        return $this->settingBool('shared_games_exclude_completed_by_either', true);
    }

    /** @return array<string, mixed> */
    private function achievementStartSettings(): array
    {
        $settings = $this->settings['achievement_start'] ?? [];

        if (!is_array($settings)) {
            throw new RuntimeException('achievement_start must be an array.');
        }

        /** @var array<string, mixed> $settings */
        return $settings;
    }

    /** @return array<string, string> */
    private function loadEnv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $values = parse_ini_file($path, false, INI_SCANNER_RAW);

        if ($values === false) {
            throw new RuntimeException('Unable to parse .env.');
        }

        $env = [];

        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $env[$key] = trim($value, " \t\n\r\0\x0B\"'");
            }
        }

        return $env;
    }

    private function envString(string $name, string $default = ''): string
    {
        $runtimeValue = getenv($name);

        if ($runtimeValue !== false && $runtimeValue !== '') {
            return $runtimeValue;
        }

        return $this->env[$name] ?? $default;
    }

    private function envInt(string $name, int $default): int
    {
        $value = $this->envString($name);

        if ($value === '') {
            return $default;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($validated) ? $validated : $default;
    }

    private function settingInt(string $name, int $default): int
    {
        $value = $this->settings[$name] ?? $default;

        return is_int($value) ? $value : $default;
    }

    private function settingBool(string $name, bool $default): bool
    {
        $value = $this->settings[$name] ?? $default;

        return is_bool($value) ? $value : $default;
    }
}
