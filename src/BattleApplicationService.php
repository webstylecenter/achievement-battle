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

        return $snapshot === null ? null : $this->historical->enrich($snapshot);
    }

    /** @return array<string, mixed> */
    public function refresh(): array
    {
        $snapshot = $this->historical->enrich($this->battle->refresh());
        $this->cache->write(self::SNAPSHOT_KEY, $snapshot);

        return $snapshot;
    }
}
