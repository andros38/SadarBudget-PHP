<div align="center">
  <img src="assets/sadarbudget-logo.svg" alt="Logo SadarBudget" width="96">

  # SadarBudget

  **Aplikasi pencatatan keuangan pribadi berbasis web yang ringan, responsif, dan dapat dijalankan tanpa framework.**

  PHP · MySQL/MariaDB · HTML · CSS · JavaScript
</div>

---

## Tentang aplikasi

SadarBudget membantu pengguna mencatat pemasukan, pengeluaran, uang tersedia, dan tujuan tabungan dalam satu aplikasi. Data setiap akun dipisahkan berdasarkan pengguna, dapat direkap per bulan maupun per tahun, serta dapat diekspor dan dipulihkan langsung dari browser.

Aplikasi ini tidak menggunakan framework PHP, Composer, npm, atau proses build. Setelah database dan konfigurasi selesai, aplikasi dapat langsung dijalankan melalui Apache.

## Fitur utama

- Registrasi, login, logout, dan pengelolaan profil pengguna.
- Foto profil/avatar dengan dukungan JPG, PNG, dan WebP.
- Pencatatan pemasukan dan pengeluaran berdasarkan kategori.
- Dashboard uang tersedia, pemasukan, pengeluaran, dan tabungan.
- Tujuan tabungan dengan target, tenggat, progres, setoran, pencairan, dan penggunaan dana.
- Arsip serta riwayat aktivitas tujuan tabungan.
- Riwayat transaksi dengan filter periode dan jenis transaksi.
- Rekapan tahunan, rincian bulanan, grafik arus keuangan, dan kategori pengeluaran terbesar.
- Ekspor laporan ke PDF dan Excel.
- Ekspor serta impor backup SQL langsung dari aplikasi.
- Pembersihan seluruh data keuangan tanpa menghapus akun pengguna.
- Tema terang dan gelap.
- Tampilan responsif untuk desktop, laptop, tablet, dan ponsel.

## Teknologi

| Komponen | Teknologi |
|---|---|
| Backend | PHP 8.1+ |
| Database | MySQL 5.7+ atau MariaDB 10.4+ |
| Akses database | PDO MySQL |
| Frontend | HTML5, CSS3, JavaScript tanpa framework |
| Web server | Apache 2.4+ |
| Ekspor | PDF, XLSX, dan SQL yang dibuat langsung oleh aplikasi |

## Persyaratan sistem

Pastikan lingkungan server menyediakan:

- PHP 8.1 atau lebih baru.
- Ekstensi PHP `pdo_mysql`, `mbstring`, dan `fileinfo`.
- MySQL 5.7+ atau MariaDB 10.4+.
- Apache dengan `mod_rewrite` aktif.
- Izin tulis pada folder `uploads/profiles`.

Konfigurasi ini kompatibel dengan XAMPP, Laragon, LAMP, dan sebagian besar shared hosting yang mendukung PHP serta MySQL/MariaDB.

## Instalasi menggunakan XAMPP

### 1. Letakkan aplikasi di `htdocs`

Ekstrak atau clone repository ke:

```text
C:\xampp\htdocs\app-finance
```

Contoh menggunakan Git:

```bash
git clone <URL-REPOSITORY-ANDA> C:\xampp\htdocs\app-finance
```

### 2. Jalankan Apache dan MySQL

Buka XAMPP Control Panel, lalu aktifkan:

```text
Apache
MySQL
```

### 3. Impor database

1. Buka `http://localhost/phpmyadmin/`.
2. Pilih menu **Import**.
3. Pilih file `schema.sql` dari folder aplikasi.
4. Jalankan proses impor.

File `schema.sql` akan membuat database berikut:

```text
keuangan_pribadi
```

Database menggunakan charset dan collation:

```text
utf8mb4
utf8mb4_unicode_ci
```

Konfigurasi tersebut mencegah konflik collation ketika membandingkan email atau memulihkan backup.

### 4. Periksa konfigurasi database

Buka `config.php`:

```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'keuangan_pribadi');
define('DB_USER', 'root');
define('DB_PASS', '');
```

Konfigurasi tersebut merupakan konfigurasi bawaan XAMPP. Sesuaikan nama database, pengguna, atau kata sandi untuk lingkungan lain.

### 5. Aktifkan `mod_rewrite`

Buka:

```text
C:\xampp\apache\conf\httpd.conf
```

Pastikan baris berikut tidak diawali tanda `#`:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

Pastikan direktori `htdocs` mengizinkan `.htaccess`:

```apache
<Directory "C:/xampp/htdocs">
    AllowOverride All
    Require all granted
</Directory>
```

Restart Apache setelah konfigurasi diubah.

`mod_rewrite` digunakan agar URL ekspor berakhir dengan nama file `.pdf` atau `.xlsx`, sehingga lebih kompatibel dengan browser dan Internet Download Manager.

### 6. Buka aplikasi

Kunjungi:

```text
http://localhost/app-finance/
```

Buat akun baru, kemudian login.

## Instalasi di hosting

1. Unggah seluruh isi repository ke document root atau subfolder hosting.
2. Buat database dan pengguna database melalui panel hosting.
3. Impor `schema.sql`.
4. Sesuaikan `config.php` dengan kredensial database hosting.
5. Pastikan folder `uploads/profiles` dapat ditulisi proses PHP.
6. Pastikan Apache mengizinkan `.htaccess` dan `mod_rewrite`.

Apabila hosting tidak mengizinkan perintah `CREATE DATABASE`, hapus bagian berikut dari `schema.sql` dan impor ke database yang sudah dibuat melalui panel hosting:

```sql
CREATE DATABASE ...;
ALTER DATABASE ...;
USE `keuangan_pribadi`;
```

Kemudian samakan `DB_NAME` di `config.php` dengan nama database hosting.

## Struktur proyek

```text
.
├── assets/                 # CSS, JavaScript, logo, favicon, dan ikon aplikasi
├── exports/                # Endpoint ekspor PDF, Excel, dan backup SQL
├── imports/                # Endpoint pemulihan backup SQL
├── includes/               # Koneksi database, fungsi, layout, dan builder laporan
├── uploads/profiles/       # Foto profil pengguna
├── add_income.php          # Form pemasukan
├── add_expense.php         # Form pengeluaran
├── annual.php              # Rekapan tahunan
├── categories.php          # Pengelolaan kategori
├── dashboard.php           # Ringkasan keuangan
├── login.php               # Login
├── profile.php             # Profil, backup, import, dan clean data
├── register.php            # Registrasi
├── savings.php             # Tujuan dan aktivitas tabungan
├── transactions.php        # Riwayat transaksi
├── config.php              # Konfigurasi aplikasi dan database
├── schema.sql              # SQL instalasi awal
└── .htaccess               # URL ekspor yang ramah browser/IDM
```

## Cara kerja perhitungan

### Uang tersedia

```text
Pemasukan
− pengeluaran dari uang tersedia
− setoran ke tabungan
+ pencairan dari tabungan
```

Dana yang sudah dipindahkan ke tabungan tidak lagi dihitung sebagai uang tersedia.

### Total dana tercatat

```text
Uang tersedia + saldo seluruh tujuan tabungan
```

Transfer antara uang tersedia dan tabungan hanya memindahkan lokasi dana dan tidak mengubah total dana tercatat.

### Total pengeluaran

```text
Pengeluaran dari uang tersedia
+ penggunaan langsung dari tabungan
```

## Backup dan pemulihan data

Buka:

```text
Profil → Data dan privasi
```

### Ekspor backup

Backup SQL mencakup:

- Kategori pengguna.
- Pemasukan dan pengeluaran.
- Tujuan tabungan.
- Setoran, pencairan, dan penggunaan tabungan.
- Snapshot nama kategori dan tujuan untuk mempertahankan riwayat.

Backup tidak menyertakan hash kata sandi atau file foto profil.

### Impor backup

Impor dapat dilakukan langsung melalui browser tanpa membuka phpMyAdmin. Proses impor:

- Memerlukan kata sandi akun saat ini.
- Memerlukan konfirmasi `IMPOR DATA`.
- Menerima file maksimal 10 MB.
- Hanya menerima format backup SadarBudget.
- Memastikan email backup sama dengan email akun yang sedang login.
- Menggunakan transaksi database agar perubahan dibatalkan apabila terjadi kegagalan.
- Tidak mengeksekusi SQL unggahan secara mentah.

Impor akan mengganti data keuangan akun saat ini. Buat backup terlebih dahulu sebelum melakukan pemulihan.

## Clean data

Fitur **Bersihkan data keuangan** menghapus transaksi, kategori, tujuan tabungan, dan aktivitas tabungan, tetapi mempertahankan:

- Akun pengguna.
- Nama dan email.
- Kata sandi.
- Foto profil.

Tindakan ini memerlukan kata sandi, checkbox persetujuan, dan konfirmasi `BERSIHKAN DATA`.

## Keamanan

Aplikasi menerapkan:

- Hash kata sandi menggunakan API `password_hash()` PHP.
- Verifikasi kata sandi menggunakan `password_verify()`.
- Prepared statement PDO untuk query database.
- Token CSRF pada operasi yang mengubah data.
- Regenerasi session ID setelah login.
- Validasi tipe serta ukuran foto profil.
- Pemblokiran eksekusi skrip pada folder upload.
- Validasi dan parsing khusus untuk file backup SadarBudget.
- Pemisahan data berdasarkan `user_id`.

Untuk penggunaan publik, jalankan aplikasi melalui HTTPS dan gunakan kredensial database yang tidak memiliki hak administratif berlebihan.

## Penamaan file ekspor

```text
sadarbudget-laporan-YYYY-MM-DD-HHMMSS.pdf
sadarbudget-laporan-YYYY-MM-DD-HHMMSS.xlsx
sadarbudget-backup-YYYY-MM-DD-HHMMSS.sql
```

## Pemecahan masalah

### `could not find driver`

Aktifkan ekstensi PDO MySQL pada `php.ini`:

```ini
extension=pdo_mysql
```

Restart Apache setelah mengubah konfigurasi.

### Ekspor PDF atau Excel menghasilkan 404

Periksa bahwa:

- `mod_rewrite` aktif.
- `AllowOverride All` sudah diterapkan pada direktori `htdocs`.
- File `.htaccess` tersedia di root aplikasi.

### Foto profil gagal disimpan

Pastikan folder berikut tersedia dan dapat ditulisi:

```text
uploads/profiles
```

Pada Linux:

```bash
chmod -R 775 uploads/profiles
```

Sesuaikan kepemilikan folder dengan pengguna web server bila diperlukan.

### Konflik collation MySQL

Gunakan `schema.sql` yang disertakan dan pastikan koneksi serta tabel menggunakan:

```text
utf8mb4_unicode_ci
```

Jangan mencampur `utf8mb4_general_ci` dengan `utf8mb4_unicode_ci` pada kolom email.

## Kontribusi

Issue dan pull request dapat digunakan untuk melaporkan bug atau mengusulkan peningkatan. Hindari menyertakan data keuangan pribadi, file backup, kredensial database, atau foto profil dalam issue maupun commit.

## Pembuat

**Ahmad Asyhari**

## Hak cipta

© Ahmad Asyhari. Repository ini belum menyertakan lisensi open-source terpisah. Penggunaan, modifikasi, dan distribusi mengikuti izin pemilik proyek sampai sebuah file `LICENSE` ditambahkan.
