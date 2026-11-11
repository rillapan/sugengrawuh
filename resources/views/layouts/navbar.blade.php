<nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
    <!-- Sidebar Toggle (Topbar) -->
    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
        <i class="fa fa-bars"></i>
    </button>

    <!-- WIB Time Display -->
    <div class="d-none d-sm-flex align-items-center mr-4">
        <div class="text-center" style="min-width: 220px;">
            <div id="wib-time" style="
                font-family: 'Roboto Mono', 'Courier New', monospace, sans-serif;
                font-size: 1.4rem;
                font-weight: 600;
                color: #2c3e50;
                letter-spacing: 1px;
                line-height: 1.2;
                text-shadow: 0 1px 2px rgba(0,0,0,0.1);
            ">
                --. --. --
            </div>
            <div id="wib-date" style="
                font-family: 'Poppins', sans-serif;
                font-size: 0.85rem;
                color: #6c757d;
                margin-top: 2px;
                letter-spacing: 0.5px;
            ">
                Loading...
            </div>
        </div>
        <div class="vr mx-3" style="height: 30px; opacity: 0.3;"></div>
    </div>



    <script>
        function formatWIBTime(date) {
            const options = { 
                timeZone: 'Asia/Jakarta',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false,
                hourCycle: 'h23'
            };
            
            const dateOptions = {
                timeZone: 'Asia/Jakarta',
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            };
            
            // Format time as HH.MM.SS
            let timeStr = date.toLocaleTimeString('id-ID', options)
                .split(':')
                .map(part => part.padStart(2, '0'))
                .join('.');
            
            // Format date as "Hari, DD Month YYYY"
            const dateStr = date.toLocaleDateString('id-ID', dateOptions);
                
            return { time: timeStr, date: dateStr };
        }
        
        function updateWIBTime() {
            const now = new Date();
            const wib = formatWIBTime(now);
            
            // Update time display
            document.getElementById('wib-time').textContent = wib.time;
            document.getElementById('wib-date').textContent = wib.date;
        }
        
        // Update time immediately and then every second
        updateWIBTime();
        setInterval(updateWIBTime, 1000);
    </script>
    
    <!-- Offline Fonts -->
    <link href="{{ asset('css/offline-fonts.css') }}" rel="stylesheet">

    <!-- Topbar Navbar -->
    <ul class="navbar-nav ml-auto">
        <!-- Nav Item - Search Dropdown (Visible Only XS) -->
        <li class="nav-item dropdown no-arrow d-sm-none">
            <a class="nav-link dropdown-toggle" href="#" id="searchDropdown" role="button" data-toggle="dropdown"
                aria-haspopup="true" aria-expanded="false">
                <i class="fas fa-search fa-fw"></i>
            </a>
            <!-- Dropdown - Messages -->
            <div class="dropdown-menu dropdown-menu-right p-3 shadow animated--grow-in"
                aria-labelledby="searchDropdown">
                <form class="form-inline mr-auto w-100 navbar-search">
                    <div class="input-group">
                        <input type="text" class="form-control bg-light border-0 small" placeholder="Search for..."
                            aria-label="Search" aria-describedby="basic-addon2" />
                        <div class="input-group-append">
                            <button class="btn btn-primary" type="button">
                                <i class="fas fa-search fa-sm"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </li>



        <!-- Nav Item - User Information -->
        <li class="nav-item dropdown no-arrow">
            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown"
                aria-haspopup="true" aria-expanded="false">
                <span class="mr-2 d-none d-lg-inline text-gray-600 small">{{ auth()->user()->name }}</span>
                @if(auth()->user()->isKasir() && auth()->user()->workShift)
                    <div class="d-none d-lg-inline-flex flex-column align-items-center ml-2">
                        <span class="text-primary font-weight-bold" style="font-size: 0.9rem; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
                            {{ auth()->user()->workShift->nama_shift }}
                        </span>
                        <span class="text-gray-500" style="font-size: 0.75rem; font-family: 'Courier New', monospace; font-weight: 600;">
                            {{ auth()->user()->workShift->jam_mulai }} - {{ auth()->user()->workShift->jam_selesai }}
                        </span>
                    </div>
                @endif
                @php
                    $userImage = auth()->user()->image ?? null;
                    $userImagePath = $userImage ? public_path('storage/' . $userImage) : null;
                @endphp
                @if($userImagePath && file_exists($userImagePath))
                    <img class="img-profile rounded-circle" src="{{ asset('storage/' . $userImage) }}" alt="Profile" />
                @else
                    <img class="img-profile rounded-circle" src="{{ asset('img/undraw_profile.svg') }}" alt="Default Profile" />
                @endif
            </a>
            <!-- Dropdown - User Information -->
            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="userDropdown">
                <a class="dropdown-item" href="{{ route('profile') }}">
                    <i class="fas fa-user fa-sm fa-fw mr-2 text-gray-400"></i>
                    Profile
                </a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#logoutModal">
                    <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i>
                    Logout
                </a>
            </div>
        </li>
    </ul>
</nav>
<!-- End of Topbar -->

<!-- Begin Page Content -->
<div class="container-fluid">
