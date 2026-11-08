@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-flex align-items-center justify-content-between">
                <h4 class="mb-0">{{ $title }}</h4>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Edit Tarif Paket Durasi</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('hourly-prices.hourly-prices.update', [$playstation->id, $hourlyPrice->id]) }}" method="POST" id="hourlyPriceForm">
                        @csrf
                        @method('PUT')
                        <div class="mb-3">
                            <label for="playstation_nama" class="form-label">Kategori PlayStation / Permainan</label>
                            <input type="text" class="form-control" value="{{ $playstation->nama }}" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Durasi Bermain</label>
                            <div class="row g-2">
                                <div class="col-4">
                                    <label for="hours" class="form-label small text-muted">Jam</label>
                                    <input type="number" 
                                           class="form-control @error('hours') is-invalid @enderror @error('total_seconds') is-invalid @enderror" 
                                           id="hours" name="hours" 
                                           value="{{ old('hours', $durationParts['hours'] ?? 0) }}" min="0" max="24" required>
                                    @error('hours')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-4">
                                    <label for="minutes" class="form-label small text-muted">Menit</label>
                                    <input type="number" 
                                           class="form-control @error('minutes') is-invalid @enderror @error('total_seconds') is-invalid @enderror" 
                                           id="minutes" name="minutes" 
                                           value="{{ old('minutes', $durationParts['minutes'] ?? 0) }}" min="0" max="59" required>
                                    @error('minutes')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-4">
                                    <label for="seconds" class="form-label small text-muted">Detik</label>
                                    <input type="number" 
                                           class="form-control @error('seconds') is-invalid @enderror @error('total_seconds') is-invalid @enderror" 
                                           id="seconds" name="seconds" 
                                           value="{{ old('seconds', $durationParts['seconds'] ?? 0) }}" min="0" max="59" required>
                                    @error('seconds')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            @error('total_seconds')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                            <div class="form-text mt-2">
                                Preview: <strong><span id="duration-preview" class="badge bg-primary text-white">00:00:00</span></strong>
                                <span id="duration-human" class="text-muted ms-2">(0 detik)</span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="price" class="form-label fw-bold">Harga Paket (Rp)</label>
                            <input type="number" class="form-control @error('price') is-invalid @enderror" 
                                   id="price" name="price" value="{{ old('price', $hourlyPrice->price) }}" min="0" required>
                            @error('price')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        
                        <div class="d-flex justify-content-between pt-2">
                            <a href="{{ route('hourly-prices.hourly-prices.index', $playstation->id) }}" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Batal
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Tarif
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const hoursInput = document.getElementById('hours');
        const minutesInput = document.getElementById('minutes');
        const secondsInput = document.getElementById('seconds');
        const previewBadge = document.getElementById('duration-preview');
        const humanSpan = document.getElementById('duration-human');

        function updatePreview() {
            let h = parseInt(hoursInput.value) || 0;
            let m = parseInt(minutesInput.value) || 0;
            let s = parseInt(secondsInput.value) || 0;

            if (h < 0) h = 0;
            if (m < 0) m = 0;
            if (s < 0) s = 0;

            const pad = (n) => n.toString().padStart(2, '0');
            previewBadge.textContent = `${pad(h)}:${pad(m)}:${pad(s)}`;

            const parts = [];
            if (h > 0) parts.push(`${h} jam`);
            if (m > 0) parts.push(`${m} menit`);
            if (s > 0) parts.push(`${s} detik`);

            humanSpan.textContent = parts.length > 0 ? `(${parts.join(' ')})` : '(0 detik)';
        }

        hoursInput.addEventListener('input', updatePreview);
        minutesInput.addEventListener('input', updatePreview);
        secondsInput.addEventListener('input', updatePreview);

        updatePreview();
    });
</script>
@endsection
