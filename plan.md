# Laravel Core Boilerplate — Phase 1 Infrastructure Plan (COMPLETED)

> **Status per 28 September 2026:** seluruh acceptance criteria Phase 1 telah diterapkan dan tetap dijaga oleh automated test. Dokumen ini dipertahankan sebagai keputusan arsitektur dan referensi ketika membuat proyek baru. Implementasi lanjutan dicatat di [`plan-v2.md`](plan-v2.md), sedangkan petunjuk operasional terkini berada di [`README.md`](README.md).

> Dokumen ini **self-contained** — gabungan dari plan awal + semua revisi (Docker network strategy, Redis DB index, session storage decision, dan Testing Foundation/Unit Testing). Cukup gunakan file ini saja untuk coding agent, tidak perlu dokumen sebelumnya.

---

## 1. Existing Development Infrastructure

Development machine sudah menyediakan shared Docker infrastructure yang reusable. **Jangan duplikasi** infrastruktur ini di dalam repo Laravel Core kecuali isolasi project benar-benar mengharuskannya.

```text
Database
├── PostgreSQL 16
├── MySQL 8
├── MySQL 5.7
└── Redis 7

PHP
├── PHP 5.6 – PHP 8.5

Runtime
├── Nginx
├── Queue Worker
└── Scheduler

Development Tools
├── Adminer
├── phpMyAdmin
├── MailDev
├── Portainer
└── MinIO

Optional Platform
└── Supabase Stack
```

Laravel Core harus terintegrasi dengan environment ini, bukan membuat container baru.

---

## 2. Laravel Core Runtime Target

```text
Laravel 13
PHP 8.5
PostgreSQL 16
Redis 7
Nginx
```

Primary database: **PostgreSQL**. Redis dipakai untuk Cache, Queue, Locks, Rate Limiting, dan Session (lihat Section 8). Dev email via **MailDev**, dev object storage via **MinIO**.

⚠️ Sebelum eksekusi, verifikasi aktual kompatibilitas Laravel 13 ↔ PHP 8.5 (jangan asumsikan kompatibel hanya karena sama-sama versi terbaru).

---

## 3. Docker Network & Connectivity Strategy

Bagian ini krusial — langkah pertama yang harus beres sebelum apapun lain, karena semua konektivitas (DB, Redis, Mail, Storage) bergantung padanya.

```text
Shared Infra Network (external, sudah ada)
        │
        ▼
Laravel Core docker-compose.override.yml
        │
        ├── join existing external network (bukan bikin network baru)
        └── TIDAK mendefinisikan service postgres/redis/nginx/minio/maildev
```

Ketentuan:

1. Laravel Core **tidak membuat** `docker-compose.yml` yang mendefinisikan ulang service database/redis/dll. Jika container PHP 8.5 project ini butuh attach ke jaringan bersama, gunakan `networks: external: true` mengacu ke nama network eksternal yang sudah dipakai shared infra.
2. Nama network eksternal, hostname service (`postgres`, `redis`, `minio`, `maildev`), dan port internal **diambil dari environment yang sudah berjalan** (inspeksi langsung: `docker network ls`, `docker inspect`, atau file compose shared infra bila bisa diakses read-only) — bukan ditebak.
3. Jika project butuh container sendiri, container tersebut **join ke network yang sama**, tidak membuat network terisolasi sendiri, supaya tetap bisa resolve hostname `postgres`, `redis`, dst.
4. Hostname/port ini hanya boleh muncul di `.env` / `.env.example`, **tidak hardcode** di kode aplikasi (lihat Section 21, Portability Requirement).

---

## 4. Shared Infrastructure Architecture

```text
                     ┌────────────────────┐
                     │       Nginx        │
                     │   Shared Gateway   │
                     └─────────┬──────────┘
                               │
                               ▼
                     ┌────────────────────┐
                     │      PHP 8.5       │
                     │   Laravel Core     │
                     └─────────┬──────────┘
                               │
             ┌─────────────────┼──────────────────┐
             │                 │                  │
             ▼                 ▼                  ▼
      ┌────────────┐    ┌────────────┐     ┌────────────┐
      │ PostgreSQL │    │   Redis    │     │   MinIO    │
      │     16     │    │     7      │     │ S3 Storage │
      └────────────┘    └──────┬─────┘     └────────────┘
                               │
                    ┌──────────┴───────────┐
                    │                      │
                    ▼                      ▼
             Queue Worker              Cache/Locks
```

```text
Laravel Mail → MailDev SMTP → MailDev Web UI
```

---

## 5. Do Not Duplicate Infrastructure

Jangan otomatis membuat service berikut di dalam Laravel Core: `postgres`, `redis`, `nginx`, `minio`, `maildev`. Semua sudah ada sebagai shared dev infrastructure. Laravel Core hanya menyediakan konfigurasi koneksi:

```env
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=laravel_core
DB_USERNAME=
DB_PASSWORD=

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=

MAIL_MAILER=smtp
MAIL_HOST=maildev
MAIL_PORT=1025

FILESYSTEM_DISK=s3
AWS_ENDPOINT=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
```

Jangan copy credential asli dari shared Docker config — gunakan placeholder di `.env.example`.

---

## 6. Database Strategy

PostgreSQL 16 default. Develop & test utamanya terhadap PostgreSQL, tapi hindari logika khusus PostgreSQL kecuali ada manfaat nyata. MySQL 5.7 hanya untuk legacy compatibility, tidak boleh memengaruhi arsitektur Core.

---

## 7. PostgreSQL Extensions

Siapkan arsitektur untuk extension opsional di masa depan: **pgvector** (untuk Laravel AI SDK, embeddings, semantic search, RAG, similarity search). **Jangan** aktifkan/wajibkan pgvector di Phase 1 kecuali ada fitur AI yang benar-benar membutuhkannya. AI infrastructure tetap optional.

---

## 8. Redis Strategy

Redis: first-class infrastructure dependency. Dipakai untuk Cache, Queue, Distributed Locks, Rate Limiting.

**Strategi DB index per fungsi** — supaya key tidak bentrok antar fungsi dan mudah di-debug/flush terpisah saat development:

```env
REDIS_CACHE_DB=0
REDIS_QUEUE_DB=1
REDIS_SESSION_DB=2
```

Dipetakan di `config/database.php` pada masing-masing koneksi Redis (cache, queue, session), masing-masing dengan `REDIS_*_DB` sendiri. `REDIS_PREFIX` tetap dipakai sebagai lapisan isolasi tambahan antar-project karena Redis ini shared dengan banyak project lain di dev environment.

**Session storage — keputusan eksplisit:**

```env
SESSION_DRIVER=redis   # menggunakan REDIS_SESSION_DB
```

Alasan: konsisten dengan pola stateless-friendly untuk API, memudahkan scaling PHP-FPM/worker tanpa sticky session, dan Redis sudah tersedia sehingga tidak menambah infrastruktur baru. Jika ke depan Laravel Core murni API token-based (Sanctum tanpa cookie session), ini bisa dievaluasi ulang di Phase 2 (Auth) — dicatat di sini supaya tidak ambigu.

Hindari membuat abstraksi Redis custom bila API Laravel sudah cukup:

```php
Cache::remember(...)
Cache::lock(...)
dispatch(...)
```

Potensi penggunaan masa depan (bukan Phase 1): Pub/Sub, realtime events, temporary state, idempotency locks.

---

## 9. Queue Architecture

Environment sudah menyediakan dedicated queue worker. Standarisasi nama queue:

```text
high    → Security-sensitive notifications, important transactional jobs, time-sensitive processing
default → Normal application jobs, notifications, standard background work
low     → Reports, cleanup, non-urgent exports, maintenance jobs
```

Job pilih queue eksplisit hanya kalau prioritas penting:

```php
ProcessPayment::dispatch($payment)->onQueue('high');
```

Job normal tetap di `default`.

---

## 10. Queue Reliability

Job penting harus mendefinisikan `tries`, `timeout`, `backoff` yang sesuai. Job harus idempotent bila eksekusi ganda bisa menyebabkan state salah. Pertimbangkan `ShouldBeUnique`, `WithoutOverlapping`, `Cache::lock()` bila relevan — **jangan** tambahkan mekanisme ini ke semua Job secara otomatis; dasarkan pada kebutuhan bisnis nyata.

---

## 11. Queue Monitoring

Phase 1 cukup `php artisan queue:work`. Laravel Horizon tidak wajib — evaluasi nanti jika butuh queue monitoring, throughput metrics, job balancing, failed job visibility, worker management. Horizon tetap optional.

---

## 12. Scheduler

Environment sudah menyediakan dedicated scheduler container. Gunakan native Laravel scheduler; semua schedule definisi ada di Laravel:

```php
Schedule::command('app:cleanup')->daily();
```

**Jangan** buat konfigurasi cron OS terpisah untuk tiap task Laravel. Infrastructure hanya memicu eksekusi scheduler Laravel; Laravel yang memiliki definisi schedule.

---

## 13. Mail Development

MailDev sebagai default mail system development:

```text
Laravel Notification → Laravel Mail → MailDev SMTP → MailDev Inbox
```

Tidak ada email asli terkirim saat development lokal. Konfigurasi provider email production tetap environment-driven (SMTP, Amazon SES, Postmark, Resend, Brevo). Business modules **tidak boleh** bergantung langsung ke provider email tertentu.

---

## 14. Object Storage

Gunakan Laravel Filesystem abstraction.

```text
Laravel → Filesystem → S3 Driver → MinIO (dev)
```

Production bisa ganti MinIO dengan AWS S3 / Cloudflare R2 / DigitalOcean Spaces / S3-compatible lain tanpa mengubah business module.

---

## 15. File Module

File module **tidak boleh** berkomunikasi langsung dengan MinIO SDK:

```text
File Module → Laravel Storage → Filesystem Driver → (Local | MinIO | S3)
```

Ini menjaga storage infrastructure tetap replaceable.

---

## 16. Nginx

Gunakan shared Nginx infrastructure yang sudah ada. Laravel Core menyediakan/mendokumentasikan virtual host config yang dibutuhkan. Domain dev yang disarankan: `laravel-core.test` atau `project-name.test` / `api.project-name.test`. Jangan bind host port terpisah per project Laravel kalau shared Nginx routing sudah bisa menangani.

---

## 17. PHP Runtime

Gunakan PHP 8.5 container yang sudah ada. Laravel Core tidak bergantung pada container PHP lama (untuk legacy project saja).

Ekstensi PHP yang dibutuhkan: PostgreSQL, Redis, Intl, Mbstring, XML, Curl, Zip, GD/Imagick (bila perlu), Sodium, PCNTL.

⚠️ **Verifikasi ketersediaan nyata** ekstensi ini di image PHP 8.5 yang sudah ada (jangan asumsi tersedia) — terutama `pgsql`/`redis` via PECL, karena bisa jadi belum ter-compile di base image. Ini bagian dari langkah inspeksi awal (Section 24). Jangan install ekstensi yang tidak dibutuhkan.

---

## 18. Adminer

Adminer boleh dipakai sebagai UI database generic (lintas engine). Laravel Core **tidak bergantung** pada Adminer — murni development tooling.

---

## 19. phpMyAdmin

Eksis untuk legacy/MySQL development. Laravel Core tidak bergantung padanya karena PostgreSQL adalah database utama.

---

## 20. Portainer

Infrastructure tooling saja — untuk inspeksi container, log, network, volume, runtime state. Laravel Core **tidak boleh** integrasi dengan Portainer API.

---

## 21. Supabase

Local Supabase stack ada, tapi statusnya **OPTIONAL EXTERNAL PLATFORM**. Jangan buat Laravel Core bergantung pada Supabase. Jangan gunakan Supabase Auth sebagai default authentication. Default authentication Laravel Core: **Laravel Sanctum**. Supabase bisa diintegrasikan per-project nanti jika butuh Supabase Auth/Realtime/Storage/PostgREST — integrasi ini tetap di luar core foundation.

---

## 22. AI Infrastructure

Laravel AI SDK tetap optional:

```text
Business Modules → AI Module → Laravel AI SDK → (OpenAI | Gemini | Anthropic | Other)
```

**Jangan** perkenalkan Ollama, Vector DB, atau AI model container di Phase 1. Saat RAG/embedding dibutuhkan nanti, evaluasi dulu **PostgreSQL + pgvector** sebelum memperkenalkan vector database terpisah.

---

## 23. Environment Profiles

```text
APP         → PHP 8.5, Laravel 13, Nginx
DATA        → PostgreSQL 16, Redis 7
ASYNC       → Queue Worker, Scheduler
DEVELOPMENT → MailDev, MinIO, Adminer, Portainer
OPTIONAL    → MySQL 8, Supabase, AI Provider
```

Laravel Core tidak boleh mewajibkan semua service optional untuk boot.

---

## 24. Health Check

Health check harus membedakan infrastruktur mandatory vs optional:

```json
{
    "status": "healthy",
    "services": {
        "database": { "status": "up", "required": true },
        "redis":    { "status": "up", "required": true },
        "storage":  { "status": "up", "required": false },
        "ai":       { "status": "not_configured", "required": false }
    }
}
```

Aplikasi **tidak boleh** dianggap unhealthy hanya karena provider AI optional belum dikonfigurasi.

---

## 25. Testing Foundation — Unit Testing Strategy

### 25.1 Framework

```text
Pest PHP (di atas PHPUnit)
```

Pest adalah standar de-facto ekosistem Laravel modern (default generator Laravel 13 juga mengarah ke Pest), sintaks ringkas, tetap 100% kompatibel dengan assertion/mocking PHPUnit di baliknya. Bisa ditukar ke PHPUnit murni bila tim punya preferensi kuat, tapi default-nya Pest.

### 25.2 Struktur Test

```text
tests/
├── Unit/
│   └── Modules/
│       └── <ModuleName>/
│           ├── Services/
│           └── Support/
├── Feature/
│   ├── Api/
│   │   └── HealthCheckTest.php
│   └── Modules/
│       └── <ModuleName>/
└── Pest.php
```

- **Unit** — logika murni (services, helper, value object), **tanpa** boot database/HTTP. Harus cepat; hindari menyentuh Postgres/Redis, gunakan fake/mock.
- **Feature** — menyentuh HTTP layer (route, controller, middleware, response format) dan/atau database via Laravel test client (`$this->get(...)`, `$this->postJson(...)`).

### 25.3 Database untuk Testing

```env
# .env.testing
DB_CONNECTION=pgsql
DB_DATABASE=laravel_core_test
```

1. Database test **terpisah** dari database development (`laravel_core_test` vs `laravel_core`), tetap di instance PostgreSQL 16 yang sama (shared infra) — tidak perlu container Postgres baru.
2. Gunakan `RefreshDatabase` trait sebagai default untuk Feature test yang menyentuh DB. Evaluasi `DatabaseTransactions` sebagai alternatif lebih cepat kalau suite membesar.
3. Redis untuk testing: gunakan DB index terpisah lagi khusus testing (mis. `REDIS_CACHE_DB=15` di `.env.testing`), atau gunakan `Cache::fake()` untuk Unit test yang tidak butuh Redis nyata.
4. Queue dan Mail di-*fake* saat testing (`Queue::fake()`, `Mail::fake()`, `Notification::fake()`) — bukan dispatch nyata ke worker/MailDev — kecuali test tersebut secara eksplisit menguji integrasi end-to-end.

### 25.4 Test Wajib di Phase 1 (minimum)

```text
✓ Health check endpoint (up/down per service, required vs optional)
✓ Module loader — module contoh ter-load dengan benar
✓ make:module command menghasilkan struktur yang benar
✓ Standard API response format (success & error shape konsisten)
✓ Exception handler menghasilkan format response standar
✓ Minimal 1 test yang membuktikan koneksi Postgres bekerja (Feature)
✓ Minimal 1 test yang membuktikan Cache::remember via Redis bekerja
✓ Minimal 1 test yang membuktikan job bisa di-dispatch (Queue::fake)
```

### 25.5 Code Coverage & CI Gate

- Tidak wajib target coverage angka tertentu di Phase 1 (hindari coverage-chasing di tahap fondasi), tapi setiap Module baru **wajib** punya minimal satu Feature test untuk endpoint utamanya sebelum dianggap selesai.
- `php artisan test` dan `Laravel Pint` harus lulus sebelum merge.
- CI runner (GitHub Actions atau sejenis) opsional Phase 1; kalau sudah ada, tambahkan service Postgres 16 + Redis 7 sebagai service container CI (terpisah dari dev environment lokal, karena CI tidak punya akses ke shared Docker lokal).

### 25.6 Yang TIDAK dilakukan di Phase 1

```text
✗ Mutation testing (Infection, dsb.)
✗ Load/performance testing
✗ Browser/E2E testing (Dusk, Playwright)
✗ Coverage threshold enforcement otomatis
```

Dievaluasi ulang setelah Phase 2+ ketika permukaan aplikasi lebih besar.

---

## 26. Observability — Catatan Ringan (bukan scope Phase 1)

Tidak wajib diimplementasikan di Phase 1, dicatat supaya tidak terlupa di Phase 2/3:

```text
- Structured logging (JSON log channel) untuk memudahkan parsing di masa depan
- Log channel terpisah untuk exception vs request log
- Evaluasi Sentry/Bugsnag/sejenis hanya jika project menuju staging/production
```

Health check (Section 24) sudah cukup untuk Phase 1.

---

## 27. Local Development Workflow

```bash
# shared infrastructure already running

cd /var/www/p85/laravel-core

composer install

cp .env.example .env
cp .env.example .env.testing   # sesuaikan DB_DATABASE=laravel_core_test

php artisan key:generate
php artisan key:generate --env=testing

php artisan migrate

php artisan test          # menjalankan Pest/PHPUnit suite
php artisan pint          # code style check
```

Application: `https://laravel-core.test`

---

## 28. Repository Responsibility

```text
Laravel Core Repository
        │ owns
        ▼
Application Code, Configuration Templates, Migrations, Modules, Tests,
Documentation, Application-specific Nginx example
        │ DOES NOT own
        ▼
Shared PostgreSQL container, Shared Redis container, Shared MinIO container,
Shared MailDev container, Shared Portainer container
```

Pemisahan ini mencegah setiap project Laravel mendup­likasi infrastruktur.

---

## 29. Portability Requirement

Meskipun development pakai shared Docker infra, Laravel Core **tidak boleh** bergantung pada environment spesifik itu — developer lain harus tetap bisa jalankan project dengan environment berbeda.

```text
Application → Environment Variables → (Database | Redis | Storage | Mail | AI)
```

**Jangan hardcode**: nama container, port host, path filesystem lokal, credential development di dalam kode aplikasi. Hanya `.env` / deployment config yang boleh tahu detail infrastruktur.

---

## 30. Security Cleanup

Existing shared Docker config berisi credential/secret development. **Jangan** copy nilai ini ke Laravel Core. Repo Core hanya berisi placeholder:

```env
DB_PASSWORD=
REDIS_PASSWORD=
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
GEMINI_API_KEY=
```

Jangan pernah commit credential asli. Jika Docker infrastructure repo pernah dipublikasikan/dibagikan keluar trusted local environment, rotate semua password, JWT secret, service key, MinIO credential, dan secret hardcoded lain yang mungkin ter-expose.

---

## 31. Phase 1 Scope

```text
Phase 1 — Foundation
│
├── Laravel 13
├── PHP 8.5 compatibility (verifikasi aktual, jangan asumsi)
│
├── Shared Infrastructure Integration
│   ├── Docker network join strategy (Section 3)
│   ├── PostgreSQL 16
│   ├── Redis 7 (DB index per fungsi, Section 8)
│   ├── MailDev
│   └── MinIO
│
├── Core Architecture
│   ├── Core/
│   └── Modules/
│
├── Module Loader
├── make:module
│
├── API Foundation
│   ├── Standard Response
│   └── Exception Handling
│
├── Health Check
│   ├── Application
│   ├── PostgreSQL
│   └── Redis
│
├── Testing Foundation (Section 25)
│   ├── Pest setup
│   ├── .env.testing + laravel_core_test database
│   ├── Unit test structure
│   ├── Feature test structure
│   └── Test minimum untuk tiap Acceptance Criteria
│
└── Documentation
```

**Batas scope saat Phase 1 direncanakan:** Auth, RBAC, Audit, business module, Supabase, AI Agents, Embeddings, RAG, Horizon, dan WebSockets belum dikerjakan pada fase ini. Identity, RBAC, Audit, dan fondasi realtime kemudian diselesaikan pada roadmap V2; AI dan business module tetap di luar boilerplate saat ini.

---

## 32. Phase 1 Acceptance Criteria

```text
✓ Laravel boots on PHP 8.5
✓ Nginx can serve Laravel Core
✓ Laravel connects to PostgreSQL 16
✓ Laravel connects to Redis (cache, queue, session DB index terpisah)
✓ Cache can use Redis
✓ Queue can dispatch and process a test Job
✓ Scheduler can execute a test scheduled command
✓ Mail can be captured by MailDev
✓ Laravel Filesystem can communicate with MinIO
✓ /api/health works (required vs optional service distinction)
✓ Module Service Providers load correctly
✓ php artisan make:module Example works
✓ Standard API responses work
✓ API exceptions use the standard response format
✓ Pest testing framework installed and configured
✓ laravel_core_test database configured and isolated from dev DB
✓ Unit test suite passes (services/helpers, tanpa DB)
✓ Feature test suite passes (HTTP + DB via RefreshDatabase)
✓ Minimum test coverage per Section 25.4 terpenuhi
✓ php artisan test passes
✓ Laravel Pint passes
✓ .env.example and .env.testing.example contain no secrets
```

Phase 1 telah lulus seluruh gate di atas. Kelanjutan historisnya adalah:

```text
Phase 2 — Identity
├── Authentication
├── User
└── RBAC
```

---

## 33. Catatan Eksekusi Phase 1

Bagian berikut adalah instruksi awal yang sudah selesai dijalankan dan dipertahankan sebagai checklist audit bila fondasi ini dipindahkan ke environment baru.

Inspect repository Laravel Core dan shared Docker development environment yang sudah ada. **Jangan** duplikasi infrastruktur yang sudah ada.

Tentukan:

1. Versi Laravel saat ini.
2. Kompatibilitas PHP saat ini (verifikasi aktual, bukan asumsi).
3. Ekstensi PHP 8.5 yang dibutuhkan — cek ketersediaan nyata di image, bukan asumsi tersedia.
4. Konektivitas PostgreSQL.
5. Konektivitas Redis, termasuk strategi DB index per fungsi.
6. **Nama Docker network eksternal & strategi join** (Section 3) — langkah pertama sebelum konektivitas lain, karena semua bergantung padanya.
7. Kebutuhan virtual-host Nginx.
8. Integrasi queue worker.
9. Integrasi scheduler.
10. Konfigurasi MailDev.
11. Konfigurasi MinIO.
12. Struktur project Laravel yang sudah ada.
13. Dependency Composer yang sudah ada.
14. Apakah Pest atau PHPUnit sudah ter-install/dikonfigurasi (jika project sudah punya starting point), untuk menentukan perlu instalasi baru atau penyesuaian.

Kemudian validasi kembali testing foundation pada Section 25.

Pada eksekusi awal, Phase 2 baru dimulai setelah seluruh gate Phase 1 lulus. Pada penggunaan boilerplate sekarang, perubahan dependency dan shared infrastructure tetap harus memiliki kebutuhan yang jelas dan dampak lintas proyek harus diperiksa.

Jika perubahan shared Docker diperlukan, jelaskan dulu sebelum melakukan perubahan:

```text
Mengapa diperlukan
File mana yang harus berubah
Project lain apa yang mungkin terdampak
Apakah perubahan backward compatible
```
