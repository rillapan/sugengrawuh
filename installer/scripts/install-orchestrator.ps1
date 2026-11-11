#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Master Installation Orchestrator & Reboot-Resume Controller
.DESCRIPTION
    Orchestrates Stage D.A (preflight-check.ps1), Stage D.B (install-prerequisites.ps1),
    Stage D.C (install-app.ps1), Stage D.D/E (configure-network.ps1 & wol-helper.ps1),
    and Stage E (startup-launcher.ps1). Supports multi-reboot continuation via Windows RunOnce
    and C:\ProgramData\SugengRawuh\install-state.json.
#>

param(
    [string]$InstallDir = "C:\SugengRawuh",
    [switch]$Resume,
    [switch]$Silent
)

$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "common.ps1")

$forwardArgs = @("-InstallDir", $InstallDir)
if ($Resume) { $forwardArgs += "-Resume" }
if ($Silent) { $forwardArgs += "-Silent" }

Assert-SRAdmin -ScriptPath $MyInvocation.MyCommand.Path -ScriptArgs $forwardArgs
Ensure-SRDirectories -InstallDir $InstallDir

if ($Resume) {
    Write-SRLog -Level "INFO" -Message "=== Melanjutkan Instalasi Sugeng Rawuh Pasca-Reboot (RunOnce Resume) ===" -InstallDir $InstallDir
} else {
    Write-SRLog -Level "INFO" -Message "=== Memulai Orkestrasi Instalasi Sugeng Rawuh Billing & POS ===" -InstallDir $InstallDir
}

$preflightScript  = Join-Path $PSScriptRoot "preflight-check.ps1"
$prereqScript     = Join-Path $PSScriptRoot "install-prerequisites.ps1"
$installAppScript = Join-Path $PSScriptRoot "install-app.ps1"
$networkScript    = Join-Path $PSScriptRoot "configure-network.ps1"
$launcherScript   = Join-Path $PSScriptRoot "startup-launcher.ps1"

# 1. Stage D.A: Preflight System Check
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $preflightScript -InstallDir $InstallDir
$preflightExit = $LASTEXITCODE

if ($preflightExit -eq 1) {
    Write-SRLog -Level "ERROR" -Message "Preflight Check gagal (Persyaratan Perangkat Keras / OS / BIOS Virtualisasi belum terpenuhi). Lihat log di data\logs\installer.log." -InstallDir $InstallDir
    exit 1
}

# 2. Stage D.B: Prerequisite Installation (if WSL2 / Docker Desktop needed or reboot pending)
if ($preflightExit -eq 10 -or $preflightExit -eq 20) {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $prereqScript -InstallDir $InstallDir
    $prereqExit = $LASTEXITCODE

    if ($prereqExit -eq 20) {
        Write-SRLog -Level "WARN" -Message "Restart Windows diperlukan. RunOnce telah didaftarkan agar instalasi berlanjut otomatis setelah restart." -InstallDir $InstallDir
        if (-not $Silent) {
            try {
                Add-Type -AssemblyName System.Windows.Forms
                $ans = [System.Windows.Forms.MessageBox]::Show(
                    "Komponen WSL2 / Docker Desktop telah diaktifkan dan membutuhkan Restart Windows.`r`n`r`nInstalasi Sugeng Rawuh akan dilanjutkan secara OTOMATIS setelah PC Kasir menyala kembali.`r`n`r`nRestart PC Kasir sekarang?",
                    "Sugeng Rawuh Billing & POS - Restart Diperlukan",
                    [System.Windows.Forms.MessageBoxButtons]::YesNo,
                    [System.Windows.Forms.MessageBoxIcon]::Information
                )
                if ($ans -eq [System.Windows.Forms.DialogResult]::Yes) {
                    & shutdown.exe /r /t 5 /c "Sugeng Rawuh POS: Restart untuk menyelesaikan aktivasi WSL2/Docker."
                }
            } catch {}
        }
        exit 20
    } elseif ($prereqExit -ne 0) {
        Write-SRLog -Level "ERROR" -Message "Instalasi prasyarat belum selesai (Exit Code: $prereqExit)." -InstallDir $InstallDir
        exit $prereqExit
    }
}

# 3. Stage D.C: Install & Start Sugeng Rawuh Containers
Save-SRInstallState -Stage "INSTALLING_APP" -InstallDir $InstallDir
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $installAppScript -InstallDir $InstallDir
if ($LASTEXITCODE -ne 0) {
    Write-SRLog -Level "ERROR" -Message "Instalasi aplikasi (install-app.ps1) gagal dengan kode keluar $LASTEXITCODE." -InstallDir $InstallDir
    exit $LASTEXITCODE
}

# 4. Stage D.D: Configure Local Network, Firewall & MikroTik DHCP Guide
Save-SRInstallState -Stage "CONFIGURING_NETWORK" -InstallDir $InstallDir
$netArgs = @("-NoProfile", "-ExecutionPolicy", "Bypass", "-File", $networkScript, "-InstallDir", $InstallDir)
if (-not $Silent) {
    $netArgs += "-Interactive"
}
& powershell.exe @netArgs
if ($LASTEXITCODE -ne 0) {
    Write-SRLog -Level "WARN" -Message "Konfigurasi jaringan selesai dengan catatan (Exit Code: $LASTEXITCODE)." -InstallDir $InstallDir
}

# 5. Clean up install state & RunOnce hook upon successful completion
Clear-SRInstallState
Write-SRLog -Level "SUCCESS" -Message "=== Seluruh Tahap Instalasi Sugeng Rawuh Selesai dengan Sukses! ===" -InstallDir $InstallDir

# 6. Stage E: Launch Cashier Startup & Open Browser
if (-not $Silent) {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $launcherScript -InstallDir $InstallDir
}
exit 0

