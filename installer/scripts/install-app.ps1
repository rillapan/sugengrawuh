#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Stage D.C Application Installation & Container Provisioning
.DESCRIPTION
    Starts Docker Engine if needed, verifies SHA-256 offline bundle checksums, loads offline Docker images,
    generates or preserves .env.production safely (never overwriting existing APP_KEY, DB_PASSWORD, or sr_mysql_data),
    enforces HARDWARE_REAL_EXECUTION_ENABLED=false and CONSOLE_REAL_EXECUTION_ENABLED=false on fresh install,
    starts MySQL & application containers, verifies /api/health, and registers WOL helper, daily backup, and Desktop shortcut.
#>

param(
    [string]$InstallDir = "C:\SugengRawuh",
    [int]$DockerWaitSeconds = 180
)

$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "common.ps1")

Assert-SRAdmin -ScriptPath $MyInvocation.MyCommand.Path -ScriptArgs @("-InstallDir", $InstallDir)
Ensure-SRDirectories -InstallDir $InstallDir

Write-SRLog -Level "INFO" -Message "=== Memulai Instalasi Aplikasi Sugeng Rawuh (Stage D.C) ===" -InstallDir $InstallDir

# 1. Ensure Docker Engine is Running
$dockerReady = $false
try {
    $null = & docker info 2>$null
    if ($LASTEXITCODE -eq 0) {
        $dockerReady = $true
    }
} catch {}

if (-not $dockerReady) {
    $dockerDesktopExe = "C:\Program Files\Docker\Docker\Docker Desktop.exe"
    if (Test-Path -LiteralPath $dockerDesktopExe) {
        Write-SRLog -Level "INFO" -Message "Menjalankan Docker Desktop dan menunggu Docker Engine siap (maksimal $DockerWaitSeconds detik)..." -InstallDir $InstallDir
        Start-Process -FilePath $dockerDesktopExe -ErrorAction SilentlyContinue | Out-Null
    } else {
        Write-SRLog -Level "ERROR" -Message "Docker Desktop tidak ditemukan di $dockerDesktopExe. Jalankan install-prerequisites.ps1 terlebih dahulu." -InstallDir $InstallDir
        exit 1
    }

    $elapsed = 0
    $interval = 5
    while ($elapsed -lt $DockerWaitSeconds) {
        Start-Sleep -Seconds $interval
        $elapsed += $interval
        try {
            $null = & docker info 2>$null
            if ($LASTEXITCODE -eq 0) {
                $dockerReady = $true
                break
            }
        } catch {}
        Write-SRLog -Level "INFO" -Message "Menunggu Docker Engine siap... ($elapsed / $DockerWaitSeconds detik)" -InstallDir $InstallDir
    }

    if (-not $dockerReady) {
        Write-SRLog -Level "ERROR" -Message "Docker Engine belum siap setelah $DockerWaitSeconds detik. Pastikan Docker Desktop berjalan dan WSL2 aktif." -InstallDir $InstallDir
        exit 1
    }
}
Write-SRLog -Level "SUCCESS" -Message "Docker Engine siap digunakan." -InstallDir $InstallDir

# 2. Verify Offline Bundle SHA-256 Checksums (if SHA256SUMS.txt is present)
$checksumManifests = @(
    (Join-Path $InstallDir "offline-bundle\SHA256SUMS.txt"),
    (Join-Path $InstallDir "SHA256SUMS.txt")
)
foreach ($manifest in $checksumManifests) {
    if (Test-Path -LiteralPath $manifest) {
        Write-SRLog -Level "INFO" -Message "Memverifikasi integritas SHA-256 dari: $manifest" -InstallDir $InstallDir
        $valid = Test-SRChecksumManifest -ManifestPath $manifest
        if (-not $valid) {
            Write-SRLog -Level "ERROR" -Message "Verifikasi checksum SHA-256 gagal! Instalasi dihentikan demi keamanan." -InstallDir $InstallDir
            exit 1
        }
    }
}

# 3. Load Offline Docker Images (linux/amd64)
$imagesDir = Join-Path $InstallDir "offline-bundle\images"
if (Test-Path -LiteralPath $imagesDir) {
    $imageArchives = @(Get-ChildItem -Path $imagesDir -Include "*.tar", "*.tar.gz" -File -Recurse -ErrorAction SilentlyContinue)
    if ($imageArchives.Count -gt 0) {
        foreach ($archive in $imageArchives) {
            Write-SRLog -Level "INFO" -Message "Memuat image Docker offline: $($archive.Name) (mohon tunggu 1-3 menit)..." -InstallDir $InstallDir
            & docker load -i $archive.FullName
            if ($LASTEXITCODE -ne 0) {
                throw "Gagal memuat image Docker dari $($archive.FullName)"
            }
            Write-SRLog -Level "SUCCESS" -Message "Image Docker berhasil dimuat: $($archive.Name)" -InstallDir $InstallDir
        }
    } else {
        Write-SRLog -Level "WARN" -Message "Tidak ada file .tar/.tar.gz di $imagesDir. Menggunakan image lokal atau repositori Docker." -InstallDir $InstallDir
    }
}

# 4. Strict .env.production & Database Credential Protection (NEVER overwrite existing secrets or sr_mysql_data!)
$envProdPath = Join-Path $InstallDir ".env.production"
$envExamplePath = Join-Path $InstallDir ".env.production.example"
$backupScriptPath = Join-Path $PSScriptRoot "backup-db.ps1"

if (Test-Path -LiteralPath $envProdPath) {
    Write-SRLog -Level "INFO" -Message "MODE UPGRADE / REPAIR: File .env.production sudah ada. Mempertahankan APP_KEY, DB_PASSWORD, dan konfigurasi yang ada!" -InstallDir $InstallDir

    # Verify APP_KEY is non-empty; only generate if somehow blank
    $existingAppKey = Get-SREnvValue -EnvFilePath $envProdPath -Key "APP_KEY"
    if ([string]::IsNullOrWhiteSpace($existingAppKey)) {
        $newKey = New-SRAppKey
        $rawContent = Get-Content -LiteralPath $envProdPath -Raw -Encoding UTF8
        $rawContent = $rawContent -replace '(?m)^APP_KEY=.*$', "APP_KEY=$newKey"
        Set-Content -LiteralPath $envProdPath -Value $rawContent -Encoding UTF8 -Force
        Write-SRLog -Level "INFO" -Message "APP_KEY kosong pada .env.production telah diisi secara aman." -InstallDir $InstallDir
    }

    # Trigger pre-update database backup if sugeng_rawuh_db container is currently running
    try {
        $runningDb = & docker ps --filter "name=sugeng_rawuh_db" --filter "status=running" --format "{{.Names}}" 2>$null
        if (($runningDb -match "sugeng_rawuh_db") -and (Test-Path -LiteralPath $backupScriptPath)) {
            Write-SRLog -Level "INFO" -Message "Membuat backup database otomatis sebelum upgrade/repair (pre-update)..." -InstallDir $InstallDir
            & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $backupScriptPath -InstallDir $InstallDir -Reason "pre-update"
        }
    } catch {
        Write-SRLog -Level "WARN" -Message "Backup pre-update dilewati: $($_.Exception.Message)" -InstallDir $InstallDir
    }
} else {
    Write-SRLog -Level "INFO" -Message "MODE FRESH INSTALL: Membuat file .env.production baru dengan kredensial kriptografis acak..." -InstallDir $InstallDir
    if (-not (Test-Path -LiteralPath $envExamplePath)) {
        throw "Template .env.production.example tidak ditemukan di $envExamplePath"
    }

    $appKey = New-SRAppKey
    $dbPassword = New-SRRandomSecret -ByteLength 20
    $dbRootPassword = New-SRRandomSecret -ByteLength 24
    $wolHelperToken = New-SRRandomSecret -ByteLength 24

    $envContent = Get-Content -LiteralPath $envExamplePath -Raw -Encoding UTF8
    $envContent = $envContent -replace '(?m)^APP_KEY=.*$', "APP_KEY=$appKey"
    $envContent = $envContent -replace '(?m)^DB_PASSWORD=.*$', "DB_PASSWORD=$dbPassword"
    $envContent = $envContent -replace '(?m)^DB_ROOT_PASSWORD=.*$', "DB_ROOT_PASSWORD=$dbRootPassword"
    $envContent = $envContent -replace '(?m)^HARDWARE_WOL_HELPER_TOKEN=.*$', "HARDWARE_WOL_HELPER_TOKEN=$wolHelperToken"
    # Strictly enforce hardware & console real execution safety flags to false on initial install
    $envContent = $envContent -replace '(?m)^HARDWARE_REAL_EXECUTION_ENABLED=.*$', "HARDWARE_REAL_EXECUTION_ENABLED=false"
    $envContent = $envContent -replace '(?m)^CONSOLE_REAL_EXECUTION_ENABLED=.*$', "CONSOLE_REAL_EXECUTION_ENABLED=false"

    Set-Content -LiteralPath $envProdPath -Value $envContent -Encoding UTF8 -Force
    Write-SRLog -Level "SUCCESS" -Message "File .env.production berhasil dibuat (HARDWARE_REAL_EXECUTION_ENABLED=false & CONSOLE_REAL_EXECUTION_ENABLED=false aktif)." -InstallDir $InstallDir
}

# 5. Start MySQL Database Service First & Wait for Healthcheck
Write-SRLog -Level "INFO" -Message "Menjalankan container database MySQL (sugeng_rawuh_db)..." -InstallDir $InstallDir
Invoke-SRDockerCompose -InstallDir $InstallDir -ComposeArgs @("up", "-d", "db") | Out-Null

$dbHealthy = $false
$dbWaitMax = 120
$dbElapsed = 0
while ($dbElapsed -lt $dbWaitMax) {
    Start-Sleep -Seconds 3
    $dbElapsed += 3
    $healthStatus = & docker inspect --format "{{.State.Health.Status}}" sugeng_rawuh_db 2>$null
    if ($healthStatus -eq "healthy") {
        $dbHealthy = $true
        break
    }
    Write-SRLog -Level "INFO" -Message "Menunggu MySQL sehat (status saat ini: $healthStatus, $dbElapsed / $dbWaitMax detik)..." -InstallDir $InstallDir
}

if (-not $dbHealthy) {
    Write-SRLog -Level "ERROR" -Message "Database sugeng_rawuh_db gagal mencapai status healthy dalam $dbWaitMax detik." -InstallDir $InstallDir
    exit 1
}
Write-SRLog -Level "SUCCESS" -Message "Database MySQL sugeng_rawuh_db berstatus HEALTHY." -InstallDir $InstallDir

# 6. Start All Application Services (app, web, daemon, scheduler)
# Note: sugeng_rawuh_app entrypoint automatically executes `php artisan migrate --force`
# and `php artisan app:production-init --reconcile` (non-destructive incremental schema migration).
Write-SRLog -Level "INFO" -Message "Menjalankan layanan aplikasi, web server Nginx, billing daemon, dan scheduler..." -InstallDir $InstallDir
Invoke-SRDockerCompose -InstallDir $InstallDir -ComposeArgs @("up", "-d") | Out-Null

# 7. Poll HTTP Health Endpoint (/api/health)
$appPort = Get-SREnvValue -EnvFilePath $envProdPath -Key "APP_PORT" -DefaultValue "8008"
$healthUrl = "http://localhost:$appPort/api/health"
$appHealthy = $false
$appWaitMax = 120
$appElapsed = 0

Write-SRLog -Level "INFO" -Message "Memverifikasi kesiapan aplikasi pada $healthUrl..." -InstallDir $InstallDir
while ($appElapsed -lt $appWaitMax) {
    Start-Sleep -Seconds 3
    $appElapsed += 3
    try {
        $resp = Invoke-RestMethod -Uri $healthUrl -Method Get -TimeoutSec 4 -ErrorAction Stop
        if ($resp.status -eq "ok" -and $resp.database -eq "connected") {
            $appHealthy = $true
            break
        }
    } catch {}
    Write-SRLog -Level "INFO" -Message "Menunggu endpoint kesehatan $healthUrl merespons... ($appElapsed / $appWaitMax detik)" -InstallDir $InstallDir
}

if (-not $appHealthy) {
    Write-SRLog -Level "ERROR" -Message "Aplikasi belum merespons status sehat di $healthUrl setelah $appWaitMax detik. Periksa data\logs." -InstallDir $InstallDir
    exit 1
}
Write-SRLog -Level "SUCCESS" -Message "Endpoint kesehatan aplikasi ($healthUrl) merespons OK (Database Connected)." -InstallDir $InstallDir

# 8. Register Windows Scheduled Tasks (WOL Helper & Daily Database Backup) and Desktop Shortcut
$wolScriptPath = Join-Path $PSScriptRoot "wol-helper.ps1"
$launcherScriptPath = Join-Path $PSScriptRoot "startup-launcher.ps1"

# 8a. Start & Register WOL Helper (TCP port 18009)
if (Test-Path -LiteralPath $wolScriptPath) {
    try {
        $wolAction = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$wolScriptPath`" -InstallDir `"$InstallDir`""
        $wolTrigger = New-ScheduledTaskTrigger -AtLogOn
        $wolSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
        Register-ScheduledTask -TaskName "SugengRawuh-WOLHelper" -Action $wolAction -Trigger $wolTrigger -Settings $wolSettings -Description "Sugeng Rawuh Physical LAN Wake-on-LAN Helper (Port 18009)" -Force | Out-Null
        Start-ScheduledTask -TaskName "SugengRawuh-WOLHelper" -ErrorAction SilentlyContinue
        Write-SRLog -Level "SUCCESS" -Message "Layanan SugengRawuh-WOLHelper terdaftar dan dijalankan di latar belakang." -InstallDir $InstallDir
    } catch {
        Write-SRLog -Level "WARN" -Message "Gagal mendaftarkan Scheduled Task WOLHelper, menjalankan proses langsung: $($_.Exception.Message)" -InstallDir $InstallDir
        Start-Process -FilePath "powershell.exe" -ArgumentList @("-NoProfile", "-ExecutionPolicy", "Bypass", "-WindowStyle", "Hidden", "-File", "`"$wolScriptPath`"", "-InstallDir", "`"$InstallDir`"") -WindowStyle Hidden
    }
}

# 8b. Register Daily Automated Database Backup at 03:30 WIB
if (Test-Path -LiteralPath $backupScriptPath) {
    try {
        $backupAction = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$backupScriptPath`" -InstallDir `"$InstallDir`" -Reason `"daily-scheduled`""
        $backupTrigger = New-ScheduledTaskTrigger -Daily -At "03:30AM"
        $backupSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
        Register-ScheduledTask -TaskName "SugengRawuh-DailyBackup" -Action $backupAction -Trigger $backupTrigger -Settings $backupSettings -Description "Backup Database Harian Otomatis Sugeng Rawuh POS" -Force | Out-Null
        Write-SRLog -Level "SUCCESS" -Message "Scheduled Task SugengRawuh-DailyBackup terdaftar (setiap hari pukul 03:30 WIB)." -InstallDir $InstallDir
    } catch {
        Write-SRLog -Level "WARN" -Message "Gagal mendaftarkan Scheduled Task DailyBackup: $($_.Exception.Message)" -InstallDir $InstallDir
    }
}

# 8c. Create Desktop Shortcut for Cashier ("Sugeng Rawuh Billing & POS")
try {
    $desktopPath = [Environment]::GetFolderPath("CommonDesktopDirectory")
    if ([string]::IsNullOrWhiteSpace($desktopPath) -or -not (Test-Path -LiteralPath $desktopPath)) {
        $desktopPath = [Environment]::GetFolderPath("Desktop")
    }
    $shortcutPath = Join-Path $desktopPath "Sugeng Rawuh Billing & POS.lnk"
    $wshShell = New-Object -ComObject WScript.Shell
    $shortcut = $wshShell.CreateShortcut($shortcutPath)
    $shortcut.TargetPath = "powershell.exe"
    $shortcut.Arguments = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$launcherScriptPath`" -InstallDir `"$InstallDir`""
    $shortcut.WorkingDirectory = $InstallDir
    $shortcut.Description = "Buka Aplikasi Kasir Sugeng Rawuh Billing & POS"
    $shortcut.Save()
    Write-SRLog -Level "SUCCESS" -Message "Shortcut Desktop berhasil dibuat: $shortcutPath" -InstallDir $InstallDir
} catch {
    Write-SRLog -Level "WARN" -Message "Gagal membuat shortcut Desktop: $($_.Exception.Message)" -InstallDir $InstallDir
}

Write-SRLog -Level "SUCCESS" -Message "=== Instalasi Aplikasi Sugeng Rawuh (Stage D.C) Selesai ===" -InstallDir $InstallDir
exit 0
