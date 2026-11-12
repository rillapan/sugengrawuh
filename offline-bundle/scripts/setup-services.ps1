#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Idempotent Service Registration, Shortcuts & Safe Repair Orchestrator
.DESCRIPTION
    Registers and ensures a single instance of SugengRawuh-WOLHelper (TCP 18009),
    registers SugengRawuh-DailyBackup while preserving any customized schedule time,
    synchronizes Windows Defender Firewall & LAN network metadata via configure-network.ps1,
    creates Desktop & Start Menu shortcuts (Cashier Launcher, Hardware Setup & Test Center, Repair Service),
    and performs non-destructive service & container recovery when invoked with -Repair.
#>

param(
    [string]$InstallDir = "",
    [switch]$Repair,
    [switch]$Interactive,
    [switch]$ForceResetBackupSchedule
)

$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "common.ps1")

$InstallDir = Get-SRInstallDir -InstallDir $InstallDir

$forwardArgs = @("-InstallDir", $InstallDir)
if ($Repair) { $forwardArgs += "-Repair" }
if ($Interactive) { $forwardArgs += "-Interactive" }
if ($ForceResetBackupSchedule) { $forwardArgs += "-ForceResetBackupSchedule" }

Assert-SRAdmin -ScriptPath $MyInvocation.MyCommand.Path -ScriptArgs $forwardArgs
Ensure-SRDirectories -InstallDir $InstallDir
Save-SRInstalledLocation -InstallDir $InstallDir

$modeLabel = if ($Repair) { "REPAIR / PEMULIHAN LAYANAN" } else { "REGISTRASI LAYANAN & SHORTCUT" }
Write-SRLog -Level "INFO" -Message "=== Memulai Setup Services ($modeLabel) pada $InstallDir ===" -InstallDir $InstallDir

$wolScriptPath      = Join-Path $PSScriptRoot "wol-helper.ps1"
$backupScriptPath   = Join-Path $PSScriptRoot "backup-db.ps1"
$networkScriptPath  = Join-Path $PSScriptRoot "configure-network.ps1"
$launcherScriptPath = Join-Path $PSScriptRoot "startup-launcher.ps1"
$selfScriptPath     = Join-Path $PSScriptRoot "setup-services.ps1"

# 1. Register & Ensure Single-Instance SugengRawuh-WOLHelper (Port 18009)
if (Test-Path -LiteralPath $wolScriptPath) {
    $wolHealthy = $false
    $wolListening = Get-NetTCPConnection -LocalPort 18009 -State Listen -ErrorAction SilentlyContinue
    if ($null -ne $wolListening) {
        try {
            $wolResp = Invoke-RestMethod -Uri "http://127.0.0.1:18009/health" -Method Get -TimeoutSec 2 -ErrorAction Stop
            if ($wolResp.status -eq "ok") {
                $wolHealthy = $true
            }
        } catch {}
    }

    try {
        $wolAction = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$wolScriptPath`" -InstallDir `"$InstallDir`""
        $wolTrigger = New-ScheduledTaskTrigger -AtLogOn
        $wolSettings = New-ScheduledTaskSettingsSet `
            -AllowStartIfOnBatteries `
            -DontStopIfGoingOnBatteries `
            -ExecutionTimeLimit ([TimeSpan]::Zero) `
            -MultipleInstances IgnoreNew `
            -RestartCount 3 `
            -RestartInterval (New-TimeSpan -Minutes 1)

        Register-ScheduledTask `
            -TaskName "SugengRawuh-WOLHelper" `
            -Action $wolAction `
            -Trigger $wolTrigger `
            -Settings $wolSettings `
            -Description "Sugeng Rawuh Physical LAN Wake-on-LAN Helper (Port 18009)" `
            -Force | Out-Null

        if ($wolHealthy) {
            Write-SRLog -Level "SUCCESS" -Message "Layanan SugengRawuh-WOLHelper sudah aktif dan sehat pada port 18009 (tidak membuat proses duplikat)." -InstallDir $InstallDir
        } else {
            Start-ScheduledTask -TaskName "SugengRawuh-WOLHelper" -ErrorAction SilentlyContinue
            Start-Sleep -Seconds 1
            $checkListen = Get-NetTCPConnection -LocalPort 18009 -State Listen -ErrorAction SilentlyContinue
            if ($null -eq $checkListen) {
                Start-Process -FilePath "powershell.exe" -ArgumentList @(
                    "-NoProfile", "-ExecutionPolicy", "Bypass", "-WindowStyle", "Hidden",
                    "-File", "`"$wolScriptPath`"", "-InstallDir", "`"$InstallDir`""
                ) -WindowStyle Hidden -ErrorAction SilentlyContinue | Out-Null
            }
            Write-SRLog -Level "SUCCESS" -Message "Layanan SugengRawuh-WOLHelper terdaftar dan dijalankan pada port 18009." -InstallDir $InstallDir
        }
    } catch {
        Write-SRLog -Level "WARN" -Message "Gagal mendaftarkan Scheduled Task SugengRawuh-WOLHelper, memastikan proses tunggal berjalan: $($_.Exception.Message)" -InstallDir $InstallDir
        if (-not $wolHealthy) {
            Start-Process -FilePath "powershell.exe" -ArgumentList @(
                "-NoProfile", "-ExecutionPolicy", "Bypass", "-WindowStyle", "Hidden",
                "-File", "`"$wolScriptPath`"", "-InstallDir", "`"$InstallDir`""
            ) -WindowStyle Hidden -ErrorAction SilentlyContinue | Out-Null
        }
    }
}

# 2. Register SugengRawuh-DailyBackup (Preserving Existing Schedule Unless -ForceResetBackupSchedule)
if (Test-Path -LiteralPath $backupScriptPath) {
    try {
        $backupAction = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$backupScriptPath`" -InstallDir `"$InstallDir`" -Reason `"daily-scheduled`""
        $backupSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable

        $existingTask = Get-ScheduledTask -TaskName "SugengRawuh-DailyBackup" -ErrorAction SilentlyContinue
        $backupTriggers = $null

        if ($null -ne $existingTask -and -not $ForceResetBackupSchedule -and $null -ne $existingTask.Triggers -and @($existingTask.Triggers).Count -gt 0) {
            $backupTriggers = $existingTask.Triggers
            Write-SRLog -Level "INFO" -Message "Mempertahankan jadwal waktu SugengRawuh-DailyBackup yang sudah ada." -InstallDir $InstallDir
        } else {
            $backupTriggers = @(New-ScheduledTaskTrigger -Daily -At "03:30AM")
            Write-SRLog -Level "INFO" -Message "Mengatur jadwal default SugengRawuh-DailyBackup pada pukul 03:30 WIB." -InstallDir $InstallDir
        }

        Register-ScheduledTask `
            -TaskName "SugengRawuh-DailyBackup" `
            -Action $backupAction `
            -Trigger $backupTriggers `
            -Settings $backupSettings `
            -Description "Backup Database Harian Otomatis Sugeng Rawuh POS" `
            -Force | Out-Null

        Write-SRLog -Level "SUCCESS" -Message "Scheduled Task SugengRawuh-DailyBackup terdaftar dan terverifikasi." -InstallDir $InstallDir
    } catch {
        Write-SRLog -Level "WARN" -Message "Gagal mendaftarkan Scheduled Task SugengRawuh-DailyBackup: $($_.Exception.Message)" -InstallDir $InstallDir
    }
}

# 3. Network & Firewall Sync (Port 8008 & 18009 + network-info.json + INFORMASI-JARINGAN-MIKROTIK.txt)
if (Test-Path -LiteralPath $networkScriptPath) {
    try {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $networkScriptPath -InstallDir $InstallDir
    } catch {
        Write-SRLog -Level "WARN" -Message "Sinkronisasi jaringan selesai dengan catatan: $($_.Exception.Message)" -InstallDir $InstallDir
    }
}

# 4. Create Desktop & Start Menu Shortcuts
try {
    $desktopPath = [Environment]::GetFolderPath("CommonDesktopDirectory")
    if ([string]::IsNullOrWhiteSpace($desktopPath) -or -not (Test-Path -LiteralPath $desktopPath)) {
        $desktopPath = [Environment]::GetFolderPath("Desktop")
    }

    $wshShell = New-Object -ComObject WScript.Shell

    if (-not [string]::IsNullOrWhiteSpace($desktopPath) -and (Test-Path -LiteralPath $desktopPath)) {
        # 4a. Main Cashier POS Launcher Shortcut
        $posShortcutPath = Join-Path $desktopPath "Sugeng Rawuh Billing & POS.lnk"
        $posShortcut = $wshShell.CreateShortcut($posShortcutPath)
        $posShortcut.TargetPath = "powershell.exe"
        $posShortcut.Arguments = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$launcherScriptPath`" -InstallDir `"$InstallDir`""
        $posShortcut.WorkingDirectory = $InstallDir
        $posShortcut.Description = "Buka Aplikasi Kasir Sugeng Rawuh Billing & POS"
        $posShortcut.Save()
        Write-SRLog -Level "SUCCESS" -Message "Shortcut Desktop berhasil dibuat: $posShortcutPath" -InstallDir $InstallDir

        # 4b. Hardware Setup & Test Center Shortcut
        $hwShortcutPath = Join-Path $desktopPath "Sugeng Rawuh - Hardware Setup & Test Center.lnk"
        $hwShortcut = $wshShell.CreateShortcut($hwShortcutPath)
        $hwShortcut.TargetPath = "powershell.exe"
        $hwShortcut.Arguments = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$launcherScriptPath`" -InstallDir `"$InstallDir`" -TargetPath `"/admin/hardware/setup`""
        $hwShortcut.WorkingDirectory = $InstallDir
        $hwShortcut.Description = "Buka Hardware Setup & Onboarding Wizard (Samsung TV, Shelly Relay & WOL)"
        $hwShortcut.Save()
        Write-SRLog -Level "SUCCESS" -Message "Shortcut Hardware Setup & Test Center dibuat: $hwShortcutPath" -InstallDir $InstallDir
    }

    # 4c. Start Menu / Program Group & App Root Repair Shortcut
    $commonPrograms = [Environment]::GetFolderPath("CommonPrograms")
    $groupDirs = @($InstallDir)
    if (-not [string]::IsNullOrWhiteSpace($commonPrograms)) {
        $srMenuDir = Join-Path $commonPrograms "Sugeng Rawuh Billing & POS"
        if (-not (Test-Path -LiteralPath $srMenuDir)) {
            New-Item -ItemType Directory -Path $srMenuDir -Force -ErrorAction SilentlyContinue | Out-Null
        }
        if (Test-Path -LiteralPath $srMenuDir) {
            $groupDirs += $srMenuDir
        }
    }

    foreach ($dir in $groupDirs) {
        $repairShortcutPath = Join-Path $dir "Repair Layanan Sugeng Rawuh.lnk"
        $repairShortcut = $wshShell.CreateShortcut($repairShortcutPath)
        $repairShortcut.TargetPath = "powershell.exe"
        $repairShortcut.Arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$selfScriptPath`" -InstallDir `"$InstallDir`" -Repair -Interactive"
        $repairShortcut.WorkingDirectory = $InstallDir
        $repairShortcut.Description = "Perbaiki Layanan Docker, Firewall, WOL Helper & Sinkronisasi Billing Sugeng Rawuh (Tanpa Menghapus Data)"
        $repairShortcut.Save()
    }
    Write-SRLog -Level "SUCCESS" -Message "Shortcut Repair Layanan Sugeng Rawuh.lnk berhasil dibuat." -InstallDir $InstallDir
} catch {
    Write-SRLog -Level "WARN" -Message "Gagal membuat sebagian shortcut: $($_.Exception.Message)" -InstallDir $InstallDir
}

# 5. Repair Mode Execution (Non-Destructive Container, Health & Backup Verification)
if ($Repair) {
    Write-SRLog -Level "INFO" -Message "=== Menjalankan Pemulihan Layanan Non-Destruktif (Repair Mode) ===" -InstallDir $InstallDir

    $envProdPath = Join-Path $InstallDir ".env.production"
    if (-not (Test-Path -LiteralPath $envProdPath)) {
        Write-SRLog -Level "ERROR" -Message "File .env.production tidak ditemukan di $envProdPath. Jalankan install-app.ps1 terlebih dahulu." -InstallDir $InstallDir
        exit 1
    }

    $preservedAppKey = Get-SREnvValue -EnvFilePath $envProdPath -Key "APP_KEY"
    Write-SRLog -Level "INFO" -Message "Mempertahankan konfigurasi .env.production (APP_KEY, DB_PASSWORD, DB_ROOT_PASSWORD, HARDWARE_WOL_HELPER_TOKEN tetap utuh)." -InstallDir $InstallDir

    # Ensure Docker Desktop & Engine Ready
    Start-SRDockerDesktop -InstallDir $InstallDir | Out-Null
    $engineReady = Wait-SRDockerEngine -InstallDir $InstallDir -MaxWaitSeconds 180 -AllowInteractiveRetry:$Interactive
    if (-not $engineReady) {
        Write-SRLog -Level "ERROR" -Message "Docker Engine belum siap saat proses Repair. Pastikan pengguna sudah login ke Windows dan Docker Desktop berjalan." -InstallDir $InstallDir
        exit 1
    }

    # Start / recreate containers safely preserving volumes
    Write-SRLog -Level "INFO" -Message "Menyalakan ulang layanan container Sugeng Rawuh (mempertahankan seluruh data transaksi & volume database)..." -InstallDir $InstallDir
    Invoke-SRDockerCompose -InstallDir $InstallDir -ComposeArgs @("up", "-d") | Out-Null

    # Poll /api/health
    $appPort = Get-SREnvValue -EnvFilePath $envProdPath -Key "APP_PORT" -DefaultValue "8008"
    $healthUrl = "http://localhost:$appPort/api/health"
    $appHealthy = $false
    for ($i = 0; $i -lt 40; $i++) {
        Start-Sleep -Seconds 3
        try {
            $resp = Invoke-RestMethod -Uri $healthUrl -Method Get -TimeoutSec 3 -ErrorAction Stop
            if ($resp.status -eq "ok" -and $resp.database -eq "connected") {
                $appHealthy = $true
                break
            }
        } catch {}
    }

    if (-not $appHealthy) {
        Write-SRLog -Level "ERROR" -Message "Endpoint kesehatan $healthUrl belum merespons OK setelah proses Repair." -InstallDir $InstallDir
        exit 1
    }

    # Reconcile active billing sessions
    Write-SRLog -Level "INFO" -Message "Menjalankan rekonsiliasi sesi rental (php artisan billing:reconcile)..." -InstallDir $InstallDir
    Invoke-SRDockerCompose -InstallDir $InstallDir -ComposeArgs @(
        "exec", "-T", "app", "gosu", "www-data", "php", "artisan", "billing:reconcile"
    ) -IgnoreExitCode | Out-Null

    # Verify latest backup checksum integrity if backups exist
    $backupDir = Join-Path $InstallDir "data\backups"
    if (Test-Path -LiteralPath $backupDir) {
        $latestBackup = Get-ChildItem -Path $backupDir -Filter "*.sql.gz" -File -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
        if ($null -ne $latestBackup) {
            $shaPath = "$($latestBackup.FullName).sha256"
            if (Test-Path -LiteralPath $shaPath) {
                $shaText = (Get-Content -LiteralPath $shaPath -Raw -Encoding ASCII).Trim()
                if ($shaText -match '^([a-fA-F0-9]{64})') {
                    $expected = $Matches[1].ToLowerInvariant()
                    $actual = (Get-FileHash -LiteralPath $latestBackup.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
                    if ($expected -eq $actual) {
                        Write-SRLog -Level "SUCCESS" -Message "Arsip backup terbaru terverifikasi utuh: $($latestBackup.Name)" -InstallDir $InstallDir
                    } else {
                        Write-SRLog -Level "WARN" -Message "Peringatan: Checksum arsip backup terbaru ($($latestBackup.Name)) tidak cocok!" -InstallDir $InstallDir
                    }
                }
            }
        }
    }

    Write-SRLog -Level "SUCCESS" -Message "=== Pemulihan Layanan (Repair) Selesai dengan Sukses! ===" -InstallDir $InstallDir
    if ($Interactive) {
        try {
            Add-Type -AssemblyName System.Windows.Forms
            [System.Windows.Forms.MessageBox]::Show(
                "Seluruh layanan Sugeng Rawuh Billing & POS (Docker, Database MySQL, Web Server, Billing Daemon, WOL Helper, dan Firewall) telah berhasil diperbaiki dan siap digunakan.`r`n`r`nTidak ada data transaksi yang diubah atau dihapus.",
                "Sugeng Rawuh Billing & POS - Repair Berhasil",
                [System.Windows.Forms.MessageBoxButtons]::OK,
                [System.Windows.Forms.MessageBoxIcon]::Information
            ) | Out-Null
        } catch {}
    }
}

exit 0

