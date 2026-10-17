# Alur pelanggan dan billing virVPN

1. Administrator menguji koneksi server di menu **Servers**. Hanya server RouterOS nyata yang online dan layanan VPN yang terdeteksi aktif dapat ditawarkan ke pelanggan. L2TP/IPsec memerlukan `use-ipsec=required` serta pre-shared key yang disimpan saat edit server. OpenVPN yang belum tersedia pada RouterOS tidak ditawarkan.
2. Administrator membuat paket di **Paket VPN**: harga dalam rupiah, masa aktif, batas koneksi, protokol, dan server. Paket nonaktif tidak tampil di katalog pelanggan.
3. Administrator mengisi rekening penerima di **Billing & invoice**. Rekening disalin ke setiap invoice saat dibuat agar perubahan rekening berikutnya tidak mengubah instruksi pada invoice lama.
4. Pelanggan mendaftar dan memverifikasi email, masuk, memilih paket/server/protokol, lalu membuat invoice. Password VPN disimpan terenkripsi sampai akun berhasil dibuat pada server; pelanggan harus menyimpan password itu sendiri.
5. Pelanggan mentransfer jumlah invoice dan mengirim nomor referensi transfer. Ini adalah klaim pelanggan, bukan bukti pembayaran otomatis. Administrator wajib mencocokkannya dengan mutasi rekening sebelum menekan **Konfirmasi lunas**.
6. Setelah pembayaran disetujui, worker antrean membuat akun VPN. Masa sewa dimulai ketika provisioning berhasil. Jika gagal, admin dapat mencoba ulang dari menu **VPN accounts** dan masa sewa belum dimulai.
7. Pelanggan melihat host, username, protokol, status, dan masa aktif pada **Layanan saya**. Untuk L2TP/IPsec, pre-shared key yang dikonfigurasi administrator ditampilkan kepada pemilik layanan dan berbeda dari password VPN pelanggan.
8. Pelanggan dapat membuat invoice perpanjangan untuk layanan aktif. Pembayaran yang dikonfirmasi admin menambah masa aktif akun yang sama.

## Syarat operasi produksi

- Konfigurasikan SMTP atau transport email yang benar di `backend/.env`; `MAIL_MAILER=log` tidak mengirim tautan verifikasi. Uji pendaftaran menggunakan alamat email nyata sebelum membuka penjualan.
- Jalankan worker antrean Laravel secara terus-menerus agar akun berbayar benar-benar diprovisioning.
- Jalankan `php artisan schedule:run` setiap menit. Scheduler menutup invoice belum dibayar yang sudah lewat 3 hari dan menghapus password VPN sementaranya; scheduler yang sama menangani kedaluwarsa layanan.
- Metode pembayaran saat ini transfer manual. Aplikasi tidak mengklaim transfer berhasil hanya berdasarkan nomor referensi yang dikirim pelanggan.
- Akun admin yang dibuat langsung melalui menu **VPN accounts** tidak otomatis menjadi milik pelanggan. Alur pelanggan yang tercatat memakai invoice dan pembayaran di menu **Billing & invoice**.
