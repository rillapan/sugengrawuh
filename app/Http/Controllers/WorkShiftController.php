<?php

namespace App\Http\Controllers;

use App\Models\WorkShift;
use Illuminate\Http\Request;
use Carbon\Carbon;

class WorkShiftController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check_role:admin');
    }

    public function index()
    {
        $workShifts = WorkShift::orderBy('jam_mulai')->get();
        $active = 'work_shifts';
        $title = 'Manajemen Jam Kerja';
        return view('work_shift.index', compact('workShifts', 'active', 'title'));
    }

    public function create()
    {
        $active = 'work_shifts';
        $title = 'Tambah Jam Kerja';
        return view('work_shift.create', compact('active', 'title'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_shift' => 'required|string|max:255|unique:work_shifts,nama_shift',
            'jam_mulai' => 'required|date_format:H:i',
            'jam_selesai' => 'required|date_format:H:i',
            'keterangan' => 'nullable|string|max:500',
        ]);

        // Custom validation for shift time logic
        $this->validateShiftTime($request->jam_mulai, $request->jam_selesai);

        WorkShift::create($validated);

        return redirect()->route('work_shifts.index')->with('success', 'Jam kerja berhasil ditambahkan');
    }

    public function edit($id)
    {
        $workShift = WorkShift::findOrFail($id);
        $active = 'work_shifts';
        $title = 'Edit Jam Kerja';
        return view('work_shift.edit', compact('workShift', 'active', 'title'));
    }

    public function update(Request $request, $id)
    {
        $workShift = WorkShift::findOrFail($id);

        $validated = $request->validate([
            'nama_shift' => 'required|string|max:255|unique:work_shifts,nama_shift,'.$workShift->id,
            'jam_mulai' => 'required|date_format:H:i',
            'jam_selesai' => 'required|date_format:H:i',
            'keterangan' => 'nullable|string|max:500',
        ]);

        // Custom validation for shift time logic
        $this->validateShiftTime($request->jam_mulai, $request->jam_selesai);

        $workShift->update($validated);

        return redirect()->route('work_shifts.index')->with('success', 'Jam kerja berhasil diperbarui');
    }

    /**
     * Custom validation for shift time logic
     */
    private function validateShiftTime($jamMulai, $jamSelesai)
    {
        // Allow same time for 24-hour shifts (rare case)
        if ($jamMulai === $jamSelesai) {
            return;
        }

        // For overnight shifts, jam_selesai should be less than jam_mulai
        // For regular shifts, jam_selesai should be greater than jam_mulai
        $start = Carbon::parse($jamMulai);
        $end = Carbon::parse($jamSelesai);

        // This is a regular shift (not overnight)
        if ($end->greaterThan($start)) {
            // Minimum 1 hour duration for regular shifts
            if ($start->diffInHours($end) < 1) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'jam_selesai' => 'Durasi shift minimal 1 jam untuk shift reguler.'
                ]);
            }
        }
        // This is an overnight shift
        else {
            // Minimum 1 hour duration for overnight shifts
            $endNextDay = $end->addDay();
            if ($start->diffInHours($endNextDay) < 1) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'jam_selesai' => 'Durasi shift minimal 1 jam untuk shift overnight.'
                ]);
            }
        }
    }

    public function destroy($id)
    {
        $workShift = WorkShift::findOrFail($id);
        
        // Check if any users are assigned to this shift
        if ($workShift->users()->count() > 0) {
            return redirect()->route('work_shifts.index')
                ->with('error', 'Tidak dapat menghapus jam kerja yang sedang digunakan oleh kasir');
        }

        $workShift->delete();

        return redirect()->route('work_shifts.index')->with('success', 'Jam kerja berhasil dihapus');
    }
}
