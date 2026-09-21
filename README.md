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
