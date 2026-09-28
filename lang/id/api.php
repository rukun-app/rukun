<?php

return [
    'errors' => [
        'unauthenticated' => 'Anda belum terautentikasi.',
        'forbidden' => 'Anda tidak memiliki izin untuk melakukan tindakan ini.',
        'not_found' => 'Resource tidak ditemukan.',
        'validation' => 'Validasi gagal.',
        'method_not_allowed' => 'Metode tidak diizinkan.',
        'rate_limited' => 'Terlalu banyak permintaan.',
        'server' => 'Terjadi kesalahan pada server.',
        'request_failed' => 'Permintaan gagal.',
        'cursor_expired' => 'Cursor event telah kedaluwarsa. Minta cursor baru dan muat ulang resource terkait.',
    ],
    'auth' => [
        'registration_disabled' => 'Registrasi sedang dinonaktifkan.',
        'registered' => 'Registrasi berhasil. Verifikasi email Anda sebelum masuk.',
        'too_many_attempts' => 'Terlalu banyak percobaan masuk.',
        'invalid_credentials' => 'Kredensial tidak valid.',
        'suspended' => 'Akun sedang ditangguhkan.',
        'unverified' => 'Alamat email belum diverifikasi.',
        'password_changed' => 'Kata sandi berhasil diubah. Token lainnya telah dicabut.',
        'logged_out' => 'Berhasil keluar.',
        'tokens_revoked' => 'Semua token telah dicabut.',
        'verification_sent' => 'Jika akun tersedia, email verifikasi telah dikirim.',
        'invalid_verification' => 'Tautan verifikasi tidak valid.',
        'verified' => 'Email berhasil diverifikasi.',
        'reset_sent' => 'Jika akun tersedia, tautan reset telah dikirim.',
    ],
    'files' => [
        'content_missing' => 'Konten file tidak ditemukan.',
        'attached' => 'Attachment file harus dilepas sebelum file dihapus.',
        'deletion_scheduled' => 'Penghapusan file telah dijadwalkan.',
    ],
    'access' => [
        'self_suspend' => 'Anda tidak dapat menangguhkan akun sendiri.',
        'last_manager_suspend' => 'Pengelola akses terakhir tidak dapat ditangguhkan.',
        'self_roles' => 'Anda tidak dapat menghapus seluruh role sendiri.',
        'last_manager_roles' => 'Pengelola akses terakhir harus mempertahankan izin pengelolaan akses.',
        'role_conflict' => 'Role telah diubah oleh permintaan lain. Muat ulang lalu coba kembali.',
        'role_in_use' => 'Role masih digunakan oleh pengguna.',
        'role_deleted' => 'Role berhasil dihapus.',
    ],
    'tokens' => [
        'not_found' => 'Token tidak ditemukan.',
        'revoked' => 'Token berhasil dicabut.',
    ],
    'settings' => [
        'unknown' => 'Setting key tidak dikenal: :keys',
    ],
];
