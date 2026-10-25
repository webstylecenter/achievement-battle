<?php

declare(strict_types=1);

use AchievementBattle\BattleService;

/** @var BattleService $battle */
$battle = require dirname(__DIR__) . '/src/bootstrap.php';

fwrite(STDOUT, "Refreshing Steam achievement battle data...\n");

try {
    $snapshot = $battle->refresh();
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("Refresh failed: %s\n", $exception->getMessage()));
    exit(1);
}

$competition = $snapshot['competition'] ?? null;

if (is_array($competition) && is_string($competition['starts_at'] ?? null)) {
    fwrite(STDOUT, sprintf("Battle start: %s\n", $competition['starts_at']));
} else {
    fwrite(STDOUT, "Battle start: all time\n");
}

$players = $snapshot['players'] ?? [];

if (is_array($players)) {
    foreach ($players as $player) {
        if (!is_array($player)) {
            continue;
        }

        $name = is_string($player['name'] ?? null) ? $player['name'] : 'Unknown';
        $battleCount = is_int($player['achievement_count'] ?? null) ? $player['achievement_count'] : 0;
        $lifetimeCount = is_int($player['lifetime_achievement_count'] ?? null)
            ? $player['lifetime_achievement_count']
            : 0;
        $perfectGames = is_int($player['completed_games'] ?? null) ? $player['completed_games'] : 0;
        $lifetimePerfectGames = is_int($player['lifetime_completed_games'] ?? null)
            ? $player['lifetime_completed_games']
            : 0;
        fwrite(
            STDOUT,
            sprintf(
                "- %s: %d battle achievements, %d lifetime after exclusions; %d/%d perfect games\n",
                $name,
                $battleCount,
                $lifetimeCount,
                $perfectGames,
                $lifetimePerfectGames,
            ),
        );

        $sync = $player['sync'] ?? null;

        if (!is_array($sync)) {
            continue;
        }

        fwrite(
            STDOUT,
            sprintf(
                "  Sync: %d refreshed, %d exact-cache, %d stale-cache, %d games without achievements\n",
                is_int($sync['refreshed_games'] ?? null) ? $sync['refreshed_games'] : 0,
                is_int($sync['cached_games'] ?? null) ? $sync['cached_games'] : 0,
                is_int($sync['stale_cache_games'] ?? null) ? $sync['stale_cache_games'] : 0,
                is_int($sync['no_achievement_games'] ?? null) ? $sync['no_achievement_games'] : 0,
            ),
        );
    }
}

fwrite(STDOUT, "Done.\n");
