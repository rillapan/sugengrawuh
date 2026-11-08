<?php

namespace App\Http\Controllers;

use App\Models\Playstation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KategoriPermainanController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $kategori = Playstation::paginate(10);
        return view('kategori-permainan.index', [
            'title' => 'Kategori Permainan',
            'active' => 'kategori-permainan',
            'kategori_permainan' => $kategori,
            'plays' => $kategori, // Backwards-compatible alias
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('kategori-permainan.create', [
            'title' => 'Tambah Kategori Permainan',
            'active' => 'kategori-permainan',
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'nama' => 'required|min:3',
            'harga' => 'required|numeric',
        ], [
            'nama.required' => 'Nama permainan wajib diisi.',
            'nama.min' => 'Nama permainan minimal 3 karakter.',
            'harga.required' => 'Harga dasar kategori permainan wajib diisi.',
            'harga.numeric' => 'Harga dasar harus berupa angka.',
        ]);

        Playstation::create($validatedData);

        return redirect()->route('kategori-permainan.index')->with('success', 'Data kategori permainan berhasil ditambahkan.');
    }

    /**
     * Display the specified resource.
     *
     * @param  int|string|\App\Models\Playstation  $kategori_permainan
     * @return \Illuminate\Http\Response
     */
    public function show($kategori_permainan)
    {
        $kategori = $kategori_permainan instanceof Playstation
            ? $kategori_permainan
            : Playstation::findOrFail($kategori_permainan);

        return redirect()->route('kategori-permainan.edit', $kategori->id);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int|string|\App\Models\Playstation  $kategori_permainan
     * @return \Illuminate\Http\Response
     */
    public function edit($kategori_permainan)
    {
        $kategori = $kategori_permainan instanceof Playstation
            ? $kategori_permainan
            : Playstation::findOrFail($kategori_permainan);

        return view('kategori-permainan.edit', [
            'title' => 'Edit Kategori Permainan',
            'active' => 'kategori-permainan',
            'kategori_permainan' => $kategori,
            'play' => $kategori, // Backwards-compatible alias
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int|string|\App\Models\Playstation  $kategori_permainan
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $kategori_permainan)
    {
        $kategori = $kategori_permainan instanceof Playstation
            ? $kategori_permainan
            : Playstation::findOrFail($kategori_permainan);

        $validatedData = $request->validate([
            'nama' => 'required|min:3',
            'harga' => 'required|numeric',
        ], [
            'nama.required' => 'Nama permainan wajib diisi.',
            'nama.min' => 'Nama permainan minimal 3 karakter.',
            'harga.required' => 'Harga dasar kategori permainan wajib diisi.',
            'harga.numeric' => 'Harga dasar harus berupa angka.',
        ]);

        $kategori->update($validatedData);

        return redirect()->route('kategori-permainan.index')->with('success', 'Data kategori permainan berhasil diupdate.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int|string|\App\Models\Playstation  $kategori_permainan
     * @return \Illuminate\Http\Response
     */
    public function destroy($kategori_permainan)
    {
        $kategori = $kategori_permainan instanceof Playstation
            ? $kategori_permainan
            : Playstation::findOrFail($kategori_permainan);

        if ($kategori->image) {
            Storage::delete($kategori->image);
        }

        $kategori->delete();

        return redirect()->route('kategori-permainan.index')->with('success', 'Data kategori permainan berhasil dihapus.');
    }
}
