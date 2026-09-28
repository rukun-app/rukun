# Rukun agent guidance

This is a Laravel 13 application targeting PHP 8.5. Run Composer, Artisan, Pest, and Pint in the `dev-php85` container at `/var/www/p85/rukun`. Laravel Boost is already installed; its generated framework guidelines are in `CLAUDE.md`.

Use the existing Docker network `docker-network` and shared PostgreSQL, Redis, Nginx, MailDev, and MinIO services. Do not add duplicate infrastructure services. The project's own worker and scheduler are defined in `compose.jobs.yml`.

Use Pest for tests. PostgreSQL test database `rukun_test` and Redis DB indices 13–15 must remain isolated from development data. Never put real credentials in tracked files. Run `php artisan test` and Laravel Pint before completing changes.
