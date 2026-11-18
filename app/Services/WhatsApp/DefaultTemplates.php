<?php

namespace App\Services\WhatsApp;

/** Template pesan bawaan S.NET (dipakai seeder & bila template di DB belum ada). */
final class DefaultTemplates
{
    /** @var array<string, array{name: string, message: string}> */
    public const ALL = [
        'welcome_customer' => [
            'name' => 'Pemberitahuan Pelanggan Baru & Akses Portal',
            'message' => "Halo Kak {nama}, Selamat Datang di {company_name}! 🎉\n\nLayanan internet PPPoE Anda telah aktif. Berikut adalah rincian akun dan akses Portal Pelanggan Anda:\n\n🌐 *Detail Layanan:*\n• Nama: {nama}\n• Paket: {paket}\n• Jatuh Tempo: Tgl {jatuh_tempo} setiap bulan\n• Biaya Bulanan: {tagihan}\n\n🔑 *Akses Portal Pelanggan:*\nAnda dapat mengganti nama & sandi WiFi sendiri, mengecek tagihan, serta bayar bulanan secara online di:\n• Link Portal: {link_portal}\n• Username: *{portal_username}*\n• Password: *{portal_password}*\n\nSimpan informasi ini dengan baik. Jika butuh bantuan, hubungi kami: {cs_phone}. Terima kasih! 🙏",
        ],
        'reminder_h3' => [
            'name' => 'Pengingat Tagihan (H-3 Jatuh Tempo)',
            'message' => "Halo Kak {nama} ({username}),\n\nKami informasikan bahwa tagihan internet {company_name} untuk bulan {bulan} sebesar *{tagihan}* akan jatuh tempo pada *{jatuh_tempo}*.\n\nMohon lakukan pembayaran tepat waktu agar kenyamanan berinternet tetap terjaga.\n\nPortal & Bayar Online: {link_portal}\nTerima kasih atas kerja samanya. 🙏",
        ],
        'reminder_h1' => [
            'name' => 'Pengingat Tagihan (H-1 Jatuh Tempo)',
            'message' => "Halo Kak {nama},\n\nTagihan internet {company_name} Anda sebesar *{tagihan}* akan jatuh tempo *BESOK ({jatuh_tempo})*.\n\nUntuk menghindari gangguan / isolir otomatis oleh sistem, silakan melakukan pembayaran melalui transfer atau portal online:\n{link_portal}\n\nTerima kasih! 🙏",
        ],
        'reminder_h0' => [
            'name' => 'Pemberitahuan Hari Jatuh Tempo (Hari H)',
            'message' => "Yth. Pelanggan {company_name},\nKak {nama} ({username})\n\nHari ini adalah batas tanggal jatuh tempo pembayaran tagihan internet Anda sebesar *{tagihan}*.\n\nSilakan segera selesaikan pembayaran hari ini. Bayar mudah via QRIS/VA melalui portal:\n{link_portal}\n\nTerima kasih atas perhatiannya. 😊",
        ],
        'isolir' => [
            'name' => 'Pemberitahuan Layanan Terisolir',
            'message' => "Pemberitahuan: Layanan Internet Terisolir ⚠️\n\nYth. Kak {nama} ({username}),\nLayanan internet {company_name} Anda saat ini telah dinonaktifkan sementara karena melewati batas waktu jatuh tempo.\n\nTotal Tunggakan: *{tagihan}*\n\nAgar koneksi aktif kembali secara otomatis dalam hitungan detik, silakan bayar sekarang melalui tautan berikut:\n{link_portal}\n\nButuh bantuan? Hubungi WhatsApp CS kami: {cs_phone}",
        ],
        'payment_success' => [
            'name' => 'Konfirmasi Pembayaran Lunas',
            'message' => "Terima Kasih! Pembayaran Berhasil ✅\n\nYth. Kak {nama},\nPembayaran tagihan internet {company_name} bulan {bulan} sebesar *{tagihan}* telah kami terima pada {waktu_bayar}.\n\nNo. Kwitansi: #{no_invoice}\nMetode: *{metode}*\nPenerima: {diterima_oleh}\nStatus: *LUNAS*\nKoneksi internet Anda aktif dan siap digunakan.\n\nLihat Kwitansi Digital: {link_receipt}\nTerima kasih telah setia bersama {company_name}! ✨",
        ],
    ];
}
