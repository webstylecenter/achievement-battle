# Achievement Battle

A one-page PHP dashboard that compares the Steam achievements of Peter van Dam and Florian.

## What it includes

- Full-screen battle reveal with animated counters and progress bars.
- Tekken-style intro: both fighter portraits slide in from the sides and collide with an impact/shake effect.
- 4.2-second accelerating score reveal and winner confetti.
- A battle score that can start at a configured date or at a player's Steam account creation date.
- Lifetime achievement totals after exclusions for comparison/debugging.
- Perfect-game counts derived from the achievement data returned by Steam.
- Recently played games with Steam artwork and a local fallback image when Steam artwork is unavailable.
- Shared-game comparison focused on unfinished games both players actually played, sorted by recent activity.
- Rarest unlocked achievements when Steam returns global rarity data; the whole section disappears when it does not.
- Game exclusions and fighter portraits configured through `config/config.php` only.
- Disk caching plus conservative retry/backoff for Steam API rate limiting.
- DDEV configuration.

Cookie Clicker (Steam AppID `1454400`) is excluded by default.

## Requirements

- DDEV
- A Steam Web API key
- Both Steam profiles must expose their game details publicly enough for Steam's player/game APIs.

Create a Steam Web API key at:

https://steamcommunity.com/dev/apikey

Do not expose the key in JavaScript or commit it to Git.

## Local setup

```bash
cp .env.example .env
nano .env
```

Set:

```dotenv
STEAM_API_KEY=your_key_here
```

Then:

```bash
ddev start
ddev exec php bin/refresh.php
ddev launch
```

The first refresh is the heaviest one because Steam exposes player achievement details per game. Later refreshes reuse the cached result until a game's playtime or last-played timestamp changes.

## Achievement sync

The application deliberately uses `ISteamUserStats/GetPlayerAchievements` for the actual achievement data. `IPlayerService/GetAchievementsProgress` looks attractive because it is batched, but it requires Steam user authentication in situations where a normal Web API key receives an unauthorized response. Treating that response as an empty list was the cause of the earlier `0/0` snapshot.

The sync now behaves defensively:

- requests are sent with low concurrency and a delay between batches;
- network failures, HTTP 429 and server errors are retried with backoff;
- unchanged games are loaded from disk cache;
- when a changed game temporarily fails, its previous cached achievement data can be retained and is reported as stale;
- if Steam fails for a game for which no usable cache exists, the refresh fails and the previous battle snapshot remains untouched.

This is intentionally slower on the first run, but it prevents a partial Steam response from silently becoming a low or zero score.

## Competition start date

The default is the creation date of Florian's Steam account:

```php
'achievement_start' => [
    'mode' => 'player_created',
    'player_key' => 'florian',
    'date' => null,
],
```

To count everything:

```php
'achievement_start' => [
    'mode' => 'all_time',
    'player_key' => null,
    'date' => null,
],
```

To use a fixed date:

```php
'achievement_start' => [
    'mode' => 'date',
    'player_key' => null,
    'date' => '2024-01-01',
],
```

With a start date enabled, the main score counts only achievements whose Steam unlock timestamp is on or after that date. Lifetime totals after exclusions remain visible lower on the page.

## Excluding games

Edit `config/config.php`:

```php
'excluded_app_ids' => [
    1454400 => 'Cookie Clicker',
    123456 => 'Another game',
],
```

Then refresh:

```bash
ddev exec php bin/refresh.php
```

Excluded games are removed from battle/lifetime achievement totals, perfect-game counts, playtime, recent games, shared-game comparisons and rarity rankings.

## Fighter portraits

Portraits are also controlled from `config/config.php`:

```php
'fighter_image' => '/assets/fighters/peter.webp',
```

The default assets live in `public/assets/fighters/`.

## Shared games

By default the shared-games section:

- only includes games both players have actually played;
- hides a game when either player has already reached 100%;
- sorts by the most recent `last played` value from either player;
- is limited to 16 rows.

These settings are editable in `config/config.php`.

## Steam sync settings

`.env` contains conservative defaults:

```dotenv
STEAM_CONCURRENCY=2
STEAM_BATCH_DELAY_MS=350
STEAM_RETRY_COUNT=5
RARITY_CACHE_MAX_AGE_SECONDS=604800
```

The first refresh can therefore take noticeably longer than subsequent refreshes. That is intentional to avoid Steam rate limits.

## Refreshing data

Run manually:

```bash
ddev exec php bin/refresh.php
```

The CLI output prints both the battle score and the lifetime count after exclusions, together with cache/refresh statistics.

For production, run the same command from cron every 15-30 minutes. The website only serves the last successfully completed snapshot.

Example:

```cron
*/20 * * * * /usr/bin/php /var/www/achievement-battle/bin/refresh.php >/dev/null 2>&1
```

## Development checks

Install dev tools:

```bash
ddev composer install
```

Then:

```bash
ddev composer analyse
ddev composer phpcs
```
