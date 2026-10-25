# Achievement Battle

A one-page PHP dashboard that compares the Steam achievements of Peter van Dam and Florian.

## What it includes

- Full-screen battle reveal with animated counters and progress bars.
- 4.2-second accelerating reveal and winner confetti.
- Total counted achievements, perfect games, games with achievements and included playtime.
- Recently played games with Steam artwork.
- Shared-game comparison with achievement progress and playtime.
- Rarest unlocked achievements based on Steam global achievement percentages.
- Game exclusions through `config/config.php` only.
- Disk caching so the public page does not hit hundreds of Steam API endpoints on every visit.
- DDEV configuration.

Cookie Clicker (Steam AppID `1454400`) is excluded by default.

## Requirements

- DDEV
- A Steam Web API key
- Both Steam profiles must expose their game details publicly enough for `GetOwnedGames` / achievement data.

Create a Steam Web API key at Steam's Web API registration page:

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

The first refresh is the slowest because achievement data and global rarity percentages have to be fetched. Later refreshes reuse per-game achievement data whenever Steam reports that the player's total playtime for that game has not changed.

## Refreshing data

Run manually:

```bash
ddev exec php bin/refresh.php
```

For production, run the same command from cron every 15-30 minutes. The website only serves the last completed snapshot, so normal visitors never trigger a large Steam refresh themselves.

Example cron entry from the project directory:

```cron
*/20 * * * * /usr/bin/php /var/www/achievement-battle/bin/refresh.php >/dev/null 2>&1
```

Use the actual project path and PHP binary on your server.

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

Excluded games are removed from achievement totals, perfect-game counts, playtime, recent games, shared-game comparisons, and rarity rankings.

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
