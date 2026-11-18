<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\TransactionFnb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AppSettingsController extends Controller
{
    /**
     * Tampilkan halaman pengaturan aplikasi (khusus admin)
     */
    public function index()
    {
        return view('settings.index', [
            'title' => 'Pengaturan Aplikasi',
            'active' => 'settings',
            'appName' => 'Sistem Kasir rental playstation',
            'appVersion' => '01',
            'tools' => 'laravel 12, php 82'
        ]);
    }

    /**
     * Reset data transaksi dan laporan penjualan secara aman dan bersih.
     * Hanya menghapus data transaksi & invoice penjualan, tanpa menghapus
     * data master (users, devices, physical devices, fnbs, price groups, members).
     */
    public function resetTransactions(Request $request)
    {
        $request->validate([
            'confirm_delete' => 'accepted',
            'password' => 'required',
        ], [
            'confirm_delete.accepted' => 'Anda harus mencentang persetujuan reset transaksi.',
            'password.required' => 'Masukkan password akun admin Anda untuk konfirmasi.',
        ]);

        // Verifikasi password admin yang sedang login
        if (!Hash::check($request->password, auth()->user()->password)) {
            return redirect()->route('settings.index')
                ->with('gagal', 'Password konfirmasi salah. Reset transaksi dibatalkan.');
        }

        try {
            DB::transaction(function () {
                // 1. Hapus invoice_items terlebih dahulu (child dari invoices dan fk ke fnb_order_items/transaction_fnbs)
                if (Schema::hasTable('invoice_items')) {
                    InvoiceItem::query()->delete();
                }

                // 2. Hapus invoices (child dari transactions dan fk cashier_user_id)
                if (Schema::hasTable('invoices')) {
                    Invoice::query()->delete();
                }

                // 3. Hapus fnb_order_items (child dari fnb_orders dan fk ke fnbs)
                if (Schema::hasTable('fnb_order_items')) {
                    FnbOrderItem::query()->delete();
                }

                // 4. Hapus fnb_orders (child dari transactions dan locations)
                if (Schema::hasTable('fnb_orders')) {
                    FnbOrder::query()->delete();
                }

                // 5. Hapus detail FnB transaksi lama
                if (Schema::hasTable('transaction_fnbs')) {
                    TransactionFnb::query()->delete();
                }

                // 6. Bersihkan riwayat mutasi stok dari penjualan (pertahankan mutasi manual in/out stock opname)
                if (Schema::hasTable('stock_mutations')) {
                    StockMutation::where('note', 'like', 'Penjualan%')
                        ->orWhere('note', 'like', 'Refund%')
                        ->delete();
                }

                // 7. Bersihkan antrean automation & tv alerts jika ada relasi transaksi
                if (Schema::hasTable('automation_intents')) {
                    DB::table('automation_intents')->whereNotNull('transaction_id')->delete();
                }
                if (Schema::hasTable('automation_execution_logs')) {
                    DB::table('automation_execution_logs')->whereNotNull('transaction_id')->delete();
                }
                if (Schema::hasTable('tv_control_events')) {
                    DB::table('tv_control_events')->whereNotNull('transaction_id')->delete();
                }

                // 8. Hapus seluruh data transaksi rental
                Transaction::query()->delete();

                // 9. Reset status seluruh stasiun perangkat menjadi 'Tersedia'
                Device::query()->update(['status' => 'Tersedia']);
            });

            Log::info('Data transaksi dan laporan penjualan berhasil direset oleh admin.', [
                'admin_id' => auth()->id(),
                'admin_email' => auth()->user()->email ?? '',
                'ip' => $request->ip(),
            ]);

            return redirect()->route('settings.index')
                ->with('success', 'Seluruh data transaksi dan laporan penjualan berhasil direset. Seluruh perangkat kini berstatus Tersedia.');

        } catch (\Throwable $e) {
            Log::error('Gagal mereset data transaksi: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('settings.index')
                ->with('gagal', 'Terjadi kesalahan saat mereset data transaksi: ' . $e->getMessage());
        }
    }
}
