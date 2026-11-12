#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Stage D.A Preflight System Inspection
.DESCRIPTION
    Inspects Windows build, CPU architecture, RAM, dual-drive disk space (Application target drive D:\ vs System drive C:\),
    BIOS Virtualization (Intel VT-x / AMD-V), WSL2 status, Docker Desktop installation & engine readiness,
    port 8008 availability, LAN connectivity, and detects FRESH_INSTALL vs UPGRADE_OR_REPAIR mode.
    Exit codes:
      0  = Ready to install application
      10 = Prerequisites needed (WSL2 / Docker Desktop installation required)
      20 = System reboot pending for WSL2 / prerequisite activation
      1  = Hardware / OS / BIOS requirement not met (blocking)
#>

param(
    [string]$InstallDir = "",
    [int]$AppPort = 8008,
    [switch]$JsonOutput
)

$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "common.ps1")

$InstallDir = Get-SRInstallDir -InstallDir $InstallDir
Ensure-SRDirectories -InstallDir $InstallDir
Write-SRLog -Level "INFO" -Message "=== Memulai Pemeriksaan Sistem (Preflight Check Stage D.A) pada $InstallDir ===" -InstallDir $InstallDir

$blockers = New-Object System.Collections.Generic.List[string]
$warnings = New-Object System.Collections.Generic.List[string]
$prereqsNeeded = New-Object System.Collections.Generic.List[string]
$windowsUpdateRebootPending = $false
$wslRebootPending = $false
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

# 5. Dual-Disk Capacity Check (Application Drive vs System Drive C:\ for WSL2/Docker vhdx)
$rawAppQualifier = Split-Path -Qualifier $InstallDir
$appDriveLetter = if (-not [string]::IsNullOrWhiteSpace($rawAppQualifier)) { $rawAppQualifier.TrimEnd(':').ToUpperInvariant() } else { "C" }
$rawSysDrive = if (-not [string]::IsNullOrWhiteSpace($env:SystemDrive)) { $env:SystemDrive } else { "C:" }
$sysDriveLetter = $rawSysDrive.TrimEnd(':').ToUpperInvariant()

$appDisk = Get-PSDrive -Name $appDriveLetter -ErrorAction SilentlyContinue
$appDiskFreeGB = if ($null -ne $appDisk) { [math]::Round($appDisk.Free / 1GB, 2) } else { 0 }
$freeDiskGB = $appDiskFreeGB

$sysDisk = Get-PSDrive -Name $sysDriveLetter -ErrorAction SilentlyContinue
$sysDiskFreeGB = if ($null -ne $sysDisk) { [math]::Round($sysDisk.Free / 1GB, 2) } else { $appDiskFreeGB }

if ($appDriveLetter -eq $sysDriveLetter) {
    $minDiskGB = if ($installMode -eq "FRESH_INSTALL") { 10 } else { 4 }
    if ($appDiskFreeGB -lt $minDiskGB) {
        $blockers.Add("Ruang penyimpanan bebas pada drive ${appDriveLetter}: hanya $appDiskFreeGB GB. Minimal $minDiskGB GB diperlukan untuk mode $installMode.")
    } else {
        Write-SRLog -Level "SUCCESS" -Message "Ruang disk bebas pada drive ${appDriveLetter}: $appDiskFreeGB GB (Minimal $minDiskGB GB)" -InstallDir $InstallDir
    }
} else {
    # Installing to a secondary drive (e.g., D:\SugengRawuh when C:\ is small)
    $minAppDiskGB = if ($installMode -eq "FRESH_INSTALL") { 4 } else { 2 }
    if ($appDiskFreeGB -lt $minAppDiskGB) {
        $blockers.Add("Ruang penyimpanan bebas pada drive target aplikasi ${appDriveLetter}: hanya $appDiskFreeGB GB. Minimal $minAppDiskGB GB diperlukan untuk mode $installMode.")
    } else {
        Write-SRLog -Level "SUCCESS" -Message "Ruang disk bebas pada drive target aplikasi ${appDriveLetter}: $appDiskFreeGB GB (Minimal $minAppDiskGB GB)" -InstallDir $InstallDir
    }

    if ($sysDiskFreeGB -lt 3 -and $installMode -eq "FRESH_INSTALL") {
        $blockers.Add("Ruang bebas pada drive sistem ${sysDriveLetter}: sangat kritis ($sysDiskFreeGB GB). Minimal 3 GB diperlukan pada ${sysDriveLetter}: untuk komponen dasar WSL2/Docker Desktop sebelum lokasi disk image dipindahkan ke ${appDriveLetter}:.")
    } elseif ($sysDiskFreeGB -lt 8) {
        $vhdxGuidance = "Ruang bebas drive sistem C: terbatas ($sysDiskFreeGB GB). Anda dapat memindahkan lokasi data Docker Desktop secara resmi melalui: Docker Desktop -> Settings -> Resources -> Advanced -> Disk image location ke drive ${appDriveLetter}: (jangan memindahkan file .vhdx secara manual)."
        $warnings.Add($vhdxGuidance)
        Write-SRLog -Level "WARN" -Message $vhdxGuidance -InstallDir $InstallDir
    } else {
        Write-SRLog -Level "SUCCESS" -Message "Ruang disk bebas pada drive sistem ${sysDriveLetter}: $sysDiskFreeGB GB." -InstallDir $InstallDir
    }
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

# 7. Check Pending Windows Reboot (Separate Windows Update Reboot Pending from WSL2 Prerequisite Reboot Pending)
$pendingRebootPaths = @(
    "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending",
    "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired"
)
foreach ($rp in $pendingRebootPaths) {
    if (Test-Path -LiteralPath $rp) {
        $windowsUpdateRebootPending = $true
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
        if (($null -ne $featWsl -and $featWsl.RestartNeeded) -or ($null -ne $featVmp -and $featVmp.RestartNeeded)) {
            $wslRebootPending = $true
        }
    } catch {}
}

$savedInstallState = Get-SRInstallState
if ($null -ne $savedInstallState -and $savedInstallState.Stage -match "REBOOT" -and -not $wslInstalled) {
    $wslRebootPending = $true
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

# Smart Reboot Pending Evaluation:
# If WSL2 and Docker Desktop are already installed, do NOT halt installation with exit code 20 solely due to a background Windows Update flag.
if ($windowsUpdateRebootPending) {
    if ($wslInstalled -and $dockerInstalled) {
        $warnings.Add("Terdeteksi status Windows Update Reboot Pending di latar belakang, namun WSL2 & Docker Desktop sudah aktif sehingga instalasi dapat dilanjutkan tanpa restart paksa.")
        Write-SRLog -Level "WARN" -Message "Windows Update RebootPending terdeteksi, namun dilewati karena WSL2 & Docker Desktop sudah siap." -InstallDir $InstallDir
    } else {
        $wslRebootPending = $true
    }
}
$rebootPending = $wslRebootPending

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
} elseif ($rebootPending) {
    $exitCode = 20
    $statusLabel = "REBOOT_PENDING"
} elseif ($prereqsNeeded.Count -gt 0) {
    $exitCode = 10
    $statusLabel = "PREREQUISITES_NEEDED"
}

$summary = [ordered]@{
    Status                     = $statusLabel
    ExitCode                   = $exitCode
    InstallDir                 = $InstallDir
    InstallMode                = $installMode
    WindowsCaption             = $osCaption
    WindowsBuild               = $buildNumber
    Architecture               = $arch
    TotalRamGB                 = $totalRamGB
    AppDriveLetter             = $appDriveLetter
    AppDiskFreeGB              = $appDiskFreeGB
    SysDriveLetter             = $sysDriveLetter
    SysDiskFreeGB              = $sysDiskFreeGB
    FreeDiskGB                 = $freeDiskGB
    VirtualizationActive       = ($hypervisorPresent -or $virtFirmwareEnabled)
    Wsl2Ready                  = $wslInstalled
    DockerInstalled            = $dockerInstalled
    DockerRunning              = $dockerRunning
    WindowsUpdateRebootPending = $windowsUpdateRebootPending
    WslRebootPending           = $wslRebootPending
    AppPort                    = $AppPort
    PrereqsNeeded              = $prereqsNeeded
    Warnings                   = $warnings
    Blockers                   = $blockers
}

if ($JsonOutput) {
    $summary | ConvertTo-Json -Depth 4
} else {
    Write-Host ""
    Write-Host "==============================================================" -ForegroundColor Cyan
    Write-Host " RINGKASAN PREFLIGHT CHECK - SUGENG RAWUH BILLING & POS" -ForegroundColor Cyan
    Write-Host "==============================================================" -ForegroundColor Cyan
    Write-Host " Lokasi Instalasi   : $InstallDir (Drive ${appDriveLetter}: $appDiskFreeGB GB bebas)"
    if ($appDriveLetter -ne $sysDriveLetter) {
        Write-Host " Drive Sistem (${sysDriveLetter}:)  : $sysDiskFreeGB GB bebas"
    }
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
