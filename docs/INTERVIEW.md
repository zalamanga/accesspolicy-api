# Menjelaskan AccessPolicy saat interview

## Penjelasan awal

“Saya membuat REST API untuk keanggotaan organisasi dengan PHP, Laravel, Sanctum, dan PostgreSQL. Tantangan utamanya adalah memastikan perubahan role langsung membatalkan token lama dan dua request bersamaan tidak bisa menghilangkan owner terakhir. Saya menggabungkan pengecekan, perubahan membership, revokasi token, dan audit dalam transaksi database.”

Sesuaikan kalimat itu dengan bagian yang sudah kamu pelajari dan hasil test yang benar-benar dijalankan. Jangan menyebut proyek ini sudah dipakai perusahaan atau sudah menangani trafik produksi.

## Alur demo

1. Jalankan `Start.ps1`, buka `/health`, dan impor OpenAPI atau koleksi Postman.
2. Login sebagai owner dari kredensial lokal. Tunjukkan `GET /orgs` dan daftar anggota Atlas. Owner ini tidak bergabung di Birch, sehingga endpoint Birch menghasilkan 404.
3. Login sebagai member. Tunjukkan permission sendiri lalu coba menetapkan dirinya menjadi owner: hasil 403.
4. Sebagai admin, ubah member Atlas menjadi viewer dengan versi yang dibaca dari daftar. Tunjukkan `version` bertambah dan jumlah `tokensRevoked`.
5. Pakai token member sebelumnya untuk `GET /me`: hasil 401. Login lagi untuk memperoleh hak terbaru.
6. Ulangi edit dengan versi lama: hasil 409. Buka audit dan tunjukkan actor, target, oldRole/newRole, dan jumlah revokasi.
7. Jelaskan test dua owner melakukan demosi diri serentak. Satu transaksi berhasil dan satu menerima 409; PostgreSQL tetap menyimpan satu owner.

## Mengapa memakai transaksi?

Jika update role berhasil tetapi revokasi gagal, token lama masih dapat dipakai. Jika audit gagal setelah role berubah, riwayat kehilangan peristiwa. Transaksi mengembalikan semuanya ketika satu langkah gagal. `MutationCheckpoint` membantu test melempar exception setelah penghapusan token; assertion memeriksa membership, versi, token, dan audit sama-sama kembali ke keadaan awal.

## Mengapa ada lock dan versi sekaligus?

Lock organisasi melindungi invariant jumlah owner saat beberapa request berjalan bersamaan. `expectedVersion` melindungi keputusan pengguna yang dibuat dari keadaan lama, termasuk ketika request lama baru dikirim setelah transaksi sebelumnya sudah selesai. Keduanya menangani masalah berbeda.

## Mengapa tidak cukup memakai token ability?

Ability adalah informasi saat token dibuat, sedangkan membership bisa berubah. Endpoint membaca role dari database pada organisasi yang diminta. Token `*` juga tidak boleh melewati policy. Revokasi memberi batas yang jelas: token lama langsung ditolak, sehingga klien harus memperoleh token baru.

## Tradeoff yang perlu dijelaskan

Seluruh token user target dicabut, termasuk token yang dipakai dalam organisasi lain. Ini mudah dipahami dan diuji, tetapi mengganggu pengguna yang aktif pada beberapa organisasi. Desain lanjutan bisa membuat token scoped per organisasi dan menggunakan membership version pada autentikasi.

Kuota login memakai satu baris database untuk sepuluh percobaan selama lima menit, termasuk yang berhasil. Kebijakan ini membatasi pekerjaan bcrypt dan tidak bergantung pada header IP yang dapat dipalsukan. Konsekuensinya semua pengguna demo berbagi kuota; layanan publik perlu kebijakan yang lebih tepat.

Role member dan viewer memiliki izin direktori yang sama pada proyek ini. Layanan tidak mengimplementasikan izin produk di luar direktori, misalnya akses invoice atau perubahan dokumen. Ini harus dijelaskan sebagai batas domain yang dipilih.

PostgreSQL dipakai langsung untuk test konkurensi. SQLite atau mock repository tidak membuktikan perilaku row lock PostgreSQL. CI juga menjalankan HTTP smoke terhadap server yang sudah dibangun.

## Bagian kode untuk dibuka

- `MembershipService::mutate`: urutan lock, policy, versi, owner count, revokasi, dan audit.
- `Policy::read` dan `liveToken`: pemeriksaan token ulang ketika request sudah diautentikasi framework.
- `AuthController::login`: kuota persisten, bcrypt, lock user, dan token sekali tampil.
- `PolicyApiTest`: kasus eskalasi role, atomic rollback, dan dua proses demosi owner.

Untuk mempelajari kode per file, lanjutkan ke [CODE-GUIDE.md](CODE-GUIDE.md).
