<?php

declare(strict_types=1);

return [
    'players' => [
        [
            'key' => 'peter',
            'display_name' => 'Peter van Dam',
            'profile' => 'webstylecenter',
            'profile_type' => 'vanity',
            'fighter_image' => '/assets/fighters/peter.webp',
        ],
        [
            'key' => 'florian',
            'display_name' => 'Florian',
            'profile' => '76561198704122893',
            'profile_type' => 'steamid',
            'fighter_image' => '/assets/fighters/florian.webp',
        ],
    ],

    // Games listed here are completely ignored by the battle and comparison statistics.
    // Add/remove Steam AppIDs with nano; no web editor is needed.
    'excluded_app_ids' => [
        1454400 => 'Cookie Clicker',
    ],

    // Battle achievements are counted from this point onward.
    // Supported modes:
    // - player_created: use the Steam account creation date of player_key.
    // - date: use the explicit YYYY-MM-DD date below.
    // - all_time: count every unlocked achievement.
    'achievement_start' => [
        'mode' => 'player_created',
        'player_key' => 'florian',
        'date' => null,
    ],

    'rare_achievement_count' => 10,
    'recent_game_count' => 4,

    // Shared games are deliberately focused on active comparisons rather than the full shared library.
    'shared_game_limit' => 16,
    'shared_games_require_both_played' => true,
    'shared_games_exclude_completed_by_either' => true,
];
