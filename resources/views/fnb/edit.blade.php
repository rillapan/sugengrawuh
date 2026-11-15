@extends('layouts.app')

@section('content')
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">{{ $title }}</h1>
    </div>
    <div class="col-md-12 col-lg-6">
        <form method="POST" action="{{ route('fnb.update', $fnb->id) }}" enctype="multipart/form-data">
            @method('put')
            @csrf
            <div class="mb-3">
                <label for="nama" class="form-label">Nama Barang</label>
                <input type="text" class="form-control @error('nama') is-invalid @enderror" id="nama"
                    name="nama" value="{{ old('nama', $fnb->nama) }}" required>
                @error('nama')
                    <div class="invalid-feedback">
                        {{ $message }}
                    </div>
                @enderror
            </div>
            <div class="mb-3">
                <label for="price_group_id" class="form-label">Kelompok Harga</label>
                @if($priceGroups->count() > 0)
                    <select class="form-control @error('price_group_id') is-invalid @enderror" id="price_group_id"
                        name="price_group_id" required>
                        <option value="">Pilih Kelompok Harga</option>
                        @foreach ($priceGroups as $priceGroup)
                            <option value="{{ $priceGroup->id }}" {{ old('price_group_id', $fnb->price_group_id) == $priceGroup->id ? 'selected' : '' }}>
                                {{ $priceGroup->nama }} - Rp {{ number_format($priceGroup->harga, 0, ',', '.') }}
                            </option>
                        @endforeach
                    </select>
                @else
                    <select class="form-control" disabled>
                        <option value="">Belum ada kelompok harga</option>
                    </select>
                    <small class="form-text text-muted">
                        <a href="{{ route('price-group.create') }}">Buat kelompok harga terlebih dahulu</a>
                    </small>
                @endif
                @error('price_group_id')
                    <div class="invalid-feedback">
                        {{ $message }}
                    </div>
                @enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Tipe Stok</label>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="stok_type" id="stok_limit" value="limit" 
                        {{ old('stok_type', $stokType) == 'limit' ? 'checked' : '' }} required>
                    <label class="form-check-label" for="stok_limit">
                        Stok Limit (Stok terbatas)
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="stok_type" id="stok_unlimit" value="unlimit"
                        {{ old('stok_type', $stokType) == 'unlimit' ? 'checked' : '' }}>
                    <label class="form-check-label" for="stok_unlimit">
                        Stok Unlimit (Stok tidak terbatas)
                    </label>
                </div>
            </div>
            <div class="mb-3" id="stok_limit_input">
                <label for="stok" class="form-label">Jumlah Stok <span class="text-danger">*</span></label>
                <input type="number" class="form-control @error('stok') is-invalid @enderror" id="stok"
                    name="stok" value="{{ old('stok', $fnb->stok == -1 ? '' : $fnb->stok) }}" min="0">
                @error('stok')
                    <div class="invalid-feedback">
                        {{ $message }}
                    </div>
                @enderror
            </div>
            <div class="mb-3">
                <label for="image" class="form-label">Gambar Produk (Opsional, max 2MB, .png/.jpg/.jpeg)</label>
                <input type="file" name="image" id="image" class="form-control-file @error('image') is-invalid @enderror" accept=".png,.jpg,.jpeg">
                <div class="mt-2">
                    <img id="image_preview" src="{{ $fnb->image_url }}" alt="{{ $fnb->nama }}" style="max-width: 150px; max-height: 150px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; margin-top: 8px;">
                </div>
                @error('image')
                    <div class="invalid-feedback d-block">
                        {{ $message }}
                    </div>
                @enderror
            </div>
            <div class="mb-3">
                <label for="deskripsi" class="form-label">Deskripsi</label>
                <textarea class="form-control @error('deskripsi') is-invalid @enderror" id="deskripsi"
                    name="deskripsi">{{ old('deskripsi', $fnb->deskripsi) }}</textarea>
                @error('deskripsi')
                    <div class="invalid-feedback">
                        {{ $message }}
                    </div>
                @enderror
            </div>
            <div class="mb-3">
                <button class="btn btn-primary btn-sm" type="submit" {{ $priceGroups->count() == 0 ? 'disabled' : '' }}>Update</button>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const stokLimitRadio = document.getElementById('stok_limit');
            const stokUnlimitRadio = document.getElementById('stok_unlimit');
            const stokLimitInput = document.getElementById('stok_limit_input');
            const stokInput = document.getElementById('stok');

            function toggleStokInput() {
                if (stokLimitRadio.checked) {
                    stokLimitInput.style.display = 'block';
                    stokInput.required = true;
                    stokInput.removeAttribute('disabled');
                } else if (stokUnlimitRadio.checked) {
                    stokLimitInput.style.display = 'none';
                    stokInput.required = false;
                    stokInput.setAttribute('disabled', 'disabled');
                    stokInput.value = '';
                }
            }

            stokLimitRadio.addEventListener('change', toggleStokInput);
            stokUnlimitRadio.addEventListener('change', toggleStokInput);

            // Set initial state
            toggleStokInput();

            // Handle form submission
            document.querySelector('form').addEventListener('submit', function(e) {
                if (stokUnlimitRadio.checked) {
                    stokInput.removeAttribute('disabled');
                    stokInput.value = '-1'; // Use -1 as marker for unlimited
                }
            });

            // Live image preview
            const imageInput = document.getElementById('image');
            const imagePreview = document.getElementById('image_preview');
            if (imageInput && imagePreview) {
                imageInput.addEventListener('change', function(e) {
                    const file = e.target.files && e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(evt) {
                            imagePreview.src = evt.target.result;
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }
        });
    </script>
@endsection
