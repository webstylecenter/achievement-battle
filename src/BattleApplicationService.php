<?php

declare(strict_types=1);

namespace AchievementBattle;

final class BattleApplicationService
{
    private const SNAPSHOT_KEY = 'battle-snapshot';

    public function __construct(
        private readonly IncrementalBattleService $battle,
        private readonly HistoricalAchievementService $historical,
        private readonly CacheRepository $cache,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function cachedSnapshot(): ?array
    {
        $snapshot = $this->battle->cachedSnapshot();

        if ($snapshot === null || $this->hasHistoricalTotals($snapshot)) {
            return $snapshot;
        }

        return $this->historical->enrich($snapshot);
    }

    /** @return array<string, mixed> */
    public function refresh(): array
    {
        $snapshot = $this->historical->enrich($this->battle->refresh());
        $this->cache->write(self::SNAPSHOT_KEY, $snapshot);

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    private function hasHistoricalTotals(array $snapshot): bool
    {
        $players = $snapshot['players'] ?? null;

        if (!is_array($players) || count($players) !== 2) {
            return false;
        }

        foreach ($players as $player) {
            if (!is_array($player) || !is_int($player['historical_cache_games'] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
