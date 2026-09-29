# Panduan Staff Orlena Dashboard

Tampilan dashboard memakai bahasa Inggris, sama seperti website. Nama menu dan tombol di panduan ini ditulis sesuai tampilan.

Login di `/admin/login` dengan **username** dan kata sandi. Ganti nama, username, email, dan kata sandi sendiri di **My profile**.

## Hak akses per peran

| Menu | Admin | Staff | Finance | Content Editor |
|---|:-:|:-:|:-:|:-:|
| Orders, Payments, Customers | ubah | ubah | lihat | – |
| Reports (Print, PDF, Excel) | ✓ | – | ✓ | – |
| Products, Hampers, Categories, Outlets | ubah | lihat | – | – |
| PO schedule | ✓ | ✓ | – | – |
| Website content, Blog | ✓ | – | – | ✓ |
| Erzap integration, Team accounts | ✓ | – | – | – |

## Alur pesanan harian

1. **Pesanan masuk** berstatus *Awaiting review*. Buka **Orders**, lalu klik **View**.
2. **Periksa** produk, tanggal, ketersediaan, dan kapasitas produksi. Bila tanggal PO penuh atau tutup, muncul peringatan merah di atas pesanan.
3. **Delivery**: isi ongkir Gojek/Grab di form pemeriksaan. Jadwal bisa diubah bila sudah disepakati dengan pelanggan.
4. Klik **Confirm & Create Payment**. Link pembayaran DOKU dibuat (berlaku 24 jam, paling lambat batas pemesanan H-1). Kirim link ke pelanggan dengan tombol **Send via WhatsApp** atau **Copy payment link**.
5. Setelah pelanggan membayar, status berubah otomatis menjadi **Paid**. Bila belum berubah, klik **Check status in DOKU**.
6. Lanjutkan **Start processing → Mark as ready → Mark as out for delivery / completed**.
7. Link kedaluwarsa atau gagal? Buat link baru dengan alasan. Batal? Isi alasan pembatalan. Pesanan yang sudah dibayar hanya bisa dibatalkan Admin, dan refund dilakukan manual di DOKU Back Office. Membatalkan pesanan juga menutup link DOKU untuk transfer bank (VA) dan QRIS; untuk metode lain, pantau apakah pelanggan tetap membayar.

Pelanggan masih bisa **menambah produk** sendiri selama pesanan belum dikonfirmasi dan belum lewat batas pemesanan. Tambahan tampil di bagian **Customer additions**.

## Produk, varian, dan hampers

- **Varian**: produk dengan nama sama dalam satu kategori tampil sebagai satu produk dengan pilihan varian, misalnya Fullsize/Halfsize atau Box isi 6. Isi kolom *Variant* di setiap produk.
- **Hampers**: dari menu **Hampers** klik **Add hampers** (centang *Hampers product* sudah aktif) lalu tulis isi paket, satu per baris. Buat kategori "Hampers" agar mudah ditemukan pelanggan. Menu **Hampers** menampilkan daftar hampers saja.
- **Hampers per event**: biarkan hampers nonaktif di luar event. Saat event dibuka, aktifkan dan isi *On sale from/until*; setelah tanggal berakhir hampers otomatis hilang dari form order. Hampers juga bisa diimport dari file Excel (kolom *Hampers* = Ya dan *Isi Hampers*).
- **Kartu ucapan**: bila keranjang berisi hampers, pelanggan dapat mengisi pesan kartu ucapan (opsional, maksimal 300 karakter). Pesan tampil di detail pesanan (*Greeting card*), pesan WhatsApp, dan catatan pesanan di Erzap.
- **Periode penjualan**: isi *On sale from/until* untuk produk event. Di luar periode itu produk tidak tampil di form order.
- Produk harus memiliki harga sebelum diaktifkan. Stok tidak dikelola di website (hanya di Erzap).
- **Import from Erzap** (menu Products): export daftar produk dari Erzap (.xlsx/.csv), upload, cek preview, lalu **Apply**. Yang diperbarui hanya nama, harga jual, barcode, dan kode produk. Foto, deskripsi, kategori, varian, status aktif, isi hampers, dan periode penjualan **tidak pernah diubah**, jadi foto yang sudah diupload tetap aman saat import ulang. Produk baru masuk dalam keadaan **nonaktif**: upload foto dulu, lalu aktifkan.

## PO schedule

- **Order deadline** (batas pemesanan): jam terakhir memesan untuk suatu tanggal, sehari sebelumnya (H-1, default 18.00 WITA).
- **Closed every week** dan **Closed dates** (libur/acara): pelanggan tidak bisa memilih tanggal tersebut. Pesanan yang sudah ada tidak berubah.
- **Orders per day capacity**: hanya peringatan untuk staff, tidak menolak pesanan.

## Reports (Admin, Finance)

Pilih periode (atau Today, 7 days, This month, dan seterusnya), lalu outlet, produk, dan status. Pendapatan dihitung dari pesanan **lunas** berdasarkan waktu pembayaran. Pesanan yang dibatalkan atau di-refund setelah bayar ditampilkan terpisah. Klik **Print**, **PDF**, atau **Excel**. File Excel berisi semua produk terlaris.

## Customers dan Payments

- **Customers** dikelompokkan per nomor WhatsApp: jumlah pesanan, total belanja lunas, riwayat, dan produk favorit.
- **Payments** menampilkan semua link pembayaran DOKU beserta statusnya. Perubahan tetap dilakukan dari halaman pesanan.

## Erzap integration (Admin)

Setelah Erzap aktif, setiap pesanan lunas dikirim otomatis ke Erzap. Isi **Mapping**: ID outlet Erzap per outlet dan **barcode Erzap untuk setiap produk** (termasuk hampers), karena Erzap mencocokkan item berdasarkan barcode. Pesanan yang dibatalkan setelah bayar dikoreksi manual di Erzap. Status *Needs mapping* berarti ada outlet atau produk yang belum diisi. Status *Failed* dicoba ulang otomatis, atau klik **Resend**.
