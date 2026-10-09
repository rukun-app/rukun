# Rukun Backend

Rukun adalah backend pengelolaan lingkungan RW/RT berbasis Core R (Laravel 13 dan PHP 8.5). Proyek menyediakan autentikasi Sanctum, manajemen user, RBAC dinamis, runtime settings, audit trail, OpenAPI, Telescope, queue worker, dan scheduler.

Aplikasi memakai PostgreSQL, Redis, Nginx, MailDev, dan MinIO bersama pada jaringan Docker eksternal `docker-network`. Repo ini tidak membuat ulang layanan tersebut.

## Implementasi Rukun

Implementasi dilakukan satu fase setiap tahap mengikuti [plan-be.md](plan-be.md). **F0–F7 selesai untuk development lokal; F8 belum dimulai; CCTV HOLD.** Checklist dan log di plan tersebut menjadi catatan status Rukun. Tabel foundation di bawah merupakan kemampuan baseline Core R, bukan bukti seluruh gate Rukun telah lulus.

Template `.env.example` menggunakan database `rukun`, Redis prefix `rukun:` dengan DB 3/4/5, bucket private `rukun`, dan hostname `rukun.p85.test`. Compose menggunakan project `rukun`, service `rukun-queue`, `rukun-scheduler`, dan `rukun-reverb`, dengan mount `/var/www/p85/rukun`. Seluruhnya tetap memakai shared network `docker-network` tanpa membuat layanan infrastruktur duplikat. Database development/testing dan bucket sudah diprovisikan; health HTTPS serta uji tulis/baca object storage lulus.

`.env` dan `.env.testing` lokal sudah dibuat dari template Rukun dengan credential infrastruktur bersama dari `../core-r`. Keduanya diabaikan Git dan memiliki permission `0600`; APP_KEY development/testing serta credential Reverb dibuat baru. Midtrans nonaktif pada F0, lalu dikonfigurasi sandbox pada F3 dan dihubungkan ke invoice pada F5. Database `rukun` / `rukun_test`, PHPUnit, CI, dan `AGENTS.md` sudah diselaraskan. Jalankan Composer/Artisan/Pest/Pint di `dev-php85` dengan working directory `/var/www/p85/rukun`; jangan memakai database Core R untuk test Rukun. Redis testing tetap 13–15 dengan prefix khusus `rukun-test:`. Credential hanya disimpan dalam environment lokal yang diabaikan Git.

Remote existing: `origin` adalah `git@github.com:rukun-app/rukun.git`; `upstream` adalah `git@github.com:RezaRiyaldi/core-r.git` dengan push dinonaktifkan. Pembaruan foundation dilakukan pada branch khusus melalui `git fetch upstream` dan `git merge upstream/main`, kemudian review migration/config/conflict dan jalankan seluruh quality gate sebelum merge ke branch utama.

Untuk staging, siapkan HTTPS, `APP_DEBUG=false`, `LOG_CHANNEL=json`, `TELESCOPE_ENABLED=false`, secret melalui environment, dan bucket private khusus environment. Backup harus mencakup PostgreSQL, object storage, serta penyimpanan aman encryption key secara terpisah. Tentukan jadwal/retention dan target pemulihan sebelum pilot. Restore dump dan object ke environment staging terisolasi, lalu verifikasi health, login, akses file, dan rekonsiliasi data; catat waktu serta hasilnya pada log F0. **Backup/restore staging belum diuji.**

Verifikasi baseline F0 lokal 28 September 2026: **96 test / 549 assertions**, Pint, OpenAPI (49 paths / 59 operations), secret scan, HTTPS health, MinIO private write/read/delete, job melalui worker Rukun, dan scheduler heartbeat lulus. Pengiriman email probe ke MailDev lokal berhasil dan hasilnya diperiksa melalui API MailDev. Dump/restore PostgreSQL lokal ke database sementara lulus dan database rehearsal sudah dihapus; ini belum menggantikan uji staging. Admin development `admin@rukun.test` telah dibuat; login dan `/api/auth/me` lulus melalui HTTPS, kemudian token probe dicabut. Credential bootstrap tersimpan di `storage/app/private/bootstrap-admin.json` (Git ignored, mode `0600`); akun lokal ini sudah ditandai wajib mengganti password, lalu hapus file tersebut. CI GitHub dan backup/restore staging tetap wajib sebelum production pilot, tetapi sesuai arahan pengguna tidak menghalangi development F1.

## RBAC admin payload

Kontrak admin RBAC sekarang menormalisasi `permissions` menjadi daftar string flat, bukan objek permission bersarang dengan `pivot` metadata. Hal ini membuat UI admin untuk roles dan users dapat langsung membaca izin yang aktif tanpa pemrosesan tambahan.

## Flow akun warga dan role default

Model domain yang benar adalah `household/KK -> resident/warga -> user account`. Pembuatan akun tidak menggantikan data rumah tangga; akun dibuat sebagai langkah berikutnya untuk warga yang memang membutuhkan login. Endpoint `POST /api/community/residents/{resident}/account` menghubungkan akun pada warga yang bersangkutan, dengan default role backend `warga` dan penguatan role RT/RW/admin dilakukan lewat assignment role yang terpisah. UI admin di ruang pengurus mengikuti azas ini agar tidak menganggap rumah tangga sebagai user yang langsung login.

Contoh payload yang dipakai oleh admin UI:

```json
{
  "roles": ["super-admin", "user"],
  "permissions": ["settings.view", "users.assign-roles", "audit.view"]
}
```

Endpoint utama yang memakai kontrak ini adalah `GET /api/users`, `GET /api/users/{id}`, `GET /api/roles`, dan `PATCH /api/roles/{id}`.

## Daftar isi

- [Status implementasi](#status-implementasi)
- [Memulai proyek baru](#memulai-proyek-baru)
- [Teknologi dan struktur](#teknologi-dan-struktur)
- [Instalasi lokal](#instalasi-lokal)
- [Menjalankan aplikasi](#menjalankan-aplikasi)
- [Environment dan isolasi testing](#environment-dan-isolasi-testing)
- [Identity dan token](#identity-dan-token)
- [Multilingual API](#multilingual-api)
- [Notification](#notification)
- [RBAC](#rbac)
- [Settings dan audit](#settings-dan-audit)
- [Daftar endpoint](#daftar-endpoint)
- [Standar API](#standar-api)
- [Swagger dan Telescope](#swagger-dan-telescope)
- [Queue dan scheduler](#queue-dan-scheduler)
- [Realtime dan polling](#realtime-dan-polling)
- [Operational reliability](#operational-reliability)
- [API reliability dan protection](#api-reliability-dan-protection)
- [Automated quality gate](#automated-quality-gate)
- [Import dan export](#import-dan-export)
- [Payment foundation](#payment-foundation)
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
| Pagination | Selesai | Cursor default; Spatie filter/fields/sort/include dan Laravel page pagination untuk daftar resource |
| V2.1: File Management | Selesai | Private upload/download, metadata, ownership policy, admin permissions, attachment service, audit, dan queued cleanup |
| V2.2: Multilingual | Selesai | Locale `en`/`id`, `Accept-Language`, preferensi user, translated validation/error, stable error code, dan localized settings metadata |
| V2.3: Notification | Selesai | Localized inbox, email channel, preferences, read/unread lifecycle, cursor pagination, queue priority, dan MailDev verification |
| V2.4: Realtime dan Polling | Selesai | Durable user events, opaque cursor, private Reverb channel, notification event, retention, dan fallback polling |
| V2.5: Operational Reliability | Selesai | Request ID, correlation context, JSON logging, redaction, job policy, failed job summary, dan pruning |
| V2.6: API Reliability & Protection | Selesai | Idempotency key untuk operasi create dan named Redis rate limiter per kelompok endpoint |
| V2.7: Automated Quality Gate | Selesai | GitHub Actions dengan PHP 8.5, PostgreSQL 16, Redis 7, Pest, Pint, OpenAPI, dan pemeriksaan secret |
| V2.8B: Import/Export Foundation | Selesai | Registry handler, CSV/XLSX async, progress, error per baris, cancel, private output, realtime, dan retention |
| V2.8C: Payment Foundation | Selesai | Checkout API idempotent, Midtrans Snap, callback dan test notification terverifikasi, redirect `en`/`id`, audit, dan realtime event |
| V2.9: Runtime Settings Governance | Selesai | Database override, fallback environment, default kode, metadata group/type/source, dan reset override |
| Roadmap V2 lanjutan | Direncanakan | Webhook keluar generik; lihat [`plan-v2.md`](plan-v2.md) |
| Rukun F1 | Selesai | Identity email/HP, provisioning/recovery scoped, RW/RT, dan temporal role assignment; lihat `plan-be.md` |
| Rukun F2 | Selesai lokal | Household, Resident, membership, scope HOUSEHOLD/VENDOR, sensitive identifiers, dan import/export CSV/XLSX |
| Rukun F3 | Selesai lokal | Tariff snapshot, invoice, cash receipt, transfer manual, allocation, ledger, expense, reversal, dan tutup buku |

Verifikasi F4 (30 September 2026, historis): **166 test lulus dengan 1173 assertions**, Laravel Pint lulus, OpenAPI tervalidasi (**126 paths / 155 operations**), secret scan dan diff check lulus pada PHP 8.5. Migration/seeder settlement dan galon diterapkan di development; worker restart, scheduler expiry terdaftar, dan HTTPS health database/Redis/storage sehat. Rekonsiliasi sintetis mencakup remittance/advance/recovery, reversal dan ledger galon, termasuk konkurensi invoice, setoran, serta reservasi kuota. Quick Tunnel diperbarui setelah endpoint lama tidak berlaku; signed sandbox webhook pada URL pengganti lulus, tanpa membuat transaksi gateway nyata. Sign-off kebijakan dan data pilot nyata masih pending; F5 belum dimulai.

## Memulai proyek baru

Repository ini sudah menjadi proyek Rukun. Untuk instalasi working copy Rukun baru, ikuti [Instalasi lokal](#instalasi-lokal). Pertahankan Core R sebagai upstream read-only; jangan menghapus Git history atau mengganti prefix tabel foundation `rcore_`.

Pembaruan upstream dilakukan pada branch khusus:

```sh
git fetch upstream
git switch -c chore/update-core-r
git merge upstream/main
```

Review migration, konfigurasi, dan conflict terhadap domain Rukun, lalu jalankan Pest, Pint, OpenAPI validation, serta secret scan sebelum merge ke branch utama.

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
| `Modules/DataTransfer/` | Orchestration import/export CSV/XLSX, handler registry, progress, cancel, dan cleanup |
| `Modules/Payments/` | Checkout API, payment contract, Midtrans Snap, callback, redirect, dan status event |
| `Modules/Example/` | Contoh struktur modul |
| `tests/Feature/` | Test API, infrastruktur, auth, RBAC, settings, dan modul |
| `compose.jobs.yml` | Worker dan scheduler Rukun |
| `compose.realtime.yml` | Server Reverb opsional khusus Rukun |

## Instalasi lokal

Prasyarat:

- Container PHP `dev-php85` memasang source di `/var/www/p85/rukun`.
- Network eksternal `docker-network` memiliki alias `postgres`, `redis`, `maildev`, dan `minio`.
- Database `rukun` dan `rukun_test` sudah dibuat.
- Bucket MinIO tersedia dan host `rukun.p85.test` diarahkan ke mesin development.

Salin dan isi environment lokal. Jangan commit kredensial nyata.

```sh
cp .env.example .env
cp .env.testing.example .env.testing

docker exec -w /var/www/p85/rukun dev-php85 composer install
docker exec -w /var/www/p85/rukun dev-php85 php artisan key:generate
docker exec -w /var/www/p85/rukun dev-php85 php artisan key:generate --env=testing
docker exec -w /var/www/p85/rukun dev-php85 php artisan migrate
docker exec -w /var/www/p85/rukun dev-php85 php artisan db:seed --no-interaction
```

Buat administrator pertama:

```sh
docker exec -it -w /var/www/p85/rukun dev-php85 php artisan identity:bootstrap-admin admin@example.com --name="Administrator"
```

Akun bootstrap dibuat aktif, terverifikasi, dan memiliki role `super-admin`. Command menolak bootstrap kedua jika role itu sudah dimiliki user lain.

## Menjalankan aplikasi

| Layanan | Alamat |
|---|---|
| Aplikasi | `https://rukun.p85.test:8443` |
| Health | `https://rukun.p85.test:8443/api/health` |
| Swagger | `https://rukun.p85.test:8443/docs/api` |
| OpenAPI JSON | `https://rukun.p85.test:8443/docs/api/openapi.json` |
| Telescope | `https://rukun.p85.test:8443/telescope` |

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
| `APP_URL` | URL backend | `https://rukun.p85.test:8443` |
| `DB_*` | PostgreSQL | Connection `core`, host `postgres`, DB `rukun` |
| `CORE_DB_PREFIX` | Prefix tabel boilerplate | `rcore_` |
| `REDIS_PREFIX` | Isolasi key | `rukun:` |
| `REDIS_CACHE_DB` | Cache | `3` |
| `REDIS_QUEUE_DB` | Queue | `4` |
| `REDIS_SESSION_DB` | Session | `5` |
| `MAIL_HOST`, `MAIL_PORT` | SMTP | `maildev`, `1025` |
| `FILESYSTEM_DISK` | Storage | `s3` |
| `AWS_*` | MinIO | Endpoint `http://minio:9000` |
| `API_DOCS_ENABLED` | Swagger | `true` pada local |
| `TELESCOPE_ENABLED` | Telescope | `true` pada local |
| `BROADCAST_CONNECTION` | Transport realtime | `null` atau `reverb` |
| `REVERB_APP_*` | Credential aplikasi Reverb | Nilai unik dari environment lokal |
| `REVERB_ALLOWED_ORIGINS` | Origin WebSocket yang diizinkan | Daftar host dipisahkan koma |
| `LOG_CHANNEL` | Format log utama | `stack` untuk local, `json` untuk structured stderr |
| `CACHE_LIMITER` | Store rate limiter | `redis` |
| `RATE_LIMIT_*` | Batas named rate limiter per menit | Lihat `.env.example` |
| `PAYMENT_GATEWAY` | Adapter pembayaran aktif | `midtrans` |
| `MIDTRANS_*` | Mode, credential, endpoint override, dan timeout Midtrans | Credential hanya pada environment lokal |
| `PAYMENT_REDIRECT_BASE_URL` | Origin HTTPS halaman finish/unfinish/error | Fallback ke `APP_URL` |
| `DATA_TRANSFER_RETENTION_DAYS` | Retensi transfer terminal dan export yang dihasilkan | `30` |

| Resource | Development | Testing |
|---|---|---|
| PostgreSQL | `rukun` | `rukun_test` |
| Redis cache | DB `3` | DB `13` |
| Redis default/queue | DB `4` | DB `14` |
| Redis session | DB `5` | DB `15` |
| Redis prefix | `rukun:` | Prefix testing terpisah |

`RefreshDatabase` hanya boleh berjalan pada `rukun_test`. Composer, Artisan, Pest, dan Pint harus dijalankan di container PHP 8.5.

## Identity dan token

Login menerima `identifier` (email atau HP Indonesia), `password`, dan `device_name`, lalu mengembalikan Sanctum Bearer token. Field legacy `email` masih diterima sebagai alternatif `identifier`; mengirim keduanya ditolak. Client mengirim token melalui `Authorization: Bearer <token>`.

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

## Community: area dan pengelolaan akun (F1)

Modul `Modules/Community` menyediakan RW/RT, assignment pengurus, dan provisioning/recovery akun. Semua ID resource pada endpoint baru berupa UUID publik. `User`, `Resident`, dan `Household` tetap berbeda: Resident/Household dan importer warga tersedia pada F2 di bawah.

| Method | Endpoint | Akses / perilaku |
|---|---|---|
| GET / POST | `/api/community/areas` | Daftar cursor dalam scope / buat RW atau RT |
| GET / PATCH / DELETE | `/api/community/areas/{area}` | Permission `areas.view` / `areas.manage` dan scope |
| GET / POST | `/api/community/role-assignments` | Global `users.assign-roles`; assignment dengan periode berlaku |
| DELETE | `/api/community/role-assignments/{assignment}` | Revoke; histori tetap disimpan |
| POST | `/api/community/accounts` | `accounts.provision` pada RT; `Idempotency-Key` wajib |
| POST | `/api/community/accounts/{user}/recover` | `users.recover-account` pada RT akun; `Idempotency-Key` wajib |
| GET | `/api/community/credentials/{operation}` | Download credential satu kali, hanya pembuat yang masih berwenang |

RW dibuat dengan `kind=rw`, `code`, dan `name`; RT menggunakan `kind=rt` serta `parent_id` UUID RW. PATCH hanya menerima `code`/`name`. Hierarki tidak dipindahkan melalui CRUD generik; delete ditolak jika area masih direferensikan. List memakai `per_page` 1–100 dan `cursor`. Create area/assignment mendukung header idempotency opsional.

Assignment menerima `user_id` UUID, `role` nama role, `scope_type=global|rw|rt|household|vendor`, tepat satu ID resource sesuai scope (`area_id`, `household_id`, atau `vendor_id`; semua kosong untuk global), `starts_at`, dan `ends_at` opsional. Scope RW mencakup child RT; scope RT tidak mencakup RT lain. Assignment masa depan, kedaluwarsa, dan revoked tidak memberi akses. Role scoped tidak ditempelkan ke relasi role global Core. Permission Core pada akun tetap bersifat global; jangan memberi role pengurus melalui endpoint role global jika akses yang dimaksud hanya RT/RW. Seeder `DatabaseSeeder` memanggil RBAC Core lalu `CommunitySeeder`; jalankan keduanya melalui `php artisan db:seed` agar permission Community tidak hilang saat sinkronisasi role Core.

Provisioning menerima `name`, `area_id` UUID RT, minimal `email` atau `phone`, serta `locale=id|en` opsional. Email dinormalisasi lowercase; nomor HP `08…`, `628…`, dan `+628…` menjadi `+628…`. F1 mendukung nomor HP Indonesia; OTP belum digunakan. Akun yang memiliki email tetap mengikuti aturan verifikasi email Core dan dapat meminta link melalui `/api/auth/email/resend` sebelum login pertama.

Server membuat password awal acak 24 karakter dan `must_change_password=true`. Response provisioning/recovery berisi UUID operasi/akun, `credential_url`, dan expiry; tidak berisi password. Download menghasilkan JSON `{user_id, initial_password}` dengan `Cache-Control: private, no-store`. Output terenkripsi di Redis, berlaku 15 menit, hanya dapat diambil sekali, dan ditolak jika ada recovery lebih baru. Response yang sudah diunduh tidak dapat diulang; gunakan recovery baru jika distribusi gagal. Password plaintext tidak disimpan pada tabel, audit, atau response cache idempotency. Recovery selalu mencabut token dan reset link lama. Akun dengan permission global atau assignment berstatus aktif yang berisi permission selain `households.view`/`residents.view` dilindungi dari recovery; self-recovery ditolak.

Saat `must_change_password=true`, akses authenticated hanya tersedia untuk `/api/auth/me`, `/api/auth/password`, `/api/auth/logout`, dan `/api/auth/logout-all`; endpoint lain mengembalikan `auth.password_change_required`. Password baru harus berbeda. Reset email yang sah menghapus flag tersebut dan mencabut seluruh token. Pemilihan role keluarga tidak memberikan hak recovery; household-assisted recovery membutuhkan capability eksplisit sebagaimana dijelaskan di F2.

Tabel `areas`, `role_assignments`, `account_scopes`, dan `account_operations` memakai koneksi PostgreSQL `rukun` tanpa prefix, menuju database fisik yang sama dengan koneksi `core`. Tabel foundation tetap `rcore_*`. Service provisioning dan adapter audit Community memakai **satu transaksi pada koneksi `rukun`**, termasuk akses eksplisit tabel Identity Core, agar user, scope, pencabutan token, dan audit commit/rollback bersama. Jangan membungkus service ini dalam transaksi koneksi `core` yang berbeda. Feature test Community memakai `DatabaseMigrations` agar tidak menyembunyikan visibilitas antar-koneksi di dalam transaksi test Core.

`account_scopes` adalah scope administratif akun. Pada F2, akun yang ditautkan ke Resident mengikuti RT membership aktif; mutasi memperbarui scope, sedangkan berakhirnya membership menghapus scope administratif tersebut.

## Community: Household, Resident, dan import (F2)

Household menyimpan unit administrasi RT, alamat, blok/nomor, status hunian, dan status `active|moved|inactive`. Resident menyimpan nama, tanggal lahir/HP opsional, status `active|moved|deceased|inactive`, serta User opsional. Satu Household dapat memiliki banyak Resident dan beberapa akun berbeda; setiap User hanya ditautkan ke satu Resident. `reference` eksternal unik dapat diberikan saat create; jika kosong server membuat UUID referensi.

| Method | Endpoint | Perilaku |
|---|---|---|
| GET / POST | `/api/community/households` | List scoped / buat Household pada RT |
| GET / PATCH | `/api/community/households/{household}` | Detail / ubah alamat, hunian, atau status |
| GET / POST | `/api/community/residents` | List scoped / buat Resident dan membership awal |
| GET / PATCH | `/api/community/residents/{resident}` | Detail / ubah demografi atau status |
| PUT | `/api/community/residents/{resident}/membership` | `{household_id: UUID|null, relationship}`; tutup membership lama |
| GET | `/api/community/residents/{resident}/memberships` | Histori pada Household yang masih boleh diakses |
| POST | `/api/community/residents/{resident}/account` | Provision akun atau link `user_id` existing; `Idempotency-Key` wajib |
| GET / PUT | `/api/community/residents/{resident}/sensitive` | Baca / simpan atau hapus `nik` |
| GET / PUT | `/api/community/households/{household}/sensitive` | Baca / simpan atau hapus `kk_number` |
| GET / POST | `/api/community/vendors` | List scoped / buat anchor scope vendor |
| PATCH | `/api/community/vendors/{vendor}` | Ubah nama/status vendor dalam scope |
| POST | `/api/community/population/imports` | `{area_id, file_id}`; antre import CSV/XLSX |
| POST | `/api/community/population/exports` | `{area_id, format: csv|xlsx}`; antre export |
| GET | `/api/community/population/transfers/{transfer}/results` | Resident hasil import dan URL credential sementara |
| GET | `/api/community/population/transfers/{transfer}/errors` | Download CSV ringkasan 100 baris error pertama |

List menggunakan `per_page` 1–100 dan `cursor`; Household/Resident dapat difilter `area_id`, Resident juga `household_id`. Permission `households.view/manage` dan `residents.view/manage` diperiksa bersama scope. Anggota aktif dengan User tertaut memiliki akses baca Household sendiri dan anggotanya. Hak jabatan RW/RT independen dari tempat tinggal. Scope vendor hanya mengakses vendor terkait; fitur operasional WiFi tetap F4.

Satu Resident hanya memiliki **satu membership aktif**, dijamin unique index PostgreSQL. Relationship `head|spouse|child|parent|other` tidak memberikan privilege. Mutasi memerlukan izin pada sumber dan tujuan, menutup histori lama, memperbarui RT/`account_scopes`, mencabut token lama dan assignment Household lama. Penggantian relationship pada Household yang sama mempertahankan scope. RT Household tidak dapat diubah lewat PATCH; pindahkan Resident ke Household tujuan. Household dengan anggota aktif tidak dapat dinonaktifkan. Status Resident nonaktif menutup membership; pengaktifan kembali memerlukan PUT membership untuk bergabung lagi. Role jabatan independen tidak dicabut otomatis.

Provisioning Resident menggunakan service F1; response berisi Resident, `operation_id`, dan `credential_url`. Menautkan User existing membutuhkan **permission global `users.assign-roles`** selain izin pengelolaan Resident. Nomor HP Resident dan identifier login adalah data terpisah; edit demografi tidak mengganti identifier login.

Role `household-account-manager` harus diberikan lewat scoped assignment `household` kepada anggota aktif. Recovery memakai endpoint akun F1 dan memerlukan actor serta target masih berada dalam Household yang sama. Akun target berprivilege tetap dilindungi. Assignment dicabut ketika membership berakhir; memilih relationship kepala keluarga saja tidak memberikan hak recovery. Jangan menempelkan role `warga` atau role pengurus ke relasi role global Core untuk akses yang seharusnya scoped.

NIK/KK menerima string 16 digit atau `null`. Penyimpanan memakai encrypted cast dan HMAC untuk deteksi duplikat; list/detail umum menyembunyikan nilai serta hash. GET sensitif memerlukan `residents.view-sensitive`; PUT juga memerlukan permission manage. Keduanya diaudit tanpa nilai identitas, response `no-store`, dan parameter disamarkan pada Telescope/log context. **Rotasi APP_KEY memerlukan migrasi re-encryption dan perhitungan ulang HMAC** untuk NIK/KK serta fingerprint import; jangan mengganti key begitu saja setelah data dimasukkan.

Import menggunakan handler Core `community.population`, queue `low`, dengan otorisasi `population.transfer`, `households.manage`, dan `residents.manage` pada RT yang dipilih. `create_account=1` juga memerlukan `accounts.provision`. Upload file dahulu melalui `/api/files`; kemudian kirim UUID file milik sendiri ke endpoint import. Import/export mewajibkan `Idempotency-Key`. Status, progress, output file, dan pembatalan memakai endpoint Core `/api/data-transfers/{id}`. Operator scoped tidak diberi permission global `data-transfers.create` sehingga tidak memperoleh akses export Identity Core.

Kolom wajib dan contoh CSV:

```csv
household_reference,resident_reference,household_address,resident_name,relationship,create_account,phone
H-001,R-001,Jalan Melati 1,Warga Satu,head,1,081234567890
H-001,R-002,Jalan Melati 1,Warga Dua,child,0,
```

Kolom opsional: `block`, `house_number`, `occupancy_status` (default `occupied`), `birth_date` (`YYYY-MM-DD`), `phone`, dan `email`. Simpan referensi dan HP sebagai teks pada XLSX; data dibaca dari worksheet pertama. NIK/KK dan kolom lain ditolak pada import umum. Satu baris membentuk Household (jika baru), Resident, membership, dan akun opsional secara atomik. Minimal email/HP diperlukan ketika membuat akun. Data alamat untuk Household reference yang sama harus konsisten. Baris identik dapat diulang tanpa duplikasi; referensi yang berubah datanya atau berasal dari RT lain ditolak. Koreksi data yang sudah masuk melalui API CRUD, kemudian gunakan referensi baru hanya untuk warga baru.

`results` menyediakan URL credential yang tetap berlaku **15 menit sejak akun dibuat**, sekali download dan hanya oleh pembuat yang masih berwenang. Import besar dapat melewati masa berlaku; gunakan recovery scoped untuk credential yang kedaluwarsa. Password tidak pernah dimasukkan ke CSV, XLSX, error result, atau audit. Error result menyimpan maksimal 100 baris pertama; counter gagal tetap menghitung semua baris. Export adalah snapshot Resident aktif beserta membership saat ini pada RT terpilih, tanpa NIK/KK; bukan backup histori atau mekanisme restore. File export mengikuti policy owner Core ditambah pemeriksaan scope saat download, termasuk setelah assignment dicabut atau riwayat transfer dihapus.

Rehearsal sintetis CSV/XLSX dan rekonsiliasi jumlah Household, Resident, membership aktif, akun, serta baris gagal dijalankan dalam Pest pada `rukun_test`. Rekonsiliasi dataset pilot nyata masih menunggu data operasional dan dicatat terpisah di `plan-be.md` sebelum production pilot.

## Billing, pembayaran manual, dan kas (F3)

`Modules/Billing` menggunakan tabel domain tanpa prefix pada koneksi `rukun`. Nominal adalah **integer rupiah IDR**, maksimal Rp1.000.000.000.000 per transaksi. Semua POST Billing memerlukan `Idempotency-Key` 8–128 karakter. Server menyimpan fingerprint dan hasil operasi dalam transaksi yang sama; penggunaan ulang key dengan payload berbeda ditolak 409, dan replay tetap memeriksa permission/scope terbaru. Tidak ada password atau credential gateway di tabel billing.

| Method | Endpoint `/api/billing` | Perilaku |
|---|---|---|
| GET / POST | `/payment-types` | Daftar jenis tagihan / buat pada RW atau RT |
| GET / POST | `/payment-types/{type}/tariffs` | Daftar / tambah nominal dan masa berlaku |
| POST | `/tariffs/{tariff}/end` | Pendekkan masa berlaku tarif sebelum memasukkan tarif pengganti |
| GET / POST | `/bank-accounts` | Rekening tujuan transfer manual pada RW/RT |
| POST | `/invoices/generate` | Generate maksimal 500 Household aktif dalam satu RT |
| GET | `/invoices`, `/invoices/{id}` | Invoice dengan saldo dan status derived |
| POST | `/invoices/{invoice}/issue`, `/invoices/{invoice}/cancel` | Terbitkan draft / batalkan invoice tanpa pembayaran aktif |
| POST | `/receipts/cash` | Terima cash, alokasikan, dan catat ledger secara atomik |
| GET | `/receipts`, `/receipts/{id}` | Nomor receipt, alokasi, dan ID reversal bila ada |
| POST | `/receipts/{receipt}/reverse` | Balik receipt beserta seluruh posting ledger |
| GET / POST | `/submissions` | Daftar / ajukan transfer manual dengan bukti |
| GET | `/submissions/{id}` | Detail status dan review |
| POST | `/submissions/{submission}/approve`, `/reject`, `/cancel` | Review atau pembatalan oleh pengaju |
| GET / POST | `/expenses` | Daftar / buat expense draft |
| GET | `/expenses/{id}` | Detail expense dan status reversal |
| POST | `/expenses/{expense}/approve`, `/post` | Approval independen lalu posting pengeluaran |
| GET / POST | `/ledger` | Daftar jurnal / tambah adjustment atau transfer kas–bank |
| GET | `/ledger/{id}` | Detail jurnal termasuk referensi reversal |
| POST | `/ledger/{entry}/reverse` | Balik expense/adjustment; transfer dibalik kedua sisinya |
| GET | `/periods`, `/periods/{id}` | Snapshot periode yang sudah ditutup |
| POST | `/periods/close` | Tutup periode RT/RW dan simpan rekonsiliasi |
| GET | `/reports/monthly?area_id={uuid}&period=YYYY-MM` | Laporan saldo per fund dan channel, atau snapshot tertutup |

Daftar memakai cursor dan `per_page` 1–100, dengan filter `area_id`. Invoice/receipt/submission mendukung `household_id`; invoice/receipt/ledger/period mendukung `period=YYYY-MM`. ID resource berupa UUID publik. Mutasi mengembalikan ID hasil; baca detail melalui GET untuk status terkini.

`payment-types` menerima `area_id`, `code`, `name`, `collection_policy=must_settle_in_period|can_accumulate`, dan `fund_classification=operational|pass_through`. Tariff menerima `amount`, `starts_at`, dan `ends_at` opsional; end bersifat **eksklusif**, periode tidak boleh tumpang tindih. Invoice bulanan memilih tariff yang berlaku pada tanggal pertama billing period. Generate menerima `area_id` RT, `payment_type_id` milik RT atau RW induk, `period`, `due_date`, `settle_by` opsional (default due date), `subject` opsional, serta `household_ids` opsional. Tanpa daftar ID, generate memilih Household aktif RT tersebut, maksimal 500. `state=draft|issued` default issued.

Unique key invoice adalah Household + payment type + period + subject. Generate ulang mengembalikan invoice yang sudah ada; tidak menimpa snapshot. Nominal, tariff, kebijakan penagihan, dan fund disimpan pada invoice; perubahan tariff tidak mengubah invoice terbit. Issue draft mengambil nominal tariff efektif saat penerbitan. Status response `draft|issued|partially_paid|paid|overdue|cancelled` dihitung dari state dan alokasi receipt yang belum dibalik; flag `overdue` tetap tersedia untuk partial payment lewat jatuh tempo.

Cash receipt menerima `household_id`, `amount`, `paid_on`, dan `allocations` opsional berupa `[{invoice_id, amount}]`. Total explicit allocation harus sama dengan receipt, invoice harus milik Household tersebut, dan nominal tidak boleh melebihi outstanding. Tanpa pilihan eksplisit, alokasi memprioritaskan invoice `must_settle_in_period` pada bulan cash receipt, lalu tunggakan tertua sampai bulan tersebut. Tidak ada saldo sisa tak teralokasi pada F3; kelebihan bayar ditolak. Explicit allocation dapat memilih invoice terbit lain milik Household yang sama, termasuk tagihan mendatang.

Upload bukti melalui File Management Core. Submission menerima `household_id`, `amount`, `transferred_at` (tanggal), `destination_account_id`, `proof_file_id` milik pengaju (PNG/JPEG/PDF), `note`, dan pilihan allocation. Submission pending **tidak membuat receipt dan tidak mengubah invoice**. Approval membutuhkan reviewer berbeda dari pengaju, scope RT/RW yang sesuai, dan saldo invoice yang masih cukup. Reviewer tidak dapat mengganti amount/allocation; reject membutuhkan `review_note`, lalu pengaju mengirim submission baru. Approval menerima `paid_on` opsional (default hari approval), tidak boleh sebelum tanggal transfer; outstanding/default allocation diperiksa ulang pada tanggal pembukuan tersebut. Approval berulang menghasilkan receipt yang sama. Pending hanya dapat dibatalkan oleh pengajunya yang masih berwenang.

Bukti yang sudah digunakan tidak dapat diubah atau dihapus lewat Core Files. File tetap dapat dibaca pemilik dan reviewer dengan scope/permission sesuai; reviewer RT lain ditolak. Referensi bukti dan review ditahan untuk audit, termasuk submission rejected/cancelled.

Role `bendahara-rt/rw` memiliki permission Billing melalui scoped assignment. `ketua-rt/rw` dapat membaca laporan/ledger/invoice/receipt, mereview transfer/expense, dan menutup periode. Anggota Household aktif memiliki akses baca invoice/receipt/submission Household sendiri dan dapat mengajukan transfer; membership tidak memberi izin menerima cash, approval, atau mengubah ledger. Expense dibuat sebagai draft, diapprove petugas lain dengan `expenses.approve`, lalu diposting dengan `expenses.post` dan tanggal pada periode terbuka. Draft tidak menyediakan edit nominal; bila keliru, buat draft pengganti dan jangan approve draft lama.

Receipt, alokasi, reversal, ledger, dan snapshot tutup buku dilindungi trigger PostgreSQL **append-only**. Receipt dan ledger ditulis bersama audit/event dalam satu transaksi `rukun`; jangan membungkus service Billing dengan transaksi koneksi Core yang terpisah. Mutasi keuangan memakai lock RW induk yang sama sehingga approval, receipt, reversal, dan close tidak berlomba pada saldo yang sama. Test dua proses receipt bersamaan membuktikan hanya transaksi yang masih memiliki outstanding yang berhasil.

`ledger` menerima `kind=adjustment|transfer`, nominal, `posted_on`, `fund_classification`, `channel=cash|bank`, dan `reason`. Adjustment boleh positif/negatif, tetapi tidak nol; transfer harus positif, memakai `destination_channel` yang berbeda, dan membuat dua posting berjumlah nol pada fund yang sama. Koreksi dilakukan melalui reversal dengan `posted_on` dan alasan, lalu transaksi pengganti. Receipt reversal memulihkan outstanding invoice; expense tetap menyimpan lifecycle historis `posted` dengan flag `reversed`. Ledger F3 mencatat saldo termasuk koreksi/opening balance; saldo negatif ditampilkan untuk rekonsiliasi, tidak diblokir otomatis. Dana `pass_through` terpisah dari operasional pada laporan; settlement vendor/benefit tetap F4.

Tutup buku menerima `area_id` RT/RW dan `period=YYYY-MM` yang tidak melampaui bulan berjalan. Close menyimpan opening, inflow, outflow, closing untuk tiap fund/channel, serta invoice total/paid/outstanding per akhir bulan. Close menolak backdating pada bulan tertutup **dan bulan sebelumnya**, termasuk transaksi child RT setelah RW ditutup. Laporan periode tertutup selalu memakai snapshot. Pembayaran tunggakan setelah close dicatat di periode kas berjalan dan tetap dialokasikan ke invoice lama. Reversal setelah close memakai tanggal periode berjalan; histori dan snapshot bulan lama tidak diedit. Transfer kas–bank ikut inflow/outflow channel sehingga angka tersebut merupakan pergerakan kas bruto, bukan pendapatan bersih.

Event durable tersedia melalui Core `/api/events`: `invoice.created`, `invoice.due_soon`, `payment_submission.created`, `payment_submission.approved`, `payment_submission.rejected`, dan `receipt.created`. Payload hanya memuat UUID resource; client mengambil detail dengan pemeriksaan scope terbaru. Scheduler menjalankan `billing:notify-due` pukul 07:00 timezone aplikasi untuk invoice belum lunas yang jatuh tempo dalam tiga hari, deduplicated per invoice/hari. Warga tanpa akun tetap valid namun tidak memiliki penerima notifikasi login.

Rehearsal sintetis satu bulan diuji terhadap pembukuan manual yang diharapkan: receipt Rp100.000, expense Rp25.000, transfer Rp30.000 kas ke bank, dan adjustment yang dibalik menghasilkan kas Rp45.000 + bank Rp30.000 = Rp75.000. Test lain memisahkan fund titipan/operasional, memeriksa closed-period arrears, dan rollback approval saat saldo invoice sudah habis. Perbandingan dengan buku kas pilot nyata belum dilakukan karena data operasional belum tersedia.

Konfigurasi Midtrans lokal disalin dari `../core-r/.env` atas arahan pengguna: server/client key dan merchant ID terisi, `MIDTRANS_ENABLED=true`, serta `MIDTRANS_PRODUCTION=false` (sandbox). `.env` tetap ignored dengan permission `0600`, dan `.env.testing` tidak memakai credential tersebut. Belum ada transaksi gateway nyata yang dibuat. Foundation Payments Core dapat memakai sandbox; **adapter invoice → checkout → verified receipt/webhook dijadwalkan pada F5**, tidak dianggap selesai oleh konfigurasi credential ini.

## WiFi, settlement, dan benefit galon (F4)

`Modules/Wifi` menghubungkan vendor F2, Household, dan billing F3. Modul mencakup pendaftaran, penagihan, settlement/remittance, advance/recovery, dan ledger/claim galon. Sign-off kebijakan dan rekonsiliasi data pilot nyata masih diperlukan; status rinci ada di `plan-be.md`.

| Method | Endpoint `/api/wifi` | Fungsi |
|---|---|---|
| GET / POST | `/packages` | Daftar scoped / daftarkan paket dengan payment type khusus WiFi |
| GET | `/packages/{id}` | Detail paket |
| GET / POST | `/customers` | Daftar scoped / aktivasi langganan Household |
| GET | `/customers/{id}` | Detail langganan; status `scheduled`, `active`, atau `ended` |
| POST | `/customers/{customer}/end` | Jadwalkan akhir langganan, `ends_on` eksklusif |
| POST | `/customers/{customer}/bill` | Terbitkan invoice untuk `period=YYYY-MM` |

Semua POST memerlukan `Idempotency-Key` 8–128 karakter; semua ID adalah UUID publik. Daftar memakai cursor, `per_page` 1–100, serta filter `vendor_id`. Permission `wifi.manage` diberikan kepada super-admin dan bendahara RT/RW; penerbitan tagihan juga memerlukan `invoices.manage`. Ketua RT/RW mendapat `wifi.view`. Role `vendor-wifi` harus dipasang melalui **assignment scope VENDOR**, sehingga hanya membaca paket/pelanggan/benefit vendor sendiri serta mencatat reservasi dan pengantaran melalui `wifi.deliver`, tanpa kemampuan menagih atau akses NIK/KK, data resident, receipt, dan buku kas. Vendor nonaktif kehilangan akses operasional. Warga aktif dapat membaca langganan/paket Household sendiri.

Urutan setup:

1. Buat vendor melalui F2 dan payment type melalui F3 pada RT atau RW induk: `collection_policy=must_settle_in_period`, `fund_classification=pass_through`. Payment type harus belum memiliki invoice dan hanya boleh terikat ke satu paket.
2. Tambahkan tariff F3 dengan `amount` integer rupiah dan `starts_at` paling lambat tanggal pertama bulan tagihan.
3. `POST /api/wifi/packages` dengan `vendor_id`, `payment_type_id`, `name`, `due_day`, `settle_day`. Kedua hari berada pada 1–28 dan `settle_day >= due_day`, sehingga valid pada setiap bulan. Paket/binding bersifat tetap; perubahan nominal memakai tariff efektif F3.
4. `POST /api/wifi/customers` dengan `package_id`, `household_id`, `starts_on` (`YYYY-MM-DD`). Paket harus milik RT Household atau RW induknya. Satu Household hanya boleh memiliki satu langganan per bulan, termasuk saat pindah paket/vendor.
5. `POST /api/wifi/customers/{customer}/bill` dengan `{"period":"2026-09"}`. Response berisi `public_id` bill dan `invoice_id`; gunakan endpoint invoice F3 untuk membaca saldo/status. Nominal diambil dari tariff server, bukan input client.
6. Pembayaran cash/manual menggunakan receipt dan allocation F3. Cicilan tetap didukung; semua penerimaan masuk klasifikasi `pass_through`, bukan dana operasional RT.

Kebijakan tahap awal: aktivasi di tengah bulan ditagih **satu bulan penuh tanpa prorata**. Akhir langganan wajib hari pertama bulan setelah aktivasi, eksklusif, dan tidak boleh memotong periode yang telah ditagih. Untuk ganti paket/vendor, jadwalkan akhir langganan lama lalu buat langganan baru mulai bulan berikutnya. Konfirmasikan kebijakan ini sebelum pilot nyata.

Invoice menyimpan snapshot nominal/due date/settle-by dan memakai subject `wifi`. Endpoint generate umum F3 menolak payment type yang telah terikat paket; penerbitan wajib melalui pelanggan WiFi. Generate ulang, termasuk dua request bersamaan, mengembalikan invoice yang sama. Relasi bill–invoice append-only di PostgreSQL. Invoice yang dibatalkan tetap memiliki histori dan tidak otomatis diterbitkan ulang. Tagihan baru mengikuti penutupan periode F3; replay tetap memeriksa akses terbaru. Household nonaktif atau berubah wilayah tidak dapat ditagih melalui langganan lama.

### Settlement, talangan, dan rekonsiliasi

Permission `wifi.settle` diberikan kepada super-admin dan bendahara sesuai scope RT/RW. Semua POST tetap memerlukan `Idempotency-Key`, nominal dihitung/ditinjau server, dan mutasi memakai lock RW serta transaksi database yang sama dengan cashbook, audit, dan idempotency. Ini adalah **pencatatan setoran/refund yang dilakukan pengurus**, bukan perintah transfer uang ke bank vendor.

| Method | Endpoint `/api/wifi` | Perilaku |
|---|---|---|
| GET | `/bills`, `/bills/{id}` | Bill yang dapat dilihat oleh pengurus, vendor terkait, atau Household sendiri; eligibility tanpa membocorkan data finance |
| GET | `/bills/{bill}/settlement` | Rekonsiliasi finance: nominal, pembayaran pada cutoff, setoran, dana receipt, advance issued/recovered/outstanding |
| POST | `/bills/{bill}/remit` | Catat satu setoran penuh ke vendor; nominal berasal dari invoice |
| POST | `/bills/{bill}/recover` | Pulihkan talangan dari alokasi receipt baru yang belum dipakai |
| GET | `/finance`, `/finance/{id}` | Histori finance, receipt sumber beserta nominal, ID jurnal, dan relasi reversal |
| POST | `/finance/{finance}/reverse` | Balik seluruh jurnal operasi itu; recovery harus dibalik sebelum remittance induknya |

Konfigurasi tambahan pada **pembuatan paket**: `remit_day` default 28 (minimal `settle_day`, maksimal 28), `allow_advance` default false, `gallon_quota` default 10 (1–1000), dan `claim_days` default 30 (1–365). Paket lama memperoleh default tersebut. Konfigurasi paket tidak diedit secara retroaktif; untuk kebijakan baru buat paket pengganti dan pindahkan langganan pada batas bulan.

Cutoff adalah akhir tanggal `settle_by` dalam timezone aplikasi. Eligibility baru final setelah tanggal itu berakhir: invoice issued harus lunas dari receipt sah dengan `paid_on <= settle_by`; receipt yang telah dibalik tidak dihitung. Tanggal pembayaran manual adalah tanggal yang diverifikasi pengurus, sehingga koreksi/backdate sah sebelum period close tetap diperhitungkan. Pembayaran setelah cutoff melunasi invoice, tetapi tidak menambah eligibility galon pada periode tersebut. Advance RT juga tidak membuat warga eligible.

Remit menerima `posted_on` (`YYYY-MM-DD`, tidak di masa depan), `channel=cash|bank`, dan `reference` bukti/referensi setoran (maksimal 150 karakter). Cutoff harus sudah berakhir, dan tanggal posting minimal hari remittance paket pada bulan invoice. Hanya satu remittance aktif per bill; request bersamaan tidak menggandakan setoran. Dana dari receipt diposting keluar pada `pass_through`; kekurangannya ditolak bila advance mati, atau dicatat sebagai pengurangan kas operasional dengan piutang advance terpisah bila advance aktif. Nominal invoice tetap utuh dan tidak ada income operasional baru.

Recovery menerima field remit ditambah `amount` integer rupiah. Jumlahnya tidak boleh melebihi piutang tersisa dan harus ditopang receipt yang belum digunakan untuk remittance/recovery. Ledger memindahkan nominal dari fund titipan ke fund operasional pada channel yang dipilih; total kas tidak berubah. Pemindahan fisik kas/bank, bila diperlukan, tetap dicatat melalui transfer F3.

Reversal menerima `posted_on` dan `reference` alasan/refund. Tidak boleh mendahului tanggal operasi asal atau masuk periode tertutup. Tabel finance, link receipt/jurnal, serta cashbook append-only. Endpoint reversal jurnal umum menolak jurnal WiFi; gunakan endpoint finance di atas agar piutang dan jurnal tidak terpisah. Receipt sumber tidak dapat dibalik selama dipakai finance aktif. Setelah reversal, histori asli tetap ada dan operasi pengganti memakai key baru.

Contoh rekonsiliasi sintetis yang diuji: invoice Rp150.000, receipt Rp50.000, setoran vendor Rp150.000 → Rp50.000 dana titipan + Rp100.000 advance. Receipt susulan Rp100.000 dan recovery Rp100.000 → advance outstanding Rp0, saldo akhir kas terkait Rp0, tanpa income operasional. Report settlement bersifat kondisi terkini; laporan kas per periode dan close snapshot tetap melalui F3.

### Kuota dan klaim galon

| Method | Endpoint `/api/wifi` | Perilaku |
|---|---|---|
| POST | `/bills/{bill}/grant` | Pengurus memberikan kuota sekali untuk bill eligible |
| GET | `/benefits`, `/benefits/{id}` | Kuota, available/reserved/confirmed, expiry, status, dan flag server `claimable` |
| POST | `/benefits/{benefit}/reserve` | Vendor mereservasi `quantity` dengan `reference` pengantaran |
| GET | `/claims`, `/claims/{id}` | Status klaim dalam scope |
| POST | `/claims/{claim}/deliver` | Vendor mencatat sudah diantar; belum menjadi konsumsi final |
| POST | `/claims/{claim}/confirm` | Anggota Household aktif mengonfirmasi penerimaan |
| POST | `/claims/{claim}/reverse` | Balik reservasi/pengantaran; klaim confirmed hanya boleh dibalik pengurus |
| POST | `/benefits/{benefit}/reverse` | Balik grant setelah tidak ada klaim pending atau confirmed |
| GET | `/gallon-ledger` | Histori grant/reserve/claim/confirm/expire/reverse dengan delta tiap saldo |

Daftar memakai cursor dan `per_page` 1–100. Filter `bill_id` tersedia pada semua koleksi di atas; `benefit_id` pada claims/gallon-ledger. Seluruh ID berupa UUID publik. Finance hanya terlihat oleh pemegang `wifi.settle`; koleksi operasional mengikuti scope pelanggan dan tidak mengekspos identitas sensitif.

Grant dilakukan eksplisit oleh pengurus setelah memeriksa eligibility. Kuota hanya diberikan sekali per bill, termasuk bila grant tersebut kemudian dibalik. Expiry adalah `settle_by + claim_days`, inklusif. `reserve` menerima quantity positif dan reference maksimal 100 karakter; reference unik per vendor mencegah duplikasi meskipun client mengganti idempotency key. Reserve memindahkan saldo available ke reserved, deliver tidak mengubah saldo, dan confirm memindahkan reserved ke confirmed. Pembuat klaim tidak boleh mengonfirmasi sendiri sekalipun juga anggota Household. Konfirmasi awal memakai akun warga terautentikasi, tanpa PIN terpisah.

Reversal memerlukan `reason` maksimal 2000 karakter. Sebelum expiry, reversal klaim mengembalikan kuota; sesudah expiry tidak menambah kuota yang dapat dipakai. Untuk membalik receipt, balik dahulu finance aktif dan benefit galon aktif/confirmed yang terkait. Ini menjaga koreksi pembayaran tidak meninggalkan konsumsi tanpa dasar pembayaran.

Scheduler `wifi:expire-benefits` berjalan setiap hari pukul 00:10. Kuota tidak terpakai serta reservasi/pengantaran belum dikonfirmasi kedaluwarsa tanpa dihitung sebagai konsumsi final. Endpoint mutasi menolak benefit lewat expiry meskipun scheduler belum berjalan; flag `claimable` juga langsung false. Ledger append-only PostgreSQL merekam seluruh delta, dan constraint/proses transaksi menjaga saldo tidak negatif. Realtime durable events `wifi.benefit.updated` dan `wifi.claim.updated` memberi tahu Household saat grant, delivery, konfirmasi, reversal, atau expiry.

## Tunnel webhook Midtrans lokal

Tunnel khusus memakai Cloudflare Quick Tunnel dan Nginx bersama `dev-nginx` pada `docker-network`. Tunnel hanya membuka **POST `/api/payments/webhooks/midtrans`**. Root, `.env`, API lain, Swagger, dan health tidak dipublikasikan. Tidak ada PostgreSQL/Redis/Nginx tambahan dan tunnel `core-r` tetap terpisah.

Jalankan dari root project pada host:

```sh
scripts/tunnel/webhook.sh start
scripts/tunnel/webhook.sh url
scripts/tunnel/webhook.sh stop
scripts/tunnel/webhook.sh renew  # hanya jika Quick Tunnel lama tidak berlaku
```

`start` memasang `scripts/tunnel/nginx.conf` ke `/etc/nginx/conf.d/rukun-webhook-tunnel.conf` pada Nginx bersama, memvalidasi dan me-reload Nginx, lalu menjalankan `compose.tunnel.yml`. Listener `18085` hanya digunakan melalui jaringan Docker; tidak ada port host baru. Jika URL belum tersedia, ulangi perintah `url` setelah beberapa detik. `stop` menghentikan connector Rukun; layanan bersama dan connector Core R tetap berjalan. `url` membaca log sejak container mulai agar URL tetap ditemukan setelah layanan berjalan lama. Bila Cloudflare melaporkan `Tunnel not found`, jalankan `renew`, ambil URL baru, lalu perbarui dashboard sandbox. Quick Tunnel lama pernah tidak berlaku lagi pada 30 September 2026; ini berbeda dari health backend lokal.

Salin hasil perintah `url` ke **Payment Notification URL di dashboard Midtrans sandbox**. `APP_URL` tetap `https://rukun.p85.test:8443`; tunnel ini tidak menyediakan halaman checkout/redirect. **URL aktif diperiksa 2 Oktober 2026:** `https://and-bullet-responded-recordings.trycloudflare.com/api/payments/webhooks/midtrans`. Signed sandbox probe lulus (200). Pastikan dashboard memakai URL ini jika masih menggunakan URL sebelumnya. Gunakan hasil `url` sebagai nilai terbaru. Quick Tunnel menyediakan URL sementara yang dapat berubah setelah connector dibuat ulang/restart; cek kembali `url` dan perbarui dashboard bila berubah. Untuk endpoint pilot yang stabil, gunakan named tunnel/domain tersendiri. Lihat [dokumentasi Cloudflare Quick Tunnels](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/do-more-with-tunnels/trycloudflare/).

Verifikasi lokal melalui URL publik pada 29 September 2026: webhook sandbox `payment_notif_test_*` dengan signature valid menghasilkan `200`, `received=true`, `test=true`; signature tidak valid ditolak `401`; root, `.env`, dan `/api/health` menghasilkan `404`. Pengujian ini tidak membuat transaksi gateway dan tidak menggantikan pengujian adapter invoice/receipt F5. Credential tetap hanya pada `.env` ignored, tidak disalin ke compose/script tunnel.

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
| `payments.view-any` | Melihat status pembayaran milik user lain |
| `payments.create` | Membuat checkout pembayaran generik untuk pengujian atau integrasi administratif |
| `data-transfers.create` | Memulai import dan export yang handler-nya sudah terdaftar |
| `data-transfers.view-any` | Melihat transfer milik user lain |
| `data-transfers.cancel-any` | Membatalkan transfer milik user lain |

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
| `files.allowed_mime_types` | array | PDF, JPEG, PNG, WebP, text, CSV, XLSX | Tidak | MIME type yang diizinkan |
| `realtime.event_retention_days` | integer | `7` | Tidak | Retensi durable event untuk polling |
| `ops.failed_job_retention_hours` | integer | `168` | Tidak | Retensi failed queue job untuk inspeksi |
| `api.idempotency_ttl_hours` | integer | `24` | Tidak | Masa simpan hasil operasi idempotent, 1-168 jam |
| `rate_limit.*` | integer | Per action | Tidak | Batas request per menit untuk delapan kelompok endpoint |
| `payments.midtrans_enabled` | boolean | `false` | Tidak | Mengaktifkan pembuatan checkout jika credential tersedia |
| `payments.midtrans_timeout` | integer | `10` | Tidak | Timeout API Midtrans dalam detik |

Metadata settings menyediakan key, group, value, source, fallback value, type, default, rules, public, editable, dan description. Rahasia tetap disimpan melalui environment.

Resolusi setiap runtime setting memakai urutan berikut:

1. Override pada tabel `rcore_settings`.
2. Nilai `.env` yang dibaca melalui `config/runtime-settings.php`.
3. Default kode pada `SettingsRegistry`.

`GET /api/settings/metadata` mengembalikan `group`, `type`, `value`, `source`, `fallback_value`, default, rules, visibility, dan description. Nilai `group` dapat langsung dipakai frontend sebagai tab: `application`, `authentication`, `files`, `realtime`, `operations`, `api`, `rate_limits`, dan `payments`.

`DELETE /api/settings/{key}` menghapus override database dan mengaktifkan kembali fallback environment/default. Perubahan dan reset dicatat pada audit. Kolom `type` dan `group` di database merupakan snapshot untuk administrasi; `SettingsRegistry` tetap menjadi schema kanonik ketika setting ditambah manual.

Setting runtime yang tersedia juga mencakup seluruh `rate_limit.*`, `payments.midtrans_enabled`, dan `payments.midtrans_timeout`. Credential dan konfigurasi infrastruktur berikut tetap hanya boleh berasal dari environment:

- `APP_KEY`, debug, environment, logging, Swagger, dan Telescope.
- Host, port, database, Redis, cache, queue, session, mail, filesystem, MinIO, dan Reverb.
- Midtrans server/client key, merchant ID, production mode, serta endpoint override.

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

### Payments dan redirect

| Method | Endpoint | Akses | Fungsi |
|---|---|---|---|
| `POST` | `/api/payments` | `payments.create` | Membuat checkout; `Idempotency-Key` wajib |
| `GET` | `/api/payments/{payment}` | Owner / `payments.view-any` | Status dan URL checkout |
| `POST` | `/api/payments/webhooks/midtrans` | Midtrans signature | Notification pembayaran dan test Sandbox |
| `GET` | `/payments/finish` | Publik | Halaman redirect pembayaran dikirim |
| `GET` | `/payments/unfinish` | Publik | Halaman redirect checkout belum selesai |
| `GET` | `/payments/error` | Publik | Halaman redirect pembayaran gagal |

Ketiga halaman redirect hanya memberi informasi kepada pengguna. Perubahan status tetap berasal dari webhook yang tervalidasi atau status API provider.

### Import dan export

| Method | Endpoint | Akses | Fungsi |
|---|---|---|---|
| `GET` | `/api/data-transfers/types` | Bearer | Handler dan kolom yang terdaftar |
| `GET` | `/api/data-transfers` | Owner | Riwayat transfer dengan cursor pagination |
| `POST` | `/api/data-transfers/exports` | `data-transfers.create` | Antrekan export CSV; `Idempotency-Key` wajib |
| `POST` | `/api/data-transfers/imports` | `data-transfers.create` | Antrekan import CSV private; `Idempotency-Key` wajib |
| `GET` | `/api/data-transfers/{dataTransfer}` | Owner / `data-transfers.view-any` | Progress, hasil, dan ringkasan error |
| `POST` | `/api/data-transfers/{dataTransfer}/cancel` | Owner / `data-transfers.cancel-any` | Membatalkan transfer aktif |

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

### Query endpoint daftar

Daftar resource memakai **`spatie/laravel-query-builder` 7.3.5** dengan sintaks standar package. Cakupan 43 GET collection meliputi Community, Billing, WiFi/galon, Civic, Engagement (termasuk anggota regu dan history), users, roles, permissions, auth tokens, files, notifications, audit, dan data transfers/hasil import. Swagger menampilkan field/filter/include yang tersedia per endpoint melalui `x-collection-query`.

| Parameter | Contoh | Perilaku |
|---|---|---|
| `filter[field]` | `filter[status]=active` | Filter AND antar-field; nilai dipisahkan koma menjadi alternatif OR dalam field yang sama. Maksimum 30 filter, 50 alternatif per filter. |
| `filter[name]` | `filter[name]=Budi` | Field teks yang didaftarkan sebagai partial filter memakai pencarian tanpa membedakan kapital. `%`/`_` diperlakukan literal. Field status/kode/UUID/angka/tanggal memakai exact filter. |
| `filter[search]` | `filter[search]=Budi` | Grup OR bawaan Spatie pada field teks terdaftar. Tersedia jika resource memiliki partial filter; tetap dibatasi scope dan filter lainnya. |
| `sort` | `sort=name,-public_id` | Daftar field dipisahkan koma; awalan `-` untuk descending. Maksimum 5; ID internal ditambahkan sebagai tie-breaker. |
| `include` | `include=household,area` | Relasi aman yang didaftarkan per resource, dimuat dengan Eloquent eager loading. Maksimum 5; satu level. Nested include dan suffix count/exists tidak didaftarkan. |
| `fields[resource]` | `fields[residents]=public_id,name` | Sparse fields pada DTO respons; maksimum 50 per resource. Namespace sesuai Swagger, misalnya `residents`, `invoices`, atau `files`. |
| `fields[relasi_plural]` | `fields[households]=public_id,reference` | Pilih field ringkasan relasi yang diminta melalui include. Nama mengikuti konvensi plural Spatie: `areas`, `households`, `users`, `vendors`, `payment_types`, atau `parents`. |
| `per_page` | `per_page=10` | Ukuran halaman 1–100, default 20. |
| `page` | `page=2` | Laravel pagination, halaman 1–10000; respons memuat `current_page`, `total`, dan URL navigasi. |
| `cursor` | `cursor=...` | Cursor opaque untuk urutan default. Tidak boleh digabung dengan `page` atau custom `sort`. |

Tanpa `sort`/`page`, default tetap cursor pagination. Dengan `sort`, default menjadi `page=1` agar kolom nullable dapat diurutkan. Roles, permissions, dan auth tokens mempertahankan array datar; `per_page`/`page` dapat membatasi hasil, tetapi tidak menambah envelope pagination. Tanpa pembatas, daftar datar tetap mengembalikan seluruh hasil yang diizinkan.

Filter FK seperti `filter[area_id]`, `filter[household_id]`, dan `filter[user_id]` memakai **UUID publik**. Field derived seperti `paid_amount` dapat dipilih pada respons invoice tetapi tidak otomatis menjadi filter/sort SQL; periksa allowlist di Swagger. Scope RT/RW dan kepemilikan diterapkan sebelum query Spatie. Relasi hanya mengembalikan ringkasan aman; household yang tidak boleh dibaca menghasilkan null. Password/token, NIK/KK, dan path file tidak dapat dipilih melalui query ini. Filter/field/include/sort yang tidak terdaftar, nilai bertipe salah, atau format query lama menghasilkan 422 dengan envelope error aplikasi.

Contoh FE:

```text
GET /api/community/residents?filter[name]=Budi&include=household,area&fields[residents]=public_id,name&fields[households]=public_id,reference&sort=name&per_page=10&page=1
```

Dengan `URLSearchParams`, gunakan `set('filter[name]', 'Budi')`, `set('include', 'household,area')`, dan `set('fields[residents]', 'public_id,name')`. Swagger menyediakan parameter bernama lengkap dengan bracket agar query dapat langsung dicoba.

Migrasi dari query custom sebelumnya:

| Sebelumnya | Pengganti |
|---|---|
| `filter[]=status` dengan operator dalam string | `filter[status]=active`; pilihan operator ditentukan konfigurasi filter server |
| `fields[]=name` | `fields[residents]=name` untuk resource residents |
| `join[]=household` | `include=household` |
| `join[]=household` dengan proyeksi field | `include=household&fields[households]=public_id,reference` |
| `sort[]=name,DESC` | `sort=-name` |
| `limit=10` | `per_page=10` |
| `offset` | Gunakan `page` atau ikuti cursor; arbitrary offset tidak disediakan |
| `s` JSON dan `or[]` | Gunakan filter terdaftar, alternatif koma, atau `filter[search]`; tidak ada penerjemah grammar lama |
| `cache=0/1` | Hapus parameter; respons selalu membaca database dan memakai `Cache-Control: private, no-store` |

Kontrak baru sengaja **tidak mempertahankan parser custom**. Parameter lama `join`, `s`, `or`, `limit`, `offset`, dan `cache` ditolak meskipun nilainya kosong. Filter top-level domain yang sudah ada sebelum fitur query bersama (misalnya `area_id` atau `period`) dan respons default tetap kompatibel. Endpoint metadata/configuration, report/agregat, detail/download, serta polling realtime `/api/events` mempertahankan kontrak khususnya.

Spatie menangani parsing, allowlist, filtering, sorting, sparse-field validation, dan include. `CollectionProfile` menyimpan kontrak domain; `ValidatedFilter` hanya memeriksa tipe PostgreSQL sebelum delegasi ke filter Spatie. `ResourceQueryBuilder` menunda SQL field selection karena serializer memerlukan FK, cursor key, serta atribut untuk saldo turunan. `CollectionPage` memproyeksikan DTO setelah metadata pagination dihitung. Query histori tanpa model domain diadaptasi ke Eloquent dengan tabel/koneksi/scope asli. Endpoint daftar baru wajib memakai helper koleksi, mendaftarkan profil dan Swagger, serta menerapkan scope sebelum helper dipanggil.

Verifikasi 9 Oktober 2026: **237 test / 2037 assertions lulus** (486.13 detik), termasuk 13 test khusus query Spatie, scope, include, sparse fields/cursor, query histori, UUID file, dan saldo invoice. Composer validation, Pint, OpenAPI 157 paths / 194 operations, serta secret/diff check lulus.

## Swagger dan Telescope

Swagger memakai OpenAPI 3.1 dan aset lokal. Generate artefak statis:

```sh
docker exec -w /var/www/p85/rukun dev-php85 php artisan api-docs:generate
```

Output berada di `storage/app/api-docs/openapi.json` dan tidak perlu di-commit. Endpoint baru wajib memiliki operation ID, summary, tag, security, parameter, request body, response, contoh, dan test dokumentasi. Gunakan `API_DOCS_ENABLED=false` untuk menyembunyikan dokumentasi.

Telescope merekam request/response, query, cache, Redis, job, mail, notification, log, dan exception. Password, reset token, cookie, serta header `Authorization` disembunyikan. Gunakan `TELESCOPE_ENABLED=false` untuk menonaktifkannya.

## Queue dan scheduler

| Service | Fungsi |
|---|---|
| `rukun-queue` | Memproses queue `high,default,low` |
| `rukun-scheduler` | Menjalankan scheduler setiap 60 detik |

| Jadwal | Command | Fungsi |
|---|---|---|
| Setiap menit | `core:heartbeat` | Probe scheduler |
| Harian | `sanctum:prune-expired --hours=24` | Bersihkan token expired |
| Harian 02:00 | `telescope:prune --hours=48` | Bersihkan data Telescope lama |
| Harian 02:15 | `realtime:prune-events` | Bersihkan durable event yang melewati retensi |
| Harian 02:30 | `core:prune-failed-jobs` | Bersihkan failed job sesuai setting retensi |
| Harian 02:45 | `data-transfers:prune` | Bersihkan transfer terminal dan file export kedaluwarsa |

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

## Operational reliability

Setiap response API memiliki header `X-Request-ID`. Client boleh mengirim UUID melalui header yang sama; server akan meneruskannya. Nilai tidak valid diganti UUID baru. Request ID ikut masuk ke log context, audit metadata, queue payload, notification context, dan realtime event payload.

Gunakan `LOG_CHANNEL=json` pada deployment yang mengirim log melalui stdout/stderr. Context terstruktur memuat request ID, actor ID, route, module, job ID, job type, dan queue bila tersedia. Field dengan nama yang mengandung password, token, secret, credential, cookie, authorization, atau access key disensor.

Operasi failed job:

```sh
# Ringkasan aman tanpa payload dan exception trace
php artisan queue:failed-summary

# Daftar lengkap bawaan Laravel
php artisan queue:failed

# Retry atau hapus satu job
php artisan queue:retry <uuid>
php artisan queue:forget <uuid>

# Prune memakai setting ops.failed_job_retention_hours
php artisan core:prune-failed-jobs
```

Default retensi failed job adalah 168 jam. Payload dan exception trace hanya diperiksa oleh operator yang memiliki akses shell karena dapat mengandung data internal.

## API reliability dan protection

`POST /api/files` dan `POST /api/users` menerima header opsional `Idempotency-Key`. Pembuatan payment serta import/export mewajibkan header tersebut karena retry tidak boleh membuat transaksi provider atau job transfer ganda. Gunakan nilai unik 8-128 karakter untuk satu operasi logis dan kirim nilai yang sama ketika request perlu diulang.

| Kondisi | Hasil |
|---|---|
| Request pertama berhasil | Operasi dijalankan dan response disimpan; `Idempotency-Replayed: false` |
| Key dan payload yang sama dikirim ulang | Response sukses sebelumnya dikembalikan; `Idempotency-Replayed: true` |
| Key sama dengan payload berbeda | HTTP `409`, code `idempotency.key_reused` |
| Operasi dengan key tersebut masih berjalan | HTTP `409`, code `idempotency.in_progress` |

Response idempotent disimpan selama `api.idempotency_ttl_hours`, dengan default 24 jam. Client perlu membuat key baru setelah payload atau tujuan operasi berubah.

Rate limiter memakai Redis dan dipisahkan menurut tujuan endpoint:

| Kelompok | Batas per menit | Endpoint utama |
|---|---:|---|
| Registrasi | 5 per IP | Register |
| Login | 20 per IP dan email | Login |
| Recovery | 3 per IP dan email | Verifikasi ulang, lupa/reset password |
| Upload | 20 per user | Upload file |
| Polling event | 120 per user | Durable event polling |
| Mutasi notifikasi | 60 per user | Read, unread, read-all, delete, preferences |
| Administrasi sensitif | 30 per user | Mutasi role, user, settings, dan pembuatan payment |

Nilai tersebut dapat diubah melalui variable `RATE_LIMIT_*` di environment. Setelah perubahan, muat ulang configuration cache dan worker. Ketika batas terlampaui API mengembalikan HTTP `429`, code `request.rate_limited`, serta header `Retry-After`, `X-RateLimit-Limit`, dan `X-RateLimit-Remaining`. Client menunggu sekurangnya selama `Retry-After` sebelum mencoba kembali.

## Automated quality gate

Workflow [`.github/workflows/quality.yml`](.github/workflows/quality.yml) berjalan pada pull request dan push ke `main`. Runner membuat PostgreSQL 16 dan Redis 7 disposable, memasang dependency sesuai `composer.lock`, lalu menjalankan gate berikut secara berurutan:

1. Menolak file `.env` lokal, private key, atau pola AWS access key yang terlacak Git.
2. Menjalankan migration pada database `rukun_test` milik runner.
3. Menjalankan seluruh Pest test.
4. Menjalankan Laravel Pint dalam mode pemeriksaan.
5. Menghasilkan OpenAPI JSON dan memvalidasi versi, metadata, operation ID unik, response, serta referensi schema.

Workflow memakai credential database sementara yang hanya berlaku di service runner. Workflow tidak mengakses PostgreSQL, Redis, MinIO, MailDev, atau Docker network development.

Sebelum merge, branch protection sebaiknya mewajibkan check **PHP 8.5 / PostgreSQL 16 / Redis 7** dari workflow **Quality Gate**. Reproduksi gate aplikasi secara lokal:

```sh
bash scripts/ci/check-secrets.sh
docker exec -w /var/www/p85/rukun dev-php85 php artisan test
docker exec -w /var/www/p85/rukun dev-php85 vendor/bin/pint --test
docker exec -w /var/www/p85/rukun dev-php85 php artisan api-docs:generate --output=storage/framework/ci-openapi.json
docker exec -w /var/www/p85/rukun dev-php85 php scripts/ci/validate-openapi.php storage/framework/ci-openapi.json
```

## Import dan export

Module `DataTransfer` menyediakan orchestration CSV dan XLSX asynchronous. Core menangani lifecycle job, file private, progress, error summary, cancellation, audit, realtime event, dan retention. OpenSpout membaca dan menulis XLSX secara streaming agar penggunaan memori tetap terkendali. Modul lain cukup mendaftarkan implementasi `DataTransferHandler` yang menentukan kolom serta logika per baris.

Handler referensi `identity.users` mendukung:

- Export user ke CSV atau XLSX dengan kolom `name`, `email`, `status`, `locale`, `email_verified_at`, dan `created_at`.
- Import/update idempotent user dari kolom `name`, `email`, `status`, dan `locale`.
- Proteksi agar super administrator tidak dapat diubah melalui import.
- Password acak untuk user baru; user menjalankan alur reset password sebelum login.

Alur export:

1. Panggil `POST /api/data-transfers/exports` dengan `type: identity.users`, pilihan `format: csv|xlsx`, dan `Idempotency-Key` unik. Format default adalah CSV.
2. Worker queue `low` menghasilkan spreadsheet melalui cursor database.
3. Pantau `GET /api/data-transfers/{id}` atau event `data_transfer.updated`.
4. Setelah `completed`, unduh `output_file_id` melalui `GET /api/files/{file}/download`.

Alur import:

1. Upload CSV atau XLSX private melalui `POST /api/files`. Format dikenali otomatis dari MIME dan ekstensi file.
2. Panggil `POST /api/data-transfers/imports` memakai `file_id`, `type`, dan `Idempotency-Key` unik.
3. Worker memproses setiap baris dan menyimpan maksimal 100 detail error; `failed_rows` tetap menghitung seluruh kegagalan.
4. Status batch dapat `completed` walaupun beberapa baris invalid. Gunakan `successful_rows`, `failed_rows`, dan `errors` untuk hasil akhirnya.

Contoh body export:

```json
{"type": "identity.users", "format": "xlsx", "options": {}}
```

Contoh body import:

```json
{"type": "identity.users", "file_id": "uuid-file-csv-atau-xlsx", "options": {}}
```

CSV dan XLSX export melindungi cell yang dapat ditafsirkan sebagai formula spreadsheet. Import XLSX membaca worksheet pertama. File output tetap private dan mengikuti policy File Management. Transfer terminal serta file export yang dihasilkan dibersihkan setelah `data_transfers.retention_days`, default 30 hari. File input milik user tidak ikut dihapus oleh cleanup transfer.

## Payment foundation

Module `Payments` menyediakan transaksi pembayaran yang tidak bergantung pada invoice, order, subscription, atau domain bisnis tertentu. Modul bisnis membuat pembayaran melalui `Modules\Payments\Services\PaymentManager` setelah memastikan resource, pemilik, dan nominalnya valid.

```php
$payment = app(PaymentManager::class)->create(
    user: $user,
    referenceType: 'billing.invoice',
    referenceId: (string) $invoice->getKey(),
    amount: $invoice->amount,
    customer: ['name' => $user->name, 'email' => $user->email],
    metadata: ['invoice_number' => $invoice->number],
);
```

| Komponen | Kontrak |
|---|---|
| Nominal | Integer, mata uang awal `IDR` |
| Referensi bisnis | `reference_type` dan `reference_id`; tidak membuat foreign key lintas modul |
| Checkout | Midtrans Snap sandbox secara default |
| Membuat checkout | `POST /api/payments`, memerlukan permission `payments.create` dan header `Idempotency-Key` |
| Callback | `POST /api/payments/webhooks/midtrans` |
| Status user | `GET /api/payments/{payment}` untuk owner atau permission `payments.view-any` |
| Event internal | `PaymentStatusChanged` |
| Realtime event | `payment.updated` |
| Delivery callback | Idempotent berdasarkan fingerprint transaksi dan status |

Status internal mencakup `creating`, `pending`, `authorized`, `paid`, `denied`, `cancelled`, `expired`, `failed`, `provider_unknown`, `refunded`, `partially_refunded`, `chargeback`, dan `partial_chargeback`. Status `capture` atau `settlement` dengan fraud status yang diterima dipetakan menjadi `paid`. Callback memverifikasi signature Midtrans dan mencocokkan nominal asli sebelum mengubah transaksi.

Konfigurasi lokal disimpan melalui environment dan tidak boleh masuk Git:

```dotenv
PAYMENT_GATEWAY=midtrans
MIDTRANS_ENABLED=true
MIDTRANS_SERVER_KEY=
MIDTRANS_CLIENT_KEY=
MIDTRANS_MERCHANT_ID=
MIDTRANS_PRODUCTION=false
PAYMENT_REDIRECT_BASE_URL=https://public-app.example.com
```

Atur Notification URL Midtrans ke `/api/payments/webhooks/midtrans` pada host publik HTTPS. Modul bisnis wajib memakai idempotency pada endpoint yang memanggil `PaymentManager::create()` dan menangani `PaymentStatusChanged` untuk mengubah invoice/order miliknya. Core tidak otomatis menganggap resource bisnis selesai hanya karena pembayaran berubah menjadi `paid`.

Gunakan halaman publik berikut pada konfigurasi redirect Midtrans:

| Hasil Midtrans | URL |
|---|---|
| Finish | `/payments/finish` |
| Unfinish | `/payments/unfinish` |
| Error | `/payments/error` |

`PAYMENT_REDIRECT_BASE_URL` menentukan origin publik ketiga halaman tersebut dan menggunakan `APP_URL` sebagai fallback. Payload Snap otomatis mengirim `callbacks.finish`. URL Unfinish dan Error dapat dipasang pada dashboard Midtrans. Halaman redirect hanya memberi informasi kepada pengguna; status pembayaran final tetap berasal dari webhook tervalidasi atau pemeriksaan status provider.

Untuk pengujian checkout generik melalui Swagger, login sebagai administrator, tekan **Authorize**, lalu panggil `POST /api/payments`. Isi `Idempotency-Key` dengan nilai unik seperti `checkout-order-1001` dan gunakan body berikut:

```json
{
  "reference_type": "testing.order",
  "reference_id": "ORDER-1001",
  "amount": 105000,
  "metadata": {
    "purpose": "sandbox checkout"
  }
}
```

Respons `201` berisi `id`, status `pending`, dan `checkout_url` Midtrans. Pada modul bisnis, nominal tetap harus dihitung dari order atau invoice di server, bukan dipercaya dari input pengguna.

## Pembayaran invoice melalui QRIS (F5)

Selesai untuk development lokal pada **2 Oktober 2026**: 190 test / 1370 assertions, Pint, OpenAPI 131 paths / 160 operations, secret scan dan diff check lulus. Migration dan permission sudah diterapkan, scheduler reconciliation serta health HTTPS terverifikasi. Pengujian pembayaran QRIS sandbox nyata dan rekonsiliasi statement belum dilakukan.

Checkout Billing memakai Core `PaymentManager` dan Midtrans Snap dengan `enabled_payments: ["other_qris"]`. Merchant sandbox harus mengaktifkan QRIS GoPay/ShopeePay sesuai [dokumentasi Other QRIS](https://docs.midtrans.com/reference/other-qris). Webhook tetap `POST /api/payments/webhooks/midtrans` melalui tunnel HTTPS yang sudah disiapkan; `scripts/tunnel/webhook.sh url` menampilkan URL aktif. Redirect browser bukan bukti pembayaran.

| Endpoint | Fungsi |
|---|---|
| `POST /api/billing/invoices/{invoice}/checkout` | Reservasi seluruh sisa invoice dan pembuatan checkout QRIS |
| `GET /api/billing/gateway-checkouts/{checkout}` | Status checkout, core payment, receipt, dan settlement dalam scope |
| `GET /api/billing/gateway-checkouts?status=reserved` | Daftar rekonsiliasi pengurus keuangan, cursor pagination |
| `POST /api/billing/gateway-checkouts/{checkout}/reconcile` | Ambil status provider dan pulihkan posting receipt |
| `POST /api/billing/gateway-checkouts/{checkout}/settlement` | Catat fee aktual dan net berdasarkan statement provider |

Semua POST memerlukan `Idempotency-Key` 8–128 karakter. Checkout menerima body kosong `{}`; `amount`, `allocations`, dan `metadata` dari client ditolak. Server memilih satu invoice `issued`, menghitung outstanding setelah pembayaran sebelumnya, lalu mereservasi seluruh sisanya. Endpoint generik `POST /api/payments` menolak reference `billing.*` agar tidak melewati validasi domain. Warga aktif dapat membayar rumahnya sendiri; pengurus membutuhkan `payments.gateway.create` pada scope yang sesuai. `payments.gateway.reconcile` untuk daftar rekonsiliasi dan pencatatan statement diberikan kepada super-admin serta bendahara RT/RW.

Response checkout memuat `public_id`, `invoice_id`, `payment_id`, `amount`, `currency`, `status`, `payment_status`, `checkout_url`, `expires_at`, `paid_at`, `receipt_id`, `review_reason`, dan `settlement`. Buka `checkout_url` ketika `payment_status=pending`. Jika `provider_unknown`, URL bisa null: order dan reservasi sudah tersimpan, gunakan reconcile atau tunggu scheduler; jangan membuat order pengganti. Mengulang key yang sama mengembalikan checkout semula setelah scope diperiksa ulang. Detail invoice menampilkan `reserved_amount` dan `payable_amount`; reservation tidak dianggap sebagai pelunasan.

Snap diberi expiry 15 menit sejak order dibuat, mengikuti [custom expiry Midtrans](https://docs.midtrans.com/docs/snap-advanced-feature). Jam lokal yang melewati `expires_at` tidak otomatis melepas reservasi yang sudah dikirim. Provider timeout atau 404 tetap memerlukan konfirmasi: status terminal `expire/cancel/deny/failure` yang terverifikasi melepaskan reservasi. Order berstatus `creating` yang belum pernah dicoba dan sudah kedaluwarsa dapat dilepas tanpa HTTP. Lock RW dan unique reservation mencegah checkout ganda; cash/manual approval, pembatalan invoice, dan penutupan periode memeriksa reservasi yang masih aktif.

Callback Billing memverifikasi signature, lalu mengambil Get Status melalui kredensial server. Nilai status, amount, currency, order, dan settlement time diproses dari hasil provider tersebut. `settlement_time` divalidasi dan dikonversi dari WIB ke timezone aplikasi; waktu callback datang tidak menggantikan waktu bayar. Satu sukses normal menghasilkan satu receipt `channel=gateway`, allocation penuh, ledger gross, audit, dan event receipt. Unique payment/receipt serta transaksi posting menjaga redelivery tetap idempotent. Receipt tetap dapat diposting setelah warga pindah atau aksesnya dicabut; perubahan akses tidak menghilangkan pembayaran yang sudah diterima.

```sh
docker exec -w /var/www/p85/rukun dev-php85 php artisan billing:reconcile-gateway
```

Scheduler menjalankannya setiap menit untuk memulihkan receipt setelah callback terputus dan memeriksa status reservasi. Jika proses gagal setelah order tersimpan tetapi sebelum HTTP, order yang sama dapat diteruskan. Jika hasil HTTP tidak pasti, sistem hanya mengambil status, tidak mengirim charge baru. Checkout dalam `review` atau `review_reason` terisi harus diperiksa pengurus: late success setelah release, periode tertutup, metode selain QRIS, refund/chargeback, dan konflik alokasi tidak memaksa overpayment atau mengubah history final. Refund provider dan koreksi statement otomatis belum termasuk MVP; reversal manual receipt gateway/fee ditolak.

Fee tidak diambil dari asumsi tarif MDR dan tidak dibebankan ke warga. Setelah sukses, bendahara mencatat settlement dari statement aktual:

```json
{
  "fee": 700,
  "settled_on": "2026-10-02",
  "statement_reference": "statement-sandbox-001"
}
```

Untuk gross Rp100.000, data rekonsiliasi menjadi `gross=100000`, `fee=700`, `net=99300`. Receipt dan pelunasan tetap Rp100.000. Fee menghasilkan ledger expense bank **operational**, sehingga dana `pass_through` WiFi tetap utuh. Settlement append-only dan satu per checkout; replay identik menggunakan key semula. `settlement=null` berarti statement belum direkonsiliasi, bukan fee nol. Pembayaran/statement sandbox nyata dan sign-off keuangan tetap harus diverifikasi sebelum production pilot.

## Module system

Buat modul:

```sh
docker exec -w /var/www/p85/rukun dev-php85 php artisan make:module Billing
```

Provider dan route modul dimuat melalui `Core/Providers/ModuleServiceProvider.php`. Modul baru perlu menambahkan migration, permission, audit, OpenAPI, dan Feature test sesuai kebutuhannya.

Tabel boilerplate memakai connection `core`, yang mengarah ke PostgreSQL yang sama dengan prefix fisik `rcore_`. Tabel modul bisnis tanpa prefix memakai connection `rukun` pada model dan operasi domain. Relasi ke user mengacu ke tabel fisik `rcore_users`. Konvensi ini menjaga tabel fondasi mudah dibedakan dari tabel domain.

Model `User` dan schema identity memakai connection `core` secara eksplisit. Migration normalisasi hanya me-rename tabel legacy `users` menjadi `rcore_users` jika tabel tujuan belum ada; PostgreSQL mempertahankan ID, sequence, index dan foreign key. Jika hanya tabel core yang ada, migration tidak mengubah apa pun. Jika kedua tabel ada, migration berhenti untuk rekonsiliasi identitas tanpa menggabungkan atau menghapus data. Normalisasi ini bersifat forward-only: `down()` tidak membuat salinan tabel legacy, karena migration sebelumnya kini memakai tabel core.

Test yang menulis lewat connection `core` dan `rukun` memakai `DatabaseMigrations`, termasuk RBAC Community. Transaksi `RefreshDatabase` pada satu connection tidak membuat data yang belum commit terlihat oleh connection lainnya; akibatnya FK dapat gagal meskipun nama tabel sudah benar. Trait `RefreshDatabase` pada test infrastruktur dideklarasikan pada tingkat file agar persiapan schema benar-benar dijalankan, bukan bergantung pada sisa database dari test lain.

## Testing

Verifikasi 2 Oktober 2026 setelah koreksi normalisasi identity dan isolasi test: **175 test lulus / 1271 assertions**, Laravel Pint, secret scan dan diff check lulus. Pengujian meliputi migration fresh/rollback, preservasi akun dan FK saat rename legacy, serta penolakan konflik dua tabel identity. Data development tidak di-reset.

```sh
docker exec -w /var/www/p85/rukun dev-php85 php artisan test
docker exec -w /var/www/p85/rukun dev-php85 vendor/bin/pint --test
```

Test mencakup isolasi PostgreSQL/Redis, health, response API, module system, queue, scheduler, identity, token ownership, RBAC, concurrency, admin protection, user filters, cursor pagination, settings, audit, Swagger, Telescope, idempotency, dan rate limiting.

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

## Data demo Community untuk testing FE

Jalankan seeder secara eksplisit pada environment `local` atau `testing`:

```sh
docker exec -w /var/www/p85/rukun dev-php85 php artisan db:seed --class=CommunityDemoSeeder --no-interaction
```

Seeder membuat **2 RW, 3 RT, 10 rumah, 40 warga (4 per rumah), 25 akun**, dan satu vendor demo. Rumah 01–04 berada di RT01/RW01, 05–08 di RT02/RW01, dan 09–10 di RT03/RW02. Nama/alamat sintetis; NIK, KK, dan nomor HP tidak diisi.

Password awal bersama dan daftar akun lengkap tersedia di file lokal **`storage/app/private/community-demo-accounts.json`**, field `password`. File ini di-ignore Git dengan permission `0600`; jangan dibagikan atau di-commit. Akun demo aktif dan tidak memerlukan penggantian password pada login pertama. Akun admin bootstrap yang sudah ada tidak diubah.

| Akun | Akses Community |
|---|---|
| `demo.admin@rukun.test` | Super-admin, seluruh 10 rumah / 40 warga |
| `demo.ketua.rw01@rukun.test` | RW01, 8 rumah / 32 warga |
| `demo.ketua.rw02@rukun.test` | RW02, 2 rumah / 8 warga |
| `demo.ketua.rt01@rukun.test` | RT01, 4 rumah / 16 warga |
| `demo.ketua.rt02@rukun.test` | RT02, 4 rumah / 16 warga |
| `demo.ketua.rt03@rukun.test` | RT03, 2 rumah / 8 warga |
| `demo.sekretaris.rt01@rukun.test` sampai `demo.sekretaris.rt03@rukun.test` | Wilayah RT masing-masing |
| `demo.sekretaris.rw01@rukun.test` | RW01 |
| `demo.warga01@rukun.test` sampai `demo.warga10@rukun.test` | Rumah masing-masing, 4 anggota |
| `demo.pengelola.kk08@rukun.test` | Pengelola akun rumah 08 |
| `demo.bendahara.rt01@rukun.test`, `demo.bendahara.rw01@rukun.test` | Keuangan wilayah; Community hanya keluarga sendiri |
| `demo.ronda@rukun.test` | Koordinator ronda RT01; Community hanya keluarga sendiri |
| `demo.vendor@rukun.test` | Vendor WiFi, tanpa akses daftar warga |

Login FE menggunakan email di atas dan password dari file lokal. Pengurus dapat memilih konteks wilayah; warga memilih konteks rumah dan membuka **Keluarga saya** untuk melihat alamat serta anggota keluarga. Respons login/me kini menyediakan `contexts` berisi scope dan capabilities untuk navigasi FE. Otorisasi dan filter data tetap diperiksa server; role wilayah tidak diberikan sebagai permission global.

Seeder aman dijalankan ulang: tidak menggandakan data, tidak mereset password/alamat yang telah diubah, dan tidak mengaktifkan kembali assignment yang dicabut. Password dalam file adalah password **awal**, bukan password baru setelah pengguna menggantinya. Simpan manifest tersebut: jika hilang sementara akun demo masih ada, seeder menolak mengambil alih email yang sama. Seeder tidak dipanggil oleh `DatabaseSeeder` biasa dan tidak berjalan pada production. Dataset ini belum membuat invoice, pembayaran, atau transaksi WiFi.

Verifikasi demo (30 September 2026): **170 test backend / 1246 assertions**, Pint dan validasi OpenAPI lulus; **52 unit test FE**, **2 browser test scoped** (workers=1), typecheck, build, lint dan format lulus. Jumlah development dan password awal seluruh 25 akun terverifikasi; secret scan serta diff check lulus.

## Pengumuman dan layanan warga (F6)

Selesai untuk development lokal pada **7 Oktober 2026**: **204 test / 1500 assertions**, Pint 323 file, OpenAPI 145 paths / 177 operations, secret scan dan diff check lulus. Migration/seeder sudah diterapkan; worker, scheduler dan health HTTPS terverifikasi. Jenis surat aktual dan sign-off workflow pengurus menunggu pilot. Berikutnya F7 Patrol & Community Activities.

Modul `Civic` menyediakan pengumuman RT/RW, laporan warga privat, serta fondasi permohonan surat. Semua endpoint berada di `/api/civic`, memerlukan login aktif dan penyelesaian penggantian password awal. ID pada payload adalah UUID publik. List memakai cursor pagination (`cursor`, `per_page` 1–100); filter `area_id` tidak memperluas hak akses.

| Endpoint | Fungsi |
| --- | --- |
| `GET/POST /api/civic/announcements` | Daftar pengumuman / membuat draft atau jadwal |
| `GET /api/civic/announcements/{id}` | Detail dan status baca pribadi |
| `POST /api/civic/announcements/{id}/publish` | Publikasi dan notifikasi ke audience yang berhak |
| `POST /api/civic/announcements/{id}/archive` | Arsipkan; warga tidak lagi dapat membuka pengumuman/lampirannya |
| `POST /api/civic/announcements/{id}/read` atau `/unread` | Tandai dibaca/belum dibaca untuk akun sendiri |
| `GET/POST /api/civic/reports` | Daftar laporan privat / kirim laporan |
| `GET/POST /api/civic/letter-requests` | Daftar permohonan privat / ajukan surat |
| `GET /api/civic/{reports\|letter-requests}/{id}` | Detail kasus yang berhak diakses |
| `GET /api/civic/{reports\|letter-requests}/{id}/timeline` | Riwayat kronologis dengan cursor pagination |
| `POST /api/civic/{reports\|letter-requests}/{id}/actions` | Penugasan, komentar, pembatalan, atau perubahan status |

Semua mutasi selain `read/unread` wajib mengirim `Idempotency-Key` sepanjang 8–128 karakter (`A–Z`, `a–z`, angka, `.`, `_`, `:`, `-`). Pengulangan key dan payload yang sama mengembalikan resource tanpa mengulang efek; payload berbeda menghasilkan `409`. Izin diperiksa kembali sebelum replay. Respons replay menunjukkan keadaan resource terkini. Mutasi kasus juga memerlukan `version` dari detail terakhir; versi lama menghasilkan `409`.

Contoh membuat pengumuman (`POST /api/civic/announcements`):

```json
{
  "area_id": "<UUID RT atau RW>",
  "title": "Kerja bakti lingkungan",
  "body": "Warga diundang berkumpul pukul 07.00 WIB.",
  "publish_at": "2026-10-11T07:00:00+07:00",
  "attachments": ["<UUID file milik pembuat>"]
}
```

Tanpa `publish_at`, status awal `draft`; dengan tanggal, status `scheduled`. Waktu dengan offset dikonversi ke zona waktu aplikasi sebelum disimpan. Jadwal yang sudah lewat diproses pada siklus scheduler berikutnya. `civic:publish-due` berjalan setiap menit dan memeriksa ulang izin pembuat; pengumuman milik pengurus yang kewenangannya telah dicabut tetap terjadwal sampai pengurus berwenang menanganinya. Publikasi manual tersedia untuk draft/jadwal. Konten dan lampiran ditetapkan saat pembuatan; koreksi dilakukan dengan mengarsipkan lalu membuat pengumuman pengganti.

Pengumuman RT menjangkau anggota rumah aktif di RT tersebut dan pengurus yang berwenang. Pengumuman RW juga menjangkau anggota serta pengurus RT di bawahnya. Draft, jadwal, dan arsip hanya terlihat oleh pengelola wilayah. Filter list `read=1` / `read=0` menggunakan status baca akun sendiri.

Contoh laporan atau permohonan (`POST /api/civic/reports` atau `/letter-requests`):

```json
{
  "household_id": "<UUID rumah tempat pemohon menjadi anggota aktif>",
  "category": "Lingkungan",
  "description": "Lampu jalan di depan rumah tidak menyala.",
  "attachments": []
}
```

Hanya pembuat dan pengurus wilayah dengan `reports.manage` / `letters.manage` yang dapat membuka kasus. Anggota lain di rumah yang sama tidak otomatis mendapat akses. Pembuat tetap dapat membaca riwayat kasusnya setelah pindah/keanggotaan berakhir, tetapi tidak dapat membuat permohonan baru atas rumah lama. Penugasan hanya kepada akun aktif yang masih memiliki kewenangan modul di wilayah kasus; pencabutan kewenangan menghentikan akses petugas meskipun namanya masih tercatat sebagai penerima tugas.

- Laporan: `submitted → in_progress → resolved`; pengurus dapat menolak dari `submitted` atau `in_progress`.
- Surat: `submitted → reviewing → approved`; pengurus dapat menolak dari `submitted` atau `reviewing`. Approval/rejection harus oleh pengurus lain, bukan pemohon sendiri. Approval wajib melampirkan PDF final lewat `output_file_id`.
- Pemohon dapat `cancel` hanya saat `submitted`. Semua status terminal (`resolved`, `approved`, `rejected`, `cancelled`) menghentikan perubahan/komentar berikutnya.
- `comment` tersedia bagi pihak yang dapat melihat kasus; `assign` hanya pengurus. `note` wajib untuk komentar dan penolakan. `assigned_to` wajib hadir saat `assign`, bernilai UUID petugas atau `null` untuk melepas tugas.

Contoh `POST /api/civic/reports/{id}/actions`:

```json
{"version": 1, "action": "assign", "assigned_to": "<UUID petugas>"}
```

Setiap aksi sukses menaikkan versi, menambah timeline immutable, dan mencatat audit dalam transaksi yang sama. Update bersamaan diserialisasi; satu versi tidak dapat dipakai untuk dua perubahan berbeda.

Upload lampiran melalui Files Core terlebih dahulu. Maksimal 10 lampiran awal berupa PDF/JPEG/PNG, harus dimiliki pembuat, dan belum terikat ke resource lain. File yang terikat tidak dapat diubah/dihapus atau digunakan ulang sebagai bukti keuangan. Metadata/download detail melalui `/api/files/{id}` mengikuti izin kasus/pengumuman, termasuk bagi akun dengan izin file global. Dokumen final surat hanya berupa PDF; pembuatan template, penomoran, tanda tangan, dan jenis surat spesifik menunggu kebutuhan pilot.

Kategori notifikasi `civic` memakai inbox; pengguna dapat mematikannya melalui `PUT /api/notification-preferences`, sedangkan email kategori ini dikunci nonaktif. Publikasi memberi notifikasi kepada audience; kasus baru kepada pembuat dan pengurus berwenang; aksi kasus kepada pembuat dan petugas yang ditugaskan. Notifikasi dan replay event `civic.updated` disimpan secara transaksional dengan konteks UUID, tanpa isi laporan, judul privat, atau komentar. API resource tetap memeriksa izin ketika dibuka.

`CivicSeeder` mendaftarkan izin pengumuman/laporan/surat untuk super-admin, ketua dan sekretaris RW/RT. Penugasan wilayah tetap memakai `RoleAssignment`; capability pengajuan warga berasal dari keanggotaan rumah aktif. Seeder ini tidak membuat transaksi layanan dummy.

```sh
docker exec -w /var/www/p85/rukun dev-php85 php artisan migrate --force
docker exec -w /var/www/p85/rukun dev-php85 php artisan db:seed --class='Modules\Civic\Database\Seeders\CivicSeeder' --force
docker exec -w /var/www/p85/rukun dev-php85 php artisan civic:publish-due
```

## Ronda dan kegiatan warga (F7)

Selesai untuk development lokal pada **8 Oktober 2026**: **224 test / 1653 assertions** (517.83 detik), Pint 339 file, OpenAPI 157 paths / 194 operations, secret scan dan diff check lulus. Migration development terverifikasi sudah diterapkan; permission seeder dijalankan ulang, worker direstart, dan health HTTPS database/Redis/storage sehat. Nominal tiap RT dan rekonsiliasi pilot nyata tetap pending. Berikutnya F8 Community Marketplace.

Modul `Engagement` menyediakan manajemen tim ronda, kebijakan ronda, jadwal kegiatan patrol dan aktivitas warga, partisipasi, izin tidak hadir, dan pencatatan insiden. Semua endpoint berada di `/api/engagement`, memerlukan login aktif dan penyelesaian penggantian password awal. ID pada payload adalah UUID publik.

| Endpoint | Fungsi |
| --- | --- |
| `GET /api/engagement/teams` | Daftar tim ronda dalam scope RT/RW |
| `POST /api/engagement/teams` | Buat tim ronda baru |
| `GET /api/engagement/teams/{team}/members` | Daftar anggota tim |
| `POST /api/engagement/teams/{team}/members` | Tambah atau update anggota tim |
| `GET /api/engagement/patrol-policy/{area}` | Baca kebijakan ronda (biaya izin, dll.) |
| `POST /api/engagement/patrol-policy` | Atur kebijakan ronda |
| `GET /api/engagement/events` | Daftar event patrol/aktivitas dalam scope |
| `POST /api/engagement/events` | Buat event baru |
| `GET /api/engagement/events/{id}` | Detail event |
| `POST /api/engagement/events/{id}/cancel` | Batalkan event (wajib `version` + `reason`) |
| `GET /api/engagement/events/{id}/participants` | Daftar peserta event |
| `POST /api/engagement/events/{id}/participants` | Daftarkan peserta (pengurus bisa enroll orang lain) |
| `POST /api/engagement/events/{id}/join` | Warga mendaftarkan diri ke aktivitas |
| `POST /api/engagement/events/{eventId}/participants/{participantId}/actions` | Aksi peserta: `attendance`, `leave`, `approve_leave`, `reject_leave`, `charge`, `withdraw` |
| `GET /api/engagement/events/{eventId}/participants/{participantId}/history` | Riwayat aksi peserta |
| `GET /api/engagement/events/{id}/incidents` | Daftar insiden event |
| `POST /api/engagement/events/{id}/incidents` | Catat insiden |

Semua mutasi wajib mengirim `Idempotency-Key` sepanjang 8–128 karakter. Mutasi yang mengubah versi resource juga memerlukan `version` dari detail terakhir; versi lama menghasilkan `409`. Cross-RT isolation diterapkan — warga dan pengurus hanya dapat melihat event dalam scope wilayah mereka.

Cancel event hanya diizinkan jika event masih `scheduled`, `starts_at` belum lewat, dan tidak ada invoice peserta yang aktif. Izin tidak hadir (`leave`) pada patrol dapat menimbulkan kewajiban pembayaran melalui Billing sesuai kebijakan RT; alur pembebasan telah disepakati, sementara nominal tiap RT mengikuti keputusan pilot.

Insiden hanya terlihat oleh manajer (semua insiden) dan peserta yang mencatatnya. Warga dari RT lain tidak dapat melihat atau bergabung ke event RT yang berbeda.


Kebijakan izin yang disepakati: **default Rp0 sampai RT mengonfigurasi tarif; tagihan hanya dibuat setelah izin disetujui; pembebasan wajib memiliki alasan**. Pembuat izin boleh warga yang ditugaskan atau pengurus atas permintaan warga. Pengurus tidak boleh menyetujui izin untuk dirinya sendiri. `leave` dan `reject_leave` wajib `note`; `approve_leave` dengan `waive=true` juga wajib `note`. Status `excused` hanya berasal dari persetujuan izin, bukan input kehadiran manual.

Contoh aksi pada `/api/engagement/events/{eventId}/participants/{participantId}/actions`:

```json
{"action":"leave","version":1,"note":"Ada keperluan keluarga"}
```

```json
{"action":"approve_leave","version":2,"waive":true,"note":"Pembebasan karena keadaan darurat"}
```

Tarif diambil dari Payment Type Billing dengan `collection_policy=can_accumulate` dan `fund_classification=operational`, dalam wilayah yang sama dengan kebijakan/jadwal. Buat jenis pembayaran dan tarif melalui Billing, lalu hubungkan `payment_type_id` lewat `POST /api/engagement/patrol-policy`. Kirim `payment_type_id=null` untuk menjadikan jadwal baru bebas biaya. Tarif yang berlaku pada tanggal jadwal serta `due_days` disimpan sebagai snapshot saat jadwal dibuat; perubahan kebijakan tidak mengubah jadwal lama. Koordinator ronda dapat mengelola jadwal/izin, tetapi tidak mengubah kebijakan tarif atau mendapat akses umum administrasi keuangan.

Saat izin berbiaya disetujui, adapter Billing membuat satu invoice per peserta. Periode invoice memakai bulan persetujuan dan jatuh tempo dihitung dari tanggal persetujuan; periode kas yang sudah ditutup menolak proses secara atomik sehingga izin tetap pending. Rumah peserta harus masih aktif dan sesuai keanggotaannya saat kewajiban diterbitkan. Pengajuan izin saja, izin ditolak, dan izin yang dibebaskan tidak menghasilkan invoice. Untuk kegiatan berbayar, `payment_type_id` ditentukan saat membuat activity; `charge` oleh pengurus menerbitkan invoice peserta melalui adapter yang sama. Pendaftaran peserta sendiri belum menerbitkan tagihan.

Respons peserta menyertakan `invoice_id` dan `billing` (`amount`, `paid_amount`, `outstanding_amount`, `status`, `overdue`). Nilainya dihitung langsung dari Receipt/Allocation Billing dan memperhitungkan reversal, bukan kolom pelunasan yang bisa diubah pada Patrol. Pembayaran dapat langsung melunasi, dicicil, atau digabung dalam receipt yang mengalokasikan nominal ke beberapa invoice rumah yang sama. Pembatalan/withdraw tidak otomatis membatalkan invoice atau mengembalikan uang; selesaikan koreksi melalui Billing dahulu. Prefix subject `engagement:` dikhususkan untuk adapter ini agar sumber kewajiban tidak ditiru melalui generate invoice umum.

Regu dibuat per RT; anggota harus memiliki akun aktif yang tertaut ke warga/rumah aktif pada RT tersebut. `POST teams/{team}/members` menerima `user_id`, `household_id`; untuk menghapus cukup `user_id` dan `remove=true`. Daftar anggota dan policy hanya dapat dibaca pengurus dengan scope yang sesuai. Anggota regu disalin ke peserta saat jadwal patrol dibuat. Perubahan regu tidak mengubah jadwal lama; penugasan ronda yang waktunya bertabrakan ditolak. MVP belum menyediakan rename/delete regu atau edit waktu jadwal; batalkan jadwal sebelum mulai dan buat pengganti bila perlu.

Pengurus dapat mendaftarkan peserta melalui `/participants`; warga mendaftar dirinya ke activity melalui `/join`. Warga hanya melihat baris peserta/izin miliknya, termasuk bila anggota rumah lain mengikuti kegiatan yang sama. `attendance` hanya `present` atau `absent`, dicatat pengurus mulai waktu event hingga 24 jam setelah selesai. Kehadiran bersifat sekali catat; versi lama, pencatatan ulang, dan penimpaan izin yang sedang diproses ditolak. History peserta bersifat immutable, berpaginasi, dan setiap aksi diaudit.

Lampiran event serta bukti insiden memakai file private Core milik pengunggah, maksimal 10 PDF/JPEG/PNG. Lampiran terkunci dari edit/delete dan penggunaan ulang. Bukti insiden hanya terlihat pelapor atau pengurus berwenang, termasuk ketika pemegang izin file global mencoba mengaksesnya. Jadwal, izin, dan insiden memakai kategori inbox `civic` dan event replay `civic.updated`, dengan resource type `community_event`, `event_participant`, atau `event_incident`. Notifikasi hanya menyimpan pesan generik serta UUID; isi izin/insiden diambil dari API yang memeriksa scope.

Koleksi pengujian tersedia di [Engagement Postman collection](docs/Engagement.postman_collection.json) dan [environment template](docs/Rukun.postman_environment.json). Isi `identifier`, `password`, dan UUID dari data lokal di Postman; token/password pada template dikosongkan. Jangan commit hasil export environment yang sudah berisi credential. OpenAPI tersedia melalui `/docs/api`.
