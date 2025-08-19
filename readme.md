# Pasti CronWorker

Sistem **Managed Cron** untuk menjalankan script PHP di Ubuntu + Apache/PHP + MySQLi.
Worker dijalankan setiap **20 detik** (via crontab + `sleep` offset), menyimpan jadwal & log eksekusi di MySQL, mendukung **interval** (detik/menit/jam/hari) maupun **jadwal harian HH\:MM (WIB)**, lengkap dengan penguncian per-task, timeout, auto retry dan UI CRUD berbasis web.

Saya membuat ini untuk sedikit membantu para pecinta HomeLab dengan spek server **Kere Hore** agar bisa menjalankan CronJob banyak namun gak bikin server ngebul.

[![CI](https://github.com/mohroyhannahado/Pasti-CronWorker/actions/workflows/php-ci.yml/badge.svg)](https://github.com/mohroyhannahado/Pasti-CronWorker/actions/workflows/php-ci.yml)
![PHP](https://img.shields.io/badge/PHP-8.1%20%7C%208.2%20%7C%208.3-777bb3)
![License](https://img.shields.io/badge/license-MIT-green)
![Issues](https://img.shields.io/github/issues/mohroyhannahado/Pasti-CronWorker)
![Stars](https://img.shields.io/github/stars/mohroyhannahado/Pasti-CronWorker)


> Timezone default: **Asia/Jakarta**.


---

## ✨ Fitur Utama

* **Terpusat di Database** → semua jadwal dikelola lewat tabel MySQL.
* **Interval & Harian** → jadwal berbasis interval detik atau jam harian tertentu.
* **Worker per 20 detik** → dijalankan dengan kombinasi cron `0/20/40s`.
* **Locking** → global + per-task (`flock`) agar tidak jalan dobel.
* **Args aman** → bisa string biasa atau JSON array (setiap item di-escape).
* **Timeout** → hentikan proses yang menggantung.
* **Retry dengan Backoff** → 30s × 2^n (maks 15 menit).
* **Auto-heal** → task yang nyangkut status `running` > 30 menit otomatis di-reset.
* **Log Output** → simpan `stdout` / `stderr` ke DB (dibatasi panjang).

---

## 🧱 Struktur

```
/var/www/kron/
 ├─ db.php       # koneksi & helper MySQLi
 ├─ worker.php   # script worker
 └─ index.php    # UI CRUD task
```

Tabel utama: `cron_tasks`.

---

## 🔧 Kebutuhan

* Ubuntu/Debian dengan PHP CLI (`/usr/bin/php`)
* Apache atau Nginx untuk akses `index.php`
* Ekstensi PHP: `mysqli` (opsional `mbstring`)
* MySQL/MariaDB
* Cron + utilitas `flock`

---

## 🚀 Instalasi

1. **Clone/copy source** ke `/var/www/kron` atau direktori web lain:

   ```
   /var/www/kron/db.php
   /var/www/kron/worker.php
   /var/www/kron/index.php
   ```

2. **Import SQL tabel** ke database MySQL:

   ```sql
   CREATE TABLE IF NOT EXISTS `cron_tasks` (
     `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
     `name`            VARCHAR(100) NOT NULL,
     `cmd`             VARCHAR(255) NOT NULL,
     `args`            VARCHAR(255) DEFAULT NULL,
     `schedule_type`   ENUM('interval','daily') NOT NULL,
     `interval_sec`    INT UNSIGNED DEFAULT NULL,
     `interval_unit`   INT UNSIGNED NOT NULL DEFAULT 1,
     `daily_time`      CHAR(5) DEFAULT NULL,
     `enabled`         TINYINT(1) NOT NULL DEFAULT 1,
     `last_run_at`     DATETIME DEFAULT NULL,
     `next_run_at`     DATETIME NOT NULL,
     `last_exit_code`  INT DEFAULT NULL,
     `last_output`     MEDIUMTEXT NULL,
     `status`          ENUM('idle','running','done','failed') DEFAULT 'idle',
     `run_timeout_sec` INT UNSIGNED NOT NULL DEFAULT 600,
     `retries`         INT UNSIGNED NOT NULL DEFAULT 0,
     `max_retries`     INT UNSIGNED NOT NULL DEFAULT 0,
     `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     PRIMARY KEY (`id`),
     KEY `idx_enabled` (`enabled`),
     KEY `idx_next_run_at` (`next_run_at`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
   ```

   Contoh seed:

   ```sql
   INSERT INTO `cron_tasks`
   (`name`,`cmd`,`schedule_type`,`interval_sec`,`interval_unit`,`enabled`,`next_run_at`,`run_timeout_sec`,`max_retries`,`status`)
   VALUES
   ('Shopee Scraper', '/var/www/shopee.php', 'interval', 60, 60, 0, NOW(), 600, 0, 'idle'),
   ('Ping IP', '/var/www/ip.php', 'interval', 120, 60, 0, NOW(), 600, 0, 'idle'),
   ('Forex API', '/var/www/harga.php', 'interval', 30, 1, 0, NOW(), 600, 0, 'idle');
   ```

3. **Sesuaikan `db.php`** dengan MySQL kamu.

4. **Pasang cron** tiap 20 detik:

   ```cron
   * * * * * /usr/bin/flock -n /tmp/pasti_cron_tick.lock /usr/bin/php /var/www/kron/worker.php
   * * * * * (sleep 20; /usr/bin/flock -n /tmp/pasti_cron_tick.lock /usr/bin/php /var/www/kron/worker.php)
   * * * * * (sleep 40; /usr/bin/flock -n /tmp/pasti_cron_tick.lock /usr/bin/php /var/www/kron/worker.php)
   ```

5. **Buka UI** → `http://server/index.php` dashboard Pasti CronWorker.

---

## 🖥️ Cara Pakai

* **Tambah Task**

  * Schedule type: interval atau daily.
  * Interval: isi angka + unit (detik/menit/jam/hari).
  * Daily: format `HH:MM` WIB.
  * Args: bisa string biasa (1 argumen) atau JSON array (`["--foo=bar","data dengan spasi"]`).

* **Aksi di UI**

  * Enable/Disable task.
  * Run Now → eksekusi segera.
  * Clear Output → hapus log terakhir.
  * Edit/Hapus task.

---

## 🔐 Keamanan

* Validasi path `.php` (harus file nyata & bisa dibaca).
* Locking global + per-task untuk mencegah tabrakan.
* Argumen dieksekusi aman (gunakan JSON array bila multi argumen).
* Timeout untuk hentikan script yang macet.
* Auto-reset task `running` lebih dari 30 menit.
* UI dilindungi CSRF token.

---

## 🧪 Troubleshooting

* **Task tidak jalan** → cek `enabled=1`, `next_run_at <= NOW()`, path PHP CLI benar.
* **Output kosong** → cek `last_exit_code` & `last_output`.
* **Bind error** → pastikan file `index.php` versi terbaru (jumlah placeholder cocok).
* **Zona waktu** → atur `date_default_timezone_set('Asia/Jakarta')` di PHP dan `SET time_zone = '+07:00'` di MySQL.

---

## 📌 Roadmap

* Dukungan ekspresi cron 5-field.
* Logging ke file/ELK.
* Autentikasi user di UI.
* Import/export tasks (JSON).

---

## 📜 Lisensi

MIT License — bebas digunakan & dimodifikasi.

---

## 👤 Author

**Bang Roy (Moh. Royhan Nahado)**
GitHub: [@mohroyhannahado](https://github.com/mohroyhannahado)
Instagram: [@bangroyhan](https://www.instagram.com/bangroyhan/)
X / Twitter: [@serpentroy](https://x.com/serpentroy)
Threads: [@bangroyhan](https://www.threads.com/@bangroyhan)

![Histats](https://sstatic1.histats.com/0.gif?4971233&101)