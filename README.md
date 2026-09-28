# Core R

Core R adalah fondasi backend API berbasis Laravel 13 dan PHP 8.5. Proyek menyediakan autentikasi Sanctum, manajemen user, RBAC dinamis, runtime settings, audit trail, OpenAPI, Telescope, queue worker, dan scheduler.

Aplikasi memakai PostgreSQL, Redis, Nginx, MailDev, dan MinIO bersama pada jaringan Docker eksternal `docker-network`. Repo ini tidak membuat ulang layanan tersebut.

## Daftar isi

- [Status implementasi](#status-implementasi)
- [Teknologi dan struktur](#teknologi-dan-struktur)
- [Instalasi lokal](#instalasi-lokal)
- [Menjalankan aplikasi](#menjalankan-aplikasi)
- [Environment dan isolasi testing](#environment-dan-isolasi-testing)
- [Identity dan token](#identity-dan-token)
- [RBAC](#rbac)
- [Settings dan audit](#settings-dan-audit)
- [Daftar endpoint](#daftar-endpoint)
- [Standar API](#standar-api)
- [Swagger dan Telescope](#swagger-dan-telescope)
- [Queue dan scheduler](#queue-dan-scheduler)
- [Realtime dan polling](#realtime-dan-polling)
- [Module system](#module-system)
- [Testing](#testing)
- [Checklist pengembangan](#checklist-pengembangan)

## Status implementasi

| Area | Status | Hasil |
|---|---|---|
| Phase 1: Laravel Core | Selesai | Laravel 13, PHP 8.5, PostgreSQL, Redis, MinIO, MailDev, health check, module loader, worker, scheduler, Pest, dan Pint |
| Dokumentasi API | Selesai | Swagger UI, OpenAPI 3.1, schema request/response, parameter, contoh, dan generator dokumen statis |
| Phase 2: Identity | Selesai | Registrasi terkontrol, login, logout, profil, verifikasi email, reset password, dan token per perangkat |
| Phase 2: RBAC | Selesai | Role dan permission dinamis, administrasi user, proteksi admin terakhir, locking, dan audit |
| Phase 2: Settings | Selesai | Registry bertipe, validasi, cache Redis, public settings, dan metadata settings |
| Observability | Selesai | Audit event dan Telescope dengan penyamaran data sensitif |
| Pagination | Selesai | Cursor pagination sebagai standar koleksi |
| V2.1: File Management | Selesai | Private upload/download, metadata, ownership policy, admin permissions, attachment service, audit, dan queued cleanup |
| V2.2: Multilingual | Selesai | Locale `en`/`id`, `Accept-Language`, preferensi user, translated validation/error, stable error code, dan localized settings metadata |
| V2.3: Notification | Selesai | Localized inbox, email channel, preferences, read/unread lifecycle, cursor pagination, queue priority, dan MailDev verification |
| V2.4: Realtime dan Polling | Selesai | Durable user events, opaque cursor, private Reverb channel, notification event, retention, dan fallback polling |
| Roadmap V2 lanjutan | Direncanakan | Operational reliability, API reliability, CI, dan integration foundation; lihat [`plan-v2.md`](plan-v2.md) |
| Modul bisnis | Belum dimulai | Dimulai setelah fase fondasi V2 yang dibutuhkan selesai |

Verifikasi terakhir: **67 test lulus dengan 324 assertions**.

## Teknologi dan struktur

| Komponen | Penggunaan |
|---|---|
| PHP 8.5 / Laravel 13 | Runtime dan framework |
| PostgreSQL | Database utama dan testing |
| Redis | Cache, queue, dan session |
| Laravel Sanctum | Bearer token API |
| Spatie Laravel Permission | Role dan permission dinamis |
| MinIO / S3 | Object storage |
| MailDev | SMTP development |
| Swagger PHP / Swagger UI | OpenAPI 3.1 |
| Laravel Telescope | Request, query, job, cache, mail, dan exception monitoring |
| Laravel Reverb | Transport WebSocket opsional untuk durable event |
| Pest 5 / Laravel Pint | Testing dan code style |

| Direktori | Tanggung jawab |
|---|---|
| `app/` | Model dan komponen Laravel umum |
| `Core/` | Response API, audit, command, job, middleware, OpenAPI, dan module provider |
| `Modules/Identity/` | Authentication dan token lifecycle |
| `Modules/Access/` | User, role, dan permission administration |
| `Modules/Settings/` | Registry dan penyimpanan settings |
| `Modules/Realtime/` | Durable event stream, polling, dan WebSocket publisher |
| `Modules/Example/` | Contoh struktur modul |
| `tests/Feature/` | Test API, infrastruktur, auth, RBAC, settings, dan modul |
| `compose.jobs.yml` | Worker dan scheduler Core R |
| `compose.realtime.yml` | Server Reverb opsional khusus Core R |

## Instalasi lokal

Prasyarat:

- Container PHP `dev-php85` memasang source di `/var/www/p85/core-r`.
- Network eksternal `docker-network` memiliki alias `postgres`, `redis`, `maildev`, dan `minio`.
- Database `laravel_core` dan `laravel_core_test` sudah dibuat.
- Bucket MinIO tersedia dan host `core-r.p85.test` diarahkan ke mesin development.

Salin dan isi environment lokal. Jangan commit kredensial nyata.

```sh
cp .env.example .env
cp .env.testing.example .env.testing

docker exec -w /var/www/p85/core-r dev-php85 composer install
docker exec -w /var/www/p85/core-r dev-php85 php artisan key:generate
docker exec -w /var/www/p85/core-r dev-php85 php artisan key:generate --env=testing
docker exec -w /var/www/p85/core-r dev-php85 php artisan migrate
docker exec -w /var/www/p85/core-r dev-php85 php artisan db:seed --class=Database\\Seeders\\RbacSeeder
```

Buat administrator pertama:

```sh
docker exec -it -w /var/www/p85/core-r dev-php85 php artisan identity:bootstrap-admin admin@example.com --name="Administrator"
```

Akun bootstrap dibuat aktif, terverifikasi, dan memiliki role `super-admin`. Command menolak bootstrap kedua jika role itu sudah dimiliki user lain.

## Menjalankan aplikasi

| Layanan | Alamat |
|---|---|
| Aplikasi | `https://core-r.p85.test:8443` |
| Health | `https://core-r.p85.test:8443/api/health` |
| Swagger | `https://core-r.p85.test:8443/docs/api` |
| OpenAPI JSON | `https://core-r.p85.test:8443/docs/api/openapi.json` |
| Telescope | `https://core-r.p85.test:8443/telescope` |

Jalankan proses background khusus proyek:

```sh
docker compose -f compose.jobs.yml up -d
docker compose -f compose.jobs.yml ps
```

Aktifkan Reverb secara terpisah setelah mengisi credential unik `REVERB_APP_*`:

```sh
docker compose -f compose.realtime.yml up -d
docker compose -f compose.realtime.yml ps
```

`BROADCAST_CONNECTION=null` adalah default aman. Ubah menjadi `reverb` hanya saat server dan reverse proxy WebSocket sudah tersedia.

Health check membedakan PostgreSQL dan Redis sebagai layanan wajib, serta storage dan AI sebagai layanan opsional.

## Environment dan isolasi testing

| Variable | Fungsi | Development |
|---|---|---|
| `APP_URL` | URL backend | `https://core-r.p85.test:8443` |
| `DB_*` | PostgreSQL | Connection `core`, host `postgres`, DB `laravel_core` |
| `CORE_DB_PREFIX` | Prefix tabel boilerplate | `rcore_` |
| `REDIS_PREFIX` | Isolasi key | `core-r:` |
| `REDIS_CACHE_DB` | Cache | `0` |
| `REDIS_QUEUE_DB` | Queue | `1` |
| `REDIS_SESSION_DB` | Session | `2` |
| `MAIL_HOST`, `MAIL_PORT` | SMTP | `maildev`, `1025` |
| `FILESYSTEM_DISK` | Storage | `s3` |
| `AWS_*` | MinIO | Endpoint `http://minio:9000` |
| `API_DOCS_ENABLED` | Swagger | `true` pada local |
| `TELESCOPE_ENABLED` | Telescope | `true` pada local |
| `BROADCAST_CONNECTION` | Transport realtime | `null` atau `reverb` |
| `REVERB_APP_*` | Credential aplikasi Reverb | Nilai unik dari environment lokal |
| `REVERB_ALLOWED_ORIGINS` | Origin WebSocket yang diizinkan | Daftar host dipisahkan koma |

| Resource | Development | Testing |
|---|---|---|
| PostgreSQL | `laravel_core` | `laravel_core_test` |
| Redis cache | DB `0` | DB `13` |
| Redis default/queue | DB `1` | DB `14` |
| Redis session | DB `2` | DB `15` |
| Redis prefix | `core-r:` | Prefix testing terpisah |

`RefreshDatabase` hanya boleh berjalan pada `laravel_core_test`. Composer, Artisan, Pest, dan Pint harus dijalankan di container PHP 8.5.

## Identity dan token

Login menerima `email`, `password`, dan `device_name`, lalu mengembalikan Sanctum Bearer token. Client mengirim token melalui `Authorization: Bearer <token>`.

| Kebijakan | Perilaku |
|---|---|
| Registrasi | Dikendalikan `auth.registration_enabled` |
| Verifikasi email | Dikendalikan `auth.email_verification_required` |
| User suspended | Tidak dapat login atau memakai endpoint terproteksi |
| Logout | Mencabut token aktif tanpa request body |
| Logout all | Mencabut seluruh token user tanpa request body |
| Ganti password | Mencabut token lain dan mempertahankan token aktif |
| Reset password | Mencabut seluruh token lama |
| Token expiration | Mengikuti `auth.token_expiration_days` saat token dibuat |

User dapat melihat token atau perangkat melalui `GET /api/auth/tokens` dan mencabut token miliknya melalui `DELETE /api/auth/tokens/{token}`.

## Multilingual API

API mendukung English (`en`) dan Bahasa Indonesia (`id`) dengan fallback `en`. Client dapat mengirim `Accept-Language: id` atau regional tag seperti `id-ID`. Response menyertakan `Content-Language`.

Jika header bahasa tidak dikirim, API memakai preferensi `locale` user, kemudian setting `app.locale`, lalu fallback. Preferensi disimpan melalui `PATCH /api/auth/profile`. Daftar bahasa tersedia melalui `GET /api/locales`.

Response error memiliki `code` yang stabil untuk logika client, sedangkan `message` dan validation errors diterjemahkan:

```json
{
  "success": false,
  "code": "auth.unauthenticated",
  "message": "Anda belum terautentikasi.",
  "errors": []
}
```

Permission, role, enum, event key, setting key, field API, dan identifier tetap machine-readable dan tidak diterjemahkan.

## Notification

Notification memakai Laravel database notification sebagai inbox dan Laravel Mail sebagai channel email. Payload database menyimpan translation key serta parameter aman sehingga teks dapat dirender mengikuti bahasa request.

| Method | Endpoint | Fungsi |
|---|---|---|
| `GET` | `/api/notifications` | Inbox dengan filter `status`, `category`, dan cursor |
| `GET` | `/api/notifications/unread-count` | Jumlah notifikasi belum dibaca |
| `GET` | `/api/notifications/{notification}` | Detail notifikasi milik user |
| `PATCH` | `/api/notifications/{notification}/read` | Tandai sudah dibaca |
| `DELETE` | `/api/notifications/{notification}/read` | Tandai belum dibaca |
| `POST` | `/api/notifications/read-all` | Tandai seluruhnya sudah dibaca |
| `DELETE` | `/api/notifications/{notification}` | Hapus notifikasi dari inbox |
| `GET` | `/api/notification-preferences` | Preferensi channel efektif |
| `PUT` | `/api/notification-preferences` | Ubah channel per kategori |

Kategori awal adalah `security`, `account`, dan `system`. Channel keamanan wajib tidak dapat dimatikan. Email diproses melalui queue dan memakai locale recipient.

## RBAC

Role tersimpan secara dinamis. Controller memeriksa permission `resource.action` dan tidak membuat keputusan berdasarkan nama role.

| Permission | Kemampuan |
|---|---|
| `users.view` | Melihat user |
| `users.create` | Membuat user |
| `users.suspend` | Mengubah status user |
| `users.assign-roles` | Mengganti role user |
| `roles.view` | Melihat role dan permission |
| `roles.create` | Membuat role |
| `roles.update` | Mengubah role |
| `roles.delete` | Menghapus role tidak terpakai |
| `settings.view` | Melihat settings dan metadata |
| `settings.update` | Mengubah settings |
| `audit.view` | Membaca audit events |
| `files.view-any` | Melihat metadata file milik user lain |
| `files.download-any` | Mengunduh file milik user lain |
| `files.update-any` | Mengubah metadata file milik user lain |
| `files.delete-any` | Menghapus file milik user lain |

| Role bawaan | Akses |
|---|---|
| `super-admin` | Seluruh permission registry |
| `admin` | Administrasi user, settings, role read-only, dan audit |
| `user` | Role dasar tanpa akses administrasi |

Pengamanan yang diterapkan:

- Pengelola akses terakhir tidak dapat disuspensi atau kehilangan akses pengelolaan.
- Perubahan kritis memakai transaction dan database lock.
- Update role wajib membawa `updated_at` terbaru; versi lama menghasilkan HTTP `409`.
- Role yang sedang digunakan tidak dapat dihapus.
- Suspensi user mencabut seluruh tokennya.

Setelah registry permission berubah, jalankan `RbacSeeder` kembali.

## Settings dan audit

Definisi settings berada di `Modules/Settings/SettingsRegistry.php`. Nilai database menimpa default kode dan dicache di Redis.

| Key | Tipe | Default | Publik | Fungsi |
|---|---|---:|:---:|---|
| `app.name` | string | `Core R` | Ya | Nama aplikasi |
| `app.locale` | string | `en` | Ya | Locale default |
| `app.timezone` | string | `Asia/Jakarta` | Ya | Timezone default |
| `auth.registration_enabled` | boolean | `false` | Ya | Registrasi publik |
| `auth.email_verification_required` | boolean | `true` | Ya | Verifikasi sebelum login |
| `auth.token_expiration_days` | integer | `30` | Tidak | Masa berlaku token baru |
| `auth.max_login_attempts` | integer | `5` | Tidak | Batas percobaan login |
| `files.max_upload_mb` | integer | `10` | Tidak | Batas ukuran upload |
| `files.allowed_mime_types` | array | PDF, JPEG, PNG, WebP, text | Tidak | MIME type yang diizinkan |
| `realtime.event_retention_days` | integer | `7` | Tidak | Retensi durable event untuk polling |

Metadata settings menyediakan key, value, type, default, rules, public, editable, dan description. Rahasia tetap disimpan melalui environment.

Audit merekam authentication dan perubahan administratif seperti settings, status dan role user, role RBAC, pembuatan user, serta pencabutan token. Password, plain text token, reset token, dan credential tidak disimpan pada metadata audit.

## Daftar endpoint

### System dan authentication

| Method | Endpoint | Akses | Fungsi |
|---|---|---|---|
| `GET` | `/api/health` | Publik | Status layanan |
| `GET` | `/api/example` | Publik | Contoh modul |
| `POST` | `/api/auth/register` | Publik/settings | Registrasi |
| `POST` | `/api/auth/login` | Publik | Membuat token |
| `POST` | `/api/auth/email/resend` | Publik | Kirim ulang verifikasi |
| `GET` | `/api/auth/email/verify/{id}/{hash}` | Signed URL | Verifikasi email |
| `POST` | `/api/auth/forgot-password` | Publik | Meminta reset password |
| `POST` | `/api/auth/reset-password` | Publik | Reset password |
| `GET` | `/api/auth/me` | Bearer | Profil dan akses efektif |
| `PATCH` | `/api/auth/profile` | Bearer | Ubah profil |
| `PUT` | `/api/auth/password` | Bearer | Ganti password |
| `POST` | `/api/auth/logout` | Bearer | Cabut token aktif |
| `POST` | `/api/auth/logout-all` | Bearer | Cabut semua token |
| `GET` | `/api/auth/tokens` | Bearer | Daftar token |
| `DELETE` | `/api/auth/tokens/{token}` | Bearer | Cabut satu token |

### User dan RBAC

| Method | Endpoint | Permission | Fungsi |
|---|---|---|---|
| `GET` | `/api/users` | `users.view` | Daftar/filter user dengan cursor |
| `POST` | `/api/users` | `users.create` | Membuat user terverifikasi |
| `GET` | `/api/users/{user}` | `users.view` | Detail, akses efektif, jumlah token |
| `PATCH` | `/api/users/{user}/status` | `users.suspend` | Active/suspended |
| `PUT` | `/api/users/{user}/roles` | `users.assign-roles` | Mengganti seluruh role |
| `GET` | `/api/permissions` | `roles.view` | Permission registry |
| `GET` | `/api/roles` | `roles.view` | Daftar role |
| `POST` | `/api/roles` | `roles.create` | Membuat role |
| `PATCH` | `/api/roles/{role}` | `roles.update` | Update dengan `updated_at` |
| `DELETE` | `/api/roles/{role}` | `roles.delete` | Hapus role tidak terpakai |

Filter user: `search`, `status`, `role`, `per_page`, dan `cursor`.

### Settings dan audit

| Method | Endpoint | Akses | Fungsi |
|---|---|---|---|
| `GET` | `/api/settings/public` | Publik | Public settings |
| `GET` | `/api/settings` | `settings.view` | Semua settings |
| `GET` | `/api/settings/metadata` | `settings.view` | Metadata lengkap |
| `PATCH` | `/api/settings` | `settings.update` | Update settings |
| `GET` | `/api/audit-events` | `audit.view` | Audit dengan filter dan cursor |

### File Management

File selalu private dan API memakai UUID publik. Storage path, disk, checksum, credential, dan primary key internal tidak dikirim kepada client.

| Method | Endpoint | Akses | Fungsi |
|---|---|---|---|
| `GET` | `/api/files` | Owner | Daftar file milik user dengan cursor pagination |
| `POST` | `/api/files` | Bearer | Upload multipart file |
| `GET` | `/api/files/{file}` | Owner / `files.view-any` | Metadata file |
| `PATCH` | `/api/files/{file}` | Owner / `files.update-any` | Ubah display name dan metadata |
| `GET` | `/api/files/{file}/download` | Owner / `files.download-any` | Download private terotorisasi |
| `DELETE` | `/api/files/{file}` | Owner / `files.delete-any` | Soft delete dan antrekan physical cleanup |

Filter daftar file: `search`, `mime_type`, `from`, `to`, `per_page`, dan `cursor`. File yang masih menjadi attachment harus dilepas sebelum dapat dihapus. Binary selalu disimpan melalui Laravel Filesystem sehingga MinIO dapat diganti dengan storage S3-compatible lain melalui environment.

Audit mendukung filter `event`, `actor_id`, `per_page`, dan `cursor`. Detail request dan response tersedia pada Swagger.

## Standar API

Response sukses:

```json
{"success": true, "data": {"message": "Operation completed successfully."}}
```

Response gagal:

```json
{
  "success": false,
  "code": "validation.failed",
  "message": "Validation failed.",
  "errors": {"email": ["The email field must be a valid email address."]}
}
```

| HTTP | Arti |
|---:|---|
| `401` | Bearer token hilang/tidak valid |
| `403` | Tidak memiliki permission atau akun unavailable |
| `404` | Resource tidak ditemukan/bukan milik user |
| `409` | Resource berubah sejak terakhir dibaca |
| `422` | Validasi atau aturan bisnis gagal |
| `429` | Rate limited |
| `500` | Error internal tidak terduga |

Endpoint koleksi memakai cursor pagination. `per_page` default `20`, minimum `1`, maksimum `100`. Client meneruskan `next_cursor` atau `prev_cursor` sebagai query `cursor`, serta wajib memperlakukannya sebagai string opaque. Query harus memiliki urutan stabil dan primary key sebagai urutan terakhir.

## Swagger dan Telescope

Swagger memakai OpenAPI 3.1 dan aset lokal. Generate artefak statis:

```sh
docker exec -w /var/www/p85/core-r dev-php85 php artisan api-docs:generate
```

Output berada di `storage/app/api-docs/openapi.json` dan tidak perlu di-commit. Endpoint baru wajib memiliki operation ID, summary, tag, security, parameter, request body, response, contoh, dan test dokumentasi. Gunakan `API_DOCS_ENABLED=false` untuk menyembunyikan dokumentasi.

Telescope merekam request/response, query, cache, Redis, job, mail, notification, log, dan exception. Password, reset token, cookie, serta header `Authorization` disembunyikan. Gunakan `TELESCOPE_ENABLED=false` untuk menonaktifkannya.

## Queue dan scheduler

| Service | Fungsi |
|---|---|
| `core-r-queue` | Memproses queue `high,default,low` |
| `core-r-scheduler` | Menjalankan scheduler setiap 60 detik |

| Jadwal | Command | Fungsi |
|---|---|---|
| Setiap menit | `core:heartbeat` | Probe scheduler |
| Harian | `sanctum:prune-expired --hours=24` | Bersihkan token expired |
| Harian 02:00 | `telescope:prune --hours=48` | Bersihkan data Telescope lama |
| Harian 02:15 | `realtime:prune-events` | Bersihkan durable event yang melewati retensi |

Service menggunakan PHP 8.5 dan `docker-network`; layanan infrastruktur bersama tidak diubah.

## Realtime dan polling

Setiap event disimpan dahulu di tabel `user_events`, lalu dikirim ke Reverb bila broadcasting aktif. Reverb bersifat opsional: REST API dan polling tetap berfungsi saat `BROADCAST_CONNECTION=null` atau server WebSocket tidak tersedia.

| Method | Endpoint | Fungsi |
|---|---|---|
| `GET` | `/api/events/cursor` | Mengambil cursor pada batas event terbaru |
| `GET` | `/api/events` | Mengambil event setelah `cursor`, dengan filter `types[]` dan `limit` |
| `POST` | `/api/broadcasting/auth` | Otorisasi channel privat memakai Sanctum bearer token |

Channel user adalah `private-users.{userId}` dan event broadcast bernama `.core.event`. Response polling membawa envelope yang sama: `id`, `type`, `schema_version`, `resource`, `payload`, dan `created_at`. Client menyimpan `next_cursor` setelah seluruh batch berhasil diproses dan melakukan deduplikasi memakai event `id`.

Cursor bersifat opaque. HTTP `409` dengan code `realtime.cursor_expired` meminta client memuat ulang resource canonical dan mengambil cursor baru. Retensi dikendalikan setting `realtime.event_retention_days`.

Untuk menambah tipe event, daftarkan key beserta versi schema di `Modules/Realtime/EventRegistry.php`, lalu gunakan kontrak `RealtimePublisher`. Publisher menulis event setelah transaksi database commit. Jangan memasukkan token, credential, path storage, atau model serialization mentah ke payload.

## Module system

Buat modul:

```sh
docker exec -w /var/www/p85/core-r dev-php85 php artisan make:module Billing
```

Provider dan route modul dimuat melalui `Core/Providers/ModuleServiceProvider.php`. Modul baru perlu menambahkan migration, permission, audit, OpenAPI, dan Feature test sesuai kebutuhannya.

Tabel boilerplate memakai connection `core`, yang mengarah ke PostgreSQL yang sama dengan prefix fisik `rcore_`. Tabel modul bisnis tanpa prefix harus memakai connection `pgsql` secara eksplisit pada migration dan model. Relasi ke user mengacu ke tabel fisik `rcore_users`. Konvensi ini menjaga tabel fondasi mudah dibedakan dari tabel domain.

## Testing

```sh
docker exec -w /var/www/p85/core-r dev-php85 php artisan test
docker exec -w /var/www/p85/core-r dev-php85 vendor/bin/pint --test
```

Test mencakup isolasi PostgreSQL/Redis, health, response API, module system, queue, scheduler, identity, token ownership, RBAC, concurrency, admin protection, user filters, cursor pagination, settings, audit, Swagger, dan Telescope configuration.

## Checklist pengembangan

- [ ] Migration dan test memakai database yang benar.
- [ ] Permission baru didaftarkan pada `RbacSeeder`.
- [ ] Endpoint memakai authentication dan permission yang sesuai.
- [ ] Input divalidasi dan response mengikuti standar API.
- [ ] Koleksi memakai cursor pagination dengan urutan stabil.
- [ ] Aktivitas keamanan/administrasi dicatat ke audit.
- [ ] Swagger menjelaskan parameter, body, response, dan error.
- [ ] Tidak ada credential atau plain text token dalam source, log, atau audit.
- [ ] `php artisan test` lulus pada PHP 8.5.
- [ ] `vendor/bin/pint --test` lulus pada PHP 8.5.
