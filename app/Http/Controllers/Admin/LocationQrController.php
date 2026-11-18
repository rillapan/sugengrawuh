<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Location;
use App\Services\Qr\QrCodeSvgService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class LocationQrController extends Controller
{
    protected QrCodeSvgService $qrCodeSvgService;

    public function __construct(QrCodeSvgService $qrCodeSvgService)
    {
        $this->qrCodeSvgService = $qrCodeSvgService;
    }

    /**
     * List all locations with device mappings and inline offline QR SVG previews.
     */
    public function index()
    {
        $locations = Location::with('device')->orderBy('id', 'asc')->get();
        $devices = Device::orderBy('nama', 'asc')->get();

        foreach ($locations as $loc) {
            $stationUrl = route('public.qr.station', $loc->code);
            $loc->qr_svg = $this->qrCodeSvgService->generateSvg($stationUrl, 100);
            $loc->station_url = $stationUrl;
        }

        return view('admin.locations.index', [
            'locations' => $locations,
            'devices' => $devices,
            'active' => 'locations',
        ]);
    }

    /**
     * Store a newly created location.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50|unique:locations,code',
            'type' => 'required|in:station,dining_table,waiting_area,takeaway',
            'device_id' => 'nullable|exists:devices,id',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['is_active'] = $request->has('is_active') ? (bool) $request->input('is_active') : true;

        Location::create($validated);

        return redirect()->route('admin.locations.index')
            ->with('success', 'Lokasi berhasil ditambahkan.');
    }

    /**
     * Update the specified location.
     */
    public function update(Request $request, $id)
    {
        $location = Location::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50|unique:locations,code,' . $id,
            'type' => 'required|in:station,dining_table,waiting_area,takeaway',
            'device_id' => 'nullable|exists:devices,id',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['is_active'] = $request->has('is_active') ? (bool) $request->input('is_active') : false;

        $location->update($validated);

        return redirect()->route('admin.locations.index')
            ->with('success', 'Lokasi berhasil diperbarui.');
    }

    /**
     * Remove the specified location from storage.
     */
    public function destroy($id)
    {
        $location = Location::findOrFail($id);

        if ($location->fnbOrders()->exists()) {
            return redirect()->route('admin.locations.index')
                ->with('error', 'Lokasi tidak dapat dihapus karena masih memiliki riwayat pesanan FnB.');
        }

        try {
            $location->delete();
        } catch (QueryException $e) {
            return redirect()->route('admin.locations.index')
                ->with('error', 'Lokasi tidak dapat dihapus karena masih terhubung dengan data lain.');
        }

        return redirect()->route('admin.locations.index')
            ->with('success', 'Lokasi berhasil dihapus.');
    }

    /**
     * Printable QR sticker view for physical placement at station/table.
     */
    public function print($id)
    {
        $location = Location::with('device')->findOrFail($id);
        $url = route('public.qr.station', $location->code);
        $qrSvg = $this->qrCodeSvgService->generateSvg($url, 280);

        return view('admin.locations.print_qr', [
            'location' => $location,
            'url' => $url,
            'qrSvg' => $qrSvg,
        ]);
    }
}
