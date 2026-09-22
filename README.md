# Core R

Fondasi Laravel 13 pada PHP 8.5. Saat development, aplikasi memakai layanan PostgreSQL, Redis, Nginx, MailDev, dan MinIO yang sudah ada di jaringan Docker `docker-network`.

## Setup lokal

Buat database PostgreSQL `laravel_core` dan `laravel_core_test`. Salin `.env.example` menjadi `.env` dan `.env.testing.example` menjadi `.env.testing`, lalu isi kredensial lokal dan bucket MinIO. Kedua file lokal itu diabaikan oleh Git. Jalankan Composer dan Artisan di container PHP 8.5:

```sh
docker exec dev-php85 sh -lc 'cd /var/www/p85/core-r && composer install && php artisan key:generate && php artisan migrate'
docker exec dev-php85 sh -lc 'cd /var/www/p85/core-r && php artisan key:generate --env=testing && php artisan test'
```

Jalankan worker dan scheduler khusus proyek:

```sh
docker compose -f compose.jobs.yml up -d
```

Worker mendengarkan queue `high,default,low`. Scheduler menjalankan `core:heartbeat` setiap menit sebagai probe awal. Kedua proses memakai PHP 8.5 dan jaringan bersama; layanan infrastruktur tidak dibuat ulang.

Nginx bersama melayani aplikasi di `https://core-r.p85.test:8443`; host perlu diarahkan ke mesin development dan sertifikat development perlu dipercaya. Cek API di `/api/health`. Respons health memberi status layanan wajib (PostgreSQL, Redis) dan opsional (storage, AI). Gunakan `php artisan make:module Name` untuk membuat provider dan route modul baru.

Redis memakai DB 0 untuk cache, 1 untuk queue, dan 2 untuk session. Testing memakai DB 13, 14, dan 15 dengan prefix berbeda. Tes database memakai `laravel_core_test`, terpisah dari database development. Jalankan `php artisan test` dan `vendor/bin/pint --test` di PHP 8.5 sebelum merge.

## Standar dokumentasi API

Swagger UI tersedia di `https://core-r.p85.test:8443/docs/api` dan dokumen OpenAPI JSON di `/docs/api/openapi.json`. UI memakai aset lokal dari Composer. Set `API_DOCS_ENABLED=false` untuk menyembunyikan keduanya, terutama pada production bila dokumentasi tidak boleh publik.

Dokumentasikan setiap endpoint baru menggunakan atribut `OpenApi\\Attributes` pada controller. Setiap operasi wajib memiliki path, operation ID unik, ringkasan, tag, serta semua response yang mungkin. Gunakan kembali schema di `Core/OpenApi/OpenApiSpec.php` untuk bentuk respons bersama. Jalankan perintah berikut untuk memvalidasi atribut dan menghasilkan artefak OpenAPI statis:

```sh
php artisan api-docs:generate
```

Artefak default ditulis ke `storage/app/api-docs/openapi.json` dan tidak perlu di-commit. Tes API harus memeriksa perilaku endpoint serta keberadaannya dalam dokumen OpenAPI.

Endpoint koleksi menggunakan cursor pagination. Client mengirim `per_page` (default 20, maksimum 100) dan meneruskan nilai opaque `next_cursor` atau `prev_cursor` sebagai query `cursor`. Client tidak boleh membaca atau membentuk isi cursor sendiri. Query koleksi harus mempunyai urutan yang stabil dan unik, dengan primary key sebagai urutan terakhir.

## Identity, RBAC, dan Settings

Jalankan migrasi dan seed registry permission setelah instalasi atau deployment:

```sh
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\RbacSeeder --force
```

Buat administrator pertama secara interaktif. Perintah akan menolak bootstrap jika `super-admin` sudah dimiliki user lain:

```sh
php artisan identity:bootstrap-admin admin@example.com --name="Administrator"
```

Authentication menggunakan Sanctum Bearer token. Profil user dibatasi pada nama, email, status `active`/`suspended`, waktu verifikasi, dan login terakhir. Role disimpan secara dinamis dan memperoleh permission berbentuk `resource.action`; controller tidak membuat keputusan berdasarkan nama role. Permission merupakan registry kapabilitas aplikasi yang dikelola oleh `RbacSeeder`.

Token milik user dapat dilihat melalui `GET /api/auth/tokens` dan dicabut per perangkat melalui `DELETE /api/auth/tokens/{token}`. Administrasi user mendukung pencarian serta filter status dan role dengan cursor pagination. Perubahan role wajib mengirim nilai `updated_at` terbaru agar perubahan admin lain tidak tertimpa.

Settings global memiliki definisi tipe, default, validasi, dan visibilitas publik di `Modules/Settings/SettingsRegistry.php`. Nilai database menimpa default kode dan dicache di Redis. Credential, encryption key, serta koneksi infrastruktur tetap menggunakan environment. Perubahan RBAC, status user, settings, dan peristiwa authentication dicatat di `audit_events` tanpa password atau token.

Metadata settings tersedia melalui `GET /api/settings/metadata`. Audit dapat dibaca oleh pemilik permission `audit.view` melalui `GET /api/audit-events` dengan filter `event`, `actor_id`, dan cursor pagination. Scheduler membersihkan token Sanctum kedaluwarsa setiap hari dan data Telescope berumur lebih dari 48 jam.

## Telescope

Laravel Telescope tersedia di `https://core-r.p85.test:8443/telescope` untuk memantau request dan response API, query, cache, Redis, job, mail, notification, log, serta exception. Password, reset token, cookie, dan header Authorization disembunyikan dari rekaman.

Telescope aktif secara default hanya pada environment `local`. Gunakan `TELESCOPE_ENABLED=false` untuk menonaktifkannya. Bersihkan data lama secara berkala:

```sh
php artisan telescope:prune --hours=48
```
