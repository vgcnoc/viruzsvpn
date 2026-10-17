# Monitoring VPN

Menu **Monitoring VPN** hanya tersedia untuk administrator. Halaman meminta sesi langsung dari adapter server setiap 30 detik (dapat dijeda atau diperbarui manual) dan memadukannya dengan status akun tersimpan.

- **Status akun** (`active`, `suspended`, `expired`, dan lainnya) adalah hak akses/provisioning di database, bukan bukti bahwa client sedang tersambung.
- **Terhubung** berarti nama akun dan protokol cocok dengan sesi aktif yang baru dibaca dari server.
- **Tidak terhubung** berarti server berhasil dibaca tetapi tidak ada sesi yang cocok. Ini normal bila pelanggan sedang tidak menggunakan VPN.
- **Tidak diketahui** berarti sesi tidak dapat dibaca atau protokol belum memiliki telemetri. Jangan perlakukan sebagai offline.

Saat ini RouterOS melaporkan sesi PPP untuk L2TP/IPsec, SSTP, OpenVPN, dan PPTP melalui `/ppp/active/print`. WireGuard dan adapter lain tetap ditampilkan sebagai **Tidak diketahui** sampai telemetri handshake/peer yang dapat dipercaya ditambahkan; sistem tidak membuat angka online palsu.

Aksi administrator:

- **Putuskan sesi**: membaca ulang sesi dari RouterOS, memastikan ID sesi, username, dan protokol cocok dengan akun terkelola, lalu menghapus sesi itu saja. Akun tetap aktif dan client bisa menyambung lagi.
- **Tangguhkan / aktifkan**: memakai aksi akun yang sudah ada. Menangguhkan akun tidak otomatis memutus sesi yang terlanjur aktif; putuskan sesi tersebut jika perlu segera menghentikan koneksi.
- **Perpanjang**: memperbarui tanggal akhir. Akun yang ditangguhkan tetap ditangguhkan sampai diaktifkan secara terpisah.

Semua aksi perubahan memerlukan akses administrator. Aksi putus sesi dicatat di `operation_logs`. Halaman tidak menampilkan password VPN maupun kredensial RouterOS.
