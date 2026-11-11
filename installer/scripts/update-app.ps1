#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Safe Application Update Script (Zero Data Loss)
.DESCRIPTION
    Performs an automated pre-update database backup, preserves .env.production and sr_mysql_data,
    loads the updated Docker image archive, recreates application containers, runs incremental
    migrations (php artisan migrate --force) and billing reconciliation (php artisan billing:reconcile),
    and verifies /api/health.
#>

param(
    [string]$InstallDir = "C:\SugengRawuh",
    [string]$ImageArchive = ""
)

$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "common.ps1")

Assert-SRAdmin -ScriptPath $MyInvocation.MyCommand.Path -ScriptArgs @("-InstallDir", $InstallDir)
Ensure-SRDirectories -InstallDir $InstallDir

Write-SRLog -Level "INFO" -Message "=== Memulai Pembaruan Aplikasi Sugeng Rawuh (Zero Data Loss Update) ===" -InstallDir $InstallDir

$envProdPath = Join-Path $InstallDir ".env.production"
if (-not (Test-Path -LiteralPath $envProdPath)) {
    throw "File .env.production tidak ditemukan di $envProdPath. Gunakan install-app.ps1 untuk instalasi awal."
}

# 1. Create Pre-Update Database Backup
$backupScript = Join-Path $PSScriptRoot "backup-db.ps1"
if (Test-Path -LiteralPath $backupScript) {
    Write-SRLog -Level "INFO" -Message "Membuat backup database sebelum update (pre-update)..." -InstallDir $InstallDir
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $backupScript -InstallDir $InstallDir -Reason "pre-update"
}

# 2. Load New Docker Image Archive (if provided or present in offline-bundle\images)
if ([string]::IsNullOrWhiteSpace($ImageArchive)) {
    $imagesDir = Join-Path $InstallDir "offline-bundle\images"
    if (Test-Path -LiteralPath $imagesDir) {
        $candidate = Get-ChildItem -Path $imagesDir -Include "*.tar", "*.tar.gz" -File -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($null -ne $candidate) {
            $ImageArchive = $candidate.FullName
        }
    }
}

if (-not [string]::IsNullOrWhiteSpace($ImageArchive) -and (Test-Path -LiteralPath $ImageArchive)) {
    Write-SRLog -Level "INFO" -Message "Memuat image Docker versi terbaru dari: $ImageArchive" -InstallDir $InstallDir
    & docker load -i $ImageArchive
    if ($LASTEXITCODE -ne 0) {
        throw "Gagal memuat image Docker dari $ImageArchive"
    }
}

# 3. Recreate Application Containers WITHOUT touching .env.production or sr_mysql_data volume
Write-SRLog -Level "INFO" -Message "Memperbarui container aplikasi (mempertahankan volume sr_mysql_data & .env.production)..." -InstallDir $InstallDir
Invoke-SRDockerCompose -InstallDir $InstallDir -ComposeArgs @("up", "-d") | Out-Null

# 4. Run Non-Destructive Migrations & Reconcile Active Billing Sessions
Write-SRLog -Level "INFO" -Message "Menjalankan migrasi database inkremental dan rekonsiliasi sesi rental..." -InstallDir $InstallDir
Invoke-SRDockerCompose -InstallDir $InstallDir -ComposeArgs @("exec", "-T", "app", "gosu", "www-data", "php", "artisan", "migrate", "--force") | Out-Null
Invoke-SRDockerCompose -InstallDir $InstallDir -ComposeArgs @("exec", "-T", "app", "gosu", "www-data", "php", "artisan", "billing:reconcile") | Out-Null

# 5. Verify /api/health
$appPort = Get-SREnvValue -EnvFilePath $envProdPath -Key "APP_PORT" -DefaultValue "8008"
$healthUrl = "http://localhost:$appPort/api/health"
$healthy = $false
for ($i = 0; $i -lt 30; $i++) {
    Start-Sleep -Seconds 2
    try {
        $resp = Invoke-RestMethod -Uri $healthUrl -Method Get -TimeoutSec 3 -ErrorAction Stop
        if ($resp.status -eq "ok" -and $resp.database -eq "connected") {
            $healthy = $true
            break
        }
    } catch {}
}

if (-not $healthy) {
    throw "Endpoint kesehatan $healthUrl belum merespons OK pasca-update."
}

Write-SRLog -Level "SUCCESS" -Message "=== Pembaruan Aplikasi Sugeng Rawuh Selesai Tanpa Kehilangan Data ===" -InstallDir $InstallDir
exit 0

