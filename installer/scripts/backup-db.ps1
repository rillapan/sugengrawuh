#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Safe MySQL Database & Configuration Backup Script
.DESCRIPTION
    Executes online non-blocking mysqldump (--single-transaction --routines --triggers),
    compresses the SQL dump into .sql.gz, generates a .sha256 verification file,
    saves a metadata backup of .env.production, and prunes backups older than RetentionDays (default 30).
#>

param(
    [string]$InstallDir = "C:\SugengRawuh",
    [string]$Reason = "manual",
    [int]$RetentionDays = 30
)

$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "common.ps1")

Ensure-SRDirectories -InstallDir $InstallDir

$envProdPath = Join-Path $InstallDir ".env.production"
if (-not (Test-Path -LiteralPath $envProdPath)) {
    Write-SRLog -Level "ERROR" -Message "File .env.production tidak ditemukan di $envProdPath. Backup dibatalkan." -InstallDir $InstallDir
    exit 1
}

$dbName = Get-SREnvValue -EnvFilePath $envProdPath -Key "DB_DATABASE" -DefaultValue "sugeng_rawuh_pos"
$dbUser = Get-SREnvValue -EnvFilePath $envProdPath -Key "DB_USERNAME" -DefaultValue "sugeng_pos"
$dbPass = Get-SREnvValue -EnvFilePath $envProdPath -Key "DB_PASSWORD"
$dbRootPass = Get-SREnvValue -EnvFilePath $envProdPath -Key "DB_ROOT_PASSWORD"

$dumpUser = if (-not [string]::IsNullOrWhiteSpace($dbRootPass)) { "root" } else { $dbUser }
$dumpPass = if (-not [string]::IsNullOrWhiteSpace($dbRootPass)) { $dbRootPass } else { $dbPass }

$safeReason = ($Reason -replace '[^a-zA-Z0-9_-]', '-')
$timestamp = (Get-Date).ToString("yyyyMMdd-HHmmss")
$backupDir = Join-Path $InstallDir "data\backups"
$sqlTempFile = Join-Path $backupDir "sugeng_rawuh_${timestamp}_${safeReason}.sql"
$gzBackupFile = "${sqlTempFile}.gz"
$shaFile = "${gzBackupFile}.sha256"

Write-SRLog -Level "INFO" -Message "Memulai backup database MySQL ($dbName) ke $gzBackupFile..." -InstallDir $InstallDir

$composeFile = Join-Path $InstallDir "docker-compose.yml"
# Execute via cmd.exe /c redirection so raw UTF-8 mysqldump bytes are written directly without PowerShell 5.1 UTF-16LE Out-File conversion
$dumpCmdLine = "docker compose --project-directory `"$InstallDir`" -f `"$composeFile`" --env-file `"$envProdPath`" exec -T db mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 -u$dumpUser -p$dumpPass $dbName > `"$sqlTempFile`""
& cmd.exe /c $dumpCmdLine
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $sqlTempFile) -or (Get-Item -LiteralPath $sqlTempFile).Length -lt 64) {
    if (Test-Path -LiteralPath $sqlTempFile) { Remove-Item -LiteralPath $sqlTempFile -Force -ErrorAction SilentlyContinue }
    throw "mysqldump gagal dieksekusi atau menghasilkan file kosong."
}

# Compress .sql into .sql.gz using .NET GZipStream
$inStream = [System.IO.File]::OpenRead($sqlTempFile)
try {
    $outStream = [System.IO.File]::Create($gzBackupFile)
    try {
        $gzipStream = New-Object System.IO.Compression.GZipStream($outStream, [System.IO.Compression.CompressionMode]::Compress)
        try {
            $inStream.CopyTo($gzipStream)
        } finally {
            $gzipStream.Dispose()
        }
    } finally {
        $outStream.Dispose()
    }
} finally {
    $inStream.Dispose()
}

Remove-Item -LiteralPath $sqlTempFile -Force -ErrorAction SilentlyContinue

# Generate SHA-256 checksum
$hash = (Get-FileHash -LiteralPath $gzBackupFile -Algorithm SHA256).Hash.ToLowerInvariant()
$gzFileName = Split-Path -Leaf $gzBackupFile
Set-Content -LiteralPath $shaFile -Value "$hash  $gzFileName" -Encoding ASCII -Force

# Backup .env.production copy to config\
$envBackupPath = Join-Path $InstallDir "config\.env.production.backup"
Copy-Item -LiteralPath $envProdPath -Destination $envBackupPath -Force

# Prune backups older than RetentionDays
$cutoffDate = (Get-Date).AddDays(-1 * $RetentionDays)
$oldFiles = @(Get-ChildItem -Path $backupDir -Include "*.sql.gz", "*.sha256" -File -Recurse -ErrorAction SilentlyContinue | Where-Object { $_.LastWriteTime -lt $cutoffDate })
foreach ($old in $oldFiles) {
    Remove-Item -LiteralPath $old.FullName -Force -ErrorAction SilentlyContinue
    Write-SRLog -Level "INFO" -Message "Menghapus arsip backup lama (> $RetentionDays hari): $($old.Name)" -InstallDir $InstallDir
}

Write-SRLog -Level "SUCCESS" -Message "Backup database berhasil: $gzBackupFile (SHA-256: $hash)" -InstallDir $InstallDir
Write-Output $gzBackupFile
exit 0
