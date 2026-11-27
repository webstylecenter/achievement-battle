# Achievement Battle

A one-page PHP dashboard that compares the Steam achievements of Peter van Dam and Florian.

## What it includes

- Full-screen battle reveal with animated counters and progress bars.
- Tekken-style intro: both fighter portraits slide in from the sides and collide with an impact/shake effect.
- 2-second accelerating score reveal and winner confetti.
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

## PlayStation and Xbox achievements

The dashboard combines **Steam (PC), Xbox and PlayStation** achievements. An achievement earned on PC and Xbox counts twice in "All platforms". Each PlayStation trophy counts once, independent of its bronze/silver/gold/platinum grade. Xbox Gamerscore is **not** added to the achievement count.

A separate Node.js CLI importer fetches authorized console data using:
- [psn-api](https://github.com/achievements-app/psn-api) for PlayStation trophies;
- [OpenXBL](https://xbl.io/) for Xbox achievements.

Both are unofficial integrations and may change or require access to the relevant profiles. You must have the players' consent. **The import is not part of the Steam refresh and runs with no console credentials in the webserver.**

### Initial setup

Install Node.js **20 or newer**, then from the repository root:

```bash
npm install --prefix tools/console-importer
mkdir -p var/import
chmod 700 var/import
```

Add the relevant values from `.env.example` to your private `.env` file:

```dotenv
# Use a signed-in PSN account whose trophy permissions allow viewing both players.
PSN_NPSSO=
PSN_PETER_ACCOUNT_ID=
PSN_FLORIAN_ACCOUNT_ID=

# OpenXBL account API key and both players' numeric Xbox XUIDs.
OPENXBL_API_KEY=
XBOX_PETER_XUID=
XBOX_FLORIAN_XUID=
```

For PlayStation:
1. Sign in to your **own** PlayStation account at [playstation.com](https://www.playstation.com/).
2. In that same browser, open [Sony's NPSSO cookie endpoint](https://ca.account.sony.com/api/v1/ssocookie) and put the returned **NPSSO** in `.env`. **This is password-equivalent: never commit it, post it in issues, share it in chat or expose it in the browser.**
3. Set `PSN_PETER_ACCOUNT_ID` and `PSN_FLORIAN_ACCOUNT_ID` to the **numeric account IDs**, not Online IDs. You can use `me` for whichever player is the authenticated account. For another account, the `psn-api` `makeUniversalSearch` function can resolve an Online ID to an account ID if your authorization can see it. Private trophy lists require that player's authorization or a privacy-setting change.
4. The importer exchanges NPSSO for an access/refresh token and saves the **refresh token** privately in `var/import/psn-tokens.json` (mode 0600). After a successful first import, you can remove `PSN_NPSSO` from `.env`. When the refresh token expires, repeat authentication with your own NPSSO.

For Xbox:
1. Sign in to [OpenXBL](https://xbl.io/), obtain your own API key and store it as `OPENXBL_API_KEY` in `.env`.
2. Find each player's numeric **XUID** (not gamertag) through OpenXBL's user search endpoint and set `XBOX_PETER_XUID` and `XBOX_FLORIAN_XUID`.
3. Ensure OpenXBL can access their achievements. Check rate limits and data-sharing/privacy settings.

Only configure the accounts/platforms you want to import. **Empty fields are skipped** and any existing other-platform import is preserved.

Run manually from the project root (requires Node.js 20.6+ for `--env-file`):

```bash
node --env-file=.env tools/console-importer/sync.mjs
```

The importer fetches all PSN titles and trophy groups and follows OpenXBL v3 achievement pagination. For unchanged PSN titles it reuses earlier trophy details to avoid needless API calls. It saves results to `var/import/console-achievements.json` outside the public directory.

- **Atomic update:** if an API call fails, the existing `console-achievements.json` remains intact. No partial score is published.
- **No credential leak:** neither the web API nor its response exposes tokens. The refresh token and import JSON are in gitignored `var/import` with private file permissions.
- **Old Xbox achievements:** achievements with no reliable unlock timestamp still count toward all-time totals. They are excluded from date-limited and monthly totals because their unlock month cannot be determined.
- **Caching:** games that disappear from the API because of visibility changes may not appear in newly imported data; review account privacy settings if totals drop.
- **No imported data:** the website continues showing Steam achievements and marks missing console sources instead of treating them as confirmed zeroes.

### Scheduled console import on Ubuntu

The repository includes a separate systemd service and timer. Adjust the username/path/Node executable in `deploy/achievement-console-import.service` if they differ on your server:

```bash
command -v node
sudo cp deploy/achievement-console-import.service /etc/systemd/system/
sudo cp deploy/achievement-console-import.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now achievement-console-import.timer
sudo systemctl start achievement-console-import.service
sudo journalctl -u achievement-console-import.service -n 80 --no-pager
```

The console timer runs twice daily. The existing Steam timer stays unchanged. The importer is read independently by `/api.php` on each page request, so a separate PHP refresh is unnecessary.

### Tests

```bash
node --test tools/console-importer/sync.test.mjs
php tests/ConsoleAchievementServiceSmokeTest.php
composer analyse
composer phpcs
```

For manual imports, the same schema remains supported; see `examples/console-achievements.example.json`. Every game has an `id`, `name`, optional HTTPS `image`, optional `total`, and an `achievements` array. Every unlocked achievement must have a unique ID per game. A timezone-aware ISO 8601 `unlocked_at` is used for monthly and date-scoped totals; it is optional for historical achievements where the provider does not supply the actual unlock time. Optional `rarity_percentage` (0–100) enables rare achievement entries.

**Limitations:** PSN and OpenXBL are not officially supported by Sony/Microsoft as a general public achievement API. Initial trophy import may require substantial API calls. Never use another player's authentication tokens without their consent.
