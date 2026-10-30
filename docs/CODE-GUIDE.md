# Belajar kode AccessPolicy dari awal

Panduan ini mengikuti source pada repository ini. Tujuannya menjelaskan tempat setiap aturan berjalan agar kamu bisa mengubah dan mendemonstrasikannya sendiri. Mulai dari alur request, lalu baca service transaksi sebelum detail Docker.

## 1. Tentukan aturan domain sebelum membuat endpoint

Satu user dapat menjadi anggota beberapa organisasi dengan role berbeda. Karena itu role disimpan di `memberships`, bukan pada kolom `users.role`. Relasinya:

```text
users 1 ---- banyak memberships banyak ---- 1 organizations
users 1 ---- banyak personal_access_tokens
users 1 ---- banyak audit_events (actor dan target)
```

`memberships` mempunyai kombinasi unik `(organization_id, user_id)`. Satu user tidak boleh memperoleh dua keanggotaan di organisasi yang sama. Role dibatasi oleh CHECK PostgreSQL. Kolom `version` dimulai dari 1 dan bertambah saat role berubah. `membership_versions` mempertahankan counter pasangan organisasi/user setelah penghapusan, sehingga penambahan kembali tidak menerima request dengan versi dari membership lama.

Aturan paling penting berada di service: organisasi harus tetap memiliki owner, admin hanya mengelola anggota biasa, dan perubahan membership mencabut token target beserta audit dalam transaksi yang sama.

## 2. Peta file

| File atau folder | Yang dipelajari |
| --- | --- |
| `composer.json`, `composer.lock` | Dependency, autoload PSR-4, versi PHP target, dan dependency yang terkunci |
| `artisan` | Entry point perintah Laravel |
| `public/index.php` | Entry point HTTP |
| `bootstrap/app.php` | Routing, middleware global, dan bentuk kesalahan JSON |
| `bootstrap/providers.php` | Provider milik aplikasi |
| `routes/api.php` | Method/path dan middleware autentikasi setiap endpoint |
| `app/Http/Middleware/JsonBoundary.php` | Batas ukuran body, JSON object, content type, header respons |
| `app/Http/Controllers/AuthController.php` | Login, logout, dan identitas sendiri |
| `app/Http/Controllers/OrganizationController.php` | Daftar organisasi/anggota, mutasi, permission, dan audit |
| `app/Services/Input.php` | Field yang diterima dan pagination |
| `app/Services/Policy.php` | Matriks hak, batas organisasi, dan token yang masih hidup |
| `app/Services/MembershipService.php` | Transaksi perubahan membership |
| `app/Services/Audit.php` | Penyimpanan peristiwa audit |
| `app/Services/MutationCheckpoint.php` | Sambungan test untuk kegagalan di tengah transaksi |
| `app/Models/User.php` | Model akun dan relasi token Sanctum |
| `app/Exceptions/ApiProblem.php` | Kesalahan domain dengan status/code publik |
| `app/Providers/AppServiceProvider.php` | Registrasi checkpoint di container Laravel |
| `database/migrations/...create_policy_schema.php` | Struktur PostgreSQL dan constraint |
| `database/seeders/DatabaseSeeder.php` | Data awal sintetis yang tidak mereset perubahan |
| `tests/TestCase.php` | Bootstrap test, pengaman database test, serta helper request |
| `tests/Feature/PolicyApiTest.php` | Skenario perilaku API terhadap PostgreSQL |
| `tests/concurrent-worker.php` | Request dari proses PHP kedua untuk kasus race |
| `Dockerfile`, `compose*.yml` | Runtime PHP dan pemisahan database utama/test |
| `scripts/*.ps1`, `scripts/*.php`, `scripts/smoke.mjs` | Inisialisasi, lifecycle, health, serta test HTTP |
| `.github/workflows/verify.yml` | Pemeriksaan otomatis, build, test, dan ekspor image |

Folder `vendor` berisi kode dependency hasil Composer. Jangan mengedit framework di sana. Source aplikasi berada di `app`, konfigurasi di `config`, dan kontrak mesin di `docs/openapi.json`.

## 3. Request masuk melalui `public/index.php`

PHP memuat autoloader Composer agar nama kelas seperti `App\Services\Policy` dapat ditemukan tanpa `require` setiap file. Berikutnya `bootstrap/app.php` membuat aplikasi Laravel. `handleRequest` menerima request HTTP lalu mengirim response.

`artisan` menggunakan aplikasi yang sama, tetapi inputnya berasal dari command line. Karena itu `php artisan migrate` dan HTTP server memakai konfigurasi database/provider yang sama, dengan environment berbeda untuk test.

Di `bootstrap/app.php`, `withRouting` memuat `routes/api.php` tanpa prefix otomatis. Source route sudah menulis `/api/v1`, jadi alamat tidak berubah menjadi `/api/api/v1`.

`withMiddleware` memasang `JsonBoundary` lebih awal. Normalisasi string bawaan dimatikan supaya password dan input tidak berubah diam-diam. Cookie session dan middleware SPA Sanctum tidak diaktifkan.

`withExceptions` mengubah `ApiProblem`, error validasi, dan error autentikasi menjadi envelope JSON. Kesalahan tidak dikenal menghasilkan pesan 500 umum. Detail SQL dan exception tidak dikirim kepada pemanggil.

## 4. `JsonBoundary::handle`

Method ini membaca body sebagai byte. `strlen($raw)` memeriksa batas 16384 byte; ini berbeda dari menghitung karakter. Header Content-Length juga diperiksa, tetapi body aktual tetap diperiksa sehingga header saja bukan sumber kebenaran.

Jika body ada, content type harus JSON. `json_decode` memakai `JSON_THROW_ON_ERROR`, sehingga JSON rusak tidak berubah menjadi `null` yang ambigu. Top-level harus `stdClass`, yang berarti objek JSON. Array seperti `[]` ditolak.

GET/HEAD tidak menerima body. Setelah controller selesai, middleware memberi `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, dan request ID acak. Request ID membantu mengenali respons, tetapi proyek ini belum memiliki penyimpanan tracing terdistribusi.

## 5. `Input` menjaga bentuk request

`body(Request, rules, integerFields)` menerima hanya field yang memiliki aturan. Sebelum validasi Laravel, method ini menolak query tambahan dan field body yang tidak dikenal. Field integer khusus juga diperiksa dengan `is_int`, sehingga `"expectedVersion":"1"` ditolak walaupun string itu terlihat numerik.

`query(Request, allowed)` memeriksa daftar parameter yang diizinkan. `page` membatasi `limit` menjadi 1 sampai 50 dan membaca cursor `afterId`. Service mengambil `limit + 1` baris untuk mengetahui apakah masih ada halaman berikutnya, kemudian mengirim maksimal `limit` baris.

Method private `known` membandingkan nama field dengan whitelist. Payload seperti `{"role":"member","isSuperAdmin":true}` tidak dapat menambahkan hak tersembunyi karena field tambahan ditolak sebelum service berjalan.

## 6. Model `User` dan Sanctum

`User` mewarisi `Authenticatable` Laravel. Trait `HasApiTokens` menambahkan relasi `tokens()` dan `createToken()`. Password disimpan sebagai hash bcrypt, sedangkan `hidden` mencegah serialisasi kolom password ketika model dikirim sebagai JSON.

Aplikasi tetap membentuk respons sendiri agar daftar anggota hanya berisi field yang diperlukan. Daftar tidak pernah mengirim seluruh model user atau alamat email target.

`config/auth.php` memilih guard `sanctum`. `config/sanctum.php` menggunakan `guard=[]` dan `stateful=[]`, sehingga cookie login browser tidak dipakai sebagai jalur autentikasi alternatif. Sanctum memeriksa bearer token yang digest-nya ada pada PostgreSQL dan belum kedaluwarsa.

## 7. `AuthController::login`

Alurnya:

1. Validasi email/password dan panjang byte password.
2. Kunci baris tunggal `login_window` dalam transaksi singkat.
3. Reset jendela jika sudah lewat 300 detik, atau tambah hitungan percobaan. Jika sudah sepuluh, kirim 429 dan sisa waktu.
4. Cari user lalu lakukan bcrypt. Email tidak ditemukan dan password salah menghasilkan pesan 401 yang sama. User tidak ditemukan tetap melakukan bcrypt terhadap hash dummy.
5. Kunci user di transaksi penerbitan token, bersihkan token kedaluwarsa dengan audit, dan periksa batas dua puluh token aktif.
6. Buat token satu jam dengan Sanctum, tulis audit, lalu kembalikan token mentah sekali.

Transaksi kuota selesai sebelum bcrypt, agar row lock tidak ditahan selama pekerjaan hash. User lock saat penerbitan token menyelaraskan login dengan perubahan membership: writer membership juga harus mendapatkan lock user itu sebelum menghapus token.

`logout` memvalidasi body kosong, mengambil user lock, memeriksa kembali token yang sedang dipakai, menghapus token tersebut, lalu mencatat audit dalam transaksi. `me` mengembalikan identitas pemanggil melalui `Policy::read`.

## 8. `Policy` memisahkan aturan akses dari controller

`permissions(role)` mengembalikan daftar capability yang eksplisit. Capability tidak dipakai sebagai salinan hak di token; role saat ini tetap dibaca dari membership database.

`membership(org, user)` mencari baris keanggotaan untuk pasangan tersebut. Tidak ditemukan menghasilkan 404. Controller memanggilnya untuk actor terlebih dahulu, sehingga user yang tidak bergabung di organisasi tidak memperoleh informasi apakah resource tenant lain ada.

`authorizeTarget(actorRole, actor, target, oldRole, newRole)` menyaring mutasi berdasarkan actor dan target. Owner dapat melanjutkan ke pemeriksaan owner terakhir. Admin hanya dapat mengelola role biasa, tidak boleh mengubah dirinya sendiri, dan tidak boleh menyentuh admin/owner. Selain owner/admin menerima 403.

`liveToken(request)` memeriksa ID token, pemilik, tipe model, serta expiry di PostgreSQL. Pemeriksaan ini dilakukan kembali setelah lock diperoleh, karena hasil autentikasi framework mungkin dibaca sebelum request lain mencabut token.

`read(request, callback)` mengambil shared lock user, memeriksa token ulang, kemudian menjalankan query privat. Writer memerlukan lock eksklusif pada user yang sama. Pembacaan yang sudah mulai dapat selesai sebelum writer, sementara pembacaan baru setelah revokasi menerima 401.

## 9. Transaksi `MembershipService::mutate`

Ini fungsi utama untuk dipahami. Controller create/update/delete memakai fungsi yang sama agar urutan aturan tidak berbeda pada setiap endpoint.

```text
BEGIN
  lock organization
  lock actor/target users in ascending ID order
  recheck actor token and current organization membership
  authorize actor against target/new role
  check existence and expectedVersion
  check final owner count
  increment persistent membership version counter
  insert/update/delete membership
  delete every target user's token
  checkpoint used by tests
  insert audit event
COMMIT
```

Lock organisasi membuat dua writer dalam organisasi yang sama berjalan bergantian. Contoh: Atlas mempunyai dua owner. Dua request ingin mendemosikan dirinya. Request pertama mengambil lock, melihat dua owner, lalu berhasil. Request kedua baru memeriksa jumlah setelah commit pertama dan melihat satu owner; hasilnya 409.

Lock user memakai urutan ID menaik agar perubahan yang melibatkan pasangan user yang sama tidak mengambil lock dengan urutan saling terbalik. Login juga mengambil user lock sebelum menerbitkan token, sehingga token yang dibuat lebih awal ikut dicabut dan token baru setelah edit melihat hak terbaru.

Create mengharuskan target user sudah ada dan belum menjadi anggota. Update/delete memerlukan versi yang cocok. Update ke role yang sama menerima `no_change`; service tidak menambah versi atau audit untuk perubahan kosong.

`$users[$targetId]->tokens()->delete()` mencabut semua token target. Checkpoint test dipanggil sesudahnya untuk membuktikan bahwa penghapusan token ikut rollback jika langkah berikutnya gagal. `Audit::record` menyimpan oldRole, newRole, version, serta jumlah token; tidak menyimpan token mentah atau password.

Parameter retry `3` pada `DB::transaction` menangani konflik transaksi database yang dapat dicoba ulang. Semua pemeriksaan tetap berada di dalam closure, sehingga retry tidak menggunakan keputusan role/owner dari transaksi lama.

## 10. `OrganizationController`

`index` menggabungkan organisasi dan membership milik pemanggil. Tidak ada endpoint publik untuk menebak daftar semua organisasi.

`members` memeriksa membership actor lalu menjalankan query berhalaman. Nama tampilan diperoleh dari tabel user, sementara email/password/token tidak disertakan.

`permissions` memeriksa actor terlebih dahulu. Anggota biasa hanya melihat dirinya; owner/admin dapat melihat target lain di organisasi yang sama. Respons memuat role dan membership version yang menjadi dasar permission.

`create`, `update`, dan `remove` hanya menangani validasi body serta status HTTP, lalu menyerahkan aturan transaksi kepada `MembershipService`. `audit` membatasi akses owner/admin dan mem-parsing kolom JSONB metadata untuk respons.

## 11. Migrasi dan seed

Migrasi membuat tabel dengan foreign key dan index. Index `(organization_id, id)` mendukung pembacaan anggota/audit berdasarkan cursor. CHECK role memastikan SQL tidak dapat menyimpan string role sembarang, tetapi aturan owner terakhir tetap berada pada transaksi service. Mengubah data langsung dengan SQL dapat melewati aturan aplikasi.

Seeder memakai advisory transaction lock agar dua proses startup tidak membuat data awal bersamaan. Marker `demo-v1` diperiksa sebelum membuat akun atau membership. `firstOrCreate` mempertahankan password akun yang sudah ada; marker mencegah membership yang sengaja dihapus muncul lagi saat restart.

Empat password berasal dari konfigurasi `demo.passwords`. Nama key merupakan email yang mengandung titik, sehingga source memakai akses array biasa, bukan pencarian dot notation Laravel.

## 12. Konfigurasi per file

| File | Pilihan yang diatur |
| --- | --- |
| `config/app.php` | URL, timezone UTC, key AES, debug selalu false |
| `config/auth.php` | Guard bearer Sanctum dan model user |
| `config/sanctum.php` | Token satu jam tanpa autentikasi cookie |
| `config/database.php` | Koneksi PostgreSQL dari environment |
| `config/cache.php` | Database cache dan tabel lock jika framework memerlukannya |
| `config/hashing.php` | Bcrypt cost 12 |
| `config/logging.php` | Error ke stderr; request body tidak dicatat aplikasi |
| `config/session.php` | Driver array, sesi browser tidak menjadi kontrak API |
| `config/demo.php` | Password sintetis dari environment privat |

`.env.example` menunjukkan nama variabel tanpa nilai rahasia. `.gitignore` mengecualikan `.env`, `.local`, dependency, dan hasil test. `.dockerignore` juga mencegah secret ikut masuk build context.

## 13. Test yang perlu dibaca

`TestCase::setUp` menolak database selain PostgreSQL bernama `accesspolicy_test` sebelum menjalankan `migrate:fresh`. Setiap test mendapatkan data awal baru. Helper token membuat token fixture langsung agar setiap test policy tidak perlu menghabiskan kuota login.

Helper `api` mengirim GET tanpa body dan method mutasi dengan objek JSON. Ia mereset guard Laravel karena feature test menjalankan beberapa request di aplikasi yang sama, sedangkan request server nyata memiliki guard baru.

Kasus rollback mengganti instance `MutationCheckpoint` lewat container dependency injection. Tidak ada header, route, atau environment produksi untuk menyalakan kegagalan tersebut. Setelah exception, assertion memeriksa role/versi, jumlah audit, dan token lama masih valid.

Kasus konkurensi menjalankan dua `Symfony Process` yang memakai `tests/concurrent-worker.php`. Input token lewat stdin, bukan argumen proses. Worker memuat HTTP kernel Laravel lalu mengirim PATCH ke user pemilik tokennya. Worker pertama ditahan oleh checkpoint sebelum commit. Test mengamati worker kedua berstatus `wait_event_type=Lock` di `pg_stat_activity` sebelum melepas worker pertama, sehingga test membuktikan adanya contention database nyata. Hasil yang diharapkan adalah 200 dan 409 serta tepat satu owner tersisa.

## 14. Docker dan perintah pendukung

Stage `base` menyediakan PHP dan extension. Stage `test` memasang dependency development dan PHPUnit. Stage `build` memasang dependency runtime saja. Stage `runtime` menyalin aplikasi yang sudah siap dan menjalankannya sebagai user nonroot 10001.

Compose utama menjalankan PostgreSQL persisten dan API loopback 8102. Startup menjalankan migrasi/seed lalu server. Compose test memakai database terpisah, port 5459, dan tmpfs yang dibuang ketika stack test berhenti. Nama project eksplisit mencegah test menghentikan proyek lain.

`Common.ps1` membungkus Docker dan pembacaan `.env` sebagai pasangan key/value. `Init.ps1` menghasilkan key/password tanpa mencetak nilainya. `Start.ps1` mengurutkan build/start dan menunggu health. `Stop.ps1` mempertahankan volume. `Test.ps1` mengembalikan environment pada blok `finally`. `health.php` memanggil health loopback dengan timeout. `smoke.mjs` menjalankan request HTTP nyata dan menyimpan bukti tanpa secret.

CI mengikuti urutan test image, test PostgreSQL, audit dependency, runtime image, HTTP smoke, lalu ekspor image yang sudah diuji. Build/test yang belum dijalankan tetap berstatus pending di [VERIFICATION.md](VERIFICATION.md).

## 15. Urutan latihan

Jalankan service dan ikuti koleksi Postman dahulu. Setelah kamu melihat 401/403/404/409 secara nyata, buka fungsi yang menghasilkan code tersebut. Coba satu perubahan kecil, misalnya menambah satu role baca saja, lalu ubah constraint, policy, OpenAPI, dan test bersama. Hindari mengubah controller saja tanpa memperbarui aturan transaksi dan kontraknya.
