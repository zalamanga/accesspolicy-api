# Menjalankan dan merawat AccessPolicy

## Prasyarat dan penyimpanan

Docker Desktop harus menjalankan Linux containers. PowerShell dipakai untuk skrip Windows; Node.js 22 diperlukan hanya untuk HTTP smoke. PHP/Composer lokal tidak diperlukan untuk menjalankan stack. Dockerfile memasang PHP 8.4, `pdo_pgsql`, `mbstring`, DOM/XML/XMLWriter, dan `pcntl` untuk test Linux.

Simpan checkout di D:. `.local` berisi kredensial demo dan hasil verifikasi yang tidak masuk Git. Volume utama bernama `accesspolicy_pgdata` pada project Compose `accesspolicy`. Lokasi fisik image, layer build, dan volume mengikuti pengaturan disk Docker Desktop; lokasi source D: saja tidak mengubah disk engine Docker.

```powershell
.\scripts\Init.ps1
.\scripts\Start.ps1
```

Jangan mengganti `DB_PASSWORD` setelah volume PostgreSQL pertama kali dibuat lalu menganggap password database ikut berubah. Variabel inisialisasi image PostgreSQL hanya dipakai ketika data directory masih kosong. Gunakan password yang sesuai atau lakukan rotasi melalui PostgreSQL; jangan menghapus volume untuk menyelesaikan kesalahan password jika datanya masih diperlukan.

`Init.ps1` mempertahankan `.env` yang sudah ada. Untuk membaca akun demo, buka `.local/LOCAL-CREDENTIALS.txt` secara lokal. Jangan salin file itu ke issue, screenshot, Postman environment yang akan dibagikan, atau commit.

## Menyalakan kembali dan berhenti

```powershell
.\scripts\Start.ps1 -NoBuild
.\scripts\Stop.ps1
```

Start menjalankan migrasi dan seeder sebelum HTTP server. Seeder memakai transaksi dan marker `demo-v1`; menjalankan ulang tidak mengembalikan password, role, atau anggota yang sudah dihapus ke keadaan awal. Stop menghentikan project `accesspolicy` dan mempertahankan volume. Skrip tidak memakai `docker system prune`, tidak menyentuh stack proyek lain, dan tidak menghapus volume utama.

## Melihat data lewat aplikasi database

Gunakan DBeaver, pgAdmin, atau klien PostgreSQL lain dengan host `127.0.0.1`, port `5458`, database `accesspolicy`, user `accesspolicy`, dan password dari `.env`. Ini port loopback untuk melihat data lokal.

Tabel yang perlu dibaca:

| Tabel | Isi |
| --- | --- |
| `users` | Akun sintetis dan hash password |
| `organizations` | Atlas dan Birch |
| `memberships` | Role/versi user pada organisasi tertentu |
| `membership_versions` | Counter organisasi/user yang bertahan setelah membership dihapus |
| `personal_access_tokens` | Digest token Sanctum dan waktu kedaluwarsa |
| `audit_events` | Perubahan membership dan siklus token |
| `login_window` | Kuota login global yang bertahan di database |
| `seed_markers` | Penanda agar seed tidak mereset data |

Lakukan perubahan role melalui API agar aturan owner, revokasi, dan audit berjalan bersama. SQL langsung dapat melewati invariant service dan bukan bagian dari kontrak API.

## Test PostgreSQL

```powershell
.\scripts\Test.ps1
```

Skrip menghasilkan password test sementara, memeriksa port 5459, lalu memakai `compose.test.yml` dengan project `accesspolicy-test`. Databasenya bernama tepat `accesspolicy_test`; PHPUnit menolak nama database lain sebelum `migrate:fresh`. Test menggunakan database nyata dan menjalankan skenario secara serial. Kasus race membuat dua proses PHP terpisah untuk dua HTTP-kernel request yang berbagi PostgreSQL tersebut.

Database test menggunakan tmpfs. Cleanup menjalankan `down` pada project test saja. Environment proses PowerShell yang diubah skrip dikembalikan di blok `finally`, termasuk ketika test gagal. PHP tidak di-install secara global dan test tidak menulis dependency Composer ke C:.

## HTTP smoke

```powershell
node .\scripts\smoke.mjs
```

Jalankan setelah stack utama sehat. Smoke membaca `.env` sebagai data, menggunakan tiga login sintetis, membuktikan tenant 404 dan larangan eskalasi role, mengubah member Atlas menjadi viewer lalu kembali member, memeriksa token lama menjadi 401, serta melihat audit. Versi anggota dan audit bertambah; identitas/role akhir dipertahankan. Hasil tersimpan di `.local/http-smoke.json` tanpa password/token. Bila proses terputus setelah perubahan pertama, periksa role dan versi user melalui API sebelum menjalankan ulang.

Batasi pengulangan login karena tiga login smoke ikut memakai batas sepuluh per 300 detik. Saat 429, ikuti `Retry-After`; restart API tidak mereset kuota database.

## Build dan dependency

Composer di Docker menjalankan `install` dari lock. Untuk memperbarui dependency, gunakan container PHP yang sesuai, tinjau perubahan lock, jalankan audit/test, lalu bangun ulang runtime. Jangan mengedit field versi di lock secara manual.

Lock awal boleh diresolusikan melalui Composer pada host PHP lama dengan `config.platform.php=8.4.0` dan `config.platform.ext-pdo_pgsql=8.4.0`, hanya menggunakan `update --no-install --no-scripts --no-plugins`. Platform override adalah petunjuk resolver, bukan pengganti runtime. Docker benar-benar memasang PHP 8.4 beserta extension PostgreSQL sebelum menjalankan aplikasi dan test. Composer home/cache/TEMP yang dipakai untuk resolusi lokal harus diarahkan ke `.local` pada D: dan dikembalikan sesudahnya.

## Batas operasional

Runtime memakai user 10001, filesystem read-only, capability kosong, dan tmpfs untuk `/tmp` serta storage Laravel. Server PHP bawaan memakai empat worker untuk demo lokal; ini bukan konfigurasi deployment publik. Aplikasi tidak mencatat body request atau header Authorization. Pesan kesalahan publik tidak memuat SQL atau stack trace.

User database demo juga menjalankan migrasi dan berasal dari `POSTGRES_USER` image PostgreSQL. Deployment publik perlu memisahkan akun migrasi dari akun aplikasi dengan hak tabel yang terbatas.

Sebelum deployment publik, sediakan reverse proxy/TLS, kuota login per identitas dan sumber tepercaya, rotasi secret, backup/restore teruji, pemantauan, serta upgrade Laravel sebelum dukungan berakhir. Belum ada klaim kapasitas, SLA, atau sertifikasi keamanan untuk proyek ini.
