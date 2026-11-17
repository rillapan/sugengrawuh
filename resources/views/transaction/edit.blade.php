@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Edit Transaksi #{{ $transaction->id_transaksi }}</h5>
                    <small class="text-muted">Hanya transaksi yang belum dibayar yang dapat diedit</small>
                </div>

                <div class="card-body">
                    @if (session('success'))
                        <div class="alert alert-success" role="alert">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if (session('error') || session('gagal'))
                        <div class="alert alert-danger" role="alert">
                            {{ session('error') ?? session('gagal') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('transaction.update', $transaction->id_transaksi) }}" id="editForm">
                        @csrf
                        @method('PUT')

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="nama" class="form-label">Nama Pelanggan</label>
                                    <input type="text" class="form-control" id="nama" value="{{ $transaction->nama }}" disabled>
                                    <small class="text-muted">Nama pelanggan tidak dapat diubah</small>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="waktu_mulai" class="form-label">Waktu Mulai</label>
                                    <input type="text" class="form-control" id="waktu_mulai" value="{{ $transaction->waktu_mulai }}" disabled>
                                    <small class="text-muted">Waktu mulai tidak dapat diubah</small>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mb-3">
                            <label for="tipe_transaksi" class="form-label">Tipe Transaksi <span class="text-danger">*</span></label>
                            <select class="form-control" id="tipe_transaksi" name="tipe_transaksi" required>
                                <option value="postpaid" {{ $transaction->tipe_transaksi === 'postpaid' ? 'selected' : '' }}>Lost Time</option>
                                <option value="prepaid" {{ $transaction->tipe_transaksi === 'prepaid' ? 'selected' : '' }}>Timer</option>
                                <option value="custom_package" {{ $transaction->tipe_transaksi === 'custom_package' ? 'selected' : '' }}>Custom Paket</option>
                            </select>
                            <small class="text-muted">Anda dapat mengubah tipe transaksi. Timer akan tetap berjalan.</small>
                        </div>

                        <!-- Timer Preview Section -->
                        <div class="alert alert-info" id="timer_preview_section">
                            <h6 class="mb-3"><i class="fas fa-clock"></i> Preview Timer</h6>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h6 class="text-muted mb-2">Timer Saat Ini</h6>
                                            <div class="timer-display-large" id="current_timer">
                                                <span class="timer-value">00:00:00</span>
                                            </div>
                                            <small class="text-muted" id="current_timer_type">
                                                {{ $transaction->tipe_transaksi === 'postpaid' ? 'Lost Time (Count Up)' : 'Timer (Countdown)' }}
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card bg-success text-white" id="new_timer_card">
                                        <div class="card-body">
                                            <h6 class="mb-2">Timer Setelah Edit</h6>
                                            <div class="timer-display-large" id="new_timer">
                                                <span class="timer-value">00:00:00</span>
                                            </div>
                                            <small id="new_timer_type">-</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3" id="timer_explanation">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    <span id="explanation_text">Timer akan disesuaikan berdasarkan waktu yang sudah terpakai.</span>
                                </small>
                            </div>
                        </div>

                        <div class="form-group mb-3">
                            <label for="device_id" class="form-label">Perangkat <span class="text-danger">*</span></label>
                            <select class="form-control" id="device_id" name="device_id" required>
                                @foreach($devices as $device)
                                    <option value="{{ $device->id }}" 
                                        {{ $transaction->device_id == $device->id ? 'selected' : '' }}
                                        data-harga="{{ $device->playstation->harga ?? 0 }}"
                                        data-playstation-id="{{ $device->playstation_id }}">
                                        {{ $device->nama }} - {{ $device->playstation->nama ?? 'N/A' }}
                                        @if($transaction->device_id == $device->id)
                                            (Sedang Digunakan)
                                        @else
                                            (Tersedia)
                                        @endif
                                        - Rp {{ number_format($device->playstation->harga ?? 0, 0, ',', '.') }}/jam
                                    </option>
                                @endforeach
                            </select>
                            <small class="text-muted">Hanya menampilkan perangkat yang tersedia. Anda dapat mengubah perangkat dan timer akan tetap berjalan.</small>
                        </div>

                        <!-- Custom Package Selection (only shown for custom_package type) -->
                        <div id="custom_package_container" style="display: {{ $transaction->tipe_transaksi === 'custom_package' ? 'block' : 'none' }}">
                            <div class="form-group mb-3">
                                <label for="custom_package_id" class="form-label">Pilih Custom Paket <span class="text-danger">*</span></label>
                                <select class="form-control" id="custom_package_id" name="custom_package_id">
                                    <option value="">-- Pilih Custom Paket --</option>
                                    @foreach($customPackages as $package)
                                        <option value="{{ $package->id }}" 
                                            {{ $transaction->custom_package_id == $package->id ? 'selected' : '' }}
                                            data-harga="{{ $package->harga_total }}"
                                            data-lama-main="{{ $package->playstations->sum('pivot.lama_main') }}"
                                            data-playstation-ids="{{ $package->playstations->pluck('id')->implode(',') }}">
                                            {{ $package->nama_paket }} - Rp {{ number_format($package->harga_total, 0, ',', '.') }}
                                            ({{ $package->playstations->sum('pivot.lama_main') }} menit)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <!-- Jam Main (only shown for prepaid type) -->
                        <div id="jam_main_container" style="display: {{ $transaction->tipe_transaksi === 'prepaid' ? 'block' : 'none' }}">
                            <div class="form-group mb-3">
                                <label for="jam_main" class="form-label">Jam Main <span class="text-danger">*</span></label>
                                <select class="form-control" id="jam_main" name="jam_main">
                                    @for($i = 1; $i <= 10; $i++)
                                        <option value="{{ $i }}" {{ $transaction->jam_main == $i ? 'selected' : '' }}>
                                            {{ $i }} Jam
                                        </option>
                                    @endfor
                                </select>
                                <small class="text-muted">Mengubah jam main akan menyesuaikan harga secara otomatis</small>
                            </div>

                            <!-- Price Preview for Prepaid -->
                            <div class="alert alert-info" id="price_preview">
                                <strong>Estimasi Harga PS:</strong> <span id="estimated_price">Rp 0</span>
                            </div>
                        </div>

                        <!-- Info for Lost Time -->
                        <div id="lost_time_info" style="display: {{ $transaction->tipe_transaksi === 'postpaid' ? 'block' : 'none' }}">
                            <div class="alert alert-warning">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Lost Time:</strong> Harga akan dihitung saat transaksi selesai berdasarkan durasi bermain.
                                Timer tetap berjalan dari waktu mulai awal.
                            </div>
                        </div>

                        <hr>

                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-utensils"></i> Kelola FnB Pesanan</h6>
                            </div>
                            <div class="card-body">
                                <!-- FnB Items Table -->
                                <div class="table-responsive mb-3">
                                    <table class="table table-bordered" id="fnbTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 40%">Nama FnB</th>
                                                <th style="width: 15%">Qty</th>
                                                <th style="width: 20%">Harga</th>
                                                <th style="width: 20%">Subtotal</th>
                                                <th style="width: 5%">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody id="fnbTableBody">
                                            @if($transaction->transactionFnbs->isEmpty())
                                                <tr id="emptyRow">
                                                    <td colspan="5" class="text-center text-muted">Belum ada FnB dalam transaksi ini</td>
                                                </tr>
                                            @else
                                                @foreach($transaction->transactionFnbs as $fnbItem)
                                                    <tr data-fnb-id="{{ $fnbItem->fnb_id }}" class="fnb-row">
                                                        <td>{{ $fnbItem->fnb->nama }}</td>
                                                        <td>
                                                            <input type="number" 
                                                                   class="form-control form-control-sm qty-input" 
                                                                   value="{{ $fnbItem->qty }}" 
                                                                   min="1"
                                                                   data-fnb-id="{{ $fnbItem->fnb_id }}"
                                                                   data-old-qty="{{ $fnbItem->qty }}">
                                                        </td>
                                                        <td>
                                                            <input type="number" 
                                                                   class="form-control form-control-sm price-input" 
                                                                   value="{{ $fnbItem->harga_jual }}" 
                                                                   min="0"
                                                                   data-fnb-id="{{ $fnbItem->fnb_id }}"
                                                                   data-old-price="{{ $fnbItem->harga_jual }}">
                                                        </td>
                                                        <td class="subtotal-cell">
                                                            Rp {{ number_format($fnbItem->qty * $fnbItem->harga_jual, 0, ',', '.') }}
                                                        </td>
                                                        <td class="text-center">
                                                            <button type="button" 
                                                                    class="btn btn-sm btn-danger delete-fnb" 
                                                                    data-fnb-id="{{ $fnbItem->fnb_id }}"
                                                                    title="Hapus">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            @endif
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-info">
                                                <th colspan="3" class="text-end">Total FnB:</th>
                                                <th id="fnbTotalDisplay">Rp {{ number_format($transaction->getFnbTotalAttribute(), 0, ',', '.') }}</th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                <!-- Add New FnB Section -->
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="mb-3"><i class="fas fa-plus-circle"></i> Tambah FnB Baru</h6>
                                        <div class="row g-2">
                                            <div class="col-md-5">
                                                <select class="form-control form-control-sm" id="newFnbSelect">
                                                    <option value="">-- Pilih FnB --</option>
                                                    @foreach($fnbs as $fnb)
                                                        <option value="{{ $fnb->id }}" 
                                                                data-harga="{{ $fnb->harga_jual }}"
                                                                data-stok="{{ $fnb->stok }}">
                                                            {{ $fnb->nama }} - Rp {{ number_format($fnb->harga_jual, 0, ',', '.') }}
                                                            @if($fnb->stok == -1)
                                                                (Stok: Unlimited)
                                                            @else
                                                                (Stok: {{ $fnb->stok }})
                                                            @endif
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <input type="number" 
                                                       class="form-control form-control-sm" 
                                                       id="newFnbQty" 
                                                       placeholder="Qty" 
                                                       min="1" 
                                                       value="1">
                                            </div>
                                            <div class="col-md-4">
                                                <button type="button" class="btn btn-sm btn-success" id="addFnbBtn">
                                                    <i class="fas fa-plus"></i> Tambah FnB
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Simpan Perubahan
                            </button>
                            <a href="{{ route('transaction.index') }}" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Batal
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tipeTransaksiSelect = document.getElementById('tipe_transaksi');
    const jamMainContainer = document.getElementById('jam_main_container');
    const jamMainSelect = document.getElementById('jam_main');
    const deviceSelect = document.getElementById('device_id');
    const customPackageContainer = document.getElementById('custom_package_container');
    const customPackageSelect = document.getElementById('custom_package_id');
    const lostTimeInfo = document.getElementById('lost_time_info');
    const pricePreview = document.getElementById('price_preview');
    const estimatedPrice = document.getElementById('estimated_price');

    // Timer data from server
    const waktuMulai = '{{ $transaction->waktu_mulai }}';
    const createdAt = '{{ $transaction->created_at->toDateString() }}';
    const currentTipe = '{{ $transaction->tipe_transaksi }}';
    const currentJamMain = {{ $transaction->jam_main ?? 0 }};
    
    // Calculate start time
    const startTime = new Date(createdAt + ' ' + waktuMulai);

    function formatTime(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }

    function updateTimers() {
        const now = new Date();
        const elapsedSeconds = Math.floor((now - startTime) / 1000);
        const elapsedMinutes = Math.floor(elapsedSeconds / 60);
        
        // Update current timer
        const currentTimerEl = document.getElementById('current_timer').querySelector('.timer-value');
        const currentTimerTypeEl = document.getElementById('current_timer_type');
        
        if (currentTipe === 'postpaid') {
            // Lost Time - Count Up
            currentTimerEl.textContent = formatTime(elapsedSeconds);
            currentTimerTypeEl.textContent = 'Lost Time (Count Up ⏱️)';
        } else if (currentTipe === 'prepaid') {
            // Paket - Countdown
            const totalSeconds = currentJamMain * 3600;
            const remainingSeconds = Math.max(0, totalSeconds - elapsedSeconds);
            currentTimerEl.textContent = formatTime(remainingSeconds);
            currentTimerTypeEl.textContent = 'Timer (Countdown ⏳)';
        } else {
            // Custom Package
            const totalSeconds = currentJamMain * 60; // jam_main is in minutes for custom package
            const remainingSeconds = Math.max(0, totalSeconds - elapsedSeconds);
            currentTimerEl.textContent = formatTime(remainingSeconds);
            currentTimerTypeEl.textContent = 'Custom Paket (Countdown ⏳)';
        }

        // Update new timer preview
        updateNewTimerPreview(elapsedSeconds, elapsedMinutes);
    }

    function updateNewTimerPreview(elapsedSeconds, elapsedMinutes) {
        const selectedTipe = tipeTransaksiSelect.value;
        const newTimerEl = document.getElementById('new_timer').querySelector('.timer-value');
        const newTimerTypeEl = document.getElementById('new_timer_type');
        const explanationEl = document.getElementById('explanation_text');
        const newTimerCard = document.getElementById('new_timer_card');

        // Check if type is changing
        if (selectedTipe === currentTipe && selectedTipe !== 'prepaid') {
            // No change in type (except prepaid which might change jam_main)
            newTimerCard.classList.remove('bg-success', 'bg-warning', 'bg-info');
            newTimerCard.classList.add('bg-secondary');
            newTimerEl.textContent = 'Tidak ada perubahan';
            newTimerTypeEl.textContent = 'Tipe transaksi sama';
            explanationEl.textContent = 'Pilih tipe transaksi berbeda untuk melihat perubahan timer.';
            return;
        }

        newTimerCard.classList.remove('bg-secondary');
        
        if (selectedTipe === 'postpaid') {
            // Changing to Lost Time - Count Up
            newTimerCard.classList.remove('bg-warning', 'bg-info');
            newTimerCard.classList.add('bg-success');
            newTimerEl.textContent = formatTime(elapsedSeconds);
            newTimerTypeEl.textContent = 'Lost Time (Count Up ⏱️)';
            
            const hours = Math.floor(elapsedMinutes / 60);
            const mins = elapsedMinutes % 60;
            explanationEl.innerHTML = `<strong>Lost Time → Count Up:</strong> Timer melanjutkan dari ${hours} jam ${mins} menit yang sudah terpakai. Harga dihitung saat selesai.`;
            
        } else if (selectedTipe === 'prepaid') {
            // Changing to Paket - Countdown
            const jamMain = parseInt(jamMainSelect.value) || 1;
            const totalSeconds = jamMain * 3600;
            const remainingSeconds = Math.max(0, totalSeconds - elapsedSeconds);
            
            newTimerCard.classList.remove('bg-success', 'bg-info');
            if (remainingSeconds === 0) {
                newTimerCard.classList.add('bg-danger');
            } else {
                newTimerCard.classList.add('bg-warning');
            }
            
            newTimerEl.textContent = formatTime(remainingSeconds);
            newTimerTypeEl.textContent = 'Timer (Countdown ⏳)';
            
            const hours = Math.floor(elapsedMinutes / 60);
            const mins = elapsedMinutes % 60;
            const remHours = Math.floor(remainingSeconds / 3600);
            const remMins = Math.floor((remainingSeconds % 3600) / 60);
            
            if (remainingSeconds === 0) {
                explanationEl.innerHTML = `<strong class="text-danger">⚠️ Overtime!</strong> Waktu sudah terpakai ${hours} jam ${mins} menit, melebihi timer ${jamMain} jam. Timer akan menunjukkan 00:00:00.`;
            } else {
                explanationEl.innerHTML = `<strong>Timer → Countdown:</strong> Dari ${jamMain} jam, sudah terpakai ${hours} jam ${mins} menit. Sisa waktu: ${remHours} jam ${remMins} menit.`;
            }
            
        } else if (selectedTipe === 'custom_package') {
            // Changing to Custom Package
            const selectedPackage = customPackageSelect.options[customPackageSelect.selectedIndex];
            if (!selectedPackage.value) {
                newTimerEl.textContent = 'Pilih paket';
                newTimerTypeEl.textContent = 'Custom Paket';
                explanationEl.textContent = 'Pilih custom paket untuk melihat preview timer.';
                return;
            }
            
            const lamaMain = parseInt(selectedPackage.getAttribute('data-lama-main')) || 0; // in minutes
            const totalSeconds = lamaMain * 60;
            const remainingSeconds = Math.max(0, totalSeconds - elapsedSeconds);
            
            newTimerCard.classList.remove('bg-success', 'bg-warning');
            newTimerCard.classList.add('bg-info');
            newTimerEl.textContent = formatTime(remainingSeconds);
            newTimerTypeEl.textContent = 'Custom Paket (Countdown ⏳)';
            
            const remMins = Math.floor(remainingSeconds / 60);
            explanationEl.innerHTML = `<strong>Custom Paket:</strong> Total ${lamaMain} menit, sudah terpakai ${elapsedMinutes} menit. Sisa: ${remMins} menit.`;
        }
    }

    function updateFormDisplay() {
        const tipeTransaksi = tipeTransaksiSelect.value;
        
        // Hide all conditional sections first
        jamMainContainer.style.display = 'none';
        customPackageContainer.style.display = 'none';
        lostTimeInfo.style.display = 'none';
        pricePreview.style.display = 'none';
        
        if (tipeTransaksi === 'prepaid') {
            jamMainContainer.style.display = 'block';
            jamMainSelect.setAttribute('required', 'required');
            customPackageSelect.removeAttribute('required');
            updatePricePreview();
        } else if (tipeTransaksi === 'postpaid') {
            lostTimeInfo.style.display = 'block';
            jamMainSelect.removeAttribute('required');
            customPackageSelect.removeAttribute('required');
        } else if (tipeTransaksi === 'custom_package') {
            customPackageContainer.style.display = 'block';
            customPackageSelect.setAttribute('required', 'required');
            jamMainSelect.removeAttribute('required');
        }
        
        // Update timer preview
        updateTimers();
    }

    function updatePricePreview() {
        const selectedDevice = deviceSelect.options[deviceSelect.selectedIndex];
        const hargaPerJam = parseInt(selectedDevice.getAttribute('data-harga')) || 0;
        const jamMain = parseInt(jamMainSelect.value) || 1;
        const totalPs = hargaPerJam * jamMain;
        
        estimatedPrice.textContent = 'Rp ' + totalPs.toLocaleString('id-ID');
        pricePreview.style.display = 'block';
        
        // Update timer preview when jam_main changes
        updateTimers();
    }

    function validateCustomPackageDevice() {
        const tipeTransaksi = tipeTransaksiSelect.value;
        if (tipeTransaksi !== 'custom_package') return true;

        const selectedPackage = customPackageSelect.options[customPackageSelect.selectedIndex];
        const selectedDevice = deviceSelect.options[deviceSelect.selectedIndex];
        
        if (!selectedPackage.value) return true; // No package selected yet
        
        const packagePlaystationIds = selectedPackage.getAttribute('data-playstation-ids').split(',');
        const devicePlaystationId = selectedDevice.getAttribute('data-playstation-id');
        
        if (!packagePlaystationIds.includes(devicePlaystationId)) {
            alert('Perangkat yang dipilih tidak sesuai dengan paket custom yang dipilih!');
            return false;
        }
        
        return true;
    }

    // Event listeners
    tipeTransaksiSelect.addEventListener('change', updateFormDisplay);
    jamMainSelect.addEventListener('change', updatePricePreview);
    deviceSelect.addEventListener('change', function() {
        updatePricePreview();
        validateCustomPackageDevice();
    });
    customPackageSelect.addEventListener('change', function() {
        validateCustomPackageDevice();
        updateTimers();
    });

    // Form submission validation
    document.getElementById('editForm').addEventListener('submit', function(e) {
        if (!validateCustomPackageDevice()) {
            e.preventDefault();
            return false;
        }
    });

    // Initial display
    updateFormDisplay();
    
    // Update timers every second
    setInterval(updateTimers, 1000);

    // ====== FnB Management Functions ======
    
    const transactionId = '{{ $transaction->id_transaksi }}';
    const csrfToken = '{{ csrf_token() }}';

    // Helper function to format currency
    function formatCurrency(number) {
        return 'Rp ' + parseInt(number).toLocaleString('id-ID');
    }

    // Helper function to show notification
    function showNotification(message, type = 'success') {
        const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
        const alert = `
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        
        // Insert at the top of card-body
        const cardBody = document.querySelector('.card-body');
        cardBody.insertAdjacentHTML('afterbegin', alert);
        
        // Auto-dismiss after 3 seconds
        setTimeout(() => {
            const alertEl = cardBody.querySelector('.alert');
            if (alertEl) alertEl.remove();
        }, 3000);
    }

    // Add New FnB
    document.getElementById('addFnbBtn').addEventListener('click', function() {
        const fnbSelect = document.getElementById('newFnbSelect');
        const fnbId = fnbSelect.value;
        const qty = parseInt(document.getElementById('newFnbQty').value);
        
        if (!fnbId) {
            alert('Silakan pilih FnB terlebih dahulu');
            return;
        }
        
        if (!qty || qty < 1) {
            alert('Quantity harus lebih dari 0');
            return;
        }
        
        // Check stock
        const selectedOption = fnbSelect.options[fnbSelect.selectedIndex];
        const stok = parseInt(selectedOption.getAttribute('data-stok'));
        
        if (stok !== -1 && qty > stok) {
            alert(`Stok tidak mencukupi. Stok tersedia: ${stok}`);
            return;
        }
        
        // Disable button during request
        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menambahkan...';
        
        fetch(`/transaction/${transactionId}/fnb/add`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({
                fnb_id: fnbId,
                qty: qty
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(data.message, 'success');
                
                // Check if FnB already exists in table
                const existingRow = document.querySelector(`tr[data-fnb-id="${fnbId}"]`);
                
                if (existingRow) {
                    // Update existing row
                    const qtyInput = existingRow.querySelector('.qty-input');
                    const newQty = parseInt(qtyInput.value) + qty;
                    qtyInput.value = newQty;
                    qtyInput.setAttribute('data-old-qty', newQty);
                    updateSubtotal(existingRow);
                } else {
                    // Add new row
                    const newRow = createFnbRow(data.fnb);
                    const emptyRow = document.getElementById('emptyRow');
                    if (emptyRow) emptyRow.remove();
                    
                    document.getElementById('fnbTableBody').appendChild(newRow);
                }
                
                // Update total
                updateFnbTotal(data.total);
                
                // Reset form
                fnbSelect.value = '';
                document.getElementById('newFnbQty').value = '1';
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Terjadi kesalahan saat menambahkan FnB', 'error');
        })
        .finally(() => {
            // Re-enable button
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-plus"></i> Tambah FnB';
        });
    });

    // Create FnB Row HTML
    function createFnbRow(fnbData) {
        const row = document.createElement('tr');
        row.className = 'fnb-row';
        row.setAttribute('data-fnb-id', fnbData.fnb_id);
        
        const subtotal = fnbData.qty * fnbData.harga_jual;
        
        row.innerHTML = `
            <td>${fnbData.fnb.nama}</td>
            <td>
                <input type="number" 
                       class="form-control form-control-sm qty-input" 
                       value="${fnbData.qty}" 
                       min="1"
                       data-fnb-id="${fnbData.fnb_id}"
                       data-old-qty="${fnbData.qty}">
            </td>
            <td>
                <input type="number" 
                       class="form-control form-control-sm price-input" 
                       value="${fnbData.harga_jual}" 
                       min="0"
                       data-fnb-id="${fnbData.fnb_id}"
                       data-old-price="${fnbData.harga_jual}">
            </td>
            <td class="subtotal-cell">${formatCurrency(subtotal)}</td>
            <td class="text-center">
                <button type="button" 
                        class="btn btn-sm btn-danger delete-fnb" 
                        data-fnb-id="${fnbData.fnb_id}"
                        title="Hapus">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        
        return row;
    }

    // Update FnB (Quantity or Price Change)
    function updateFnb(fnbId, qty, hargaJual) {
        fetch(`/transaction/${transactionId}/fnb/${fnbId}/update`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({
                qty: qty,
                harga_jual: hargaJual
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(data.message, 'success');
                
                // Update the row's data attributes
                const row = document.querySelector(`tr[data-fnb-id="${fnbId}"]`);
                const qtyInput = row.querySelector('.qty-input');
                const priceInput = row.querySelector('.price-input');
                
                qtyInput.setAttribute('data-old-qty', qty);
                priceInput.setAttribute('data-old-price', hargaJual);
                
                updateSubtotal(row);
                updateFnbTotal(data.total);
            } else {
                showNotification(data.message, 'error');
                
                // Revert to old values
                const row = document.querySelector(`tr[data-fnb-id="${fnbId}"]`);
                const qtyInput = row.querySelector('.qty-input');
                const priceInput = row.querySelector('.price-input');
                
                qtyInput.value = qtyInput.getAttribute('data-old-qty');
                priceInput.value = priceInput.getAttribute('data-old-price');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Terjadi kesalahan saat mengupdate FnB', 'error');
        });
    }

    // Update subtotal in a row
    function updateSubtotal(row) {
        const qty = parseInt(row.querySelector('.qty-input').value);
        const price = parseInt(row.querySelector('.price-input').value);
        const subtotal = qty * price;
        
        row.querySelector('.subtotal-cell').textContent = formatCurrency(subtotal);
    }

    // Update FnB total display
    function updateFnbTotal(total) {
        document.getElementById('fnbTotalDisplay').textContent = formatCurrency(total);
    }

    // Delete FnB
    document.addEventListener('click', function(e) {
        if (e.target.closest('.delete-fnb')) {
            const button = e.target.closest('.delete-fnb');
            const fnbId = button.getAttribute('data-fnb-id');
            
            if (!confirm('Apakah Anda yakin ingin menghapus FnB ini?')) {
                return;
            }
            
            // Disable button during request
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            fetch(`/transaction/${transactionId}/fnb/${fnbId}/delete`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    
                    // Remove the row
                    const row = document.querySelector(`tr[data-fnb-id="${fnbId}"]`);
                    row.remove();
                    
                    // Check if table is empty
                    const remainingRows = document.querySelectorAll('.fnb-row');
                    if (remainingRows.length === 0) {
                        const tbody = document.getElementById('fnbTableBody');
                        tbody.innerHTML = '<tr id="emptyRow"><td colspan="5" class="text-center text-muted">Belum ada FnB dalam transaksi ini</td></tr>';
                    }
                    
                    // Update total
                    updateFnbTotal(data.total);
                } else {
                    showNotification(data.message, 'error');
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-trash"></i>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Terjadi kesalahan saat menghapus FnB', 'error');
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-trash"></i>';
            });
        }
    });

    // Handle quantity change
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('qty-input')) {
            const input = e.target;
            const fnbId = input.getAttribute('data-fnb-id');
            const newQty = parseInt(input.value);
            const oldQty = parseInt(input.getAttribute('data-old-qty'));
            
            if (newQty === oldQty) return;
            
            if (newQty < 1) {
                alert('Quantity harus minimal 1');
                input.value = oldQty;
                return;
            }
            
            const row = input.closest('tr');
            const priceInput = row.querySelector('.price-input');
            const hargaJual = parseInt(priceInput.value);
            
            updateFnb(fnbId, newQty, hargaJual);
        }
    });

    // Handle price change
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('price-input')) {
            const input = e.target;
            const fnbId = input.getAttribute('data-fnb-id');
            const newPrice = parseInt(input.value);
            const oldPrice = parseInt(input.getAttribute('data-old-price'));
            
            if (newPrice === oldPrice) return;
            
            if (newPrice < 0) {
                alert('Harga tidak boleh negatif');
                input.value = oldPrice;
                return;
            }
            
            const row = input.closest('tr');
            const qtyInput = row.querySelector('.qty-input');
            const qty = parseInt(qtyInput.value);
            
            updateFnb(fnbId, qty, newPrice);
        }
    });
});
</script>

<style>
.form-label {
    font-weight: 600;
}
.text-danger {
    color: #dc3545;
}
.timer-display-large {
    text-align: center;
    padding: 15px 0;
}
.timer-display-large .timer-value {
    font-size: 2.5rem;
    font-weight: bold;
    font-family: 'Courier New', monospace;
    letter-spacing: 3px;
}
#timer_preview_section .card {
    transition: all 0.3s ease;
    min-height: 150px;
}
#timer_preview_section .card-body {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}
#new_timer_card.bg-danger .timer-value {
    animation: pulse 1s infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}
</style>
@endsection
