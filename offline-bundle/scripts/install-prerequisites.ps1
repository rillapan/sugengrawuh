#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Stage D.B Prerequisite Installation & Reboot-Resume State Machine
.DESCRIPTION
    Enables WSL2 & VirtualMachinePlatform Windows features (without installing an unnecessary Ubuntu distro),
    installs WSL2 kernel update and Docker Desktop if bundled in installer\prereqs\, registers RunOnce
    reboot-resume hook, and configures Docker Desktop auto-start on login.
    Exit codes:
      0  = All prerequisites installed and ready
      20 = Reboot required (RunOnce registered to resume automatically after restart)
      1  = Prerequisite binary missing or installation failed
#>

param(
    [string]$InstallDir = "C:\SugengRawuh",
    [switch]$AutoReboot
)

$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "common.ps1")

Assert-SRAdmin -ScriptPath $MyInvocation.MyCommand.Path -ScriptArgs @("-InstallDir", $InstallDir)
Ensure-SRDirectories -InstallDir $InstallDir

Write-SRLog -Level "INFO" -Message "=== Memulai Instalasi Prasyarat (Stage D.B: WSL2 & Docker Desktop) ===" -InstallDir $InstallDir

$existingState = Get-SRInstallState
$rebootCount = if ($null -ne $existingState -and $null -ne $existingState.RebootCount) { [int]$existingState.RebootCount } else { 0 }
$installMode = if ($null -ne $existingState -and -not [string]::IsNullOrWhiteSpace($existingState.Mode)) { [string]$existingState.Mode } else { "FRESH_INSTALL" }

$restartNeeded = $false
$prereqsDir = Join-Path $InstallDir "installer\prereqs"
$offlinePrereqsDir = Join-Path $InstallDir "offline-bundle\prereqs"

# 1. Enable Windows Optional Features for WSL2 (No separate Ubuntu distribution needed for Docker Desktop WSL2 backend!)
$requiredFeatures = @(
    "Microsoft-Windows-Subsystem-Linux",
    "VirtualMachinePlatform"
)

foreach ($featureName in $requiredFeatures) {
    Write-SRLog -Level "INFO" -Message "Memeriksa fitur Windows: $featureName..." -InstallDir $InstallDir
    $feat = Get-WindowsOptionalFeature -Online -FeatureName $featureName -ErrorAction SilentlyContinue
    if ($null -eq $feat -or $feat.State -ne "Enabled") {
        Write-SRLog -Level "INFO" -Message "Mengaktifkan fitur Windows: $featureName (tanpa distro Ubuntu terpisah)..." -InstallDir $InstallDir
        $res = Enable-WindowsOptionalFeature -Online -FeatureName $featureName -All -NoRestart -ErrorAction Stop
        if ($res.RestartNeeded) {
            $restartNeeded = $true
        }
    } else {
        Write-SRLog -Level "SUCCESS" -Message "Fitur Windows $featureName sudah aktif." -InstallDir $InstallDir
    }
}

# 2. Install WSL2 Linux Kernel Update (offline MSI if bundled, otherwise wsl --install --no-distribution)
$wslMsiCandidates = @(
    (Join-Path $prereqsDir "wsl_update_x64.msi"),
    (Join-Path $offlinePrereqsDir "wsl_update_x64.msi")
)
$wslMsiPath = $wslMsiCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1

if ($null -ne $wslMsiPath) {
    Write-SRLog -Level "INFO" -Message "Menginstal paket kernel WSL2 offline dari: $wslMsiPath" -InstallDir $InstallDir
    $msiProc = Start-Process -FilePath "msiexec.exe" -ArgumentList @("/i", "`"$wslMsiPath`"", "/quiet", "/norestart") -Wait -PassThru
    if ($msiProc.ExitCode -eq 3010) {
        $restartNeeded = $true
    } elseif ($msiProc.ExitCode -ne 0) {
        Write-SRLog -Level "WARN" -Message "msiexec wsl_update_x64.msi selesai dengan kode $($msiProc.ExitCode)." -InstallDir $InstallDir
    }
} else {
    Write-SRLog -Level "INFO" -Message "Paket offline wsl_update_x64.msi tidak ditemukan di prereqs/. Mencoba 'wsl.exe --install --no-distribution'..." -InstallDir $InstallDir
    try {
        & wsl.exe --set-default-version 2 2>$null
        if ($LASTEXITCODE -ne 0) {
            & wsl.exe --install --no-distribution 2>$null
            if ($LASTEXITCODE -ne 0) {
                Write-SRLog -Level "WARN" -Message "Perhatian: 'wsl --install --no-distribution' memerlukan koneksi internet atau file installer\prereqs\wsl_update_x64.msi." -InstallDir $InstallDir
            }
        }
    } catch {
        Write-SRLog -Level "WARN" -Message "Gagal menjalankan wsl.exe secara otomatis: $($_.Exception.Message)" -InstallDir $InstallDir
    }
}

# If Windows requires a reboot before Docker Desktop can be installed or started, persist state & RunOnce
if ($restartNeeded) {
    Save-SRInstallState -Stage "PREREQ_WSL_REBOOT" -InstallDir $InstallDir -Mode $installMode -RebootCount ($rebootCount + 1)
    Register-SRRunOnceResume -InstallDir $InstallDir
    Write-SRLog -Level "WARN" -Message "Restart Windows diperlukan untuk menyelesaikan aktivasi fitur WSL2 & VirtualMachinePlatform." -InstallDir $InstallDir
}

# 3. Check & Install Docker Desktop
$dockerDesktopExe = "C:\Program Files\Docker\Docker\Docker Desktop.exe"
$dockerInstalled = (Test-Path -LiteralPath $dockerDesktopExe) -or ($null -ne (Get-Command "docker.exe" -ErrorAction SilentlyContinue))

if (-not $dockerInstalled) {
    $dockerInstallerCandidates = @(
        (Join-Path $prereqsDir "DockerDesktopInstaller.exe"),
        (Join-Path $offlinePrereqsDir "DockerDesktopInstaller.exe")
    )
    $dockerInstallerPath = $dockerInstallerCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1

    if ($null -ne $dockerInstallerPath) {
        Write-SRLog -Level "INFO" -Message "Menginstal Docker Desktop secara otomatis dari: $dockerInstallerPath" -InstallDir $InstallDir
        $dockerArgs = @("install", "--quiet", "--accept-license", "--backend=wsl-2", "--always-run-service")
        $dockerProc = Start-Process -FilePath $dockerInstallerPath -ArgumentList $dockerArgs -Wait -PassThru
        if ($dockerProc.ExitCode -eq 3010) {
            $restartNeeded = $true
        } elseif ($dockerProc.ExitCode -ne 0) {
            Write-SRLog -Level "ERROR" -Message "Instalasi Docker Desktop gagal dengan kode keluar $($dockerProc.ExitCode)." -InstallDir $InstallDir
            exit 1
        }
        Write-SRLog -Level "SUCCESS" -Message "Instalasi Docker Desktop selesai." -InstallDir $InstallDir
    } else {
        # Honest guidance: never claim offline Docker installation if DockerDesktopInstaller.exe is not bundled!
        $missingDockerMsg = @"
PERHATIAN TEKNISI: File 'DockerDesktopInstaller.exe' tidak disertakan di dalam paket installer ini.
Installer tidak akan mengklaim instalasi offline berhasil tanpa binary Docker Desktop yang valid.

Silakan lakukan salah satu langkah berikut:
  1. Salin file 'DockerDesktopInstaller.exe' (x86_64 Windows) ke folder:
     $prereqsDir\DockerDesktopInstaller.exe
     lalu jalankan ulang installer ini, ATAU
  2. Instal Docker Desktop for Windows (WSL2 backend) secara manual, lalu jalankan ulang shortcut
     'Sugeng Rawuh Billing & POS' di Desktop.
"@
        Write-SRLog -Level "ERROR" -Message $missingDockerMsg -InstallDir $InstallDir
        Save-SRInstallState -Stage "WAITING_FOR_DOCKER_INSTALLER" -InstallDir $InstallDir -Mode $installMode -RebootCount $rebootCount
        exit 1
    }
} else {
    Write-SRLog -Level "SUCCESS" -Message "Docker Desktop sudah terinstal di sistem." -InstallDir $InstallDir
}

# 4. Configure Docker Desktop Auto-Start on Windows Login
if (Test-Path -LiteralPath $dockerDesktopExe) {
    $hkcuRunPath = "HKCU:\Software\Microsoft\Windows\CurrentVersion\Run"
    if (-not (Test-Path -LiteralPath $hkcuRunPath)) {
        New-Item -Path $hkcuRunPath -Force | Out-Null
    }
    Set-ItemProperty -Path $hkcuRunPath -Name "Docker Desktop" -Value "`"$dockerDesktopExe`"" -Type String -Force
    Write-SRLog -Level "SUCCESS" -Message "Docker Desktop dikonfigurasi untuk berjalan otomatis saat login Windows." -InstallDir $InstallDir
}

# 5. Handle Reboot Requirement & RunOnce Resume
if ($restartNeeded) {
    Save-SRInstallState -Stage "PREREQ_REBOOT_PENDING" -InstallDir $InstallDir -Mode $installMode -RebootCount ($rebootCount + 1)
    Register-SRRunOnceResume -InstallDir $InstallDir

    Write-SRLog -Level "WARN" -Message "Sistem membutuhkan restart Windows. Instalasi Sugeng Rawuh akan dilanjutkan secara otomatis setelah login (via RunOnce)." -InstallDir $InstallDir

    if ($AutoReboot) {
        Write-SRLog -Level "INFO" -Message "Melakukan restart Windows otomatis dalam 10 detik..." -InstallDir $InstallDir
        & shutdown.exe /r /t 10 /c "Sugeng Rawuh Billing & POS: Restart untuk mengaktifkan WSL2 & Docker. Instalasi akan lanjut otomatis setelah menyala."
    }
    exit 20
}

Save-SRInstallState -Stage "PREREQ_COMPLETED" -InstallDir $InstallDir -Mode $installMode -RebootCount $rebootCount
Write-SRLog -Level "SUCCESS" -Message "Semua prasyarat sistem (WSL2 & Docker Desktop) telah siap." -InstallDir $InstallDir
exit 0

