<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransactionsExport implements FromView, ShouldAutoSize, WithHeadings, WithStyles
{
    protected $transactions;
    protected $startDate;
    protected $endDate;
    protected $total;
    protected $dailyTotals;
    protected $standaloneInvoices;

    public function __construct($transactions, $startDate, $endDate, $total, $dailyTotals, $standaloneInvoices = null)
    {
        $this->transactions = $transactions;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->total = $total;
        $this->dailyTotals = $dailyTotals;
        $this->standaloneInvoices = $standaloneInvoices ?? collect();
    }

    public function view(): View
    {
        return view('laporan.cetak-excel', [
            'transactions' => $this->transactions,
            'standaloneInvoices' => $this->standaloneInvoices,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'total' => $this->total,
            'dailyTotals' => $this->dailyTotals,
        ]);
    }

    public function headings(): array
    {
        return [
            'ID Transaksi',
            'Nama',
            'Data Perangkat',
            'Tipe',
            'Jam Main',
            'Waktu Mulai',
            'Waktu Selesai',
            'FnB',
            'Total',
            'Diskon',
            'Total Setelah Diskon',
            'Tanggal',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $rowCount = $sheet->getHighestRow();

        $styleArray = [
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['argb' => '000000'],
                ],
            ],
        ];

        // Apply borders to all 12 columns (A-L)
        for ($row = 4; $row <= $rowCount; $row++) {
            foreach (range('A', 'L') as $column) {
                $sheet->getStyle($column . $row . ':' . $column . $row)->applyFromArray($styleArray);
            }
        }
    }
}
