<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Memeriksa apakah konfigurasi SMTP benar-benar bisa mengirim.
 *
 * VMS belum punya satu pun kelas Notification atau Mailable, jadi tanpa endpoint ini tidak ada cara sama sekali membuktikan kredensial SMTP-nya bekerja sebelum fitur email pertama dibangun.
 *
 * Pengiriman dilakukan SINKRON di dalam request, bukan lewat antrean. Itu
 * disengaja: pada 8 September 2026 endpoint uji SRP yang mengantre menjawab 200
 * dua kali sementara queue worker-nya crash-loop dan tidak satu pun surat
 * terkirim. 200 di sini berarti server SMTP menerima pesannya.
 */
final class MailTestController extends Controller
{
    /** POST /api/admin/mail-test */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email:rfc', 'max:255'],
        ]);

        $mailer = (string) config('mail.default');

        // 'log', 'array', dan 'null' sama-sama "berhasil" tanpa mengirim apa
        // pun. Membiarkannya lolos membuat pemeriksaan ini memberi rasa aman
        // palsu -- persis hal yang ia ada untuk menghapusnya.
        if (in_array($mailer, ['log', 'array', 'null'], true)) {
            return response()->json([
                'message' => "Mailer aktif adalah '{$mailer}', jadi tidak ada surat yang benar-benar dikirim.",
            ], 422);
        }

        try {
            Mail::raw(
                'Pemeriksaan konfigurasi SMTP dari '.config('app.name').' di '.config('app.url').".\n"
                .'Waktu: '.now()->toDateTimeString()." UTC\n\n"
                .'Kalau surat ini sampai, kredensial SMTP dan jalur keluar dari App Service keduanya bekerja.',
                static function ($message) use ($data) {
                    $message->to($data['to'])
                        ->subject('['.config('app.name').'] Uji konfigurasi email');
                }
            );
        } catch (Throwable $e) {
            // Pesan galat SMTP menyebut host, kode respons, dan kegagalan
            // autentikasi -- tidak ada data pengguna di dalamnya, jadi aman
            // dikembalikan ke admin yang memang sedang mendiagnosis.
            return response()->json(['message' => 'Pengiriman gagal: '.$e->getMessage()], 502);
        }

        return response()->json([
            'message' => 'Server SMTP menerima surat untuk '.$data['to'].'.',
            'to' => $data['to'],
            'mailer' => $mailer,
        ]);
    }
}
