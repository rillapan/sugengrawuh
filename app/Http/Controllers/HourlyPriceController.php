<?php

namespace App\Http\Controllers;

use App\Models\HourlyPrice;
use App\Models\Playstation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HourlyPriceController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param  int  $playstationId
     * @return \Illuminate\Http\Response
     */
    public function index($playstationId)
    {
        $playstation = Playstation::findOrFail($playstationId);
        $hourlyPrices = $playstation->hourlyPrices()
            ->orderByRaw('COALESCE(duration_seconds, hour * 3600) ASC')
            ->get();

        return view('playstation.hourly-prices.index', [
            'title' => 'Harga per Jam - ' . $playstation->nama,
            'active' => 'play',
            'playstation' => $playstation,
            'hourlyPrices' => $hourlyPrices
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @param  int  $playstationId
     * @return \Illuminate\Http\Response
     */
    public function create($playstationId)
    {
        $playstation = Playstation::findOrFail($playstationId);

        return view('playstation.hourly-prices.create', [
            'title' => 'Tambah Harga per Jam - ' . $playstation->nama,
            'active' => 'play',
            'playstation' => $playstation
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $playstationId
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $playstationId)
    {
        $playstation = Playstation::findOrFail($playstationId);

        $hours = $request->filled('hours') ? (int) $request->hours : ($request->filled('hour') ? (int) $request->hour : 0);
        $minutes = $request->filled('minutes') ? (int) $request->minutes : 0;
        $seconds = $request->filled('seconds') ? (int) $request->seconds : 0;
        $totalSeconds = HourlyPrice::toTotalSeconds($hours, $minutes, $seconds);

        $request->merge([
            'hours' => $hours,
            'minutes' => $minutes,
            'seconds' => $seconds,
            'total_seconds' => $totalSeconds,
        ]);

        $request->validate([
            'hours' => 'required|integer|min:0|max:24',
            'minutes' => 'required|integer|min:0|max:59',
            'seconds' => 'required|integer|min:0|max:59',
            'total_seconds' => [
                'required',
                'integer',
                'min:1',
                'max:86400',
                Rule::unique('hourly_prices', 'duration_seconds')->where('playstation_id', $playstationId),
            ],
            'price' => 'required|integer|min:0',
        ], [
            'hours.min' => 'Jam tidak boleh negatif.',
            'hours.max' => 'Jam maksimal 24.',
            'minutes.min' => 'Menit harus antara 0 dan 59.',
            'minutes.max' => 'Menit harus antara 0 dan 59.',
            'seconds.min' => 'Detik harus antara 0 dan 59.',
            'seconds.max' => 'Detik harus antara 0 dan 59.',
            'total_seconds.min' => 'Durasi total harus lebih dari 0 detik.',
            'total_seconds.max' => 'Durasi total maksimal 24 jam (86.400 detik).',
            'total_seconds.unique' => 'Tarif untuk durasi tersebut sudah terdaftar pada kategori ini.',
            'price.required' => 'Harga wajib diisi.',
            'price.min' => 'Harga tidak boleh negatif.',
        ]);

        $hourEquivalent = ($totalSeconds % 3600 === 0) ? intdiv($totalSeconds, 3600) : null;

        HourlyPrice::create([
            'playstation_id' => $playstationId,
            'hour' => $hourEquivalent,
            'duration_seconds' => $totalSeconds,
            'price' => (int) $request->price,
        ]);

        return redirect()->route('hourly-prices.hourly-prices.index', $playstationId)
            ->with('success', 'Harga per jam berhasil ditambahkan.');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $playstationId
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($playstationId, $id)
    {
        $playstation = Playstation::findOrFail($playstationId);
        $hourlyPrice = HourlyPrice::where('id', $id)->where('playstation_id', $playstationId)->firstOrFail();

        $parts = HourlyPrice::secondsToParts($hourlyPrice->effective_duration_seconds);

        return view('playstation.hourly-prices.edit', [
            'title' => 'Edit Harga per Jam - ' . $playstation->nama,
            'active' => 'play',
            'playstation' => $playstation,
            'hourlyPrice' => $hourlyPrice,
            'durationParts' => $parts,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $playstationId
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $playstationId, $id)
    {
        $hourlyPrice = HourlyPrice::where('id', $id)->where('playstation_id', $playstationId)->firstOrFail();

        $hours = $request->filled('hours') ? (int) $request->hours : ($request->filled('hour') ? (int) $request->hour : 0);
        $minutes = $request->filled('minutes') ? (int) $request->minutes : 0;
        $seconds = $request->filled('seconds') ? (int) $request->seconds : 0;
        $totalSeconds = HourlyPrice::toTotalSeconds($hours, $minutes, $seconds);

        $request->merge([
            'hours' => $hours,
            'minutes' => $minutes,
            'seconds' => $seconds,
            'total_seconds' => $totalSeconds,
        ]);

        $request->validate([
            'hours' => 'required|integer|min:0|max:24',
            'minutes' => 'required|integer|min:0|max:59',
            'seconds' => 'required|integer|min:0|max:59',
            'total_seconds' => [
                'required',
                'integer',
                'min:1',
                'max:86400',
                Rule::unique('hourly_prices', 'duration_seconds')
                    ->where('playstation_id', $playstationId)
                    ->ignore($id),
            ],
            'price' => 'required|integer|min:0',
        ], [
            'hours.min' => 'Jam tidak boleh negatif.',
            'hours.max' => 'Jam maksimal 24.',
            'minutes.min' => 'Menit harus antara 0 dan 59.',
            'minutes.max' => 'Menit harus antara 0 dan 59.',
            'seconds.min' => 'Detik harus antara 0 dan 59.',
            'seconds.max' => 'Detik harus antara 0 dan 59.',
            'total_seconds.min' => 'Durasi total harus lebih dari 0 detik.',
            'total_seconds.max' => 'Durasi total maksimal 24 jam (86.400 detik).',
            'total_seconds.unique' => 'Tarif untuk durasi tersebut sudah terdaftar pada kategori ini.',
            'price.required' => 'Harga wajib diisi.',
            'price.min' => 'Harga tidak boleh negatif.',
        ]);

        $hourEquivalent = ($totalSeconds % 3600 === 0) ? intdiv($totalSeconds, 3600) : null;

        $hourlyPrice->update([
            'hour' => $hourEquivalent,
            'duration_seconds' => $totalSeconds,
            'price' => (int) $request->price,
        ]);

        return redirect()->route('hourly-prices.hourly-prices.index', $playstationId)
            ->with('success', 'Harga per jam berhasil diupdate.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $playstationId
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($playstationId, $id)
    {
        $hourlyPrice = HourlyPrice::where('id', $id)->where('playstation_id', $playstationId)->firstOrFail();
        $hourlyPrice->delete();

        return redirect()->route('hourly-prices.hourly-prices.index', $playstationId)
            ->with('success', 'Harga per jam berhasil dihapus.');
    }
}
