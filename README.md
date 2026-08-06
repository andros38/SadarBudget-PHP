# SadarBudget 2.0

SadarBudget adalah aplikasi web pencatatan keuangan pribadi berbasis PHP dan MySQL. Rilis 2.0 berfokus pada pemasukan, pengeluaran, kategori, laporan, serta perlindungan data melalui backup.

## Fitur

- Pencatatan pemasukan dan pengeluaran.
- Ubah dan hapus transaksi.
- Kategori pemasukan dan pengeluaran yang dapat dikelola pengguna.
- Ringkasan saldo dan rasio pengeluaran bulanan.
- Riwayat transaksi dengan filter bulan dan jenis.
- Laporan tahunan.
- Ekspor PDF dan Excel dengan saldo berjalan.
- Auto backup lokal dan backup SQL manual.
- Impor serta pemulihan backup dari browser.
- Pembersihan data dengan verifikasi kata sandi.
- Profil pengguna dan crop foto rasio 1:1.
- Tema terang/gelap dan antarmuka responsif.

## Persyaratan

- PHP 8.1 atau lebih baru.
- MySQL 5.7+ atau MariaDB 10.4+.
- Ekstensi PHP: `pdo_mysql`, `mbstring`, `fileinfo`, dan `gd`.
- Apache/XAMPP, Laragon, atau server PHP yang setara.

## Instalasi otomatis

1. Unduh atau clone repository ini ke direktori web, misalnya `htdocs/sadarbudget`.
2. Buka `http://localhost/sadarbudget/install.php`.
3. Masukkan host, port, nama database, pengguna database, dan kata sandi database.
4. Gunakan database baru atau kosong.
5. Setelah instalasi berhasil, buat akun pertama.

Installer akan:

- membuat database bila akun MySQL memiliki izin;
- mengimpor `schema.sql`;
- membuat `config.php`;
- menyiapkan folder auto backup dan foto profil;
- memasang perlindungan akses direktori.

Pada sebagian hosting, database harus dibuat lebih dahulu melalui panel hosting. Setelah itu, masukkan nama database tersebut ke installer.

## Instalasi manual

1. Buat database kosong dengan collation `utf8mb4_unicode_ci`.
2. Pilih database tersebut, lalu impor `schema.sql`.
3. Salin `config.example.php` menjadi `config.php`.
4. Sesuaikan konfigurasi database:

```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'sadarbudget');
define('DB_USER', 'root');
define('DB_PASS', '');
```

5. Pastikan direktori berikut dapat ditulisi PHP:

```text
storage/auto-backups
uploads/profiles
```

6. Buka aplikasi dan buat akun.

## Struktur repository

```text
install.php                 Installer berbasis browser
config.example.php          Contoh konfigurasi lokal
schema.sql                  Struktur database bersih
add_income.php              Pencatatan pemasukan
add_expense.php             Pencatatan pengeluaran
transactions.php            Riwayat transaksi
edit_transaction.php        Ubah transaksi
delete_transaction.php      Hapus transaksi
categories.php              Kelola kategori
dashboard.php               Ringkasan keuangan
annual.php                  Laporan tahunan
data.php                    Backup, pemulihan, dan pembersihan data
profile.php                 Profil dan keamanan akun
exports/                    Ekspor PDF, Excel, dan backup
imports/                    Impor backup
includes/                   Fungsi aplikasi dan builder laporan
assets/                     CSS, JavaScript, ikon, dan logo
storage/auto-backups/       Snapshot backup lokal
uploads/profiles/           Foto profil
```

## Auto backup

Snapshot dibuat:

- setelah transaksi ditambahkan, diubah, atau dihapus;
- setelah kategori berubah;
- sebelum dan sesudah impor;
- sebelum dan sesudah pembersihan data;
- sebelum dan sesudah pemulihan snapshot;
- secara berkala ketika aplikasi digunakan.

Konfigurasi berada pada `config.php`:

```php
define('AUTO_BACKUP_ENABLED', true);
define('AUTO_BACKUP_INTERVAL_MINUTES', 30);
define('AUTO_BACKUP_MAX_FILES', 40);
```

Untuk menyimpan snapshot pada drive lain:

```php
define('AUTO_BACKUP_DIRECTORY', 'D:/SadarBudget-Backups');
```

Auto backup hanya dapat dibuat ketika PHP dan MySQL aktif. Simpan salinan backup di perangkat atau drive lain untuk perlindungan terhadap kerusakan disk.

## Backup dan pemulihan

Backup berisi kategori, transaksi pemasukan/pengeluaran, dan metadata akun yang diperlukan untuk validasi. Importer tidak mengeksekusi SQL unggahan secara langsung; data dibaca dan ditulis melalui prepared statement.

Pemulihan mengganti seluruh kategori dan transaksi akun yang sedang login. Aplikasi membuat snapshot pengaman sebelum proses pemulihan.

## Ekspor laporan

PDF dan Excel memuat transaksi sesuai filter aktif dengan kolom:

- tanggal;
- kategori;
- keterangan;
- jenis;
- nominal;
- saldo setelah transaksi.

Saldo berjalan dihitung secara kronologis dari seluruh pemasukan dan pengeluaran pengguna.

## Keamanan

- Password disimpan menggunakan `password_hash`.
- Query menggunakan PDO prepared statements.
- Form perubahan data memakai CSRF token.
- Setiap query data dibatasi berdasarkan `user_id`.
- Ekspor, impor, pemulihan, dan pembersihan data memerlukan verifikasi kata sandi.
- `config.php` diabaikan Git dan diblokir dari akses HTTP oleh Apache.
- Folder auto backup ditolak dari akses web langsung.
- Folder foto profil menolak eksekusi skrip.

## Upload ke GitHub

Repository ini sudah menyertakan:

- `.gitignore` untuk mengecualikan `config.php`, snapshot, dan foto pengguna;
- `.gitattributes` untuk konsistensi line ending;
- GitHub Actions untuk lint PHP 8.1–8.4;
- `CHANGELOG.md`.

Contoh perintah:

```bash
git init
git add .
git commit -m "Release SadarBudget 2.0.0"
git branch -M main
git remote add origin <URL_REPOSITORY_GITHUB>
git push -u origin main
```

Jangan menambahkan `config.php` ke repository karena file tersebut berisi kredensial database.

## Pengembangan

Aplikasi tidak menggunakan framework atau proses build. Perubahan CSS dan JavaScript dapat langsung diterapkan pada folder `assets`.
