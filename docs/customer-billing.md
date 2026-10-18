# Alur pelanggan dan billing virVPN

1. Administrator menguji koneksi server di menu **Servers**. Hanya server RouterOS nyata yang online dan layanan SSTP/L2TP-IPsec yang terdeteksi aktif dapat ditawarkan ke pelanggan. L2TP/IPsec memerlukan `use-ipsec=required` serta pre-shared key yang disimpan saat edit server. OpenVPN dan PPTP dapat diprovisioning manual oleh admin bila aktif. WireGuard MikroTik belum didukung untuk pembuatan peer otomatis; WireGuard Linux-agent memerlukan public key dan IP client.
2. Administrator membuat paket di **Paket VPN**: harga dalam rupiah, masa aktif, batas koneksi, protokol, dan server. Paket nonaktif tidak tampil di katalog pelanggan.
3. Administrator mengisi rekening penerima di **Billing & invoice**. Rekening disalin ke setiap invoice saat dibuat agar perubahan rekening berikutnya tidak mengubah instruksi pada invoice lama.
4. Pelanggan mendaftar dan memverifikasi email, masuk, memilih paket/server/protokol, lalu membuat invoice. Password VPN disimpan terenkripsi sampai akun berhasil dibuat pada server; pelanggan harus menyimpan password itu sendiri.
5. Pelanggan mentransfer jumlah invoice dan mengirim nomor referensi transfer. Ini adalah klaim pelanggan, bukan bukti pembayaran otomatis. Administrator wajib mencocokkannya dengan mutasi rekening sebelum menekan **Konfirmasi lunas**.
6. Setelah pembayaran disetujui, worker antrean membuat akun VPN. Masa sewa dimulai ketika provisioning berhasil. Jika gagal, admin dapat mencoba ulang dari menu **Akun VPN** dan masa sewa belum dimulai.
7. Pelanggan melihat host, username, protokol, status, dan masa aktif pada **Layanan saya**. Untuk L2TP/IPsec, pre-shared key yang dikonfigurasi administrator ditampilkan kepada pemilik layanan dan berbeda dari password VPN pelanggan.
8. Pelanggan dapat membuat invoice perpanjangan untuk layanan aktif. Pembayaran yang dikonfirmasi admin menambah masa aktif akun yang sama.

## Syarat operasi produksi

- Konfigurasikan SMTP atau transport email yang benar di `backend/.env`; `MAIL_MAILER=log` tidak mengirim tautan verifikasi. Uji pendaftaran menggunakan alamat email nyata sebelum membuka penjualan.
- Jalankan worker antrean Laravel secara terus-menerus agar akun berbayar benar-benar diprovisioning.
- Jalankan `php artisan schedule:run` setiap menit. Contoh cron produksi ada di `deploy/viruzsvpn.cron`. Scheduler menutup invoice belum dibayar yang sudah lewat 3 hari dan menghapus password VPN sementaranya; scheduler yang sama menangani kedaluwarsa layanan.
- Metode pembayaran saat ini transfer manual. Aplikasi tidak mengklaim transfer berhasil hanya berdasarkan nomor referensi yang dikirim pelanggan.
- Akun admin yang dibuat langsung melalui menu **Akun VPN** tidak membuat invoice dan tidak otomatis menjadi milik pelanggan. Alur pelanggan yang tercatat memakai invoice dan pembayaran di menu **Billing & invoice**.

## Pembuatan akun VPN manual oleh admin

1. Di **Servers**, klik **Test** pada server yang akan dipakai. Status harus online.
2. Di **Akun VPN**, klik **Buat akun VPN**, lalu pilih server. Form memeriksa layanan aktif melalui adapter server dan hanya menampilkan protokol yang benar-benar bisa dibuat. Pada RouterOS, WireGuard/PPP generik tidak ditawarkan karena driver tidak membuat peer WireGuard atau mengaktifkan layanan PPP generik.
3. Isi username unik. Untuk RouterOS, pilih SSTP/L2TP-IPsec/OpenVPN/PPTP yang aktif dan isi password VPN; simpan password sebelum mengirim form karena tidak dapat dibaca kembali setelah akun berhasil dibuat. L2TP/IPsec juga memerlukan pre-shared key server yang tersimpan.
4. Pilih paket bila ingin masa aktif mengikuti paket, atau gunakan **Tanpa paket / manual** dan isi masa aktif hari. Pembuatan manual tidak menerima pembayaran dan tidak membuat invoice. Masa aktif paket manual dimulai setelah provisioning berhasil.
5. Lihat status di daftar **Akun VPN** dan klik **Detail** untuk hasil worker atau alasan kegagalan. Status pending/provisioning diperbarui otomatis; akun gagal dapat dicoba ulang tanpa mengganti username.

## Menghubungkan MikroTik client ke akun SSTP

- Untuk akun SSTP aktif di `vpn.viruzs.my.id`, buka **Akun VPN → Detail → Generate script MikroTik client**. Pelanggan dengan layanan SSTP aktif juga melihat tombol **Script MikroTik** di **Layanan saya**.
- Masukkan ulang password VPN yang dipilih saat akun dibuat, kemudian klik **Salin script lengkap**. Password hanya berada di memori browser dan clipboard selama proses ini; aplikasi tidak dapat mengambilnya lagi dari CHR. Jangan membagikan script yang sudah disalin.
- Paste di terminal **router MikroTik client**, bukan di CHR server. CA publik tertanam dalam script sehingga router tidak perlu mengambilnya dari internet. Script menulis file CA di router, mengimpor dan memverifikasi fingerprint SHA-256, lalu membuat atau memperbarui SSTP client dengan verifikasi sertifikat dan hostname. Pesan `virVPN [1/3]`, `[2/3]`, dan `[3/3]` menunjukkan tahap yang dicapai; jika gagal, salin teks error Terminal tanpa password untuk diagnosis. Router tetap memerlukan DNS dan waktu yang benar serta akses TCP 443 ke server VPN.
- Script tidak menambah default route, NAT, atau mengubah koneksi internet router. Periksa tanda `R` di **Interfaces → SSTP Client** atau hasil `/interface sstp-client print detail`. Jika belum berjalan, periksa `/log print where topics~"sstp"`. Routing jaringan di balik VPN diatur terpisah sesuai kebutuhan.
- CA ini adalah sertifikat publik untuk CHR Sukabumi BC1, bukan private key. Masa berlakunya sampai Oktober 2027; bila CA server dirotasi, berkas publik dan fingerprint dalam generator harus diperbarui bersamaan.
