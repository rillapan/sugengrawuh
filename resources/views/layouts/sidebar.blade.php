<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

    <!-- Sidebar - Brand -->
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="{{ route('home') }}">
        <div class="sidebar-brand-icon rotate-n-15">
            <i class="fab fa-playstation"></i>
        </div>
        <div class="sidebar-brand-text mx-3">RentalPS</div>
    </a>

    <!-- Divider -->
    <hr class="sidebar-divider my-0">

    <!-- Nav Item - Dashboard -->
    <li class="nav-item {{ isset($active) && $active === 'dashboard' ? 'active' : '' }}">
        <a class="nav-link" href="{{ route('home') }}">
            <i class="fas fa-fw fa-tachometer-alt"></i>
            <span>Dashboard</span></a>
    </li>

    <!-- Divider -->
    <hr class="sidebar-divider">

    @if (auth()->user()->isAdmin())
        <!-- Heading -->
        <div class="sidebar-heading">
            Admin Menu
        </div>

        <li class="nav-item {{ isset($active) && $active === 'users' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('users.index') }}">
                <i class="fas fa-users"></i>
                <span>Manajemen User</span></a>
        </li>

        <li class="nav-item {{ isset($active) && $active === 'work_shifts' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('work_shifts.index') }}">
                <i class="fas fa-clock"></i>
                <span>Manajemen Jam Kerja</span></a>
        </li>

        <li class="nav-item {{ isset($active) && in_array($active, ['kategori-permainan', 'play']) ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('kategori-permainan.index') }}">
                <i class="fas fa-gamepad"></i>
                <span>Kategori Permainan</span></a>
        </li>
        <li class="nav-item {{ isset($active) && $active === 'settings' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('settings.index') }}">
                <i class="fas fa-cogs"></i>
                <span>Pengaturan Aplikasi</span></a>
        </li>
    @endif

    @if (auth()->user()->isAdmin() || auth()->user()->isKasir() || auth()->user()->isOwner())
        <div class="sidebar-heading">
            Operasional
        </div>

        <li class="nav-item {{ isset($active) && $active === 'device' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('device.index') }}">
                <i class="fas fa-tv"></i>
                <span>Data Perangkat</span></a>
        </li>

        <li class="nav-item {{ isset($active) && $active === 'transaction' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('transaction.index') }}">
                <i class="fas fa-shopping-cart"></i>
                <span>Transaksi</span></a>
        </li>
    @endif

    @if (auth()->user()->isAdmin())
        <li class="nav-item {{ isset($active) && $active === 'custom-package' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('custom-package.index') }}">
                <i class="fas fa-box"></i>
                <span>Custom Paket</span></a>
        </li>
    @endif

    @if (auth()->user()->isAdmin() || auth()->user()->isOwner())
        <!-- Heading -->
        <div class="sidebar-heading">
            Hardware IoT
        </div>

        <li class="nav-item {{ isset($active) && $active === 'hardware-registry' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('admin.hardware.registry') }}">
                <i class="fas fa-microchip"></i>
                <span>Device Registry</span></a>
        </li>

        <li class="nav-item {{ isset($active) && $active === 'hardware-mappings' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('admin.hardware.mappings') }}">
                <i class="fas fa-network-wired"></i>
                <span>Station Mapping</span></a>
        </li>

        @if(auth()->user()->isAdmin() && config('hardware.simulation_enabled', false))
            <li class="nav-item {{ isset($active) && $active === 'hardware-simulator' ? 'active' : '' }}">
                <a class="nav-link text-warning" href="{{ route('admin.hardware.simulator.index') }}">
                    <i class="fas fa-flask text-warning"></i>
                    <span>Hardware Simulator</span></a>
            </li>
        @endif

        @if(auth()->user()->isAdmin())
            <li class="nav-item {{ isset($active) && $active === 'hardware-diagnostics' ? 'active' : '' }}">
                <a class="nav-link text-info" href="{{ route('admin.hardware.diagnostics.index') }}">
                    <i class="fas fa-stethoscope text-info"></i>
                    <span>Hardware Diagnostics</span></a>
            </li>
            @if(\Illuminate\Support\Facades\Route::has('admin.tv-lab.index'))
                <li class="nav-item {{ isset($active) && $active === 'tv-lab' ? 'active' : '' }}">
                    <a class="nav-link text-warning" href="{{ route('admin.tv-lab.index') }}">
                        <i class="fas fa-tv text-warning"></i>
                        <span>Samsung TV Lab</span></a>
                </li>
            @endif
        @endif
    @endif

    @if (auth()->user()->isAdmin() || auth()->user()->isOwner())
        <li class="nav-item">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseFnb"
                aria-expanded="true" aria-controls="collapseFnb">
                <i class="fas fa-utensils"></i>
                <span>Kelola FnB</span>
            </a>
            <div id="collapseFnb" class="collapse" aria-labelledby="headingFnb" data-parent="#accordionSidebar">
                <div class="bg-white py-2 collapse-inner rounded">
                    <h6 class="collapse-header">FnB Management:</h6>
                    @if(auth()->user()->isAdmin())
                        <a class="collapse-item {{ isset($active) && $active === 'fnb' ? 'active' : '' }}" href="{{ route('fnb.index') }}">Kelola Barang</a>
                        <a class="collapse-item {{ isset($active) && $active === 'price-group' ? 'active' : '' }}" href="{{ route('price-group.index') }}">Kelompok Harga</a>
                        <a class="collapse-item {{ isset($active) && $active === 'stock' ? 'active' : '' }}" href="{{ route('stock.index') }}">Manajemen Stok</a>
                    @endif
                    <a class="collapse-item {{ isset($active) && $active === 'fnb-laporan' ? 'active' : '' }}" href="{{ route('fnb.laporan') }}">Laporan Penjualan</a>
                </div>
            </div>
        </li>
    @endif

    @if (auth()->user()->isAdmin() || auth()->user()->isKasir() || auth()->user()->isOwner())
        <li class="nav-item">
            <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseExpense"
                aria-expanded="true" aria-controls="collapseExpense">
                <i class="fas fa-money-bill-wave"></i>
                <span>Pengeluaran</span>
            </a>
            <div id="collapseExpense" class="collapse" aria-labelledby="headingExpense" data-parent="#accordionSidebar">
                <div class="bg-white py-2 collapse-inner rounded">
                    <h6 class="collapse-header">Pengeluaran Management:</h6>
                    @if(auth()->user()->isAdmin())
                        <a class="collapse-item {{ isset($active) && $active === 'expense-category' ? 'active' : '' }}" href="{{ route('expense-category.index') }}">Kategori Pengeluaran</a>
                    @endif
                    <a class="collapse-item {{ isset($active) && $active === 'expense' ? 'active' : '' }}" href="{{ route('expense.index') }}">Daftar Pengeluaran</a>
                </div>
            </div>
        </li>
    @endif

    @if (auth()->user()->isAdmin() || auth()->user()->isOwner())
        <!-- Heading -->
        <div class="sidebar-heading">
            Laporan
        </div>

        <li class="nav-item {{ isset($active) && $active === 'report' ? 'active' : '' }}">
            <a class="nav-link" href="{{ route('report') }}">
                <i class="fas fa-fw fa-chart-area"></i>
                <span>Laporan Penjualan</span></a>
        </li>
    @endif

    <!-- Divider -->
    <hr class="sidebar-divider d-none d-md-block">



    <!-- Sidebar Toggler (Sidebar) -->
    <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle"></button>
    </div>

</ul>
