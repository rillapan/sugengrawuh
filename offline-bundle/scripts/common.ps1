#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Shared PowerShell Helper Library
.DESCRIPTION
    Provides logging, Administrator elevation, RunOnce reboot-resume state management,
    cryptographic secret/APP_KEY generation, SHA-256 verification, and Docker Compose wrappers.
#>

Set-StrictMode -Version Latest

$Script:DefaultInstallDir = "C:\SugengRawuh"
$Script:StateDir          = "C:\ProgramData\SugengRawuh"
$Script:StateFilePath     = Join-Path $Script:StateDir "install-state.json"
$Script:RunOnceKeyPath    = "HKLM:\Software\Microsoft\Windows\CurrentVersion\RunOnce"
$Script:RunOnceValueName  = "SugengRawuhResumeInstall"

function Get-SRInstallDir {
    param([string]$InstallDir = "")
    if (-not [string]::IsNullOrWhiteSpace($InstallDir)) {
        return $InstallDir
    }
    if (-not [string]::IsNullOrWhiteSpace($env:SR_INSTALL_DIR)) {
        return $env:SR_INSTALL_DIR
    }
    $candidateRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
    if (Test-Path (Join-Path $candidateRoot "docker-compose.yml")) {
        return $candidateRoot
    }
    return $Script:DefaultInstallDir
}

function Ensure-SRDirectories {
    param([string]$InstallDir = "")
    $root = Get-SRInstallDir -InstallDir $InstallDir
    $dirs = @(
        $root,
        (Join-Path $root "data"),
        (Join-Path $root "data\logs"),
        (Join-Path $root "data\backups"),
        (Join-Path $root "config"),
        $Script:StateDir
    )
    foreach ($dir in $dirs) {
        if (-not (Test-Path -LiteralPath $dir)) {
            New-Item -ItemType Directory -Path $dir -Force -ErrorAction SilentlyContinue | Out-Null
        }
    }
}

function Write-SRLog {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Message,
        [ValidateSet("INFO", "WARN", "ERROR", "SUCCESS")]
        [string]$Level = "INFO",
        [string]$InstallDir = "",
        [string]$LogFileName = "installer.log"
    )

    $root = Get-SRInstallDir -InstallDir $InstallDir
    $logDir = Join-Path $root "data\logs"
    if (-not (Test-Path -LiteralPath $logDir)) {
        New-Item -ItemType Directory -Path $logDir -Force -ErrorAction SilentlyContinue | Out-Null
    }

    $logPath = Join-Path $logDir $LogFileName
    $timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    $line = "[$timestamp] [$Level] $Message"

    switch ($Level) {
        "ERROR"   { Write-Host $line -ForegroundColor Red }
        "WARN"    { Write-Host $line -ForegroundColor Yellow }
        "SUCCESS" { Write-Host $line -ForegroundColor Green }
        default   { Write-Host $line -ForegroundColor Cyan }
    }

    try {
        Add-Content -LiteralPath $logPath -Value $line -Encoding UTF8 -ErrorAction Stop
    } catch {
        $fallback = Join-Path $env:TEMP "sugeng-rawuh-$LogFileName"
        Add-Content -LiteralPath $fallback -Value $line -Encoding UTF8 -ErrorAction SilentlyContinue
    }
}

function Test-SRAdmin {
    $currentIdentity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($currentIdentity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Assert-SRAdmin {
    param(
        [string]$ScriptPath,
        [string[]]$ScriptArgs = @()
    )

    if (Test-SRAdmin) {
        return
    }

    Write-SRLog -Level "WARN" -Message "Meminta hak akses Administrator (Run as Administrator)..."
    $argList = @(
        "-NoProfile",
        "-ExecutionPolicy", "Bypass",
        "-File", "`"$ScriptPath`""
    ) + $ScriptArgs

    $proc = Start-Process -FilePath "powershell.exe" -ArgumentList $argList -Verb RunAs -PassThru -Wait
    exit $proc.ExitCode
}

function Get-SRInstallState {
    if (-not (Test-Path -LiteralPath $Script:StateFilePath)) {
        return $null
    }
    try {
        $raw = Get-Content -LiteralPath $Script:StateFilePath -Raw -Encoding UTF8
        return ($raw | ConvertFrom-Json)
    } catch {
        Write-SRLog -Level "WARN" -Message "Gagal membaca file state instalasi: $($_.Exception.Message)"
        return $null
    }
}

function Save-SRInstallState {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Stage,
        [string]$InstallDir = "",
        [string]$Mode = "FRESH_INSTALL",
        [int]$RebootCount = 0,
        [hashtable]$Metadata = @{}
    )

    $root = Get-SRInstallDir -InstallDir $InstallDir
    if (-not (Test-Path -LiteralPath $Script:StateDir)) {
        New-Item -ItemType Directory -Path $Script:StateDir -Force | Out-Null
    }

    $stateObj = [ordered]@{
        Stage       = $Stage
        InstallDir  = $root
        Mode        = $Mode
        RebootCount = $RebootCount
        Timestamp   = (Get-Date).ToString("o")
        Metadata    = $Metadata
    }

    $json = $stateObj | ConvertTo-Json -Depth 5
    Set-Content -LiteralPath $Script:StateFilePath -Value $json -Encoding UTF8 -Force
    Write-SRLog -Level "INFO" -Message "State instalasi disimpan: Stage=$Stage, Mode=$Mode, RebootCount=$RebootCount" -InstallDir $root
}

function Clear-SRInstallState {
    if (Test-Path -LiteralPath $Script:StateFilePath) {
        Remove-Item -LiteralPath $Script:StateFilePath -Force -ErrorAction SilentlyContinue
    }
    Unregister-SRRunOnceResume
}

function Register-SRRunOnceResume {
    param([string]$InstallDir = "")
    $root = Get-SRInstallDir -InstallDir $InstallDir
    $orchestratorPath = Join-Path $root "installer\scripts\install-orchestrator.ps1"
    $cmd = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Normal -File `"$orchestratorPath`" -Resume -InstallDir `"$root`""

    if (-not (Test-Path -LiteralPath $Script:RunOnceKeyPath)) {
        New-Item -Path $Script:RunOnceKeyPath -Force | Out-Null
    }

    Set-ItemProperty -Path $Script:RunOnceKeyPath -Name $Script:RunOnceValueName -Value $cmd -Type String -Force
    Write-SRLog -Level "INFO" -Message "RunOnce resume hook terdaftar di registry untuk melanjutkan instalasi pasca-reboot." -InstallDir $root
}

function Unregister-SRRunOnceResume {
    if (Test-Path -LiteralPath $Script:RunOnceKeyPath) {
        $prop = Get-ItemProperty -Path $Script:RunOnceKeyPath -Name $Script:RunOnceValueName -ErrorAction SilentlyContinue
        if ($null -ne $prop) {
            Remove-ItemProperty -Path $Script:RunOnceKeyPath -Name $Script:RunOnceValueName -Force -ErrorAction SilentlyContinue
        }
    }
}

function New-SRRandomBytes {
    param([int]$Length = 32)
    $bytes = New-Object byte[] $Length
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($bytes)
    } finally {
        $rng.Dispose()
    }
    return $bytes
}

function New-SRAppKey {
    $bytes = New-SRRandomBytes -Length 32
    return "base64:" + [Convert]::ToBase64String($bytes)
}

function New-SRRandomSecret {
    param([int]$ByteLength = 24)
    $bytes = New-SRRandomBytes -Length $ByteLength
    $hex = -join ($bytes | ForEach-Object { $_.ToString("x2") })
    return $hex
}

function Read-SREnvFile {
    param(
        [Parameter(Mandatory = $true)]
        [string]$EnvFilePath
    )

    $map = [ordered]@{}
    if (-not (Test-Path -LiteralPath $EnvFilePath)) {
        return $map
    }

    $lines = Get-Content -LiteralPath $EnvFilePath -Encoding UTF8
    foreach ($line in $lines) {
        $trimmed = $line.Trim()
        if ([string]::IsNullOrWhiteSpace($trimmed) -or $trimmed.StartsWith("#")) {
            continue
        }
        $idx = $trimmed.IndexOf("=")
        if ($idx -gt 0) {
            $key = $trimmed.Substring(0, $idx).Trim()
            $val = $trimmed.Substring($idx + 1).Trim()
            if (($val.StartsWith('"') -and $val.EndsWith('"')) -or ($val.StartsWith("'") -and $val.EndsWith("'"))) {
                $val = $val.Substring(1, $val.Length - 2)
            }
            $map[$key] = $val
        }
    }
    return $map
}

function Get-SREnvValue {
    param(
        [Parameter(Mandatory = $true)]
        [string]$EnvFilePath,
        [Parameter(Mandatory = $true)]
        [string]$Key,
        [string]$DefaultValue = ""
    )

    $map = Read-SREnvFile -EnvFilePath $EnvFilePath
    if ($map.Contains($Key) -and -not [string]::IsNullOrWhiteSpace([string]$map[$Key])) {
        return [string]$map[$Key]
    }
    return $DefaultValue
}

function Invoke-SRDockerCompose {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$ComposeArgs,
        [string]$InstallDir = "",
        [switch]$IgnoreExitCode
    )

    $root = Get-SRInstallDir -InstallDir $InstallDir
    $composeFile = Join-Path $root "docker-compose.yml"
    $envFile = Join-Path $root ".env.production"

    $fullArgs = @(
        "compose",
        "--project-directory", $root,
        "-f", $composeFile
    )
    if (Test-Path -LiteralPath $envFile) {
        $fullArgs += @("--env-file", $envFile)
    }
    $fullArgs += $ComposeArgs

    Write-SRLog -Level "INFO" -Message "Menjalankan: docker $($fullArgs -join ' ')" -InstallDir $root
    & docker @fullArgs
    $exitCode = $LASTEXITCODE
    if (-not $IgnoreExitCode -and $exitCode -ne 0) {
        throw "Perintah docker compose gagal dengan kode keluar $exitCode."
    }
    return $exitCode
}

function Test-SRChecksumManifest {
    param(
        [Parameter(Mandatory = $true)]
        [string]$ManifestPath,
        [string]$BaseDir = ""
    )

    if (-not (Test-Path -LiteralPath $ManifestPath)) {
        Write-SRLog -Level "WARN" -Message "File checksum tidak ditemukan di $ManifestPath (melewati verifikasi opsional)."
        return $true
    }

    if ([string]::IsNullOrWhiteSpace($BaseDir)) {
        $BaseDir = Split-Path -Parent $ManifestPath
    }

    $lines = Get-Content -LiteralPath $ManifestPath -Encoding UTF8
    foreach ($line in $lines) {
        $trimmed = $line.Trim()
        if ([string]::IsNullOrWhiteSpace($trimmed) -or $trimmed.StartsWith("#")) {
            continue
        }
        if ($trimmed -match '^([a-fA-F0-9]{64})\s+\*?(.+)$') {
            $expectedHash = $Matches[1].ToLowerInvariant()
            $relPath = $Matches[2].Trim()
            $targetFile = Join-Path $BaseDir $relPath
            if (-not (Test-Path -LiteralPath $targetFile)) {
                Write-SRLog -Level "ERROR" -Message "File paket tidak ditemukan untuk verifikasi SHA-256: $targetFile"
                return $false
            }
            $actualHash = (Get-FileHash -LiteralPath $targetFile -Algorithm SHA256).Hash.ToLowerInvariant()
            if ($actualHash -ne $expectedHash) {
                Write-SRLog -Level "ERROR" -Message "Checksum SHA-256 tidak cocok untuk $relPath! Expected=$expectedHash, Actual=$actualHash"
                return $false
            }
            Write-SRLog -Level "SUCCESS" -Message "Verifikasi SHA-256 valid: $relPath"
        }
    }
    return $true
}
