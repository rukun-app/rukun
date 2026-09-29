# Rukun --- Final Backend Implementation Plan

> **Status:** Final backend plan\
> **Baseline:** Core R (Laravel 13 / PHP 8.5)\
> **Scope dokumen:** Backend only. Frontend dibahas dalam dokumen
> terpisah.\
> **Prinsip:** Rukun menambahkan domain bisnis di atas Core R, bukan
> membuat ulang foundation yang sudah tersedia.

------------------------------------------------------------------------

## Status Implementasi

> **Status per 29 September 2026:** F0–F3 selesai untuk development lokal; rekonsiliasi dataset/buku kas pilot nyata F2/F3 belum dilakukan; F4 tahap 1 (paket/pelanggan/tagihan WiFi) diimplementasikan; settlement dan galon belum selesai; F5–F8 belum dimulai; F9 HOLD. Gate CI remote dan staging dipindahkan ke sebelum production pilot sesuai arahan pengguna. Status foundation Core R tidak otomatis berarti acceptance gate Rukun sudah lulus.

| Fase | Nama | Status |
|---|---|---|
| F0 | Bootstrap & Core R Isolation | Selesai untuk development lokal |
| F1 | Identity, Account Provisioning & Area | Selesai termasuk dependency F2 |
| F2 | Household, Resident & Scoped Authorization | Selesai lokal; dataset pilot nyata belum direkonsiliasi |
| F3 | Billing, Manual Payments & Cashbook | Selesai lokal; buku kas pilot nyata belum direkonsiliasi |
| F4 | WiFi Collective & Gallon Benefit | Tahap 1 diimplementasikan; settlement dan galon belum selesai |
| F5 | Payment Gateway / QRIS | Belum dimulai |
| F6 | Announcements & Citizen Services | Belum dimulai |
| F7 | Patrol & Community Activities | Belum dimulai |
| F8 | Community Marketplace | Belum dimulai |
| F9 | CCTV | HOLD / DEFERRED |

### Checklist F0

- [x] Remote `origin` menuju `rukun-app/rukun`; `upstream` Core R mempunyai push URL `no_push`.
- [x] Workflow merge upstream didokumentasikan di README.
- [x] Template development memakai nama Rukun, hostname Rukun, database `rukun`, prefix Redis `rukun:`, DB Redis 3–5, dan bucket `rukun`.
- [x] Compose worker/scheduler/Reverb memakai identitas Rukun dan jaringan existing `docker-network`.
- [x] Prefix tabel foundation `rcore_` dipertahankan.
- [x] AGENTS.md memakai `/var/www/p85/rukun`; `.env.testing`, PHPUnit, test infrastruktur, dan CI memakai `rukun_test`.
- [x] `.env` dan `.env.testing` lokal dibuat dengan credential shared infrastructure dari `../core-r`, APP_KEY terpisah, credential Reverb baru, permission `0600`, dan Git ignore terverifikasi. Midtrans nonaktif pada F0; sandbox dikonfigurasi pada F3.
- [x] Database `rukun` / `rukun_test` dan bucket `rukun` dibuat; migration, RBAC seeder, dan private storage write/read/delete lulus.
- [x] Health HTTPS Rukun: database, Redis, dan storage `up`.
- [x] Feature test bootstrap admin, login, dan `/api/auth/me` lulus pada database `rukun_test`.
- [x] Administrator development dibuat dan login serta `/api/auth/me` diverifikasi melalui HTTPS; token probe dicabut. Credential lokal berada di `storage/app/private/bootstrap-admin.json` (Git ignored, permission `0600`); hapus setelah password diganti.
- [x] PostgreSQL, Redis, MinIO, Nginx HTTPS, worker khusus Rukun (ProbeJob), dan scheduler (heartbeat) terverifikasi.
- [x] Koneksi SMTP MailDev berhasil.
- [x] Email probe ke mailbox lokal MailDev diterima dan diverifikasi melalui API MailDev.
- [x] Pest: 96 test / 549 assertions; Pint lulus pada working copy Rukun.
- [x] Validasi gabungan Compose jobs/realtime, `git diff --check`, dan secret scan file tracked lulus.
- [x] OpenAPI generation/validation lulus: 49 paths / 59 operations.
- [ ] **Gate sebelum production pilot:** GitHub Quality Gate lulus pada repository Rukun.
- [ ] **Gate sebelum production pilot:** konfigurasi staging HTTPS, JSON logging, Telescope disabled, secret handling, backup schedule, dan object storage diverifikasi.
- [x] Dump/restore PostgreSQL lokal ke database rehearsal sementara: jumlah tabel dan seeded roles sesuai; database rehearsal dihapus.
- [ ] **Gate sebelum production pilot:** backup dan restore staging (termasuk object storage) diuji.
- [x] Belum ada modul bisnis Rukun yang dibuat sebelum gate F0 selesai.

### Checklist F1

- [x] Login `identifier` menerima email atau nomor HP Indonesia; field `email` lama tetap didukung secara eksklusif.
- [x] Normalisasi server-side, unique email case-insensitive / phone, UUID publik user, dan constraint minimal identifier.
- [x] Akun phone-only tidak memerlukan email atau OTP; akun dengan email tetap mengikuti kebijakan verifikasi Core.
- [x] `must_change_password` membatasi akses ke profil sendiri, ganti password, dan logout; password baru harus berbeda.
- [x] Provisioning manual melalui `POST /api/community/accounts`, scoped ke RT, dengan mandatory `Idempotency-Key`.
- [x] Password awal acak unik, output ciphertext Redis berumur 15 menit, download satu kali oleh pembuat yang masih berwenang, tanpa plaintext DB/audit.
- [x] Recovery administratif scoped/audited, idempotent, mencabut token lama dan reset link lama, serta melindungi akun pengurus berprivilege.
- [x] Recovery email menggunakan Core dan menghapus pembatasan password awal setelah reset sukses.
- [x] CRUD RW/RT dinamis, UUID publik, cursor pagination, policy, en/id, OpenAPI, serta DB constraint hierarki.
- [x] Scoped role assignment GLOBAL/RW/RT dengan starts_at/ends_at, multi-role, revocation history, dan RW inheritance.
- [x] Tabel bisnis tanpa prefix; audit dan mutasi provisioning memakai transaksi yang sama melalui koneksi `rukun` ke database yang sama.
- [x] Final F1: 116 Pest test / 686 assertions, Pint, OpenAPI (56 paths / 70 operations), secret scan, dan diff check lulus.
- [x] **Dependency F2:** link Resident ↔ User dan import Household/Resident dengan `create_account`.
- [x] **Dependency F2:** household-assisted recovery dengan explicit capability, scope HOUSEHOLD/VENDOR, serta sinkronisasi scope akun saat mutasi membership.

Pembagian dependency: F1 membuat akun dengan `account_scopes` sebagai scope pengelolaan administratif di RT. Data ini bukan Household/Resident atau bukti hubungan keluarga. F2 membangun hubungan domain tersebut dan memastikan scope akun mengikuti mutasi yang sah. Penempatan household recovery/import di F2 menghindari pemakaian role keluarga sebagai shortcut authorization.

### Checklist F2

- [x] Household pada RT dengan alamat/blok/nomor, status hunian, status administratif, dan UUID/reference unik.
- [x] Resident dengan demografi, phone normalisasi, status, serta User opsional; satu Household dapat memiliki banyak Resident/akun.
- [x] Satu membership aktif per Resident dengan constraint PostgreSQL, relationship dan histori mutasi.
- [x] API list/detail/create/update, membership/history, account provisioning/linking, serta sensitive read/write.
- [x] NIK/KK optional encrypted; HMAC unique; tidak ada dalam list/audit payload; read sensitif permission-gated dan diaudit; redaksi Telescope/log.
- [x] Scope GLOBAL/RW/RT/HOUSEHOLD/VENDOR, inheritance RW, temporal multi-role, dan vendor scope anchor (operasional WiFi tetap F4).
- [x] Household-assisted recovery dengan capability eksplisit dan keanggotaan aktif actor/target; relationship tidak memberi privilege.
- [x] Mutasi membership memperbarui account_scopes, mencabut token serta assignment Household lama, dan menjaga role jabatan independen.
- [x] Core DataTransfer `community.population`: CSV/XLSX, row validation, duplicate/replay detection, progress, audit, error CSV, dan optional `create_account` melalui service F1.
- [x] Import satu transaksi per baris; kegagalan provisioning me-rollback Household/Resident/membership pada baris tersebut.
- [x] Scope diperiksa saat submit, proses queue, dan download export; role scoped tidak memperoleh global DataTransfer privilege.
- [x] Rehearsal sintetis: CSV 2 baris valid/1 gagal menghasilkan 1 Household import, 2 Resident/membership, 1 User; replay tidak menggandakan data. XLSX 3 baris menghasilkan 2 Household import/3 Resident/membership, export kembali 3 baris.
- [x] OpenAPI, README, dan kontrak import/retensi credential diperbarui.
- [x] Verifikasi akhir: 130 Pest test / 846 assertions, Pint, OpenAPI 71 paths / 92 operations, secret scan tracked/untracked, dan diff check lulus. Migration/seeder development diterapkan, sinyal restart worker dikirim, serta HTTPS health database/Redis/storage sehat.
- [ ] **Sebelum production pilot:** import dan rekonsiliasi dataset warga nyata bersama pengurus; dataset belum diberikan. Rehearsal sintetis bukan sign-off data operasional.

Keputusan F2: import/export umum tidak memuat NIK/KK; identifier sensitif dikelola melalui endpoint khusus. Export memuat snapshot Resident aktif dan membership saat ini, bukan backup histori. Error download memuat maksimal 100 baris gagal pertama. Credential import tetap 15 menit dari waktu pembuatan. Detail endpoint, kolom CSV/XLSX, serta konsekuensi rotasi APP_KEY ada di README. F3 dapat dilanjutkan setelah gate lokal F2 lulus; CI remote, staging, dan sign-off data operasional tetap gate sebelum production pilot.

### Checklist F3

- [x] Payment type scoped RT/RW, tariff dengan effective period eksklusif/non-overlap, dan snapshot invoice historis.
- [x] Generate invoice idempotent/natural unique, draft/issue/cancel, partial/paid/overdue derived, collection policy dan fund classification.
- [x] Cash receipt bernomor, explicit/default allocation, no-overpay, dan pemisahan dana operasional/titipan.
- [x] Submission transfer manual dengan bukti privat, pending tanpa receipt, reviewer independen scoped, approve/reject/cancel, dan history review.
- [x] Approval satu transaksi: validasi saldo ulang, receipt, allocation, ledger, audit, event, dan status submission; duplicate approval tidak menggandakan receipt.
- [x] Bukti terpakai dilindungi dari update/delete; akses file oleh reviewer tetap scoped.
- [x] Ledger append-only pada PostgreSQL; adjustment, transfer kas/bank dua sisi, receipt/journal reversal dengan audit trail.
- [x] Expense draft → independent approval → posted, evidence opsional, tanpa edit histori.
- [x] Accounting period close RT/RW, snapshot report, larangan backdate, dan pembayaran arrears periode lama melalui cash date periode berjalan.
- [x] Event durable F3 dan scheduler invoice.due_soon dengan deduplikasi harian.
- [x] Rehearsal satu bulan sintetis direkonsiliasi: penerimaan 100.000, pengeluaran 25.000, transfer kas/bank 30.000, adjustment dibalik → kas 45.000 + bank 30.000 = 75.000.
- [x] Test dua proses receipt bersamaan: hanya satu transaksi dapat memakai outstanding yang tersisa; cross-RT isolation, partial payment, rejection, reversal dan closed period teruji.
- [x] `.env` lokal Midtrans disalin dari `../core-r`: credential terisi tanpa dicetak/tracked, mode sandbox aktif, permission 0600; `.env.testing` tetap terpisah. Adapter invoice/gateway masih F5.
- [x] OpenAPI Billing, README, dan kontrak financial operation diperbarui.
- [x] Gate akhir: 145 Pest test / 997 assertions, Pint, OpenAPI 100 paths / 127 operations, secret scan tracked/untracked, dan diff check lulus. Migration/seeder development diterapkan, sinyal restart worker dikirim, serta health HTTPS database/Redis/storage sehat.
- [ ] **Sebelum production pilot:** bandingkan minimal satu bulan data nyata dengan buku kas pengurus. Data operasional belum tersedia; rehearsal sintetis bukan sign-off keuangan nyata.

Keputusan F3: rupiah integer, semua POST memakai Idempotency-Key persisten, partial allocation tidak menyisakan saldo tak teralokasi, reviewer berbeda dari pembuat, dan koreksi menggunakan reversal. Close membekukan bulan yang dipilih serta menolak transaksi mundur ke bulan sebelumnya. Lock mutasi memakai RW induk; ledger dan audit/events commit bersama. Cashbook mengizinkan saldo negatif untuk rekonsiliasi, bukan kontrol saldo rekening bank. F4 dimulai setelah gate lokal F3 selesai; remote CI, staging, dan rekonsiliasi data pilot nyata tetap gate sebelum production pilot.

### Checklist F4

- [x] Paket WiFi mengikat vendor dan payment type khusus F3, wajib `must_settle_in_period` + `pass_through`.
- [x] Pelanggan menghubungkan Household, paket/vendor, tanggal aktivasi/akhir, dan konfigurasi tanggal tagihan; periode satu Household tidak bertumpang tindih.
- [x] Scope RT/RW/VENDOR dan akses Household sendiri; vendor tidak memperoleh akses finance atau identitas sensitif.
- [x] Invoice per pelanggan/bulan, snapshot tariff, due date/settle-by configurable, idempotency persisten, dan relasi bill append-only.
- [x] Endpoint general billing tidak dapat melewati jalur pelanggan WiFi; pembayaran parsial menggunakan receipt/allocation F3 dan dana titipan.
- [x] Test konkurensi dua proses menghasilkan satu invoice/bill; replay setelah pencabutan scope ditolak; closed period, inactive vendor dan mismatch area diuji.
- [x] Tunnel webhook-only Rukun berjalan melalui Nginx bersama dan Cloudflare Quick Tunnel; signature valid 200, invalid 401, path lain 404. Credential tetap ignored.
- [x] README dan OpenAPI tahap 1 diperbarui.
- [x] Gate tahap 1: 154 test / 1064 assertions, Pint, OpenAPI 106 paths / 135 operations, secret scan dan diff check lulus. Migration/seeder development diterapkan, worker restart, dan HTTPS health database/Redis/storage sehat.
- [ ] Settlement eligibility pada cutoff, remittance vendor dan rekonsiliasi receipt WiFi.
- [ ] Advance/receivable saat RT mendahulukan dana, reversal dan rekonsiliasinya.
- [ ] Gallon ledger append-only: grant/reserve/claim/confirm/expire/reverse, quota default 10 dan saldo tidak negatif.
- [ ] Claim vendor → konfirmasi warga, reversal, deduplikasi dan pengujian konkurensi kuota.
- [ ] Sign-off kebijakan remittance/advance, prorata dan metode konfirmasi sebelum pilot nyata; rekonsiliasi satu periode WiFi/galon nyata.
- [ ] Konfigurasi Payment Notification URL di dashboard Midtrans oleh pengguna; adapter invoice/gateway tetap F5.

Keputusan tahap 1: tagihan bulanan penuh tanpa prorata; aktivasi boleh di tengah bulan, akhir langganan eksklusif pada hari pertama bulan berikutnya. Hari due/settle configurable 1–28. F4 **belum selesai**; pekerjaan berikutnya adalah settlement/remittance/advance, lalu benefit galon. Gate CI remote dan staging tetap sebelum production pilot.

### Log Implementasi

| Tanggal | Fase | Perubahan / bukti | Tindak lanjut |
|---|---|---|---|
| 2026-09-28 | F0 | Audit remote dan mount container; template identity dan compose diisolasi untuk Rukun; README dan tracker ini diperbarui. | Compose config, diff check, dan tracked secret scan lulus; gate runtime, Pest/Pint, OpenAPI, CI, dan staging belum lulus. |
| 2026-09-28 | F0 | `.env` dan `.env.testing` dibuat; isolasi identity/key dan Git ignore diperiksa tanpa menampilkan secret. | Database testing masih `laravel_core_test` sesuai AGENTS.md; penyelarasan aturan, provisioning database/bucket, dan runtime check belum selesai. |
| 2026-09-28 | F0 | Isolasi database mengikuti konfigurasi terbaru `rukun` / `rukun_test`; migration, RBAC seeder, bucket, HTTPS health, worker/scheduler, SMTP connection, local DB restore, 96 test / 549 assertions, Pint, OpenAPI 49 paths / 59 operations lulus. | Admin development, email end-to-end, CI remote dan staging belum terverifikasi; F1 belum dimulai. |
| 2026-09-28 | F0 | Admin development login dan `/api/auth/me` lulus melalui HTTPS; email probe diterima MailDev lokal; seluruh gate lokal selesai. | Sesuai arahan pengguna, CI remote dan staging menjadi gate sebelum production pilot. |
| 2026-09-28 | F1 | Identity email/HP, UUID user, first-login password change, provisioning/recovery scoped dan idempotent, output sementara terenkripsi, area CRUD, temporal scope GLOBAL/RW/RT, audit satu transaksi, constraint DB, en/id, dan OpenAPI selesai. Migration dan seeder diterapkan di development. | 116 test / 686 assertions, Pint, OpenAPI 56 paths / 70 operations, secret scan, serta diff check lulus. Integrasi Resident/Household, import create_account, scope HOUSEHOLD/VENDOR, dan household-assisted recovery dilanjutkan di F2. |
| 2026-09-29 | F2 | Household/Resident, histori satu membership aktif, encrypted NIK/KK dan audit akses, scope HOUSEHOLD/VENDOR, account linking/provisioning, household recovery, sinkronisasi scope/token saat mutasi, serta Core DataTransfer CSV/XLSX dengan validasi/replay/hasil error selesai. README dan OpenAPI diperbarui. Migration/seeder development diterapkan; worker diberi sinyal restart dan health HTTPS sehat. | 130 test / 846 assertions, Pint, OpenAPI 71 paths / 92 operations, secret scan dan diff check lulus. Rehearsal sintetis terrekonsiliasi; dataset pilot nyata, CI remote, dan staging tetap belum diverifikasi sebelum production pilot. F3 belum dimulai. |
| 2026-09-29 | F3 | Billing scoped, tariff snapshot, invoice draft/issue/cancel, partial cash receipt dan alokasi, transfer manual dengan independent review/evidence, ledger append-only, expense, reversal dua sisi, period close/report snapshot, serta durable notification/scheduler selesai. Konfigurasi Midtrans sandbox disalin ke .env ignored. Migration/seeder development dan health lulus. | 145 test / 997 assertions (termasuk dua proses receipt bersamaan), Pint, OpenAPI 100 paths / 127 operations, secret scan, dan diff check lulus. Rehearsal sintetis satu bulan cocok; pembukuan pilot nyata belum dibandingkan. F4 belum dimulai; adapter invoice/gateway tetap F5. |
| 2026-09-29 | F4 tahap 1 + tunnel | Paket/pelanggan scoped, aktivasi/akhir langganan, billing khusus WiFi dengan snapshot F3, pembayaran partial pass-through, idempotency dan histori bill immutable selesai. Tunnel Rukun aktif, hanya POST webhook Midtrans; signature valid 200, invalid 401, path lain 404. Migration/seeder development, worker restart dan health lulus. | 154 test / 1064 assertions (termasuk dua proses generate), Pint, OpenAPI 106 paths / 135 operations, secret scan dan diff check lulus. F4 belum selesai: settlement/remittance/advance serta ledger/claim galon berikutnya. Pengguna memasang URL tunnel dari scripts/tunnel/webhook.sh url ke dashboard sandbox; URL dapat berubah setelah restart. Adapter invoice/gateway tetap F5. |

Checklist `[x]` hanya untuk pekerjaan yang telah dilakukan; `[ ]` berarti belum selesai atau belum diverifikasi. Setiap tahap memperbarui tabel fase, checklist, log perubahan, hasil pengujian, dan README. Fase berikutnya tidak dimulai sebelum gate development fase aktif selesai. CI remote dan staging tetap wajib sebelum production pilot, tetapi tidak menghalangi F1 dan fase development berikutnya.

------------------------------------------------------------------------

## 1. Tujuan

Rukun adalah platform pengelolaan lingkungan RW/RT dengan fokus awal
pada:

-   struktur RW, RT, household/KK, dan warga;
-   akun warga dan scoped management access;
-   tagihan, pembayaran, kas, dan laporan;
-   pembayaran cash, transfer manual dengan approval, dan payment
    gateway;
-   pengelolaan WiFi kolektif dan benefit galon;
-   pengumuman dan layanan warga;
-   ronda dan kegiatan;
-   marketplace warga;
-   integrasi CCTV pada fase akhir.

Pilot awal menggunakan satu RW dengan jumlah RT dan household dinamis.
Arsitektur data tidak boleh meng-hardcode jumlah RT/household dan harus
tetap memungkinkan evolusi ke multi-RW di masa depan, tetapi
multi-RW/multi-tenant bukan scope MVP.

------------------------------------------------------------------------

## 2. Baseline Core R

Backend Rukun menggunakan Core R sebagai upstream/foundation.

Foundation yang sudah tersedia dan harus digunakan ulang:

-   Laravel 13 / PHP 8.5;
-   PostgreSQL 16;
-   Redis;
-   Laravel Sanctum bearer token;
-   Identity dan token lifecycle;
-   dynamic Role & Permission;
-   Settings;
-   Audit Trail;
-   File Management;
-   Multilingual `en` / `id`;
-   Notification in-app + email;
-   Realtime durable events + optional Reverb + polling;
-   Request ID dan structured logging;
-   Idempotency;
-   Rate limiting;
-   automated quality gate;
-   Import/Export CSV/XLSX;
-   Payment Foundation / Midtrans;
-   OpenAPI 3.1 / Swagger;
-   Pest dan Laravel Pint.

Business module Rukun berada di `Modules/`.

Core R tetap dipertahankan sebagai `upstream`. Perubahan terhadap Core
harus sekecil mungkin dan hanya dilakukan jika capability tersebut
memang domain-neutral atau diperlukan untuk extension Identity.

------------------------------------------------------------------------

## 3. Prinsip Arsitektur

### 3.1 Pemisahan identity dan domain

Empat konsep berikut tidak boleh disamakan:

``` text
User
= akun authentication

Resident
= individu/warga

Household
= rumah tangga / KK dan unit billing

Household Membership
= hubungan Resident dengan Household
```

Seorang Resident tidak wajib mempunyai User.

Satu Household dapat mempunyai beberapa Resident yang mempunyai akun
masing-masing.

Tagihan default melekat pada Household, bukan pada User.

### 3.2 Server sebagai sumber kebenaran

Client tidak boleh menjadi sumber authoritative untuk:

-   nominal invoice;
-   outstanding balance;
-   payment allocation;
-   permission;
-   scope;
-   status pembayaran;
-   eligibility WiFi/galon;
-   gateway fee;
-   status financial ledger.

### 3.3 Financial history tidak diedit

Financial ledger dan benefit ledger menggunakan prinsip append-only.

Kesalahan diperbaiki dengan reversal/correction entry, bukan mengubah
histori lama.

### 3.4 API contract

Semua business module mengikuti standar Core R:

-   stable machine-readable error `code`;
-   cursor pagination;
-   public UUID/ULID;
-   `Idempotency-Key` pada create operation yang relevan;
-   `X-Request-ID`;
-   audit trail;
-   translation `en` dan `id`;
-   OpenAPI;
-   Feature test.

------------------------------------------------------------------------

# PHASE 0 --- Project Bootstrap & Core R Isolation

## Tujuan

Mengubah clone Core R menjadi backend Rukun yang terisolasi tanpa
merusak kemampuan menerima update upstream.

## Deliverables

### Repository

-   `origin` mengarah ke repository Rukun.
-   Core R disimpan sebagai read-only `upstream`.
-   workflow merge upstream didokumentasikan.

### Project identity

Ganti seluruh identity Core R yang project-specific:

-   `APP_NAME`;
-   `APP_URL`;
-   `FRONTEND_URL`;
-   database development/testing;
-   Redis prefix dan DB index;
-   MinIO/S3 bucket;
-   Reverb credential/origin;
-   container/service identity;
-   log/service labels.

Core table prefix tetap dipertahankan untuk foundation Core R.

Business tables Rukun menggunakan PostgreSQL tanpa prefix Core.

### Infrastructure

Verifikasi:

-   PostgreSQL;
-   Redis;
-   MinIO;
-   MailDev;
-   queue worker;
-   scheduler;
-   optional Reverb;
-   Nginx;
-   testing database isolation.

### Production/staging preparation

Siapkan:

-   HTTPS;
-   JSON structured logging;
-   Telescope disabled pada production;
-   database backup schedule;
-   restore procedure;
-   object storage strategy;
-   environment secret handling.

## Acceptance Gate

Phase selesai jika:

-   `/api/health` sehat;
-   admin dapat login;
-   `/api/auth/me` bekerja;
-   PostgreSQL/Redis/storage/mail/queue/scheduler teruji;
-   test DB terpisah dari development;
-   `php artisan test` lulus;
-   Pint lulus;
-   OpenAPI generation lulus;
-   secret scan lulus;
-   GitHub Quality Gate lulus sebelum production pilot (bukan blocker development);
-   backup + restore staging pernah diuji sebelum production pilot (bukan blocker development);
-   tidak ada business module Rukun sebelum gate lokal selesai.

------------------------------------------------------------------------

# PHASE 1 --- Identity Extension, Account Provisioning & Area

## 1. Authentication

Core R Identity diperluas agar login menerima:

``` text
identifier + password + device_name
```

`identifier` dapat berupa:

-   email; atau
-   nomor HP.

### User login identifiers

Tambahkan normalized phone pada User.

Aturan:

-   email boleh nullable;
-   phone boleh nullable;
-   login-enabled User wajib memiliki minimal email atau phone;
-   email unique;
-   normalized phone unique;
-   phone dinormalisasi server-side;
-   password tetap menggunakan hashing Laravel;
-   Sanctum tetap menjadi token mechanism.

OTP tidak diperlukan pada MVP.

SMS/WhatsApp OTP menjadi backlog/future integration.

## 2. Initial Account Provisioning

Implementasi F1 menyediakan provisioning akun manual scoped RT. Alur yang membuat Resident/Household dan flag import `create_account` diselesaikan di F2 bersama model domain tersebut, menggunakan service provisioning F1.

Akun dapat dibuat:

-   manual oleh pengurus berwenang;
-   melalui import Resident/Household;
-   invitation flow dapat ditambahkan kemudian.

Resident tidak otomatis mempunyai User.

Import menyediakan flag semacam:

``` text
create_account = yes/no
```

Jika akun dibuat:

``` text
Create Resident
    ↓
Create User
    ↓
Link User ↔ Resident
    ↓
Generate random initial credential
    ↓
must_change_password = true
```

### Initial password

Dilarang menggunakan satu password default universal.

Setiap akun mendapat random initial password unik.

Plaintext initial password:

-   tidak disimpan dalam database;
-   tidak masuk log;
-   tidak masuk audit payload;
-   hanya tersedia sebagai provisioning output sementara;
-   provisioning output bersifat private;
-   hanya pengurus berwenang dapat mengunduhnya;
-   memiliki retention/expiry pendek.

Login menggunakan initial password wajib mengarahkan User ke password
change sebelum menggunakan aplikasi secara normal.

## 3. Password Recovery

MVP menyediakan tiga jalur.

### Email self-service

Jika User mempunyai email:

``` text
Forgot Password
→ email reset link
→ password baru
→ revoke token lama
```

Gunakan capability reset password Core R.

### Administrative recovery

Pengurus berwenang dapat memulai recovery untuk warga dalam scope-nya.

Contoh permission:

``` text
users.recover-account
```

Administrative recovery harus:

-   scoped;
-   diaudit;
-   mencabut token lama sesuai lifecycle Identity;
-   tidak memungkinkan admin membaca password permanen User;
-   mewajibkan User menetapkan password baru.

### Household-assisted recovery — implementasi di F2

Anggota Household tertentu dapat membantu recovery akun anggota lain
jika mempunyai explicit household account-management capability.

Relationship seperti `head`, `spouse`, atau `child` tidak otomatis
memberikan permission recovery.

Semua household-assisted recovery diaudit.

## 4. Area

Model area mendukung:

``` text
RW
└── RT
```

Pilot awal:

``` text
1 RW
└── N RT
```

Jumlah aktual merupakan data runtime/import.

## Acceptance Gate

-   email login bekerja;
-   phone login bekerja;
-   identifier normalization teruji;
-   duplicate phone/email ditolak;
-   initial credential unik;
-   first-login password change enforced;
-   email recovery bekerja;
-   administrative recovery scoped dan audited;
-   household recovery tidak dapat dilakukan anggota tanpa hak (gate F2, setelah Household/Membership tersedia);
-   token lifecycle setelah reset teruji;
-   RW/RT CRUD terproteksi;
-   cross-area unauthorized access ditolak.

------------------------------------------------------------------------

# PHASE 2 --- Household, Residents & Scoped Authorization

## 1. Household

Household adalah unit administrasi dan unit billing utama.

Data minimum:

-   public ID;
-   RT;
-   alamat;
-   blok/nomor;
-   status hunian;
-   nomor KK optional encrypted;
-   status active/moved/inactive;
-   timestamps.

## 2. Resident

Data minimum:

-   public ID;
-   nama;
-   NIK optional encrypted;
-   tanggal lahir optional;
-   phone optional;
-   status;
-   user reference optional;
-   timestamps.

Status dapat mencakup:

``` text
active
moved
deceased
inactive
```

## 3. Household Membership

Relasi Resident ↔ Household menyimpan relationship.

Contoh:

``` text
head
spouse
child
parent
other
```

Model harus memungkinkan histori mutasi warga.

## 4. Sensitive Data

NIK dan nomor KK:

-   optional;
-   encrypted at rest;
-   masked/default hidden;
-   tidak masuk generic list response;
-   tidak masuk log;
-   tidak masuk generic audit payload.

Permission khusus:

``` text
residents.view-sensitive
```

Akses terhadap data sensitif harus dicatat.

## 5. Scoped RBAC

Core R tetap menjadi Role/Permission engine.

Rukun menambahkan scoped assignment.

Contoh data:

``` text
RoleAssignment
├── user_id
├── role_id
├── scope_type
├── scope_id
├── starts_at
├── ends_at
├── assigned_by
└── status
```

Scope awal:

``` text
GLOBAL
RW
RT
HOUSEHOLD
VENDOR
```

### Authorization pipeline

``` text
User
 ↓
Active RoleAssignment
 ↓
Role / Permission
 ↓
Scope Resolver
 ↓
Resource scope
 ↓
Policy
 ↓
ALLOW / DENY
```

### Scope inheritance

RW assignment mencakup RT di bawah RW tersebut.

RT assignment hanya mencakup RT tersebut.

Household assignment hanya mencakup Household terkait.

Vendor assignment hanya mencakup resource vendor terkait.

Multi-role diperbolehkan.

Temporal role assignment wajib didukung agar pergantian pengurus tidak
menghapus histori jabatan.

## 6. Roles awal

Contoh role:

``` text
super-admin
ketua-rw
bendahara-rw
sekretaris-rw
ketua-rt
bendahara-rt
sekretaris-rt
koordinator-ronda
vendor-wifi
warga
```

Permission tetap menjadi sumber authorization; role hanya grouping
permission.

## 7. Import / Export

Gunakan Core R DataTransfer.

Import awal mendukung:

-   Household;
-   Resident;
-   membership;
-   optional User provisioning.

Import harus mempunyai:

-   row-level validation;
-   duplicate detection;
-   progress;
-   downloadable error result;
-   idempotent/retry-safe behavior yang relevan;
-   audit.

## Acceptance Gate

-   Resident/User provisioning dan import create_account terhubung ke service F1;
-   household-assisted recovery membutuhkan explicit capability dan diaudit;
-   account_scopes mengikuti mutasi Household/Membership yang sah;
-   satu Household dapat mempunyai beberapa Resident;
-   Resident tanpa User valid;
-   beberapa User dapat terhubung ke Household yang sama;
-   warga hanya melihat Household yang berhak diakses;
-   RT tidak dapat melihat RT lain;
-   RW dapat melihat child RT;
-   temporal role assignment bekerja;
-   multi-role bekerja;
-   sensitive data terlindungi;
-   import data pilot dapat direkonsiliasi;
-   cross-RT isolation mempunyai Feature test.

------------------------------------------------------------------------

# PHASE 3 --- Billing, Manual Payments & Cashbook

Phase ini menjadi pusat financial domain Rukun.

## 1. Payment Types & Tariffs

Payment type mendukung contoh:

-   iuran warga;
-   keamanan;
-   kebersihan;
-   WiFi;
-   kegiatan;
-   jenis tagihan lain.

Tariff mempunyai effective period.

Saat invoice diterbitkan, nominal tariff di-snapshot.

Perubahan tariff tidak mengubah invoice historis.

## 2. Invoice

Invoice default diterbitkan kepada Household.

Data penting:

-   public ID;
-   household;
-   payment type;
-   billing period;
-   subject optional;
-   amount;
-   due/settlement date;
-   collection policy;
-   fund classification;
-   status.

Status derived dari financial state, misalnya:

``` text
draft
issued
partially_paid
paid
overdue
cancelled
```

## 3. Collection Policy

Dukung:

``` text
must_settle_in_period
can_accumulate
```

Contoh:

-   WiFi: `must_settle_in_period`;
-   iuran tertentu: dapat `can_accumulate`.

Partial payment didukung.

## 4. Receipt & Allocation

Semua pembayaran terverifikasi akhirnya menghasilkan Receipt.

``` text
Receipt
    ↓
ReceiptAllocation
    ↓
Invoice
```

Satu receipt dapat mempunyai allocation sesuai business rule.

Allocation tidak boleh melebihi outstanding invoice.

Default allocation:

1.  current `must_settle_in_period`;
2.  kemudian arrears tertua.

Explicit allocation oleh payer/pengurus dapat didukung selama valid.

## 5. Payment Channels

### A. Cash

``` text
Warga
 ↓
Bendahara menerima cash
 ↓
Record Receipt
 ↓
Allocation
 ↓
Invoice state recalculated
```

Setiap cash receipt mempunyai nomor receipt dan audit.

### B. Manual Bank Transfer

Warga dapat melakukan transfer di luar payment gateway lalu mengajukan
pembayaran.

``` text
Transfer bank
 ↓
Payment Submission
 ↓
upload evidence
 ↓
PENDING
 ↓
authorized reviewer
 ├── APPROVE
 └── REJECT
```

Data submission:

``` text
public_id
household_id
submitted_by
amount
transferred_at
destination_account
proof_file
note
status
reviewed_by
reviewed_at
review_note
```

Status:

``` text
pending
approved
rejected
cancelled
```

Submission bukan Receipt.

Invoice tidak berubah hanya karena submission dibuat.

### Approval

Approval harus transactional:

``` text
lock submission
→ verify pending state
→ authorize reviewer + scope
→ validate target invoice/outstanding
→ create Receipt
→ create Allocation
→ create Ledger entries
→ mark submission approved
→ recalculate invoice
→ audit
→ publish event
```

Nominal submission yang salah tidak diedit reviewer.

Reviewer menolak dengan alasan, kemudian User membuat submission baru.

### Permissions

Contoh:

``` text
payments.manual.submit
payments.manual.view
payments.manual.approve
payments.manual.reject
```

Permission selalu digabungkan dengan scope.

## 6. Cashbook / Ledger

Ledger append-only.

Kategori dasar:

``` text
income
expense
transfer
adjustment
reversal
```

Fund classification membedakan uang operasional dan dana titipan.

Kesalahan:

``` text
wrong entry
→ reversal
→ correct entry
```

bukan edit histori.

## 7. Expenses

Expense mempunyai lifecycle:

``` text
draft
→ approved
→ posted
```

Approval mengikuti scoped permission.

Evidence dapat menggunakan File Management Core R.

## 8. Accounting Period

Period dapat ditutup per RT/RW sesuai scope.

Setelah close:

-   histori periode tidak diedit;
-   cash backdate ke closed period ditolak;
-   pembayaran arrears setelah close dicatat sebagai current cash
    receipt tetapi tetap dialokasikan ke invoice lama;
-   close menghasilkan report snapshot/reconciliation.

## 9. Notification

Event minimum:

``` text
invoice.created
invoice.due_soon
payment_submission.created
payment_submission.approved
payment_submission.rejected
receipt.created
```

## Acceptance Gate

-   invoice generation idempotent;
-   tariff snapshot benar;
-   partial payment benar;
-   allocation tidak dapat overpay;
-   cash receipt bekerja;
-   manual submission tidak mengubah invoice sebelum approval;
-   duplicate approval tidak menghasilkan receipt ganda;
-   reviewer di RT lain ditolak;
-   rejection mempertahankan history;
-   reversal menjaga audit trail;
-   closed period rules teruji;
-   monthly report dapat direkonsiliasi;
-   minimal satu bulan pilot dapat dibandingkan dengan pembukuan manual.

------------------------------------------------------------------------

# PHASE 4 --- WiFi Collective & Gallon Benefit

## 1. WiFi Vendor

Vendor mempunyai scope sendiri.

Vendor hanya dapat mengakses customer/data yang memang diperlukan untuk
operasional vendor tersebut.

## 2. WiFi Package & Customer

WiFi Customer menghubungkan:

``` text
Vendor
Package
Household
Activation
Status
Billing configuration
```

## 3. WiFi Billing

WiFi menggunakan Billing Phase 3.

Payment type WiFi:

``` text
collection_policy = must_settle_in_period
fund_classification = pass_through
```

Dana WiFi adalah titipan/pass-through, bukan income RT.

## 4. Settlement

Pada `settle_by`, sistem menentukan payment eligibility.

Vendor remittance direkonsiliasi dengan receipt WiFi.

Perbedaan karena RT mendahulukan pembayaran warga dicatat sebagai
advance/receivable, bukan mengubah income.

Detail tanggal remittance dan kebijakan advance tetap
configurable/decision bisnis sebelum pilot WiFi nyata.

## 5. Gallon Benefit

Default awal dapat menggunakan kuota 10 galon per periode untuk customer
yang memenuhi eligibility.

Model benefit menggunakan append-only gallon ledger.

Event:

``` text
grant
reserve
claim
confirm
expire
reverse
```

Balance tidak boleh negatif.

## 6. Claim

``` text
Eligible Household
 ↓
Gallon quota
 ↓
Vendor records delivery/claim
 ↓
Resident confirmation
 ↓
Final claim
```

Unconfirmed delivery tidak dianggap final.

Metode confirmation awal dapat berupa PIN singkat; detail final dapat
dikunci sebelum implementasi production.

## Acceptance Gate

-   invoice WiFi tidak duplicate;
-   partial payment dalam periode bekerja;
-   eligibility hanya diberikan sesuai settlement rule;
-   WiFi fund tidak masuk RT income;
-   vendor isolation teruji;
-   gallon balance tidak negatif;
-   duplicate claim dicegah;
-   unconfirmed claim tidak final;
-   reversal mempunyai history;
-   remittance dapat direkonsiliasi dengan receipt dan advance.

------------------------------------------------------------------------

# PHASE 5 --- Payment Gateway / QRIS

Payment gateway adalah payment channel, bukan financial source of truth.

## 1. Integration

Gunakan Core R Payment Foundation / PaymentManager.

Rukun menyediakan Billing integration.

``` text
Invoice
 ↓
Billing validates payable amount
 ↓
PaymentManager
 ↓
Midtrans
 ↓
QRIS
```

MVP menggunakan dynamic QRIS.

## 2. Checkout

Server menentukan:

-   invoice;
-   allocation;
-   outstanding;
-   payable amount.

Client tidak boleh mengirim nominal authoritative.

Create checkout menggunakan `Idempotency-Key`.

Pending checkout/reservation harus diperhitungkan agar concurrent
checkout tidak menyebabkan overpayment.

## 3. Callback

``` text
Midtrans
 ↓
verified callback
 ↓
Core Payment status
 ↓
Rukun Billing handler
 ↓
Receipt
 ↓
Allocation
 ↓
Ledger
 ↓
Invoice recalculation
```

Callback harus idempotent.

Webhook redelivery tidak boleh membuat receipt ganda.

## 4. Gateway Fee

QRIS MDR/gateway fee tidak dibebankan ke warga pada model awal.

Catat:

``` text
gross receipt
gateway fee
net settlement
```

secara terpisah agar reconciliation memungkinkan.

## 5. Payment Time

Payment timestamp menggunakan gateway success time yang tervalidasi.

## Acceptance Gate

-   server-side amount authoritative;
-   concurrent checkout tidak overpay;
-   webhook signature/verification bekerja;
-   webhook redelivery idempotent;
-   gateway success membuat tepat satu Receipt;
-   allocation benar;
-   fee terpisah;
-   expired checkout melepaskan reservation;
-   reconciliation gross/fee/net dapat dilakukan.

------------------------------------------------------------------------

# PHASE 6 --- Announcements & Citizen Services

## Modules

### Announcements

Mendukung:

-   RW/RT scoped announcement;
-   read/unread;
-   attachments;
-   notification;
-   publish scheduling bila diperlukan.

### Citizen Reports

Data:

``` text
reporter
household
area
category
description
attachments
status
assigned_to
timeline
```

Status lifecycle ditentukan modul.

### Administrative Requests / Letters

Foundation untuk:

-   request surat;
-   workflow review;
-   approval/rejection;
-   attachment/output document;
-   audit.

Jenis surat aktual ditambahkan sesuai kebutuhan pilot.

## Acceptance Gate

-   announcement hanya mencapai target scope;
-   read status bekerja;
-   report privacy benar;
-   assignment mengikuti scope;
-   attachments private;
-   status history audited;
-   notification bekerja.

------------------------------------------------------------------------

# PHASE 7 --- Patrol & Community Activities

## Patrol

Mendukung:

-   schedule;
-   group/team;
-   member assignment;
-   attendance;
-   pengajuan izin tidak hadir oleh warga;
-   pencatatan izin oleh admin/pengurus RT terkait atas permintaan warga;
-   kewajiban biaya izin sesuai kebijakan RT;
-   notes;
-   incident/evidence bila dibutuhkan.

### Izin Tidak Hadir & Kewajiban Pembayaran

Jika warga yang dijadwalkan ronda tidak dapat hadir, warga dapat mengajukan izin melalui aplikasi atau menghubungi admin/pengurus RT yang berwenang agar izin dicatatkan.

Izin dapat menimbulkan kewajiban pembayaran sesuai kebijakan RT. Modul Patrol tidak mencatat pembayaran sendiri. Jika ada biaya, Patrol membuat kewajiban melalui Billing sehingga dapat dibayar langsung atau dialokasikan/dikalkulasi pada pembayaran berikutnya.

Status pelunasan selalu mengikuti verified Receipt dan Allocation pada Billing, bukan status finansial yang diubah manual di Patrol. Nominal biaya, kondisi pembebasan, dan aturan izin lainnya ditentukan saat Phase 7 diimplementasikan.

## Activities

Mendukung:

-   event/activity;
-   scope RW/RT;
-   schedule;
-   participant;
-   attendance;
-   attachment;
-   optional contribution reference.

Jika kegiatan menghasilkan kewajiban pembayaran, modul harus membuat
Billing Invoice melalui Billing service, bukan membangun financial
ledger sendiri.

## Acceptance Gate

-   schedule scoped;
-   attendance valid;
-   warga dapat mengajukan izin untuk assignment miliknya;
-   pengurus berwenang dapat mencatat izin dalam scope-nya;
-   izin berbiaya menghasilkan kewajiban melalui Billing;
-   kewajiban izin dapat dibayar langsung atau dialokasikan pada pembayaran berikutnya;
-   status pelunasan konsisten dengan Receipt/Allocation Billing;
-   cross-RT isolation;
-   activity payment tidak menduplikasi Billing;
-   audit dan notification tersedia.

------------------------------------------------------------------------

# PHASE 8 --- Community Marketplace

Marketplace MVP adalah listing warga, bukan e-commerce platform penuh.

## Scope

``` text
Listing
Seller
Category
Title
Description
Price
Images
Contact method
Status
```

Seller harus merupakan User/Resident yang valid.

File gambar menggunakan Core R File Management.

Status contoh:

``` text
draft
active
sold
inactive
removed
```

## Non-goals MVP

Tidak ada:

-   escrow;
-   Rukun checkout;
-   marketplace payment gateway;
-   shipping;
-   commission;
-   wallet.

Payment marketplace tidak menggunakan Billing sampai business model
secara eksplisit ditentukan.

## Acceptance Gate

-   hanya owner/authorized moderator dapat mengubah listing;
-   listing scope/privacy benar;
-   image authorization benar;
-   moderation audited;
-   tidak ada dependency ke financial ledger.

------------------------------------------------------------------------

# PHASE 9 --- CCTV Integration — HOLD / DEFERRED

**Status: HOLD / DEFERRED.**

Perangkat CCTV belum terpasang dan masih dalam proses pengajuan. Phase ini bukan blocker untuk development maupun production pilot Rukun.

Tidak dilakukan implementasi CCTV sampai perangkat aktual tersedia. Setelah perangkat tersedia, identifikasi merek/model, akses yang tersedia, kebutuhan keamanan, dan lakukan PoC protocol/integration sebelum arsitektur teknis dikunci.

## Backend scope (rencana awal, belum diimplementasikan)

Backend dapat menyimpan:

``` text
Camera
Area
Location
Display metadata
Stream reference/configuration
Access policy
Status
```

Credential CCTV tidak boleh diekspos ke frontend atau disimpan plaintext
tanpa protection.

Sebelum implementasi, lakukan PoC terhadap brand/protocol aktual.

Possible integration baru dipilih setelah diketahui apakah perangkat
menggunakan:

-   RTSP;
-   HLS;
-   ONVIF;
-   vendor cloud;
-   gateway/transcoding khusus.

## Acceptance Gate

Belum ditetapkan selama Phase 9 berstatus HOLD.

Acceptance gate baru disusun setelah perangkat CCTV tersedia/terpasang, merek dan model diketahui, protocol aktual diketahui, dan PoC integrasi dilakukan. Tidak boleh mengunci arsitektur streaming sebelum hardware/protocol aktual diketahui.

------------------------------------------------------------------------

# 4. Cross-Cutting Requirements

## 4.1 Module Definition of Done

Setiap module/phase dianggap selesai jika relevan dan mempunyai:

-   migration;
-   model;
-   factory/seeder bila perlu;
-   policy;
-   permission;
-   service/use-case layer;
-   request validation;
-   API;
-   audit events;
-   `en` / `id` translation;
-   OpenAPI;
-   Unit test untuk pure business logic;
-   Feature test untuk HTTP/database/authorization;
-   cross-scope isolation test;
-   README/plan update.

## 4.2 Database

-   PostgreSQL sebagai primary DB.
-   internal PK boleh bigint.
-   public API memakai UUID/ULID.
-   money disimpan integer Rupiah.
-   timestamp disimpan konsisten.
-   financial record penting tidak hard-delete.
-   constraint/index dibuat di DB untuk invariant penting.

## 4.3 Concurrency

Gunakan transaction + DB locking ketika race condition dapat
menyebabkan:

-   overpayment;
-   duplicate approval;
-   duplicate claim;
-   negative gallon balance;
-   duplicate invoice;
-   conflicting close period.

Redis lock digunakan hanya jika DB transaction/constraint tidak cukup
atau operasi lintas-resource membutuhkannya.

## 4.4 Audit

Audit wajib untuk:

-   role assignment;
-   sensitive data access;
-   account recovery;
-   manual payment review;
-   receipt reversal;
-   expense approval;
-   accounting close;
-   WiFi remittance;
-   gallon reversal;
-   administrative request decision;
-   privileged file access.

Jangan memasukkan secret, password, NIK/KK plaintext, credential, token,
atau unnecessary sensitive payload ke audit.

## 4.5 Files

Gunakan Core R File Management untuk:

-   payment evidence;
-   expense evidence;
-   resident documents bila nanti dibutuhkan;
-   citizen report attachment;
-   administrative document;
-   marketplace images;
-   WiFi remittance evidence.

File private by default.

## 4.6 Notifications

Channel MVP:

``` text
database / in-app
email
```

SMS/WhatsApp/mobile push bukan dependency MVP.

Business modules publish notification melalui Core contract, bukan
provider langsung.

## 4.7 Realtime

Business modules publish durable event melalui Core.

Frontend nantinya dapat menggunakan:

-   polling cursor; dan/atau
-   Reverb WebSocket.

Business logic tidak boleh bergantung pada WebSocket availability.

## 4.8 Idempotency

Idempotency wajib dipertimbangkan untuk:

-   invoice generation;
-   account provisioning/import;
-   manual payment approval;
-   payment checkout;
-   gateway callback;
-   gallon grant;
-   gallon claim/confirmation;
-   scheduled jobs.

## 4.9 Privacy

Prinsip:

-   collect minimum required data;
-   NIK/KK optional;
-   encrypt sensitive identifiers;
-   permission + scope before access;
-   redact logs;
-   private files;
-   audit privileged access;
-   retention policy ditentukan sebelum production pilot.

------------------------------------------------------------------------

# 5. Business Rules yang Dikunci

## BR-01 --- Billing Unit

Household/KK adalah unit billing default.

## BR-02 --- Tariff Snapshot

Invoice menyimpan nominal saat diterbitkan.

## BR-03 --- Collection Policy

Dukung `must_settle_in_period` dan `can_accumulate`.

## BR-04 --- Partial Payment

Partial payment diperbolehkan selama policy payment type mengizinkan.

## BR-05 --- Allocation

Receipt harus dialokasikan secara eksplisit/terdeterminasi ke invoice.

## BR-06 --- Payment Verification

Payment hanya dianggap diterima jika:

-   cash dicatat pengurus berwenang;
-   manual transfer disetujui reviewer berwenang; atau
-   payment gateway memberikan status sukses tervalidasi.

User submission sendiri tidak pernah menjadikan invoice paid.

## BR-07 --- Invoice Status

`partially_paid` / `paid` merupakan hasil dari verified Receipt
Allocation.

Tidak boleh diubah manual sebagai shortcut.

## BR-08 --- Ledger

Financial ledger append-only.

Correction melalui reversal.

## BR-09 --- Closed Period

Closed accounting period tidak dapat menerima backdated mutation.

## BR-10 --- WiFi Fund

WiFi collective fund adalah pass-through/titipan, bukan RT income.

## BR-11 --- Gallon Eligibility

Benefit galon berasal dari eligibility pembayaran WiFi sesuai settlement
rule.

## BR-12 --- Gallon Ledger

Append-only dan tidak boleh menghasilkan negative balance.

## BR-13 --- QRIS Fee

Gateway fee dicatat terpisah dan tidak dibebankan ke warga pada model
awal.

## BR-14 --- Sensitive Data

NIK/KK tidak tersedia melalui endpoint/list biasa tanpa explicit
permission.

## BR-15 --- Scope Isolation

Setiap administrative operation harus lolos permission dan domain scope.

## BR-16 --- Password Provisioning

Tidak ada universal default password.

Imported/provisioned account menggunakan unique initial credential dan
wajib mengganti password.

## BR-17 --- Account Recovery

Recovery administratif/household selalu authorized, scoped, audited, dan
tidak memberikan kemampuan membaca password permanen user.

------------------------------------------------------------------------

# 6. Open Decisions Sebelum Fase Terkait

Keputusan berikut sengaja tidak menghambat fase awal.

## Sebelum Phase 4 --- WiFi

Tentukan:

-   vendor settlement/remittance date;
-   `settle_by`;
-   gallon expiration;
-   maximum delivery rule;
-   kebijakan RT advance jika resident belum membayar saat remittance;
-   final resident confirmation mechanism.

## Sebelum Phase 5 --- Payment Gateway

Tentukan:

-   merchant account production;
-   settlement bank account;
-   production credential ownership;
-   reconciliation procedure;
-   final QRIS minimum transaction constraint;
-   platform revenue model jika kelak ada.

## Sebelum Production Pilot

Tentukan:

-   privacy policy;
-   retention NIK/KK;
-   retention payment evidence;
-   consent/terms;
-   backup retention;
-   disaster recovery target;
-   siapa yang boleh menjadi household account manager.

## Sebelum Phase 9 --- CCTV

Tentukan hardware/brand/protocol melalui PoC.

------------------------------------------------------------------------

# 7. Implementation Order

``` text
Core R Baseline
      │
      ▼
F0  Project Bootstrap
      │
      ▼
F1  Identity + Account Provisioning + Area
      │
      ▼
F2  Household + Resident + Scoped RBAC
      │
      ▼
F3  Billing + Manual Payment + Cashbook
      │
      ▼
F4  WiFi + Gallon
      │
      ▼
F5  QRIS / Midtrans
      │
      ▼
F6  Announcements + Citizen Services
      │
      ▼
F7  Patrol + Activities
      │
      ▼
F8  Marketplace
      │
      ▼
F9  CCTV [HOLD / DEFERRED]
```

F5 tidak menjadi blocker pilot financial manual karena F3 sudah
mendukung cash dan manual bank transfer.

F4 ditempatkan sebelum F5 karena WiFi/galon dapat diuji menggunakan
manual payment terlebih dahulu.

------------------------------------------------------------------------

# 8. Final Backend Boundary

Backend Rukun bertanggung jawab terhadap:

``` text
identity
authorization
domain rules
data integrity
financial state
payment verification
scope isolation
audit
notification event
realtime event
files
import/export
API contract
```

Frontend tidak bertanggung jawab menentukan business state.

Frontend nantinya hanya:

``` text
request
display
collect input
present server state
react to event
```

Dengan boundary ini, backend dapat dikembangkan dan diuji terlebih
dahulu sebelum final frontend architecture dikunci.

------------------------------------------------------------------------

# 9. Final Target

Backend dianggap siap menuju production pilot ketika:

1.  F0-F5 selesai dan seluruh acceptance gate lulus.
2.  Household/Resident pilot berhasil di-import dan direkonsiliasi.
3.  Scoped RBAC tidak memiliki cross-RT/RW leakage pada test.
4.  Satu siklus billing bulanan berhasil berjalan.
5.  Cash, manual transfer approval, dan QRIS menghasilkan
    Receipt/Allocation yang konsisten.
6.  Monthly close dan report dapat direkonsiliasi.
7.  WiFi pass-through fund dapat direkonsiliasi.
8.  Gallon eligibility dan claim dapat direkonsiliasi.
9.  Backup/restore production-like environment terbukti.
10. Audit, notification, OpenAPI, Pest, Pint, dan CI lulus.

F6-F8 dapat dilanjutkan setelah transactional core F0-F5 stabil. F9 CCTV tetap HOLD sampai perangkat tersedia dan proses pengajuan selesai.
