# Pairsly - Memory Game (WordPress plugin)

A memory (concentration) game for WordPress: your own card images, three
difficulty tiers with separate leaderboards, server-verified scores and
optional bot protection (Cloudflare Turnstile, Google reCAPTCHA v2/v3,
hCaptcha). Drop it in with a block, a shortcode, or the dedicated page the
plugin maintains for you.

- Requires WordPress 6.0+, PHP 7.4+. Tested up to WordPress 7.1.
- License: GPLv2 or later.
- Author: [Matic Korošec](https://nextgen-solutions.xyz)

The user-facing description, FAQ and changelog live in [`readme.txt`](readme.txt)
(the WordPress.org format). This file is for people working on the code.

## Layout

```
pairsly-memory-game.php        bootstrap: constants, requires, hooks
includes/
  class-pairsmg-settings.php     one option array, defaults, presets
  class-pairsmg-db.php           scores table (dbDelta), reads/writes
  class-pairsmg-token.php        HMAC session/run tokens, single-use marker
  class-pairsmg-captcha.php      provider abstraction + server-side verify
  class-pairsmg-post-type.php    "Cards" CPT, meta box, cached active pool
  class-pairsmg-deck.php         board selection (specials, own cards, fallback)
  class-pairsmg-scoring.php      the score formula
  class-pairsmg-rest.php         public REST routes + rate limiting
  class-pairsmg-assets.php       registration, theme CSS vars, localized config
  class-pairsmg-shortcode.php    [pairs_memory_game] + shared renderer
  class-pairsmg-block.php        dynamic block wrapper
  class-pairsmg-game-page.php    optional dedicated page at a stable slug
  class-pairsmg-admin-settings.php   tabbed settings screen
  class-pairsmg-admin-leaderboard.php moderation, clear, CSV export
  class-pairsmg-cron.php         daily sweep of expired rate-limit/token transients
templates/game.php           frontend markup (data-pmg hooks, no ids)
assets/js/game.js            frontend logic (no build step, ES5)
assets/css/game.css          scoped styles, all colours via --pmg-* vars
assets/cards/*.svg           built-in 16-card fallback deck
assets/fonts/                Rajdhani + Open Sans (OFL), served locally
blocks/game/                 block.json + plain-JS editor script
languages/                   .pot + sl_SI source translation (not shipped; see below)
uninstall.php                removes data only if opted in
```

## Request flow

```
GET  /config          -> pair counts, pool stats
POST /verify          captcha token (or nothing when provider = none) -> session token
POST /start-run       session token + tier -> run token + server-picked deck
POST /finish-run      run token + moves -> server-timed score (single use)
POST /submit-score    run token + name -> stored, ranked
GET  /leaderboard     tier, limit -> entries
```

Namespace: `pairsly-memory-game/v1`. All routes are anonymous by design (players
are visitors, not WordPress users); trust comes from the signed tokens, not
WordPress auth. The POST routes additionally refuse browser requests whose
`Origin` is another site (filter `pairsmg_allowed_origins`). See
[SECURITY.md](SECURITY.md).

## Local environment

Everything runs in Docker; no PHP, wp-cli or MySQL is installed on the
host. `docker-compose.yml` in the repo root defines:

| service     | image                   | purpose |
|-------------|-------------------------|---------|
| `db`        | mysql:8.0               | database (volume `db_data`) |
| `wordpress` | wordpress:php8.2-apache | the site, served as http://pairs.local (volume `wp_data`) |
| `wpcli`     | wordpress:cli-php8.2    | wp-cli against the INSTALLED site (shares `wp_data`, runs as www-data) |
| `tools`     | wordpress:cli-php8.2    | wp-cli / php against the CHECKOUT (bind-mounts `.` as `/src`, runs as your uid) |

Two deliberate choices:

- The plugin is not bind-mounted into WordPress. It is installed from the
  built zip with `bin/deploy-local.sh`, exactly the way an end user or
  wp.org gets it, so `.distignore` mistakes and activation bugs show up
  locally instead of in review.
- `wpcli` and `tools` are separate because of file ownership: `wpcli`
  must run as www-data (uid 33) to write into `wp_data`, while anything
  that writes into the repo (`wp i18n`, phpunit cache, phpcbf) must run as
  you. `tools` has a compose profile, so `docker compose up -d` never
  starts it; `docker compose run --rm tools ...` starts it on demand. If
  your uid is not 1000, export `DEV_UID`/`DEV_GID`.

### First-time setup

1. The machine's shared traefik proxy (`~/PROJECTS/_infra/localhost-proxy`)
   must be running; it owns ports 80/443 and routes `Host(pairs.local)`
   to the `wordpress` container over the external `proxy` network.
2. Add `127.0.0.1 pairs.local` to `/etc/hosts`.
3. Start the stack and install WordPress (dev-only credentials, admin/admin):

```
docker compose up -d
docker compose exec -T wpcli wp core install --url=http://pairs.local \
  --title="Pairsly dev" --admin_user=admin --admin_password=admin \
  --admin_email=admin@pairs.local --skip-email
docker compose exec -T wpcli wp plugin install plugin-check --activate
bin/deploy-local.sh
```

Plugin Check stays installed permanently on pairs.local; it is the same
tool the wp.org review runs.

### Day-to-day

```
bin/deploy-local.sh                               # build the zip from the checkout, wp plugin install --force, activate
docker compose exec -T wpcli wp plugin check pairsly-memory-game --format=csv   # same checks as wp.org
docker compose exec -T wpcli wp option get pairsmg_settings              # poke at the installed site
docker compose exec -T wpcli wp core update && docker compose exec -T wpcli wp core update-db   # bump WP, then bump "Tested up to"
docker compose logs -f wordpress                  # PHP errors; WP_DEBUG_LOG is on, see wp-content/debug.log
docker compose down                               # stop; `down -v` wipes the site and database
```

Run `bin/deploy-local.sh` after every change you want to see on
pairs.local (the site never reads the checkout directly). It fails if the
zip would contain anything outside `pairsly-memory-game/`.

Plugin Check's AI features need an AI connector key on the local site
(WordPress 7.x Settings > Connectors; the `ai-provider-for-anthropic`
plugin is installed there). From the CLI:

```
docker compose exec -T wpcli wp option update connectors_ai_anthropic_api_key 'sk-ant-...'
docker compose exec -T wpcli wp plugin check pairsly-memory-game --ai      # AI only triages findings; no-op when the run is clean
docker cp bin/ai-name-check.php "$(docker compose ps -q wpcli):/tmp/"
docker compose exec -T wpcli wp eval-file /tmp/ai-name-check.php "Pairsly - Memory Game" "Matic Korošec (bordar11)"
```

`bin/ai-name-check.php` runs the Namer prompts (name similarity plus a
simulated pre-review) that the wp.org review uses; treat it as guidance,
the reviewer's instance was stricter than the shipped prompt on the old
name.

### Tooling (against the checkout)

```
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app composer:2 install   # once; vendor/ is git-ignored
docker compose run --rm tools php vendor/bin/phpunit                       # unit tests (tests/, stubbed WP, no DB)
docker compose run --rm tools php vendor/bin/phpcs                         # WordPress + PHPCompatibility standards
docker compose run --rm tools php vendor/bin/phpcbf                        # auto-fix what phpcs can
docker compose run --rm tools php vendor/bin/parallel-lint --exclude vendor .
node --check assets/js/game.js blocks/game/index.js
```

The unit tests cover the parts that must not regress silently: the score
formula, token signing / expiry / tamper / replay, deck selection (own
cards first, special quota, fallback deck), settings sanitising (per-tab
checkbox handling, secret retention, clamping) and the captcha provider
matrix. They run against a ~100-line stub of the WordPress functions the
classes use, so they take milliseconds and need no database. CI
(`.github/workflows/ci.yml`) runs the same phpcs, tests, Plugin Check on
the built distributable, and a check that the .pot is current.

### Translations

```
docker compose run --rm tools wp i18n make-pot . languages/pairsly-memory-game.pot \
  --exclude=vendor,node_modules,.github,bin,tests \
  --headers='{"Report-Msgid-Bugs-To":"https://github.com/Mult1Hunter/pairsly-memory-game/issues"}' --skip-audit
python3 bin/build-sl_SI.py                        # Slovenian table lives in this script; prints untranslated msgids
docker compose run --rm tools wp i18n make-mo languages
```

Run this after changing any user-facing string; CI fails when the .pot
is stale (line references and dates are ignored, only string changes
count). The .po/.mo are kept in the repo as the source for the Slovenian
translation but are excluded from the release zip (`.distignore`):
wp.org delivers language packs from translate.wordpress.org, and since
WordPress 4.6 those load without `load_plugin_textdomain()`, which the
plugin therefore no longer calls. After the plugin is live on wp.org,
import the .po there once.

### Release

Bump `Version:` and `PAIRSMG_VERSION` in `pairsly-memory-game.php`,
`Stable tag` and the changelog in `readme.txt`, regenerate the .pot
(its header carries the version), commit, then tag `vX.Y.Z`. The release
workflow checks that all four agree, builds `pairsly-memory-game-X.Y.Z.zip`
(unpacking to a plain `pairsly-memory-game/` folder, as WordPress
requires) and attaches it to the GitHub release. Install that asset - not
GitHub's automatic "Source code" archives, which unpack to
`pairsly-memory-game-X.Y.Z/` and include development files.

The same zip built by hand, for a wp.org upload:

```
mkdir -p /tmp/dist/pairsly-memory-game
rsync -a --exclude-from=.distignore ./ /tmp/dist/pairsly-memory-game/
(cd /tmp/dist && zip -r pairsly-memory-game.zip pairsly-memory-game)
```

### Naming

Display name "Pairsly - Memory Game"; slug, text domain, block name,
REST namespace and main file are `pairsly-memory-game` (renamed from
`pairs-memory-game` in 1.0.5 at the request of the wp.org review, which
considered the old name too generic). Internal prefixes stayed:
`pairsmg_` for functions, options, hooks, tables and transients;
`PairsMG_` for classes; `.pairsmg-app` / `pmg-*` for CSS. The shortcode
is still `[pairs_memory_game]`.

## Hooks

Filters: `pairsmg_settings`, `pairsmg_pair_counts`, `pairsmg_active_pairs`,
`pairsmg_default_cards`, `pairsmg_build_deck`, `pairsmg_par_time`,
`pairsmg_score`, `pairsmg_sanitize_name`, `pairsmg_client_ip`,
`pairsmg_theme_css`, `pairsmg_frontend_config`, `pairsmg_allowed_origins`,
`pairsmg_min_time`.

Actions: `pairsmg_run_started`, `pairsmg_run_rejected`, `pairsmg_score_saved`,
`pairsmg_captcha_verified`, `pairsmg_cleanup_done`.

## Contributing

Issues and pull requests are welcome. Keep to the existing style (4-space
PSR-ish PHP, ES5 JS, no build step), escape everything on output, sanitize
everything on input, and make CI green.
