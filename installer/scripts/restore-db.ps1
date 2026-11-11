#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Safe MySQL Database Restore Script
.DESCRIPTION
    Verifies backup file integrity (.sha256), creates a pre-restore safety snapshot,
    decompresses .sql.gz if needed, imports SQL into sugeng_rawuh_db while preserving APP_KEY,
    and executes php artisan migrate --force and php artisan billing:reconcile.
#>

param(
    [Parameter(Mandatory = $true)]
    [string]$BackupFile,
    [string]$InstallDir = "C:\SugengRawuh",
    [switch]$SkipPreRestoreBackup
)

$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "common.ps1")

Ensure-SRDirectories -InstallDir $InstallDir

if (-not (Test-Path -LiteralPath $BackupFile)) {
    Write-SRLog -Level "ERROR" -Message "File backup tidak ditemukan: $BackupFile" -InstallDir $InstallDir
    exit 1
}

# 1. Verify .sha256 Checksum if Accompanying Checksum File Exists
$shaFile = "${BackupFile}.sha256"
if (Test-Path -LiteralPath $shaFile) {
    $shaContent = (Get-Content -LiteralPath $shaFile -Raw -Encoding ASCII).Trim()
    if ($shaContent -match '^([a-fA-F0-9]{64})') {
        $expectedHash = $Matches[1].ToLowerInvariant()
        $actualHash = (Get-FileHash -LiteralPath $BackupFile -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($expectedHash -ne $actualHash) {
            throw "Checksum SHA-256 tidak cocok untuk $BackupFile! Expected=$expectedHash, Actual=$actualHash"
        }
        Write-SRLog -Level "SUCCESS" -Message "Checksum SHA-256 file backup terverifikasi valid." -InstallDir $InstallDir
    }
}

# 2. Take a Safety Pre-Restore Snapshot First
$backupScript = Join-Path $PSScriptRoot "backup-db.ps1"
if (-not $SkipPreRestoreBackup -and (Test-Path -LiteralPath $backupScript)) {
    Write-SRLog -Level "INFO" -Message "Membuat safety snapshot database sebelum proses restore (pre-restore-safety)..." -InstallDir $InstallDir
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $backupScript -InstallDir $InstallDir -Reason "pre-restore-safety"
}

$envProdPath = Join-Path $InstallDir ".env.production"
if (-not (Test-Path -LiteralPath $envProdPath)) {
    throw "File .env.production tidak ditemukan di $envProdPath"
}

# Preserve APP_KEY strictly
$preservedAppKey = Get-SREnvValue -EnvFilePath $envProdPath -Key "APP_KEY"
Write-SRLog -Level "INFO" -Message "Mempertahankan APP_KEY yang ada di .env.production selama restore database." -InstallDir $InstallDir

$dbName = Get-SREnvValue -EnvFilePath $envProdPath -Key "DB_DATABASE" -DefaultValue "sugeng_rawuh_pos"
$dbRootPass = Get-SREnvValue -EnvFilePath $envProdPath -Key "DB_ROOT_PASSWORD"
$dbUser = Get-SREnvValue -EnvFilePath $envProdPath -Key "DB_USERNAME" -DefaultValue "sugeng_pos"
$dbPass = Get-SREnvValue -EnvFilePath $envProdPath -Key "DB_PASSWORD"

$importUser = if (-not [string]::IsNullOrWhiteSpace($dbRootPass)) { "root" } else { $dbUser }
$importPass = if (-not [string]::IsNullOrWhiteSpace($dbRootPass)) { $dbRootPass } else { $dbPass }

# 3. Decompress .sql.gz if Compressed
$tempSqlFile = $BackupFile
$cleanupTemp = $false

if ($BackupFile.EndsWith(".gz", [System.StringComparison]::OrdinalIgnoreCase)) {
    $tempSqlFile = Join-Path $env:TEMP ("sr_restore_" + [Guid]::NewGuid().ToString("N") + ".sql")
    $cleanupTemp = $true
    Write-SRLog -Level "INFO" -Message "Mengekstrak arsip backup .sql.gz..." -InstallDir $InstallDir

    $inStream = [System.IO.File]::OpenRead($BackupFile)
    try {
        $gzipStream = New-Object System.IO.Compression.GZipStream($inStream, [System.IO.Compression.CompressionMode]::Decompress)
        try {
            $outStream = [System.IO.File]::Create($tempSqlFile)
            try {
                $gzipStream.CopyTo($outStream)
            } finally {
                $outStream.Dispose()
            }
        } finally {
            $gzipStream.Dispose()
        }
    } finally {
        $inStream.Dispose()
    }
}

try {
    # 4. Import SQL into MySQL Container
    Write-SRLog -Level "INFO" -Message "Mengimpor database ke MySQL ($dbName)..." -InstallDir $InstallDir
    $composeFile = Join-Path $InstallDir "docker-compose.yml"
    $cmdLine = "docker compose --project-directory `"$InstallDir`" -f `"$composeFile`" --env-file `"$envProdPath`" exec -T db mysql --default-character-set=utf8mb4 -u$importUser -p$importPass $dbName < `"$tempSqlFile`""
    & cmd.exe /c $cmdLine
    if ($LASTEXITCODE -ne 0) {
        throw "Gagal mengimpor file SQL ke container MySQL."
    }

    # 5. Run Non-Destructive Schema Migrations & Billing Reconciliation
    Write-SRLog -Level "INFO" -Message "Menjalankan migrasi inkremental (php artisan migrate --force) dan rekonsiliasi sesi..." -InstallDir $InstallDir
    Invoke-SRDockerCompose -InstallDir $InstallDir -ComposeArgs @("exec", "-T", "app", "gosu", "www-data", "php", "artisan", "migrate", "--force") | Out-Null
    Invoke-SRDockerCompose -InstallDir $InstallDir -ComposeArgs @("exec", "-T", "app", "gosu", "www-data", "php", "artisan", "billing:reconcile") | Out-Null

    Write-SRLog -Level "SUCCESS" -Message "Restore database dari $BackupFile selesai dengan sukses!" -InstallDir $InstallDir
} finally {
    if ($cleanupTemp -and (Test-Path -LiteralPath $tempSqlFile)) {
        Remove-Item -LiteralPath $tempSqlFile -Force -ErrorAction SilentlyContinue
    }
}
exit 0

