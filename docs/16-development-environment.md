# Development Environment

## Supported baseline

- PHP `8.4`
- Composer `2.x`
- Laravel `13.x`, locked in `composer.lock`
- MariaDB `11.4` compatibility baseline
- Redis `7.4` with authentication
- Docker Engine with Compose v2 for disposable dependencies
- Git

Application and database timestamps are UTC. User-facing business time is `Asia/Tehran`. Development must not change the host clock to simulate business time; use the injectable application Clock.

## First setup

From the repository root:

```bash
cp .env.example .env
docker compose -f docker-compose.ci.yml up -d --wait
composer install --no-interaction --prefer-dist
php artisan key:generate
php artisan migrate:fresh --seed
php artisan health:check
```

Set these non-production values in `.env`; never commit the file:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_TIMEZONE=UTC
BUSINESS_TIMEZONE=Asia/Tehran
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=33067
DB_DATABASE=freedom_platform_ci
DB_USERNAME=freedom_ci
DB_PASSWORD=ci-only-database-password
REDIS_HOST=127.0.0.1
REDIS_PORT=6380
REDIS_PASSWORD=ci-only-redis-password
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

The values above are public disposable local-test credentials, not production secrets. Provider, Telegram, SMS, panel, and payment calls default to Fake adapters locally. Real credentials must be entered only through the installer, a protected environment file, or a hidden-input Artisan command.

Expected result: both containers are `healthy`; migrations and seeders complete; the health command reports database and authenticated Redis connectivity. Verify with:

```bash
docker compose -f docker-compose.ci.yml ps
php artisan about
php artisan health:check
```

## Daily commands

```bash
composer ci:static
composer ci:forbidden-patterns
composer ci:test
composer audit --locked
composer ci:licenses
php artisan schedule:list
php artisan queue:work redis --queue=critical-payments --once
```

Use `php artisan test --filter <TestName>` only for focused iteration; run the complete Composer CI contract before requesting review.

## Local queues and Scheduler

Use separate terminals:

```bash
php artisan queue:work redis --queue=critical-payments,bank-verification,gift-card-verification --tries=3 --timeout=120
php artisan queue:work redis --queue=provisioning,telegram-delivery,synchronization,default --tries=3 --timeout=180
php artisan queue:work redis --queue=broadcasts,reports,backups,maintenance --tries=3 --timeout=300
php artisan schedule:work
```

These processes are for local development only. Production uses Supervisor and one system Cron entry.

## Reset and shutdown

The following deletes only disposable CI database/Redis volumes:

```bash
docker compose -f docker-compose.ci.yml down --volumes
```

Do not run it against another Compose project. Recreate with `up -d --wait`, then run `php artisan migrate:fresh --seed`.

## Required extensions

Both production PHP runtimes must provide the application's locked requirements. Development and CI minimally require `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `intl`, `json`, `mbstring`, `openssl`, `pcntl`, `pdo`, `pdo_mysql`, `redis`, `session`, `sodium`, `tokenizer`, and `xml`. The installer is the authoritative runtime preflight and must check CLI and LSPHP independently.

## Troubleshooting contract

For a failed setup, do not send `.env` or raw application logs. Send only:

```bash
docker compose -f docker-compose.ci.yml ps
php -v
composer --version
php -m
php artisan health:check --redact
```

Include the first failing command, exit code, and sanitized error. Remove tokens, passwords, customer identifiers, subscription URLs, OTPs, gift-card codes, and provider payloads.
