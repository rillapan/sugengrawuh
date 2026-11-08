<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\TransactionFnb;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * Reset transaksi telah dinonaktifkan demi integritas data keuangan dan kepatuhan audit.
     */
    public function resetTransactions(Request $request)
    {
        \Illuminate\Support\Facades\Log::warning('Percobaan eksekusi reset data transaksi ditolak.', [
            'user_id' => auth()->id(),
            'ip' => $request->ip()
        ]);

        return redirect()->route('settings.index')->with('gagal', 'Operasi reset data transaksi telah dinonaktifkan demi integritas data dan keamanan operasional.');
    }
}
