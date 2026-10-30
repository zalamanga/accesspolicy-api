# Kontrak AccessPolicy

Kontrak mesin berada di [openapi.json](openapi.json), tersedia juga melalui `GET /openapi.json`. Versi API menggunakan awalan `/api/v1`. Semua contoh memakai identitas sintetis, bukan akun organisasi nyata.

## Endpoint

| Method | Path | Hasil |
| --- | --- | --- |
| GET | `/health` | Status API dan koneksi PostgreSQL |
| GET | `/openapi.json` | Kontrak OpenAPI 3.0.3 |
| POST | `/api/v1/auth/login` | Token baru dengan masa berlaku satu jam, status 201 |
| POST | `/api/v1/auth/logout` | Hapus token saat ini, status 204 |
| GET | `/api/v1/me` | ID, nama, dan email milik pemanggil |
| GET | `/api/v1/orgs` | Organisasi tempat pemanggil menjadi anggota |
| GET | `/api/v1/orgs/{org}/members` | Daftar anggota organisasi, tanpa email/password/token |
| POST | `/api/v1/orgs/{org}/members` | Tambahkan user yang sudah ada |
| PATCH | `/api/v1/orgs/{org}/members/{user}` | Ubah role dengan `expectedVersion` |
| DELETE | `/api/v1/orgs/{org}/members/{user}` | Hapus keanggotaan dengan body JSON `expectedVersion` |
| GET | `/api/v1/orgs/{org}/members/{user}/permissions` | Permission efektif menurut role dalam organisasi itu |
| GET | `/api/v1/orgs/{org}/audit` | Audit perubahan keanggotaan, hanya owner/admin |

ID path berupa bilangan positif maksimal sepuluh digit. User ID pada body pembuatan maksimal 2147483647. Role yang diterima: `owner`, `admin`, `member`, dan `viewer`.

## Body dan respons

Login menerima tepat `email` dan `password`. Password maksimal 72 byte UTF-8 karena bcrypt memiliki batas input. Email dinormalisasi menjadi huruf kecil untuk pencarian; spasi pada input tidak dibuang secara diam-diam.

Pembuatan keanggotaan:

```json
{"userId":3,"role":"member"}
```

Perubahan role:

```json
{"role":"viewer","expectedVersion":1}
```

Penghapusan menggunakan body JSON, bukan query string atau header versi:

```json
{"expectedVersion":2}
```

Respons mutasi memakai bentuk berikut. Role bernilai `null` setelah penghapusan, dan `version` menunjukkan nomor perubahan terakhir pada hasil mutasi itu.

```json
{"data":{"id":3,"userId":3,"role":"viewer","version":2,"tokensRevoked":1}}
```

Penambahan kembali user setelah keanggotaan dihapus membuat baris keanggotaan dengan ID baru, tetapi nomor versi tetap naik. Tabel `membership_versions` menyimpan counter pasangan organisasi/user walaupun membership dihapus. Contohnya versi 1 dihapus menjadi peristiwa versi 2, kemudian penambahan kembali memakai versi 3. Request lama dengan `expectedVersion:1` tetap ditolak dan tidak dapat mengubah membership yang baru.

Kesalahan mempunyai bentuk konsisten:

```json
{"error":{"code":"stale_version","message":"Membership changed. Read its current version before retrying.","details":null}}
```

`details` dapat berisi kesalahan per field. Aplikasi tidak mengembalikan exception database atau stack trace kepada pemanggil.

## Matriks kemampuan

Viewer dan member memiliki kemampuan yang sama pada layanan direktori ini: `members.read` dan `permissions.self.read`. Perbedaan nama role disediakan untuk integrasi aplikasi klien; tidak ada hak tersembunyi pada member.

Admin menambahkan `audit.read`, `permissions.others.read`, dan `members.ordinary.manage`. Admin hanya boleh menambahkan, mengubah, atau menghapus target dengan role member/viewer dan hanya boleh menetapkan role member/viewer. Admin tidak boleh mengubah dirinya sendiri maupun admin/owner lain.

Owner menambahkan `members.privileged.manage` dan `membership.self.leave`. Owner boleh menetapkan seluruh role serta mengubah atau menghapus dirinya jika setidaknya satu owner lain tetap ada. Owner terakhir menerima 409 untuk demosi ataupun penghapusan. Capability adalah nama aturan, bukan janji bahwa seluruh target selalu boleh diubah; pemeriksaan target dan jumlah owner tetap dijalankan.

`GET permissions` hanya mengizinkan anggota melihat dirinya sendiri. Owner/admin boleh melihat anggota lain dari organisasi yang sama. User ID di luar organisasi menerima 404 setelah hak melihat target diperiksa. Organisasi di luar keanggotaan pemanggil selalu menerima 404.

## Autentikasi dan revokasi

Sanctum menyimpan SHA-256 token di `personal_access_tokens`. Token mentah hanya diberikan pada login. Konfigurasi `stateful=[]` dan `guard=[]` menonaktifkan pencarian sesi browser; semua endpoint privat memerlukan bearer token.

Token memiliki ability `api`, tetapi service tidak memakai ability itu untuk melewati pemeriksaan role. Bahkan token dengan `*` tetap tunduk pada keanggotaan saat ini. Token dibuat untuk user, sehingga perubahan keanggotaan mencabut **seluruh token user target di seluruh organisasi**. Cakupan global ini disengaja agar kontrak pencabutan mudah diuji. Implementasi aplikasi dengan banyak klien dapat menggunakan token per organisasi sebagai pengembangan berikutnya.

Setiap pembacaan privat mengambil shared lock pada baris user dan mengecek ulang keberadaan/expiry token. Writer mengambil lock eksklusif user yang sama. Writer mengambil lock organisasi lebih dahulu, lalu user actor/target dengan urutan ID menaik. Login mengambil lock user sebelum membuat token. Akibatnya login yang selesai sebelum perubahan akan dicabut, sedangkan login yang menunggu perubahan selesai menghasilkan token baru dengan hak database terbaru.

Audit `membership.created`, `membership.role_changed`, dan `membership.removed` dicatat bersama edit dan revokasi. Audit `token.issued`, `token.revoked`, dan pembersihan token kedaluwarsa dicatat dalam transaksi token terkait, dengan `organization_id=null`; endpoint audit organisasi tidak menampilkan peristiwa global tersebut. Token yang langsung dibuat oleh fixture test bukan login pengguna dan tidak menghasilkan audit login.

## Batas dan pagination

Semua body harus berupa objek JSON. Batas 16384 byte berlaku sebelum controller; body terlalu besar menerima 413. Body pada GET/HEAD ditolak 400. JSON rusak/array top-level menerima 400. Body berisi field tidak dikenal atau query tambahan menerima 422, termasuk parameter yang tampak seperti opsi admin.

`limit` adalah integer desimal 1 sampai 50, default 20. `afterId` adalah ID eksklusif positif maksimal sembilan digit. Nilai query dikirim sebagai teks desimal tanpa tanda atau nol awal. Respons daftar berisi `data` dan `nextAfterId`; nilai cursor `null` berarti halaman saat ini tidak mempunyai kelanjutan yang teramati. Daftar bukan snapshot multi-request; perubahan di antara permintaan dapat mengubah hasil berikutnya.

Login menggunakan satu baris `login_window` yang dikunci untuk setiap penerimaan percobaan. Sepuluh request dengan bentuk input valid diterima dalam jendela tetap 300 detik; baik login berhasil maupun password salah menghitung kuota. Request berikutnya menerima 429 dan `Retry-After`. Kuota global membuat spoofing header IP tidak berpengaruh dan menjaga ukuran tabel tetap. Ini pilihan demo lokal, bukan kebijakan kapasitas untuk layanan publik.

## Keadaan konflik

| Kode | Kondisi | Tindakan klien |
| --- | --- | --- |
| `stale_version` | Versi database berbeda dari `expectedVersion` | Baca anggota terbaru dan minta keputusan baru |
| `no_change` | Role sudah sama | Tampilkan keadaan saat ini |
| `membership_exists` | POST untuk user yang sudah menjadi anggota | Gunakan PATCH dengan versi terbaru |
| `last_owner` | Demosi/penghapusan akan menghilangkan owner terakhir | Tambahkan owner lain lebih dahulu |

Service tidak melakukan retry otomatis terhadap keputusan pengguna yang sudah usang. Retry transaksi internal hanya menangani konflik database sampai tiga kali, dengan pengecekan hak dan versi ulang pada setiap percobaan.
