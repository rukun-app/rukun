# Core R Boilerplate Roadmap V2

## 1. Tujuan

Roadmap V2 melanjutkan fondasi Core R setelah Infrastructure, Identity, RBAC, Settings, Audit, Swagger, dan Telescope selesai. Setiap fondasi besar menjadi fase terpisah agar implementasi, review, dan acceptance gate dapat diselesaikan secara bertahap.

Fondasi domain-neutral utama dalam roadmap ini:

1. **File Management** untuk upload, metadata, akses, attachment, download, dan penghapusan file melalui Laravel Filesystem.
2. **Multilingual Foundation** untuk Bahasa Inggris dan Bahasa Indonesia pada API, validation, email, notification, dan modul berikutnya.
3. **Notification Foundation** untuk notifikasi in-app dan email yang berjalan melalui queue, memiliki status baca, serta preferensi user.
4. **Realtime & Polling Foundation** agar modul dapat menerbitkan event yang diterima melalui WebSocket atau disinkronkan ulang melalui polling cursor.
5. **Payment Foundation** untuk checkout generik, Midtrans Snap, webhook tervalidasi, redirect pengguna, audit, dan event status tanpa memasukkan lifecycle order bisnis ke Core.

> **Status per 28 September 2026:** V2.1–V2.7, V2.8B, V2.8C, dan V2.9 selesai. V2.8A Webhook Umum tetap backlog opsional. Boilerplate sudah dapat dijadikan baseline proyek bisnis baru.

Implementasi harus tetap independen dari domain bisnis tertentu. Modul bisnis berikutnya menggunakan kontrak dan service yang disediakan roadmap V2 tanpa bergantung langsung pada MinIO, SMTP provider, Reverb, atau struktur internal notifikasi.

### Baseline sebelum V2

| Baseline | Status |
|---|---|
| Laravel Core dan shared infrastructure | Selesai |
| Identity, user administration, dan RBAC | Selesai |
| Runtime settings dan audit trail | Selesai |
| Swagger/OpenAPI dan Telescope | Selesai |
| Cursor pagination dan API response standard | Selesai |

### Roadmap fase V2

| Fase | Nama | Status | Deliverable utama |
|---:|---|---|---|
| V2.1 | File Management | Selesai | Storage abstraction, metadata, private download, attachment, dan cleanup |
| V2.2 | Multilingual Foundation | Selesai | English/Indonesian locale resolution, translated API, validation, dan notification locale |
| V2.3 | Notification Foundation | Selesai | Inbox, email, preferences, queue delivery, dan notification API |
| V2.4 | Realtime & Polling | Selesai | Durable event stream, WebSocket adapter, polling cursor, dan recovery |
| V2.5 | Operational Reliability | Selesai | Request ID, structured logging, queue failure policy, dan operational commands |
| V2.6 | API Reliability & Protection | Selesai | Idempotency dan named rate limiters |
| V2.7 | Automated Quality Gate | Selesai | CI untuk Pest, Pint, PostgreSQL, Redis, dan OpenAPI |
| V2.8 | Integration Foundation | Sebagian selesai | V2.8B Import/Export dan V2.8C Payment selesai; V2.8A webhook umum tetap backlog |
| V2.9 | Runtime Settings Governance | Selesai | Database override, environment fallback, grouping metadata, dan reset API |

Setiap fase harus lulus test dan dokumentasinya sendiri sebelum fase berikutnya dimulai.

## 2. Prinsip desain

| Prinsip | Keputusan |
|---|---|
| Portability | File memakai Laravel Filesystem; email memakai Laravel Notification |
| Private by default | File baru tidak dapat diakses publik tanpa keputusan eksplisit |
| Stable public identifier | API menggunakan UUID/ULID file, bukan path storage atau ID berurutan |
| Authorization first | Setiap operasi file dan notifikasi memeriksa pemilik atau permission |
| Asynchronous delivery | Email dan pekerjaan file yang berat diproses melalui Redis queue |
| Auditable | Aktivitas administratif dan perubahan penting dicatat di audit trail |
| Extensible | Modul bisnis dapat menempelkan file dan mengirim notifikasi tanpa mengetahui implementasi storage/channel |
| Safe defaults | Ukuran, MIME type, nama file, metadata, dan akses divalidasi server side |
| Cursor pagination | Seluruh endpoint koleksi mengikuti standar cursor yang sudah ada |

## 3. Scope

### 3.1 File Management

- Upload file melalui multipart API.
- Penyimpanan binary melalui disk Laravel Filesystem yang dikonfigurasi environment.
- Metadata file di PostgreSQL.
- File private sebagai default.
- Download private melalui streamed response atau temporary signed URL.
- Daftar dan detail file milik user.
- Penggantian nama tampilan dan metadata aman.
- Soft delete metadata dan penghapusan object storage melalui queued job.
- Attachment polymorphic agar modul bisnis dapat mengaitkan file ke model domain.
- Permission administratif untuk melihat dan menghapus file lintas user.
- Audit upload, attachment, detach, download administratif, dan delete.
- Settings untuk batas upload dan tipe MIME yang diperbolehkan.

### 3.2 Multilingual Foundation

- Bahasa Inggris (`en`) dan Bahasa Indonesia (`id`) sebagai locale awal.
- Middleware pemilihan locale untuk setiap API request.
- Preferensi locale pada profil user.
- Terjemahan validation, authentication, authorization, business error, dan response message.
- Locale-aware queued mail dan notification.
- Translation namespace per module agar modul baru dapat menambah bahasa tanpa mengubah Core.
- Stable machine-readable error code yang tidak berubah ketika bahasa berubah.
- Fallback dan observability untuk translation key yang belum tersedia.

### 3.3 Notification Foundation

- Laravel database notifications sebagai inbox in-app.
- Email notifications melalui MailDev pada development.
- Endpoint daftar, unread count, detail, mark as read/unread, mark all as read, dan delete.
- Cursor pagination dan filter read/unread/type.
- Preferensi channel per user dan kategori notifikasi.
- Service/contract untuk mengirim notifikasi dari modul lain.
- Queue routing berdasarkan prioritas `high`, `default`, dan `low`.
- Satu notification contoh untuk verifikasi integrasi end-to-end.
- Audit perubahan preferensi dan tindakan administratif.

### 3.4 Realtime & Polling Foundation

- Kontrak penerbitan event yang tidak bergantung langsung pada provider WebSocket.
- Laravel Broadcasting dengan Reverb sebagai adapter WebSocket pertama.
- Private channel terotorisasi untuk user dan resource domain.
- Durable user event stream sebagai fallback saat client offline atau WebSocket terputus.
- Endpoint polling incremental berbasis cursor.
- Event envelope yang versioned dan konsisten untuk seluruh modul.
- Retention dan cleanup event stream terjadwal.
- WebSocket tetap opsional; aplikasi dan polling dapat berjalan tanpa proses Reverb.

## 4. Di luar scope

| Fitur | Alasan |
|---|---|
| Antivirus/malware scanning engine | Membutuhkan layanan eksternal; hanya siapkan status/extension point bila diperlukan nanti |
| Image transformation dan thumbnail pipeline | Dikerjakan saat kebutuhan domain nyata tersedia |
| Chunked/resumable upload | Multipart upload biasa cukup untuk foundation |
| Public media library/CDN | Memerlukan kebijakan publikasi dan caching khusus |
| SMS, WhatsApp, mobile push | Provider dan aturan bisnis belum ditentukan |
| Template email yang diedit dari database | Menambah kompleksitas versioning dan keamanan terlalu dini |
| File sharing antar-user | Membutuhkan model sharing dan kebijakan domain |
| Quota/billing storage | Diterapkan saat model tenant atau paket layanan tersedia |

## 5. Struktur modul

```text
Modules/
├── Files/
│   ├── Contracts/
│   ├── Http/
│   ├── Jobs/
│   ├── Models/
│   ├── Policies/
│   ├── Services/
│   └── routes/
├── Notifications/
    ├── Contracts/
    ├── Http/
    ├── Models/
    ├── Notifications/
    ├── Services/
│   └── routes/
└── Realtime/
    ├── Contracts/
    ├── Events/
    ├── Http/
    ├── Models/
    ├── Services/
    └── routes/
```

Kedua modul didaftarkan melalui module loader yang sudah tersedia.

## 6. Desain data File Management

### 6.1 Tabel `files`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | Primary key internal |
| `public_id` | uuid/ulid, unique | Identifier yang diekspos ke API |
| `owner_id` | foreign key users | Pemilik file |
| `disk` | string | Nama disk Laravel, bukan credential |
| `path` | string, unique per disk | Object key internal, tidak diekspos |
| `original_name` | string | Nama saat upload setelah normalisasi |
| `display_name` | string | Nama yang boleh diubah user |
| `mime_type` | string | MIME hasil deteksi server |
| `extension` | nullable string | Extension hasil normalisasi |
| `size` | unsigned bigint | Ukuran byte |
| `checksum` | string | SHA-256 untuk integritas |
| `visibility` | enum/string | Default `private`; public belum diaktifkan pada API awal |
| `metadata` | nullable json | Metadata aman dan terbatas |
| `created_at`, `updated_at` | timestamp | Timestamp standar |
| `deleted_at` | timestamp | Soft delete |

### 6.2 Tabel `attachments`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | Primary key |
| `file_id` | foreign key files | File terkait |
| `attachable_type`, `attachable_id` | morph | Model domain tujuan |
| `collection` | string | Contoh `documents`, `avatar`, `evidence` |
| `sort_order` | integer | Urutan file dalam koleksi |
| `created_by` | foreign key users | Actor yang memasang attachment |
| `created_at`, `updated_at` | timestamp | Timestamp standar |

Unique constraint mencegah file yang sama terpasang dua kali pada collection dan resource yang sama.

### 6.3 Lifecycle file

```text
Upload request
    → validate size and detected MIME
    → stream to configured disk
    → calculate checksum
    → persist metadata in transaction
    → return public_id

Delete request
    → authorize
    → soft-delete metadata
    → dispatch physical deletion job
    → retry safely when storage is temporarily unavailable
```

Jika penulisan database gagal setelah object tersimpan, service harus membersihkan object kompensasi. Job penghapusan fisik harus idempotent.

## 7. API File Management

| Method | Endpoint | Akses | Fungsi |
|---|---|---|---|
| `POST` | `/api/files` | Bearer | Upload multipart file |
| `GET` | `/api/files` | Bearer | Daftar file milik user |
| `GET` | `/api/files/{file}` | Owner / `files.view-any` | Detail metadata |
| `PATCH` | `/api/files/{file}` | Owner / `files.update-any` | Ubah display name/metadata |
| `GET` | `/api/files/{file}/download` | Owner / `files.download-any` | Download private |
| `DELETE` | `/api/files/{file}` | Owner / `files.delete-any` | Soft delete dan antrekan cleanup |

Endpoint daftar mendukung `search`, `mime_type`, `from`, `to`, `per_page`, dan `cursor`. Attachment API generik hanya ditambahkan bila policy resource tujuan dapat dievaluasi dengan aman. Jika belum, attachment dilakukan melalui service dari controller modul pemilik resource.

## 8. File authorization dan keamanan

- Route model binding menggunakan `public_id`.
- Path, bucket, disk credential, dan internal primary key tidak dikirim ke client.
- Nama file tidak dipakai sebagai storage path.
- Object key dibuat server dengan prefix proyek dan tanggal/identifier acak.
- MIME dideteksi dari isi file; extension client bukan sumber kebenaran.
- Validasi menggunakan daftar MIME yang diizinkan dan ukuran maksimum settings.
- Download memakai response dengan `Content-Disposition` aman.
- Temporary URL memiliki expiry pendek dan hanya dibuat setelah authorization.
- SVG, HTML, executable, dan format aktif ditolak secara default.
- File private tidak boleh berada pada route web publik tanpa authorization.
- Metadata bebas dibatasi ukuran, key, dan tipe nilainya.
- Penghapusan file yang masih terpasang ditolak, kecuali operasi delete eksplisit mendukung detach secara transactional.

## 9. Permission File Management

| Permission | Fungsi |
|---|---|
| `files.view-any` | Melihat metadata file milik user lain |
| `files.download-any` | Mengunduh file milik user lain |
| `files.update-any` | Mengubah metadata file milik user lain |
| `files.delete-any` | Menghapus file milik user lain |

User biasa tetap dapat mengelola file miliknya melalui ownership policy tanpa permission administratif.

## 10. Settings File Management

| Key | Tipe | Default awal | Publik |
|---|---|---:|:---:|
| `files.max_upload_mb` | integer | `10` | Tidak |
| `files.allowed_mime_types` | array | PDF, JPEG, PNG, WebP, plain text | Tidak |

Nilai final harus divalidasi melalui `SettingsRegistry`. Batas reverse proxy/PHP juga didokumentasikan karena dapat membatasi request sebelum Laravel berjalan.

## 11. Multilingual Foundation

### Locale registry dan resolution

Locale awal yang didukung:

| Locale | Bahasa | Peran |
|---|---|---|
| `en` | English | Default teknis dan fallback terakhir |
| `id` | Bahasa Indonesia | Bahasa aplikasi kedua |

Gunakan registry kode/config untuk supported locale; client tidak boleh mengaktifkan locale arbitrary. Middleware `ResolveLocale` menentukan locale dengan urutan:

1. Header `Accept-Language` yang valid dan didukung sebagai override request.
2. Preferensi `locale` user yang authenticated.
3. Runtime setting `app.locale` jika didukung.
4. `APP_FALLBACK_LOCALE`, dikunci ke `en` pada baseline V2.

Tag regional dinormalisasi ke bahasa yang didukung, misalnya `id-ID` menjadi `id` dan `en-US` menjadi `en`. Locale tidak valid atau tidak didukung diabaikan dan memakai fallback. Response menyertakan header `Content-Language` agar pilihan bahasa eksplisit.

Tambahkan kolom nullable `locale` pada `users`. `PATCH /api/auth/profile` menerima `locale: en|id`. Tambahkan endpoint publik `GET /api/locales` yang mengembalikan daftar locale, default, dan fallback agar frontend tidak melakukan hardcode konfigurasi deployment.

### Struktur translation

```text
lang/
├── en/
│   ├── api.php
│   ├── auth.php
│   ├── passwords.php
│   └── validation.php
└── id/
    ├── api.php
    ├── auth.php
    ├── passwords.php
    └── validation.php

Modules/<Name>/lang/
├── en/messages.php
└── id/messages.php
```

Core memakai translation key terkelompok. Module provider memuat namespace, misalnya `files::messages.uploaded`. Jangan memakai kalimat Inggris sebagai translation key karena sulit direfactor dan tidak memberi grouping yang stabil.

### Kontrak API multilingual

Response error menambahkan machine-readable `code` yang stabil:

```json
{
  "success": false,
  "code": "auth.unauthenticated",
  "message": "Unauthenticated.",
  "errors": []
}
```

Dengan `Accept-Language: id`, `code` tetap sama sedangkan `message` diterjemahkan. Client mengambil keputusan berdasarkan HTTP status dan `code`, bukan membandingkan teks. Validation error menerjemahkan message, nama atribut, dan representasi enum yang ditampilkan kepada manusia.

Nilai machine-readable berikut tidak diterjemahkan:

- Permission dan role key.
- Event type dan audit event key.
- Enum/database value seperti `active` dan `suspended`.
- Setting key, API field, route, cursor, identifier, serta error code.
- Filename asli dan user-generated content.

Swagger mendokumentasikan `Accept-Language`, `Content-Language`, supported locale, stable error code, dan contoh `en` serta `id`. OpenAPI operation ID dan schema name tetap berbahasa Inggris sebagai identifier teknis.

### Notification dan queued locale

User mengimplementasikan preferensi locale Laravel untuk queued notification. Locale recipient disimpan atau dibawa secara eksplisit saat dispatch agar worker tidak bergantung pada locale HTTP request yang sudah berakhir.

- Email dirender menggunakan locale recipient.
- Database notification menyimpan translation key, parameter aman, schema version, dan resource reference; teks dapat dirender sesuai locale request saat dibaca.
- Realtime event membawa key/data canonical, bukan hanya kalimat hasil render.
- Pergantian bahasa user memengaruhi tampilan berikutnya tanpa mengubah audit history.
- Audit dan structured log menyimpan event code serta data canonical, bukan pesan yang sudah diterjemahkan.

### Settings dan konten dinamis

- Deskripsi Settings Registry berubah dari literal English menjadi translation key.
- `app.locale` harus termasuk supported locale.
- Translation file hanya untuk copy aplikasi yang dikelola developer.
- Konten buatan user tidak diterjemahkan otomatis.
- Konten bisnis multilingual di database ditunda sampai modul domain benar-benar membutuhkan field translation.
- Format tanggal/waktu API tetap ISO 8601; frontend melakukan presentasi berdasarkan locale dan timezone.

### Test multilingual

- Request tanpa header memakai default/fallback yang benar.
- `Accept-Language: en`, `id`, `en-US`, dan `id-ID` dipetakan dengan benar.
- Unsupported locale jatuh ke fallback tanpa error server.
- Preferensi user dipakai dan header request dapat melakukan override sementara.
- Validation, auth, authorization, not found, conflict, rate limit, dan business error tersedia dalam dua bahasa.
- Error `code` identik pada kedua bahasa.
- Notification/email queue mempertahankan locale recipient.
- Missing translation key terdeteksi oleh test dan dicatat tanpa membocorkan data sensitif.
- Setiap key `en` memiliki pasangan `id`, dan sebaliknya, untuk namespace yang diwajibkan.

## 12. Desain Notification Foundation

Gunakan tabel `notifications` bawaan Laravel dengan UUID notification. Payload database memiliki bentuk stabil:

```json
{
  "schema_version": 1,
  "category": "security",
  "title": "New login",
  "message": "A new device signed in to your account.",
  "action": {
    "type": "open_url",
    "url": "/security/sessions"
  },
  "context": {}
}
```

Payload tidak boleh berisi secret, plain text token, credential, atau data domain sensitif yang tidak diperlukan oleh client.

### 12.1 Tabel `notification_preferences`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | Primary key |
| `user_id` | foreign key users | Pemilik preferensi |
| `category` | string | Kategori stabil seperti `security`, `account`, `system` |
| `database_enabled` | boolean | In-app notification |
| `mail_enabled` | boolean | Email notification |
| `created_at`, `updated_at` | timestamp | Timestamp standar |

Unique constraint: `user_id + category`.

Kategori keamanan kritis dapat mengunci channel tertentu agar tidak dapat dimatikan. Aturan tersebut didefinisikan dalam registry kode, bukan hanya dari input client.

## 13. API Notification

| Method | Endpoint | Akses | Fungsi |
|---|---|---|---|
| `GET` | `/api/notifications` | Bearer | Inbox dengan cursor pagination |
| `GET` | `/api/notifications/unread-count` | Bearer | Jumlah notifikasi belum dibaca |
| `GET` | `/api/notifications/{notification}` | Owner | Detail notifikasi |
| `PATCH` | `/api/notifications/{notification}/read` | Owner | Tandai sudah dibaca |
| `DELETE` | `/api/notifications/{notification}/read` | Owner | Tandai belum dibaca |
| `POST` | `/api/notifications/read-all` | Bearer | Tandai semua sudah dibaca |
| `DELETE` | `/api/notifications/{notification}` | Owner | Hapus dari inbox user |
| `GET` | `/api/notification-preferences` | Bearer | Daftar preference efektif |
| `PUT` | `/api/notification-preferences` | Bearer | Update preference per kategori |

Daftar notifikasi mendukung filter `status=read|unread`, `category`, `per_page`, dan `cursor`.

## 14. Notification delivery

Sediakan service/contract yang menerima recipient, notification, category, dan priority. Service menentukan channel berdasarkan registry dan preferensi user.

| Prioritas | Queue | Contoh |
|---|---|---|
| Critical/security | `high` | Perubahan password atau login perangkat baru |
| Normal | `default` | Perubahan akun dan aktivitas aplikasi |
| Informational/bulk | `low` | Ringkasan atau pemberitahuan non-urgent |

Notification email mengimplementasikan `ShouldQueue`, memiliki retry/backoff yang masuk akal, dan tidak menyebabkan transaksi utama gagal saat SMTP sedang tidak tersedia. Database notification tetap dapat tersimpan walaupun delivery email gagal.

## 15. Desain Realtime & Polling

WebSocket dan polling membawa event yang sama. WebSocket merupakan jalur dengan latensi rendah, sedangkan polling mengambil event durable yang belum diterima client. Database event stream menjadi sumber recovery; koneksi WebSocket bukan sumber kebenaran.

### 15.1 Event envelope

```json
{
  "id": "01K...",
  "schema_version": 1,
  "type": "notification.created",
  "occurred_at": "2026-09-25T10:00:00Z",
  "resource": {
    "type": "notification",
    "id": "01K..."
  },
  "data": {}
}
```

Ketentuan envelope:

- `id` unik dipakai client untuk deduplication.
- `type` berasal dari registry kode dan memakai pola `module.event`.
- `schema_version` memungkinkan perubahan payload tanpa merusak client lama.
- Payload hanya memuat data minimum; client mengambil detail terbaru dari REST API.
- Secret, credential, token, storage path, dan model serialization mentah dilarang.

### 15.2 Tabel `user_events`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | ulid/uuid | Cursor dan event identifier |
| `user_id` | foreign key users | Recipient |
| `type` | string | Event registry key |
| `schema_version` | integer | Versi payload |
| `resource_type`, `resource_id` | nullable string | Referensi resource publik |
| `payload` | json | Payload minimum dan aman |
| `created_at` | timestamp | Waktu event |
| `expires_at` | timestamp | Retention boundary |

Index utama: `user_id + id`, `user_id + type + id`, dan `expires_at`. Satu baris dibuat per recipient agar authorization polling sederhana dan tidak membocorkan audience.

### 15.3 Kontrak penerbitan

Modul memanggil service seperti `RealtimePublisher` dengan recipient, event type, resource reference, dan payload. Service bertanggung jawab untuk:

1. Memvalidasi type dan schema melalui registry.
2. Menyimpan event durable setelah transaksi bisnis commit.
3. Menerbitkan event yang sama ke broadcast queue jika WebSocket aktif.
4. Tetap berhasil menyimpan event ketika Reverb sedang tidak tersedia.

Event yang bergantung pada perubahan database harus dikirim setelah commit. Laravel mendukung queued broadcast dan dispatch after commit; implementasi harus menggunakannya agar client tidak menerima referensi ke data yang belum committed.

## 16. WebSocket design

Laravel Broadcasting menjadi abstraction dan Laravel Reverb menjadi provider development awal. Provider dapat diganti melalui environment tanpa mengubah module event.

| Channel | Jenis | Fungsi |
|---|---|---|
| `users.{userId}` | Private | Notification dan event pribadi user |
| `{module}.{resourcePublicId}` | Private | Event resource domain |
| Presence channel | Opsional per modul | Hanya untuk fitur yang membutuhkan daftar subscriber aktif |

Aturan channel:

- Tidak ada public channel untuk data user atau domain private.
- Authorization channel menggunakan policy/permission yang sama dengan REST resource.
- Identifier pada nama channel bukan mekanisme keamanan; callback authorization wajib memastikan user hanya dapat bergabung ke channel yang diizinkan.
- Modul mendaftarkan channel dan event type melalui registry, bukan mengubah dispatcher inti.
- Allowed origins berasal dari environment dan tidak menggunakan wildcard pada staging/production.
- Reverb dijalankan sebagai proses khusus proyek dan bergabung ke `docker-network` hanya saat fitur realtime diaktifkan.
- Health aplikasi tidak gagal jika Reverb tidak dikonfigurasi karena WebSocket merupakan transport opsional.

## 17. Polling dan synchronization API

| Method | Endpoint | Akses | Fungsi |
|---|---|---|---|
| `GET` | `/api/events` | Bearer | Mengambil event setelah cursor tertentu |
| `GET` | `/api/events/cursor` | Bearer | Membuat cursor awal pada posisi event terbaru |

Parameter `/api/events`: `cursor`, `types[]`, dan `limit` dengan batas maksimum. Response mengembalikan event berurutan naik dan `next_cursor`.

Polling awal memakai **short polling**. Client yang aktif dapat meminta setiap 15–30 detik, memakai exponential backoff saat error, berhenti saat tab/app tidak aktif, dan segera melakukan polling setelah reconnect. Long polling baru dipertimbangkan bila pengukuran menunjukkan manfaat nyata.

Sinkronisasi client:

```text
Connect/login
    → obtain current cursor
    → subscribe WebSocket when available
    → receive and deduplicate event by id
    → poll from last acknowledged cursor after reconnect
    → fetch canonical resource through REST when needed
```

Cursor terlalu lama yang sudah melewati retention menghasilkan response eksplisit agar client melakukan full refresh. Scheduler membersihkan expired events secara bertahap. Retention awal disarankan 7 hari dan dapat diatur melalui setting `realtime.event_retention_days`.

## 18. Audit dan observability

| Event | Dicatat |
|---|---|
| `file.uploaded` | Actor, subject file, ukuran, MIME |
| `file.updated` | Metadata sebelum/sesudah yang aman |
| `file.attached` / `file.detached` | File dan resource target |
| `file.deleted` | Actor dan metadata file |
| `notification.preferences_updated` | Preference sebelum/sesudah |
| Delivery failure | Log/Telescope dan failed job, tanpa payload sensitif |

Download milik sendiri tidak harus diaudit agar volume audit tetap terkendali. Download lintas user dengan permission administratif dicatat.

## 19. Dokumentasi API

- Tambahkan tag OpenAPI `Files` dan `Notifications`.
- Dokumentasikan multipart request, batas file, MIME yang diperbolehkan, response metadata, dan error storage.
- Dokumentasikan seluruh filter cursor pagination.
- Tambahkan schema notification payload, preference, unread count, dan error ownership.
- Swagger tidak menampilkan binary response sebagai JSON.
- Semua endpoint baru ditambahkan ke `ApiDocumentationTest`.
- Tambahkan dokumentasi event envelope, polling cursor, channel convention, dan daftar event type yang stabil.

## 20. Testing strategy

### 20.1 File tests

- Upload valid memakai `Storage::fake()` dan metadata tersimpan.
- File terlalu besar atau MIME terlarang ditolak.
- MIME ditentukan server, bukan hanya extension/nama client.
- User hanya dapat melihat, mengubah, mengunduh, dan menghapus file miliknya.
- Permission administratif bekerja lintas owner.
- Path dan credential storage tidak bocor pada response.
- Cursor pagination dan filter stabil.
- Kegagalan database setelah upload membersihkan object kompensasi.
- Delete men-soft-delete metadata dan dispatch job idempotent.
- File yang masih attached tidak dapat dihapus.
- Minimal satu integration test development membuktikan MinIO dapat write/read/delete tanpa memasukkan credential ke test output.

### 20.2 Notification tests

- Inbox hanya menampilkan notifikasi milik user.
- Read, unread, read-all, delete, unread count, filter, dan cursor bekerja.
- Preference menentukan channel yang dipakai.
- Security notification yang wajib tidak dapat dinonaktifkan.
- Mail/notification queue memakai queue prioritas yang benar.
- Delivery failure tidak menghapus database notification.
- Payload dan audit tidak memuat secret.
- Email development dapat diterima MailDev pada integration verification.

### 20.3 Regression gates

- Seluruh test Phase 1 dan Phase 2 tetap lulus.
- `php artisan test` lulus di PHP 8.5.
- `vendor/bin/pint --test` lulus.
- OpenAPI dapat digenerate dan semua endpoint terdokumentasi.

### 20.4 Realtime dan polling tests

- Event tersimpan hanya setelah transaksi berhasil commit.
- User hanya dapat mengambil dan subscribe event miliknya atau resource yang diizinkan.
- WebSocket dan polling menghasilkan envelope serta event ID yang sama.
- Event dapat dideduplicate dan urutannya stabil.
- Filter type dan cursor tidak melewatkan atau menggandakan event.
- Reverb unavailable tidak menggagalkan transaksi bisnis atau penyimpanan durable event.
- Cursor expired menghasilkan kontrak resync yang terdokumentasi.
- Cleanup hanya menghapus event melewati retention.

## 21. Fase implementasi V2

### V2.1 — File Management

1. Buat module, migration, model, policy, permission, dan settings.
2. Implementasikan storage service dan lifecycle upload/delete.
3. Implementasikan endpoint owner dan admin.
4. Tambahkan audit, OpenAPI, dan automated tests.
5. Verifikasi integrasi MinIO.

### V2.2 — Multilingual Foundation

1. Publish translation Laravel dan buat locale registry `en` serta `id`.
2. Tambahkan kolom/preferensi locale user dan middleware `ResolveLocale`.
3. Tambahkan `GET /api/locales`, update profile locale, serta header `Content-Language`.
4. Tambahkan stable error `code` dan pindahkan literal user-facing message ke translation key.
5. Terjemahkan validation, auth, authorization, settings metadata, serta error bisnis yang sudah ada.
6. Integrasikan locale preference dengan queued notification dan email.
7. Dokumentasikan kontrak locale di OpenAPI dan tambahkan parity test translation key.

Acceptance minimum V2.2:

- Seluruh error publik utama tersedia dalam `en` dan `id`.
- Locale resolution, fallback, regional tag, user preference, dan request override bekerja konsisten.
- `code` dan machine-readable value tidak berubah antarbahasa.
- Email dan notification menggunakan locale recipient walaupun diproses worker.
- Missing atau unmatched translation key menyebabkan test gagal.

### V2.3 — Notification Foundation

1. Buat migration notification dan preferences.
2. Buat category/channel registry serta delivery service.
3. Implementasikan inbox dan preference API.
4. Buat notification contoh berbasis database dan mail queue.
5. Tambahkan audit, OpenAPI, dan automated tests.
6. Verifikasi queue worker dan MailDev.

### V2.4 — Realtime & Polling

1. Buat module, event registry, migration event stream, dan publisher contract.
2. Implementasikan polling cursor dan retention cleanup.
3. Pasang Laravel Broadcasting/Reverb sebagai adapter opsional.
4. Implementasikan private user channel dan authorization test.
5. Hubungkan notification event sebagai reference implementation.
6. Dokumentasikan kontrak agar module baru dapat mendaftarkan event sendiri.

### V2.5 — Operational Reliability

1. Tambahkan middleware `X-Request-ID`; gunakan nilai client yang valid atau buat UUID baru.
2. Propagasikan correlation ID dari HTTP request ke log context, queued job, notification, audit metadata, dan realtime event.
3. Sediakan JSON log channel yang environment driven tanpa menghapus log channel development yang mudah dibaca.
4. Standarkan context log: request ID, actor ID, route, job ID, module, event type, dan exception class.
5. Pastikan password, token, credential, payload file, cookie, dan authorization header selalu disensor.
6. Audit konfigurasi `failed_jobs`, retry, `tries`, timeout, backoff, dan `maxExceptions` untuk job penting.
7. Tambahkan scheduler untuk pruning failed job sesuai retention setting.
8. Sediakan command/read-only endpoint berpermission untuk ringkasan queue failure bila dibutuhkan operasional.
9. Dokumentasikan prosedur inspect, retry, dan forget failed job.

Acceptance minimum V2.5:

- Setiap response memiliki `X-Request-ID` dan ID yang sama muncul pada log terkait.
- Queue job mempertahankan correlation context tanpa menyimpan data sensitif.
- Job file, email, dan realtime memiliki retry/backoff serta failure behavior eksplisit.
- Failed job dapat diinspeksi, di-retry, dan dipangkas dengan prosedur terdokumentasi.

### V2.6 — API Reliability & Protection

1. Buat middleware idempotency untuk endpoint mutasi yang secara eksplisit mengaktifkannya.
2. Gunakan header `Idempotency-Key`, fingerprint actor + route + payload, status processing/completed, response tersimpan, expiry, dan lock Redis.
3. Request ulang dengan key dan fingerprint sama mengembalikan response semula; payload berbeda menghasilkan HTTP `409`.
4. Jangan menerapkan idempotency otomatis pada seluruh endpoint.
5. Definisikan named rate limiter untuk login, password recovery, upload file, polling event, notification mutation, dan endpoint administratif sensitif.
6. Gunakan actor ID jika authenticated serta kombinasi IP/identifier yang dinormalisasi untuk endpoint publik.
7. Dokumentasikan header rate limit, HTTP `429`, retry behavior, dan batas yang dapat dikonfigurasi.

Acceptance minimum V2.6:

- Retry request idempotent tidak membuat resource atau job ganda.
- Concurrent request dengan key sama hanya menjalankan satu operasi.
- Reuse key untuk payload berbeda ditolak.
- Rate limiter terisolasi per action dan memakai Redis atomik.
- Swagger menjelaskan `Idempotency-Key`, conflict, dan rate limiting.

### V2.7 — Automated Quality Gate

1. Tambahkan workflow CI yang memakai PHP 8.5, PostgreSQL 16, dan Redis 7.
2. Install dependency dari lock file dan cache dependency secara aman.
3. Jalankan migration terhadap database CI yang disposable.
4. Jalankan `php artisan test`, `vendor/bin/pint --test`, dan generator/validation OpenAPI.
5. Pastikan CI tidak bergantung pada network atau credential development lokal.
6. Tambahkan pemeriksaan secret sederhana dan larang `.env` masuk artifact/log.
7. Dokumentasikan required checks sebelum merge.

Acceptance minimum V2.7:

- Workflow berjalan dari environment bersih dan tidak memakai shared Docker development.
- Test, Pint, atau OpenAPI yang gagal membuat workflow gagal.
- Tidak ada credential nyata dalam workflow, fixture, artifact, atau log.

### V2.8 — Integration Foundation

V2.8 dibagi menjadi tiga submodule yang dapat dikerjakan terpisah.

#### V2.8A — Webhooks

- Registry event webhook yang menggunakan event type/version yang sama dengan realtime.
- Endpoint management subscription dengan URL HTTPS, event selection, active status, dan secret rotation.
- Signature HMAC, timestamp, delivery ID, replay protection, retry/backoff, dan delivery log.
- Queued delivery bersifat at least once; consumer melakukan deduplication melalui delivery ID.
- Proteksi SSRF: validasi URL, blok private/link-local target, dan resolusi DNS aman.
- Admin permission, audit, retention delivery log, test signature, dan test retry.

#### V2.8B — Import/Export

- Contract job untuk proses data berukuran besar melalui queue.
- File input/output memakai File Management, bukan path lokal langsung.
- Status `pending`, `processing`, `completed`, `failed`, dan `cancelled` dengan progress serta error summary.
- Download export mengikuti authorization file.
- Import memiliki validation report dan batas jumlah error yang disimpan.
- Progress menerbitkan durable realtime event sehingga dapat diterima lewat WebSocket atau polling.
- Format/domain importer dan exporter dibuat oleh modul bisnis; core hanya menyediakan orchestration.
- Implementasi memakai CSV dan XLSX streaming, handler registry, queue `low`, UUID publik, cursor listing, ownership, RBAC, dan idempotency wajib.
- Status, progress, hasil, maksimal 100 detail error per baris, cancellation, audit, dan event `data_transfer.updated` tersedia melalui API.
- File input/output memakai File Management dan tetap private. Cleanup terjadwal menghapus transfer terminal serta output export setelah retention, tanpa menghapus file input user.
- Handler referensi `identity.users` membuktikan export dan import/update CSV/XLSX yang idempotent; super administrator dilindungi dari perubahan melalui import.

#### V2.8C — Payment Foundation

- Provider contract agar modul bisnis tidak bergantung langsung pada SDK atau payload provider.
- Penyimpanan payment intent generik dengan referensi tipe dan ID milik modul bisnis.
- Midtrans Snap adapter memakai server-side Basic Auth, sandbox sebagai default, dan credential dari environment.
- Callback Midtrans memverifikasi SHA-512 signature, nominal asli, dan memproses redelivery secara idempotent.
- Pemetaan status provider ke status internal, termasuk paid, failure, expiry, refund, dan chargeback.
- Event `PaymentStatusChanged`, durable realtime event `payment.updated`, audit, ownership, permission administrator, OpenAPI, dan test.
- Modul bisnis tetap bertanggung jawab atas harga, invoice/order lifecycle, hak akses create payment, serta aksi setelah pembayaran berhasil.
- Endpoint administratif `POST /api/payments` memakai permission `payments.create`, rate limit, dan `Idempotency-Key` wajib.
- Endpoint owner/admin `GET /api/payments/{payment}` menyediakan status dan checkout URL tanpa mengekspos token/provider order internal.
- Notification test Sandbox hanya diterima ketika signature valid dan tidak membuat transaksi fiktif.
- Halaman redirect publik finish, unfinish, dan error tersedia dalam `en`/`id`; redirect tidak dipercaya sebagai sumber status final.
- Origin redirect berasal dari `PAYMENT_REDIRECT_BASE_URL` dengan fallback `APP_URL`.

Acceptance minimum V2.8:

- Webhook dapat diverifikasi, di-retry, diaudit, dan tidak dapat mengakses network target terlarang.
- Import/export tidak menahan HTTP request panjang dan aman terhadap retry.
- Progress dapat dipantau tanpa bergantung pada WebSocket.
- Payment checkout dapat dibuat idempotent, callback asli memperbarui status, callback test Sandbox aman, dan redirect pengguna tersedia melalui HTTPS publik.

### V2.9 — Runtime Settings Governance

1. Terapkan urutan resolusi nilai: database override, fallback `.env` melalui config, lalu default kode.
2. Setiap definition wajib memiliki `group`, `type`, validation rules, visibility, editability, description, dan fallback config yang eksplisit.
3. Metadata API harus membawa `group`, effective value, fallback value, dan source agar UI dapat membentuk tab dan status override tanpa hardcode.
4. Simpan snapshot `type` dan `group` pada row override settings; registry kode tetap menjadi schema kanonik.
5. Sediakan reset API untuk menghapus database override dan kembali ke environment/default.
6. Pindahkan hanya runtime policy yang aman: application display, auth policy, file limits, retention, idempotency TTL, rate limits, serta enable/timeout provider pembayaran.
7. Credential, encryption key, host/port infrastructure, database, Redis, queue, filesystem, mail transport, Midtrans key/mode/endpoint, Reverb secret, logging, debug, Telescope, dan deployment flags tetap environment-only.

Acceptance minimum V2.9:

- Database mengalahkan environment, environment mengalahkan default kode.
- Nilai boolean, integer, array, dan string mempertahankan tipe setelah melalui Redis cache.
- Metadata dapat dikelompokkan menjadi tab tanpa pemetaan kategori di frontend.
- Reset override tercatat di audit dan langsung mengaktifkan fallback.
- Tidak ada secret yang dapat dibaca atau ditulis melalui Settings API.

## 22. Acceptance criteria

### File Management

- [x] File private dapat di-upload, didaftar, dilihat, diunduh, diubah metadata, dan dihapus oleh owner.
- [x] User lain menerima response aman tanpa mengetahui keberadaan/path file.
- [x] Administrator membutuhkan permission eksplisit untuk operasi lintas owner.
- [x] Binary tersimpan melalui Laravel Filesystem dan metadata tersimpan di PostgreSQL.
- [x] Response tidak mengekspos storage path, disk credential, atau internal ID.
- [x] Size, MIME, filename, dan metadata tervalidasi.
- [x] Delete dan cleanup aman terhadap retry.
- [x] Attachment dapat digunakan modul bisnis melalui service/policy yang jelas.
- [x] MinIO integration verification berhasil.

### Multilingual

- [x] API mendukung `en` dan `id` dengan `en` sebagai fallback terakhir.
- [x] Locale dapat berasal dari header, preferensi user, atau default aplikasi dengan precedence terdokumentasi.
- [x] Response mengirim `Content-Language` dan error memiliki `code` stabil.
- [x] Validation, authentication, authorization, settings metadata, serta business error utama diterjemahkan.
- [x] Notification dan email queue mempertahankan locale recipient.
- [x] Translation key module memiliki parity `en` dan `id`.
- [x] Machine-readable value dan audit event tidak diterjemahkan.

### Notifications

- [x] User memiliki inbox terisolasi dengan cursor pagination.
- [x] Read/unread, unread count, read-all, delete, dan filter bekerja.
- [x] Preferensi channel tervalidasi dan security notification wajib tetap aktif.
- [x] Database dan email delivery berjalan melalui abstraction Laravel.
- [x] Email queue memakai prioritas dan retry yang sesuai.
- [x] MailDev menerima notification integration test.
- [x] Failure email tidak merusak transaksi bisnis atau database notification.

### Quality

- [x] Seluruh endpoint memakai response API standar.
- [x] Permission dan audit registry diperbarui.
- [x] Swagger menjelaskan request, response, filter, security, dan error.
- [x] Seluruh test lama dan baru lulus.
- [x] Laravel Pint lulus.
- [x] README diperbarui setelah implementasi selesai.

### Realtime dan polling

- [x] Modul dapat menerbitkan event tanpa bergantung langsung pada Reverb.
- [x] Event tersimpan durable dan tersedia melalui cursor polling.
- [x] Event yang sama dapat diterima melalui private WebSocket channel.
- [x] Disconnect, reconnect, deduplication, dan catch-up polling tervalidasi.
- [x] Channel authorization memakai ownership/policy/permission resource.
- [x] Reverb yang tidak tersedia tidak menggagalkan transaksi utama.
- [x] Event retention dan expired cursor memiliki perilaku terdokumentasi.

### Payment Foundation

- [x] Provider contract memisahkan modul bisnis dari Midtrans.
- [x] Checkout generik tersimpan dengan UUID publik dan referensi domain tanpa foreign key lintas modul.
- [x] `POST /api/payments` dilindungi RBAC, rate limit, dan idempotency wajib.
- [x] Callback memverifikasi signature, currency, dan nominal asli serta aman terhadap redelivery.
- [x] Status provider dipetakan ke status internal dan tidak dapat menurunkan pembayaran yang sudah paid melalui event stale.
- [x] Test notification Sandbox diakui tanpa membuat pembayaran fiktif.
- [x] Redirect finish, unfinish, dan error tersedia dalam `en` dan `id` tanpa dipercaya sebagai status final.
- [x] Payment status menerbitkan audit dan durable realtime event.
- [x] Credential tetap environment-only dan kontrak API terdokumentasi di OpenAPI.

### Import dan Export Foundation

- [x] Handler registry memungkinkan modul menambah tipe tanpa mengubah orchestration Core.
- [x] Import dan export CSV berjalan asynchronous pada queue `low`.
- [x] File input/output private menggunakan File Management.
- [x] API menyediakan type metadata, cursor listing, create, progress/detail, dan cancellation.
- [x] `Idempotency-Key`, RBAC, ownership, audit, dan stable API response diterapkan.
- [x] Error import dicatat per baris secara terbatas dan jumlah sukses/gagal tersedia.
- [x] Progress akhir diterbitkan sebagai durable realtime event `data_transfer.updated`.
- [x] Cleanup retention menghapus transfer terminal dan output export tanpa menghapus input user.
- [x] OpenAPI, test integration, dan handler referensi `identity.users` tersedia.

## 23. Keputusan yang dikunci untuk implementasi

| Topik | Keputusan |
|---|---|
| Storage API | Laravel Filesystem, bukan MinIO SDK |
| Default visibility | Private |
| Public identifier | UUID/ULID |
| Upload awal | Multipart melalui backend |
| Download | Authorized stream atau temporary URL berdurasi pendek |
| File removal | Soft delete metadata + queued physical cleanup |
| Attachment | Polymorphic service untuk modul bisnis |
| Supported locale awal | `en` dan `id` |
| Locale fallback | `en` |
| Locale precedence | `Accept-Language` → user preference → `app.locale` → fallback |
| Translation organization | Core language files + namespace per module |
| API localization | Localized `message` + stable machine-readable `code` |
| Notification localization | Recipient locale dipertahankan saat queued |
| Notification inbox | Laravel database notifications |
| Email | Laravel Notification + queued mail |
| Preferences | Per user dan kategori |
| Realtime abstraction | Laravel Broadcasting melalui publisher contract |
| WebSocket provider | Laravel Reverb, opsional dan environment driven |
| Polling | Durable event stream dengan opaque cursor |
| Delivery semantics | At least once; client deduplicate memakai event ID |
| Realtime consistency | Dispatch setelah database commit |
| Correlation | `X-Request-ID` diteruskan ke log, job, audit, notification, dan event |
| Structured log | JSON channel opsional melalui environment |
| Idempotency | Opt-in per endpoint dengan Redis lock dan response replay |
| Rate limiting | Named limiter per action menggunakan Redis |
| CI | PHP 8.5 + PostgreSQL 16 + Redis 7 yang disposable |
| Webhook delivery | Signed, queued, at least once, dan SSRF protected |
| Import/export | Async orchestration; file melalui File Management |
| Payment abstraction | Provider contract; modul bisnis tidak memanggil Midtrans langsung |
| Payment provider awal | Midtrans Snap, sandbox sebagai default |
| Payment callback | Signature dan nominal diverifikasi; redelivery idempotent |
| Payment creation API | RBAC `payments.create`, named rate limit, dan `Idempotency-Key` wajib |
| Payment redirect | Finish/unfinish/error publik; informatif saja, status final dari webhook/status provider |
| Runtime settings precedence | Database override → environment fallback → code default |
| Settings schema | Registry kode kanonik; row database menyimpan snapshot type dan group |
| Environment-only config | Secret, credential, topology infrastructure, dan deployment flags |
| Pagination | Cursor |
| Secrets | Environment only |

## 24. Urutan rekomendasi

Urutan implementasi dikunci mengikuti nomor fase:

1. **V2.1 File Management**
2. **V2.2 Multilingual Foundation**
3. **V2.3 Notification Foundation**
4. **V2.4 Realtime & Polling**
5. **V2.5 Operational Reliability**
6. **V2.6 API Reliability & Protection**
7. **V2.7 Automated Quality Gate**
8. **V2.8 Integration Foundation**
9. **V2.9 Runtime Settings Governance**

File lifecycle dikerjakan lebih awal karena memiliki risiko integritas antara PostgreSQL dan object storage. Multilingual diselesaikan sebelum notification agar inbox dan email tidak perlu dirombak kemudian. Notification menyediakan use case realtime pertama. Realtime menjadi dasar progress import/export dan webhook event registry. Reliability dan CI mengunci kualitas fondasi sebelum integration module diperluas.

Setiap fase harus memperbarui README, OpenAPI, permission/settings registry, audit event, serta test yang relevan. Implementasi fase berikutnya tidak dimulai sebelum acceptance minimum fase aktif lulus.

## 25. Baseline rilis boilerplate

Baseline siap dipakai untuk proyek baru setelah langkah berikut dipenuhi pada environment tujuan:

1. Ganti identitas aplikasi, hostname, database, prefix Redis, indeks Redis, bucket storage, nama container, dan credential Reverb.
2. Pertahankan database serta Redis testing yang terisolasi dari development.
3. Isi credential infrastruktur dan provider hanya melalui environment yang tidak dilacak Git.
4. Jalankan migration, `RbacSeeder`, dan `identity:bootstrap-admin`.
5. Verifikasi health, MailDev, MinIO, queue, scheduler, Swagger, Telescope, polling, dan bila diperlukan Reverb.
6. Untuk Midtrans lokal, gunakan origin HTTPS publik sementara atau domain development yang dapat dijangkau provider. Jangan simpan hostname tunnel sementara di file Git.
7. Jalankan seluruh Pest test, Pint, generator/validator OpenAPI, dan pemeriksaan secret sebelum membuat modul bisnis.

Backlog Core yang tidak menghalangi proyek baru:

- V2.8A webhook keluar generik dengan signature, delivery log, retry, dan proteksi SSRF.
- Provider pembayaran tambahan, refund API, settlement/reconciliation terjadwal, dan fitur bisnis payment ditambahkan ketika domain pertama membutuhkannya.
