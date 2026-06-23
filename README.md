# Verstka CMS (demo, PHP)

A minimal **Slim 4** + **SQLite** + **verstka/sdk** app: CMS at `/cms`, Verstka callbacks at `/verstka/`, static pages under **`storage/`** served by **nginx** without hitting PHP.

## Requirements

- PHP **8.2+** with extensions: `json`, `hash`, `zip`, `pdo_sqlite`.
- [Composer](https://getcomposer.org/).
- A Verstka account and API keys for `session/open` and callbacks.

## Setup

```bash
cd demo-php
composer install
cp .env.example .env
# edit .env
```

Dependencies include [`verstka/sdk`](https://packagist.org/packages/verstka/sdk) from Packagist (`^0.1`).

## Run (development)

Built-in PHP server — no nginx or php-fpm required:

```bash
php -S 127.0.0.1:8000 -t public
```

For local development, set `VERSTKA_CALLBACK_URL=http://127.0.0.1:8000/verstka/callback` in `.env`.

### First admin

If the **`cms_users`** table is empty, opening **`/cms/login`** shows a form to create the first administrator. The email and an argon2 password hash are written directly to SQLite, then the administrator is signed in immediately.

### CMS users and Verstka `user_email`

- The **`cms_users`** table is the single source of admins for `/cms` and for **`on_content_pre_save`**: **`metadata["user_email"]`** in the Verstka callback must match **`cms_users.user_email`**.
- In the Verstka editor, set the author email field to the **same email** as in the CMS.

### `VERSTKA_CALLBACK_URL`

Must match the public URL of the SDK callback endpoint, for example:

`https://your-domain/verstka/callback`

The shared `/verstka/callback` endpoint accepts both article-save callbacks and `site_fonts_updated` callbacks.

For local development, `VERSTKA_CALLBACK_URL=http://127.0.0.1:8000/verstka/callback` can boot the CMS but may not be accepted by Verstka API keys. If `session/open` returns `Host 127.0.0.1 not allowed for this API key`, expose the app through an HTTPS tunnel or use a server URL allowed in the Verstka dashboard. In production behind nginx, use your public HTTPS callback URL instead.

### `invalid_signature` on `POST /verstka/callback`

The SDK checks `HMAC_SHA256(VERSTKA_API_SECRET, "{material_id}:{content_url}")` against the `X-Verstka-Signature` HTTP header.

1. Confirm **`VERSTKA_API_SECRET`** is exactly the secret for this API key in the Verstka dashboard.
2. **`VERSTKA_API_KEY`**, **`VERSTKA_API_SECRET`**, and **`VERSTKA_CALLBACK_URL`** are trimmed of leading/trailing whitespace when loaded from `.env`.
3. With **`DEBUG=1`**, `VerstkaConfig(debug=true)` adds more detail if signature verification fails.

Create an article with path **`/index`** for the home page (nginx redirects `/` → `/index/`).

## Viewer assets and article rendering

Published pages load the npm-published `verstka-viewer` wrapper at runtime via `VERSTKA_VIEWER_SCRIPT_URL` (default: `https://go.r2.verstka.org/viewer-latest.js`).

## Nginx and static files (production)

Example config: [`staff/nginx.conf`](staff/nginx.conf). PHP runs via **php-fpm** (not `php -S`).

- **`root`** points at **`storage/`**, where the app writes `index.html`, article media, and `sitemap.xml` / `favicon.ico`.
- **`/cms`** and **`/verstka/`** are passed to php-fpm → `public/index.php` (Slim).
- **`/`** → **`/index/`**; articles are served with **`try_files`** and **`index.html`**.

## Production deploy (nginx + php-fpm)

1. Deploy app to e.g. `/var/www/demo-cms` and run `composer install --no-dev --optimize-autoloader`.
2. Copy `.env`, set `PUBLIC_BASE_URL=https://your-domain` and Verstka keys.
3. Copy [`staff/php-fpm-pool.conf.example`](staff/php-fpm-pool.conf.example) to `/etc/php/8.3/fpm/pool.d/demo-cms.conf` (adjust PHP version). Ensure the `listen` socket matches `$php_fpm` in nginx.
4. Copy [`staff/nginx.conf`](staff/nginx.conf) to nginx `sites-enabled`, adjust `$demo_public`, static paths, and `server_name`.
5. Set permissions (php-fpm runs as `www-data`):

| Path | Access |
|------|--------|
| `/var/www/demo-cms` | code readable by `www-data` |
| `storage/` | writable by `www-data` |
| `data.db` | writable by `www-data` |
| `.env` | readable by `www-data` (e.g. `chmod 640`, group `www-data`) |

6. Enable and reload: `sudo systemctl enable --now php8.3-fpm nginx` then `sudo systemctl reload php8.3-fpm nginx`.
7. Open **`/cms/login`** once to bootstrap the first admin (creates `data.db` if missing).

`.env` is loaded by the app via Dotenv — you do not need to duplicate variables in the php-fpm pool unless you prefer that approach.

## Reserved article paths

You cannot create articles with an empty path, **`/cms`**, or **`/fonts`**. The **`storage/fonts/`** directory is reserved for Verstka fonts; the article template links **`/fonts/fonts.css`** when that file exists.

## Tests

```bash
vendor/bin/phpunit
```

## Layout

- `src/` — application code (Slim routes, services, Verstka hooks).
- `templates/` — Twig templates for CMS and static pages.
- `public/` — web root (`index.php`).
- `storage/` — generated files (listed in `.gitignore`).
- `staff/` — nginx and php-fpm pool examples.

## Monorepo development

If you clone this repo inside the `verstka_v2` monorepo next to `verstka-sdk-php`, you can point Composer at the local SDK without editing `composer.json`:

```bash
composer config repositories.verstka path ../verstka-sdk-php
composer require verstka/sdk:dev-main --no-update
composer update verstka/sdk
```
