# Frame Up Shopify App

This is a Laravel 13 embedded Shopify app using `kyon147/laravel-shopify`.

## Resolved environment

- Laravel: 13.32.0
- PHP: MAMP PHP 8.5.0 (`/Applications/MAMP/bin/php/php8.5.0/bin/php`)
- Shopify package: `kyon147/laravel-shopify` 27.1.0
- Node.js: 24.12.0; npm: 11.6.2
- Shopify API version: 2026-07 (latest stable at build time)
- Database: SQLite for local verification; MAMP MySQL is also supported
- Project: `/Applications/MAMP/htdocs/frame-up`

Use MAMP PHP explicitly:

```bash
export PATH="/Applications/MAMP/bin/php/php8.5.0/bin:$PATH"
php -v
```

For automatic project-only selection, install `direnv`, enable its zsh hook,
then run `direnv allow` once from this directory. The included `.envrc` will
make `php -v` show MAMP PHP 8.5.0 inside `frame-up` while leaving PHP unchanged
in other directories:

```bash
brew install direnv
echo 'eval "$(direnv hook zsh)"' >> ~/.zshrc
source ~/.zshrc
cd /Applications/MAMP/htdocs/frame-up
direnv allow
php -v
```

## Setup

```bash
cp .env.example .env
/Applications/MAMP/bin/php/php8.5.0/bin/php /usr/local/bin/composer install
/Applications/MAMP/bin/php/php8.5.0/bin/php artisan key:generate
/Applications/MAMP/bin/php/php8.5.0/bin/php artisan migrate
npm install
npm run build
```

Set these `.env` values with real local values; `.env.example` contains placeholders only:
`APP_URL`, `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `SHOPIFY_APP_NAME`,
`SHOPIFY_API_SCOPES`, `SHOPIFY_API_VERSION`,
`SHOPIFY_EXPIRING_OFFLINE_TOKENS`, and `SHOPIFY_FRONTEND_TYPE`.

For MAMP MySQL, set `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=8889`,
`DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` to the credentials you created.
Point the MAMP virtual host/document root at this app's `public` directory, never the repository root.

## Shopify and HTTPS

Use a stable HTTPS tunnel or Shopify CLI dev URL. Set that origin consistently in
`APP_URL`, `SHOPIFY_APP_URL`, Vite asset configuration if needed, and Shopify's app URL.
Do not use a local HTTP URL in Shopify. In Shopify Partner Dashboard, configure:

- App URL: `https://YOUR_PUBLIC_ORIGIN/`
- Allowed redirection URL: `https://YOUR_PUBLIC_ORIGIN/authenticate`
- Token callback used by the package: `https://YOUR_PUBLIC_ORIGIN/authenticate/token`

The package also registers `GET /`, `GET|POST /authenticate`, billing routes, and
`POST /webhook/{type}`. Confirm exact names and middleware with `artisan route:list`.

The app uses the package's MPA embedded flow. The main page loads Shopify App Bridge,
obtains a fresh `window.shopify.sessionToken.getToken()` immediately before the button
request, and sends it as `Authorization: Bearer ...` to the same-origin diagnostic route.
The token is not persisted in storage, cookies, HTML, or URLs.

## Checks

```bash
/Applications/MAMP/bin/php/php8.5.0/bin/php artisan about
/Applications/MAMP/bin/php/php8.5.0/bin/php artisan route:list
/Applications/MAMP/bin/php/php8.5.0/bin/php artisan migrate:status
/Applications/MAMP/bin/php/php8.5.0/bin/php artisan test
/Applications/MAMP/bin/php/php8.5.0/bin/php /usr/local/bin/composer audit
npm run build
```

## Remove the temporary authentication check

Delete `app/Http/Controllers/TemporaryAuthCheckController.php`,
`tests/Feature/TemporaryAuthCheckTest.php`, and
`resources/views/vendor/shopify-app/home/index.blade.php`; remove the
`temporary.auth.check` route from `routes/api.php`; and remove the
`temporary-auth` rate limiter from `app/Providers/AppServiceProvider.php`.
Also remove the `temporary.auth.json` alias from `bootstrap/app.php` and
`app/Http/Middleware/TemporaryAuthJson.php`.

## Troubleshooting

- Wrong PHP: use the full MAMP PHP path above; check `php -v` and `composer check-platform-reqs`.
- Missing extension: enable it in the selected MAMP PHP `php.ini`, then re-run Composer checks.
- Redirect or iframe/CSP failure: ensure every Shopify URL matches the public HTTPS origin and let the package's iframe middleware run.
- Invalid/expired session token: open the app from Shopify Admin and reload; never put the token in a URL.
- Mixed content/proxy HTTPS: use HTTPS for the tunnel and configure only the tunnel's trusted proxy if needed.
- Database failure: start MAMP and verify host, port, database, username, and password; SQLite is the default local fallback.

Real Shopify installation, tunnel reachability, and authenticated browser fetch require Shopify credentials, a development store, and a public HTTPS URL; those user-dependent steps were not claimed as locally verified.
