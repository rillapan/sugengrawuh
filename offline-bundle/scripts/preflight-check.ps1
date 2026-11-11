#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Stage D.A Preflight System Inspection
.DESCRIPTION
    Inspects Windows build, CPU architecture, RAM, disk space, BIOS Virtualization (Intel VT-x / AMD-V),
    WSL2 status, Docker Desktop installation & engine readiness, port 8008 availability, LAN connectivity,
    and detects FRESH_INSTALL vs UPGRADE_OR_REPAIR mode.
    Exit codes:
      0  = Ready to install application
      10 = Prerequisites needed (WSL2 / Docker Desktop installation required)
      20 = System reboot pending
      1  = Hardware / OS / BIOS requirement not met (blocking)
#>

param(
    [string]$InstallDir = "C:\SugengRawuh",
    [int]$AppPort = 8008,
    [switch]$JsonOutput
)

$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "common.ps1")

Ensure-SRDirectories -InstallDir $InstallDir
Write-SRLog -Level "INFO" -Message "=== Memulai Pemeriksaan Sistem (Preflight Check Stage D.A) ===" -InstallDir $InstallDir

$blockers = New-Object System.Collections.Generic.List[string]
$warnings = New-Object System.Collections.Generic.List[string]
$prereqsNeeded = New-Object System.Collections.Generic.List[string]
$rebootPending = $false

# 1. Detect Existing Installation (FRESH_INSTALL vs UPGRADE_OR_REPAIR)
$envProdPath = Join-Path $InstallDir ".env.production"
$envLegacyPath = Join-Path $InstallDir ".env"
$hasExistingEnv = (Test-Path -LiteralPath $envProdPath) -or (Test-Path -LiteralPath $envLegacyPath)
$hasMysqlVolume = $false

try {
    if (Get-Command "docker" -ErrorAction SilentlyContinue) {
        $volList = & docker volume ls --format "{{.Name}}" 2>$null
        if ($LASTEXITCODE -eq 0 -and ($volList -match "sr_mysql_data")) {
            $hasMysqlVolume = $true
        }
    }
} catch {}

$installMode = if ($hasExistingEnv -or $hasMysqlVolume) { "UPGRADE_OR_REPAIR" } else { "FRESH_INSTALL" }
Write-SRLog -Level "INFO" -Message "Mode Instalasi Terdeteksi: $installMode" -InstallDir $InstallDir

# 2. Check CPU Architecture (64-bit AMD64 / x86_64)
$arch = $env:PROCESSOR_ARCHITECTURE
$is64BitOs = [Environment]::Is64BitOperatingSystem
if (-not $is64BitOs -or ($arch -ne "AMD64" -and $env:PROCESSOR_ARCHITEW6432 -ne "AMD64")) {
    $blockers.Add("Arsitektur CPU/OS tidak didukung ($arch). Sugeng Rawuh membutuhkan Windows 64-bit (x86_64 / AMD64).")
} else {
    Write-SRLog -Level "SUCCESS" -Message "Arsitektur CPU valid: 64-bit ($arch)" -InstallDir $InstallDir
}

# 3. Check Windows Version & Build (>= 19041 for WSL2)
$osInfo = Get-CimInstance -ClassName Win32_OperatingSystem -ErrorAction SilentlyContinue
$buildNumber = if ($null -ne $osInfo) { [int]$osInfo.BuildNumber } else { [Environment]::OSVersion.Version.Build }
$osCaption = if ($null -ne $osInfo) { $osInfo.Caption } else { "Windows" }

if ($buildNumber -lt 19041) {
    $blockers.Add("Versi Windows ($osCaption Build $buildNumber) terlalu lama. Minimal Windows 10 2004 / Windows 11 (Build 19041 atau lebih baru) diperlukan untuk WSL2 & Docker.")
} else {
    Write-SRLog -Level "SUCCESS" -Message "Versi Windows memenuhi syarat: $osCaption (Build $buildNumber)" -InstallDir $InstallDir
}

# 4. Check Total Physical RAM (Block < 4 GB, Warn < 8 GB, Pass >= 8 GB)
$totalRamBytes = if ($null -ne $osInfo) { [int64]$osInfo.TotalVisibleMemorySize * 1024 } else { 0 }
$totalRamGB = [math]::Round($totalRamBytes / 1GB, 2)
if ($totalRamGB -lt 3.8) {
    $blockers.Add("RAM fisik tidak mencukupi ($totalRamGB GB). Minimal 4 GB diperlukan (disarankan >= 8 GB).")
} elseif ($totalRamGB -lt 7.5) {
    $warnings.Add("RAM fisik terdeteksi $totalRamGB GB. Sistem dapat berjalan, namun disarankan minimal 8 GB untuk performa optimal Docker Desktop + MySQL.")
    Write-SRLog -Level "WARN" -Message "RAM fisik: $totalRamGB GB (Peringatan: disarankan >= 8 GB)" -InstallDir $InstallDir
} else {
    Write-SRLog -Level "SUCCESS" -Message "RAM fisik mencukupi: $totalRamGB GB" -InstallDir $InstallDir
}

# 5. Check Free Disk Space (>= 10 GB for fresh install, >= 4 GB for upgrade/repair)
$driveLetter = (Split-Path -Qualifier $InstallDir).TrimEnd(':')
$disk = Get-PSDrive -Name $driveLetter -ErrorAction SilentlyContinue
$freeDiskGB = if ($null -ne $disk) { [math]::Round($disk.Free / 1GB, 2) } else { 0 }
$minDiskGB = if ($installMode -eq "FRESH_INSTALL") { 10 } else { 4 }

if ($freeDiskGB -lt $minDiskGB) {
    $blockers.Add("Ruang penyimpanan bebas pada drive ${driveLetter}: hanya $freeDiskGB GB. Minimal $minDiskGB GB diperlukan untuk mode $installMode.")
} else {
    Write-SRLog -Level "SUCCESS" -Message "Ruang disk bebas pada drive ${driveLetter}: $freeDiskGB GB (Minimal $minDiskGB GB)" -InstallDir $InstallDir
}

# 6. Check CPU Virtualization Support & BIOS/UEFI Activation
# NOTE: Never attempt automatic BIOS modification! Guide technician clearly if disabled.
$csInfo = Get-CimInstance -ClassName Win32_ComputerSystem -ErrorAction SilentlyContinue
$procs = @(Get-CimInstance -ClassName Win32_Processor -ErrorAction SilentlyContinue)
$hypervisorPresent = if ($null -ne $csInfo -and $null -ne $csInfo.HypervisorPresent) { [bool]$csInfo.HypervisorPresent } else { $false }
$virtFirmwareEnabled = $false
foreach ($p in $procs) {
    if ($p.VirtualizationFirmwareEnabled -eq $true) {
        $virtFirmwareEnabled = $true
    }
}

if (-not $hypervisorPresent -and -not $virtFirmwareEnabled) {
    $biosGuide = @"
Virtualisasi CPU (Intel VT-x / AMD-V SVM) masih NONAKTIF di BIOS/UEFI PC Kasir!
Installer TIDAK memodifikasi BIOS secara otomatis demi keamanan perangkat keras.
Langkah Mengaktifkan Virtualisasi di BIOS/UEFI:
  1. Restart PC Kasir dan tekan tombol BIOS (Del / F2 / F10 / F12 tergantung merk motherboard).
  2. Untuk prosesor Intel: Buka menu Advanced -> CPU Configuration -> aktifkan 'Intel Virtualization Technology (VT-x)'.
  3. Untuk prosesor AMD: Buka menu Advanced -> CPU Configuration -> aktifkan 'SVM Mode' (AMD-V).
  4. Tekan F10 (Save & Exit), lalu jalankan kembali installer Sugeng Rawuh setelah masuk Windows.
"@
    Write-SRLog -Level "ERROR" -Message $biosGuide -InstallDir $InstallDir
    $blockers.Add("Virtualisasi CPU (Intel VT-x / AMD-V SVM) belum diaktifkan di BIOS/UEFI.")
} else {
    Write-SRLog -Level "SUCCESS" -Message "Virtualisasi CPU (BIOS/UEFI / Hypervisor) aktif." -InstallDir $InstallDir
}

# 7. Check Pending Windows Reboot
$pendingRebootPaths = @(
    "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending",
    "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired"
)
foreach ($rp in $pendingRebootPaths) {
    if (Test-Path -LiteralPath $rp) {
        $rebootPending = $true
    }
}

# 8. Check WSL2 Status & Windows Optional Features
$wslInstalled = $false
if (Get-Command "wsl.exe" -ErrorAction SilentlyContinue) {
    try {
        $null = & wsl.exe --status 2>$null
        if ($LASTEXITCODE -eq 0) {
            $wslInstalled = $true
        }
    } catch {}
}

if (Test-SRAdmin) {
    try {
        $featWsl = Get-WindowsOptionalFeature -Online -FeatureName "Microsoft-Windows-Subsystem-Linux" -ErrorAction SilentlyContinue
        $featVmp = Get-WindowsOptionalFeature -Online -FeatureName "VirtualMachinePlatform" -ErrorAction SilentlyContinue
        if (($null -ne $featWsl -and $featWsl.State -ne "Enabled") -or ($null -ne $featVmp -and $featVmp.State -ne "Enabled")) {
            $wslInstalled = $false
        }
    } catch {}
}

if (-not $wslInstalled) {
    $prereqsNeeded.Add("WSL2")
    Write-SRLog -Level "WARN" -Message "Fitur WSL2 / VirtualMachinePlatform belum aktif sepenuhnya." -InstallDir $InstallDir
} else {
    Write-SRLog -Level "SUCCESS" -Message "Komponen WSL2 terdeteksi aktif." -InstallDir $InstallDir
}

# 9. Check Docker Desktop Installation & Daemon Readiness
$dockerDesktopExe = "C:\Program Files\Docker\Docker\Docker Desktop.exe"
$dockerInstalled = (Test-Path -LiteralPath $dockerDesktopExe) -or ($null -ne (Get-Command "docker.exe" -ErrorAction SilentlyContinue))
$dockerRunning = $false

if (-not $dockerInstalled) {
    $prereqsNeeded.Add("DockerDesktop")
    Write-SRLog -Level "WARN" -Message "Docker Desktop belum terinstal di $dockerDesktopExe." -InstallDir $InstallDir
} else {
    Write-SRLog -Level "SUCCESS" -Message "Docker Desktop terdeteksi terinstal." -InstallDir $InstallDir
    try {
        $null = & docker info 2>$null
        if ($LASTEXITCODE -eq 0) {
            $dockerRunning = $true
            Write-SRLog -Level "SUCCESS" -Message "Docker Engine daemon berstatus READY." -InstallDir $InstallDir
        } else {
            Write-SRLog -Level "WARN" -Message "Docker Desktop terinstal, tetapi Docker Engine daemon belum berjalan (akan dijalankan otomatis saat instalasi)." -InstallDir $InstallDir
        }
    } catch {
        Write-SRLog -Level "WARN" -Message "Docker Engine daemon belum merespons." -InstallDir $InstallDir
    }
}

# 10. Check Application Port Availability (Default 8008)
$portListeners = @(Get-NetTCPConnection -LocalPort $AppPort -State Listen -ErrorAction SilentlyContinue)
if ($portListeners.Count -gt 0) {
    $ownedBySugengRawuh = $false
    if ($dockerRunning) {
        try {
            $runningWeb = & docker ps --filter "name=sugeng_rawuh_web" --format "{{.Names}}" 2>$null
            if ($runningWeb -match "sugeng_rawuh_web") {
                $ownedBySugengRawuh = $true
            }
        } catch {}
    }

    if ($ownedBySugengRawuh) {
        Write-SRLog -Level "INFO" -Message "Port $AppPort sedang digunakan oleh container sugeng_rawuh_web (instalasi Sugeng Rawuh yang aktif)." -InstallDir $InstallDir
    } else {
        $pidList = ($portListeners | Select-Object -ExpandProperty OwningProcess -Unique) -join ", "
        $warnings.Add("Port TCP $AppPort sedang digunakan oleh proses lain (PID: $pidList). Pastikan tidak ada aplikasi lain yang memakai port $AppPort atau sesuaikan APP_PORT di .env.production.")
        Write-SRLog -Level "WARN" -Message "Port TCP $AppPort sedang digunakan oleh PID: $pidList" -InstallDir $InstallDir
    }
} else {
    Write-SRLog -Level "SUCCESS" -Message "Port aplikasi TCP $AppPort tersedia." -InstallDir $InstallDir
}

# 11. Check Active Physical LAN Network Adapter
$lanAdapters = @(
    Get-NetIPConfiguration -ErrorAction SilentlyContinue | Where-Object {
        $null -ne $_.IPv4Address -and
        $_.NetAdapter.Status -eq "Up" -and
        $_.InterfaceAlias -notmatch "vEthernet|WSL|Docker|Loopback|VMware|VirtualBox|Bluetooth" -and
        $_.NetAdapter.InterfaceDescription -notmatch "Hyper-V|Virtual|Loopback|VPN|TAP"
    }
)

if ($lanAdapters.Count -eq 0) {
    $warnings.Add("Tidak ditemukan adaptor jaringan fisik (Ethernet/Wi-Fi) yang aktif. Koneksi LAN ke router MikroTik diperlukan untuk kontrol Samsung Smart TV dan konsol PlayStation.")
    Write-SRLog -Level "WARN" -Message "Adaptor LAN fisik belum terhubung." -InstallDir $InstallDir
} else {
    $ipSummary = ($lanAdapters | ForEach-Object { "$($_.InterfaceAlias): $($_.IPv4Address[0].IPAddress)" }) -join "; "
    Write-SRLog -Level "SUCCESS" -Message "Adaptor LAN fisik aktif terdeteksi: $ipSummary" -InstallDir $InstallDir
}

# Determine Final Exit Code
$exitCode = 0
$statusLabel = "READY"

if ($blockers.Count -gt 0) {
    $exitCode = 1
    $statusLabel = "BLOCKED"
} elseif ($prereqsNeeded.Count -gt 0) {
    $exitCode = 10
    $statusLabel = "PREREQUISITES_NEEDED"
} elseif ($rebootPending) {
    $exitCode = 20
    $statusLabel = "REBOOT_PENDING"
}

$summary = [ordered]@{
    Status               = $statusLabel
    ExitCode             = $exitCode
    InstallMode          = $installMode
    WindowsCaption       = $osCaption
    WindowsBuild         = $buildNumber
    Architecture         = $arch
    TotalRamGB           = $totalRamGB
    FreeDiskGB           = $freeDiskGB
    VirtualizationActive = ($hypervisorPresent -or $virtFirmwareEnabled)
    Wsl2Ready            = $wslInstalled
    DockerInstalled      = $dockerInstalled
    DockerRunning        = $dockerRunning
    AppPort              = $AppPort
    PrereqsNeeded        = $prereqsNeeded
    Warnings             = $warnings
    Blockers             = $blockers
}

if ($JsonOutput) {
    $summary | ConvertTo-Json -Depth 4
} else {
    Write-Host ""
    Write-Host "==============================================================" -ForegroundColor Cyan
    Write-Host " RINGKASAN PREFLIGHT CHECK - SUGENG RAWUH BILLING & POS" -ForegroundColor Cyan
    Write-Host "==============================================================" -ForegroundColor Cyan
    Write-Host " Mode Instalasi     : $installMode"
    Write-Host " Sistem Operasi     : $osCaption (Build $buildNumber, $arch)"
    Write-Host " Memori RAM         : $totalRamGB GB"
    Write-Host " Ruang Disk Bebas   : $freeDiskGB GB"
    Write-Host " Virtualisasi BIOS  : $(if ($hypervisorPresent -or $virtFirmwareEnabled) { 'AKTIF' } else { 'NONAKTIF' })"
    Write-Host " Status WSL2        : $(if ($wslInstalled) { 'SIAP' } else { 'PERLU INSTALASI' })"
    Write-Host " Docker Desktop     : $(if ($dockerInstalled) { 'TERINSTAL' } else { 'BELUM TERINSTAL' })"
    Write-Host " Status Akhir       : $statusLabel (Exit Code: $exitCode)"
    Write-Host "==============================================================" -ForegroundColor Cyan
}

exit $exitCode

