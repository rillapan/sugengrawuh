<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * DRAFT MIGRATION — GATE G1 (DO NOT EXECUTE ON PRODUCTION WITHOUT EXPLICIT APPROVAL)
 *
 * Tujuan:
 * 1. Mengonversi kolom 'harga' dan 'total' dari VARCHAR(255) ke DECIMAL(12, 2)
 *    secara aman tanpa kehilangan data desimal (.00) atau korupsi nilai.
 * 2. Menyiapkan kolom absolute timestamp ('started_at', 'expires_at', 'ended_at')
 *    sebagai persiapan arsitektur G2 (dibiarkan nullable, tanpa backfill massal).
 *
 * Safety Protocol:
 * - Tidak menggunakan naive REPLACE('.', '') yang berpotensi melipatgandakan nominal 100x
 *   pada baris berformat desimal (misal '10000.00' menjadi 1.000.000).
 * - Menggunakan dual-column staging (cast ke kolom sementara, validasi integritas, lalu swap).
 */
class HardenTransactionsAndFnbSchema extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('harga_numeric', 12, 2)->nullable()->after('harga');
            $table->decimal('total_numeric', 12, 2)->nullable()->after('total');
            $table->timestamp('started_at')->nullable()->after('waktu_Selesai');
            $table->timestamp('expires_at')->nullable()->after('started_at');
            $table->timestamp('ended_at')->nullable()->after('expires_at');
            $table->string('billing_status', 32)->nullable()->after('ended_at');

            $table->index(['billing_status', 'expires_at']);
            $table->index(['device_id', 'billing_status']);
        });

        // 2. Data Migration Staging: Konversi aman menggunakan CAST numerik MySQL
        // Kolom yang bernilai desimal (seperti '10000.00') dan integer (seperti '7000')
        // secara akurat dikonversi ke DECIMAL(12, 2) tanpa manipulasi string destruktif.
        DB::statement("
            UPDATE transactions 
            SET 
                harga_numeric = CAST(TRIM(REPLACE(harga, ' ', '')) AS DECIMAL(12, 2)),
                total_numeric = CAST(TRIM(REPLACE(total, ' ', '')) AS DECIMAL(12, 2))
            WHERE harga IS NOT NULL AND total IS NOT NULL
        ");

        // 3. Verifikasi Integritas: Pastikan tidak ada data yang NULL setelah staging
        $unconvertedCount = DB::table('transactions')
            ->whereNotNull('total')
            ->whereNull('total_numeric')
            ->count();

        if ($unconvertedCount > 0) {
            throw new \RuntimeException("Abort: Terdapat {$unconvertedCount} baris transaksi dengan format nominal tidak valid.");
        }

        // 4. Backfill aman data historis & proteksi sesi aktif:
        // A. Transaksi yang secara eksplisit sudah selesai (status_transaksi = 'selesai')
        // Ini adalah bukti valid bahwa sesi telah berakhir -> COMPLETED.
        DB::statement("
            UPDATE transactions 
            SET billing_status = 'COMPLETED'
            WHERE billing_status IS NULL 
              AND status_transaksi = 'selesai'
        ");

        // B. Rekonsiliasi transaksi legacy yang berpotensi masih berjalan pada station yang 'Digunakan'.
        // Aturan ketat:
        // - Hanya 1 transaksi terbaru per station yang boleh dipertimbangkan sebagai sesi berjalan.
        // - Transaksi sebelumnya pada station yang sama TIDAK boleh diaktifkan sebagai RUNNING.
        // - started_at harus valid.
        // - Untuk prepaid/custom package, expires_at harus dapat direkonstruksi secara aman dari durasi & waktu mulai.
        // - Untuk Lost Time (postpaid), expires_at dibiarkan NULL karena berakhir secara manual.
        // - Jika durasi atau waktu mulai tidak dapat dipastikan, pertahankan billing_status NULL (ambigu) untuk audit manual.
        // - Status pembayaran (payment_status) dan status perangkat (devices.status) TIDAK BOLEH diubah.
        $occupiedDevices = DB::table('devices')->where('status', 'Digunakan')->get();

        foreach ($occupiedDevices as $device) {
            // Ambil HANYA 1 transaksi terbaru yang berstatus 'berjalan' untuk station ini
            $latestTx = DB::table('transactions')
                ->where('device_id', $device->id)
                ->where('status_transaksi', 'berjalan')
                ->whereNull('billing_status')
                ->orderByDesc('id_transaksi')
                ->first();

            if (!$latestTx) {
                continue;
            }

            // 1. Validasi started_at secara aman
            $startedAt = null;
            if (!empty($latestTx->started_at)) {
                $startedAt = Carbon::parse($latestTx->started_at);
            } elseif (!empty($latestTx->lost_time_start)) {
                $startedAt = Carbon::parse($latestTx->lost_time_start);
            } elseif (!empty($latestTx->waktu_mulai) && !empty($latestTx->created_at)) {
                try {
                    $createdDate = Carbon::parse($latestTx->created_at)->toDateString();
                    $startedAt = Carbon::parse($createdDate . ' ' . $latestTx->waktu_mulai);
                } catch (\Throwable $e) {
                    $startedAt = null;
                }
            } elseif (!empty($latestTx->created_at)) {
                $startedAt = Carbon::parse($latestTx->created_at);
            }

            // Jika waktu mulai tidak dapat dipastikan, jangan mengarang nilai -> pertahankan NULL
            if (!$startedAt) {
                continue;
            }

            $tipeTransaksi = $latestTx->tipe_transaksi;
            $updatePayload = [];

            if ($tipeTransaksi === 'postpaid') {
                // Lost Time: started_at valid, expires_at NULL (berakhir manual), billing_status = RUNNING
                $updatePayload = [
                    'started_at' => $startedAt,
                    'expires_at' => null,
                    'billing_status' => 'RUNNING',
                ];
            } else {
                // Prepaid atau Custom Package: Rekonstruksi expires_at secara aman
                $expiresAt = null;

                if (!empty($latestTx->expires_at)) {
                    $expiresAt = Carbon::parse($latestTx->expires_at);
                } elseif (is_numeric($latestTx->jam_main) && (float) $latestTx->jam_main > 0) {
                    $jamMain = (float) $latestTx->jam_main;
                    // Jika > 24 kemungkinan durasi dalam menit (misal 60, 120), jika <= 24 dalam jam
                    $minutes = ($jamMain <= 24) ? ($jamMain * 60) : $jamMain;
                    $expiresAt = $startedAt->copy()->addMinutes(round($minutes));
                } elseif (!empty($latestTx->waktu_Selesai)) {
                    try {
                        $createdDate = $startedAt->toDateString();
                        $tentativeExpires = Carbon::parse($createdDate . ' ' . $latestTx->waktu_Selesai);
                        if ($tentativeExpires->lt($startedAt)) {
                            $tentativeExpires->addDay();
                        }
                        $expiresAt = $tentativeExpires;
                    } catch (\Throwable $e) {
                        $expiresAt = null;
                    }
                }

                // Jika durasi atau waktu selesai tidak dapat direkonstruksi, pertahankan NULL
                if (!$expiresAt) {
                    continue;
                }

                $updatePayload['started_at'] = $startedAt;
                $updatePayload['expires_at'] = $expiresAt;

                if ($expiresAt->isPast()) {
                    $updatePayload['billing_status'] = 'EXPIRED_PENDING_ACTION';
                    $updatePayload['ended_at'] = $expiresAt;
                } else {
                    $updatePayload['billing_status'] = 'RUNNING';
                }
            }

            if (!empty($updatePayload)) {
                DB::table('transactions')
                    ->where('id_transaksi', $latestTx->id_transaksi)
                    ->update($updatePayload);
            }
        }

        // Catatan: Transaksi historis ambigu (tidak selesai, bukan sesi aktif device)
        // secara sengaja DIBIARKAN dengan billing_status = NULL agar tidak ada asumsi keliru.
        // Asumsi DATE(created_at) < CURRENT_DATE() telah dihapus total.

        // Catatan: Swap kolom final (drop old column & rename staging)
        // hanya dieksekusi setelah verifikasi manual dan persetujuan Gate G1.
        /*
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['harga', 'total']);
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->renameColumn('harga_numeric', 'harga');
            $table->renameColumn('total_numeric', 'total');
        });
        */
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'billing_status')) {
                $table->dropIndex(['billing_status', 'expires_at']);
                $table->dropIndex(['device_id', 'billing_status']);
                $table->dropColumn('billing_status');
            }
            $table->dropColumn([
                'harga_numeric',
                'total_numeric',
                'started_at',
                'expires_at',
                'ended_at',
            ]);
        });
    }
}
