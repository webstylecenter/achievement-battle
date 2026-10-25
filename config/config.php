<?php

declare(strict_types=1);

return [
    'players' => [
        [
            'key' => 'peter',
            'display_name' => 'Peter van Dam',
            'profile' => 'webstylecenter',
            'profile_type' => 'vanity',
        ],
        [
            'key' => 'florian',
            'display_name' => 'Florian',
            'profile' => '76561198704122893',
            'profile_type' => 'steamid',
        ],
    ],

    // Games listed here are completely ignored by the battle and comparison statistics.
    // Add/remove Steam AppIDs with nano; no web editor is needed.
    'excluded_app_ids' => [
        1454400 => 'Cookie Clicker',
    ],

    'rare_achievement_count' => 10,
    'recent_game_count' => 4,
    'shared_game_limit' => 100,
];
