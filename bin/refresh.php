<?php

declare(strict_types=1);

use AchievementBattle\IncrementalBattleService;

/** @var IncrementalBattleService $battle */
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
    }
}

$sync = $snapshot['sync'] ?? null;

if (is_array($sync)) {
    $pending = is_int($sync['pending_games'] ?? null) ? $sync['pending_games'] : 0;
    $missing = is_int($sync['missing_games'] ?? null) ? $sync['missing_games'] : 0;
    $processed = is_int($sync['processed_this_run'] ?? null) ? $sync['processed_this_run'] : 0;
    $cooldownUntil = is_int($sync['rate_limited_until'] ?? null) ? $sync['rate_limited_until'] : 0;

    fwrite(
        STDOUT,
        sprintf(
            "Sync progress: %d processed this run, %d pending, %d without initial data.\n",
            $processed,
            $pending,
            $missing,
        ),
    );

    if ($cooldownUntil > time()) {
        fwrite(
            STDOUT,
            sprintf("Steam rate limit cooldown until %s. Re-running before then keeps current progress.\n", gmdate(DATE_ATOM, $cooldownUntil)),
        );
    } elseif ($pending > 0) {
        fwrite(STDOUT, "Run this command again to continue from the remaining games.\n");
    }
}

fwrite(STDOUT, "Done.\n");
