#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Shared PowerShell Helper Library
.DESCRIPTION
    Provides logging, Administrator elevation, RunOnce + Desktop rescue shortcut reboot-resume
    state management, dynamic drive/directory resolution (C:\ or D:\), session-aware Docker Desktop
    startup & engine readiness polling, network metadata persistence, cryptographic secret/APP_KEY
    generation, SHA-256 verification, and Docker Compose wrappers.
#>

Set-StrictMode -Version Latest

$Script:DefaultInstallDir = "C:\SugengRawuh"
$Script:StateDir          = "C:\ProgramData\SugengRawuh"
$Script:StateFilePath     = Join-Path $Script:StateDir "install-state.json"
$Script:LocationFilePath  = Join-Path $Script:StateDir "installed-location.json"
$Script:RunOnceKeyPath    = "HKLM:\Software\Microsoft\Windows\CurrentVersion\RunOnce"
$Script:RunOnceValueName  = "SugengRawuhResumeInstall"
$Script:ResumeShortcutName = "Lanjutkan Instalasi Sugeng Rawuh.lnk"

function Get-SRInstallDir {
    param([string]$InstallDir = "")
    if (-not [string]::IsNullOrWhiteSpace($InstallDir)) {
        return $InstallDir
    }
    if (-not [string]::IsNullOrWhiteSpace($env:SR_INSTALL_DIR)) {
        return $env:SR_INSTALL_DIR
    }
    if (-not [string]::IsNullOrWhiteSpace($PSScriptRoot)) {
        $candidateRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
        if (-not [string]::IsNullOrWhiteSpace($candidateRoot)) {
            if ((Test-Path -LiteralPath (Join-Path $candidateRoot "docker-compose.yml")) -or
                (Test-Path -LiteralPath (Join-Path $candidateRoot ".env.production"))) {
                return $candidateRoot
            }
        }
    }
    foreach ($metaFile in @($Script:StateFilePath, $Script:LocationFilePath)) {
        if (Test-Path -LiteralPath $metaFile) {
            try {
                $raw = Get-Content -LiteralPath $metaFile -Raw -Encoding UTF8 -ErrorAction Stop
                $parsed = $raw | ConvertFrom-Json
                if ($null -ne $parsed -and -not [string]::IsNullOrWhiteSpace([string]$parsed.InstallDir)) {
                    return [string]$parsed.InstallDir
                }
            } catch {}
        }
    }
    return $Script:DefaultInstallDir
}

function Save-SRInstalledLocation {
    param([string]$InstallDir = "")
    $root = Get-SRInstallDir -InstallDir $InstallDir
    if (-not (Test-Path -LiteralPath $Script:StateDir)) {
        New-Item -ItemType Directory -Path $Script:StateDir -Force -ErrorAction SilentlyContinue | Out-Null
    }
    $locObj = [ordered]@{
        InstallDir = $root
        UpdatedAt  = (Get-Date).ToString("o")
    }
    $json = $locObj | ConvertTo-Json -Depth 3
    Set-Content -LiteralPath $Script:LocationFilePath -Value $json -Encoding UTF8 -Force
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

    Save-SRInstalledLocation -InstallDir $root

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
    Write-SRLog -Level "INFO" -Message "State instalasi disimpan: Stage=$Stage, Mode=$Mode, RebootCount=$RebootCount, InstallDir=$root" -InstallDir $root
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
    Save-SRInstalledLocation -InstallDir $root
    $orchestratorPath = Join-Path $root "installer\scripts\install-orchestrator.ps1"
    $cmd = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Normal -File `"$orchestratorPath`" -Resume -InstallDir `"$root`""

    if (-not (Test-Path -LiteralPath $Script:RunOnceKeyPath)) {
        New-Item -Path $Script:RunOnceKeyPath -Force | Out-Null
    }

    Set-ItemProperty -Path $Script:RunOnceKeyPath -Name $Script:RunOnceValueName -Value $cmd -Type String -Force
    Write-SRLog -Level "INFO" -Message "RunOnce resume hook terdaftar di registry untuk melanjutkan instalasi pasca-reboot." -InstallDir $root

    # Create Desktop rescue shortcut ("Lanjutkan Instalasi Sugeng Rawuh.lnk") so technician can resume manually if RunOnce is interrupted
    try {
        $desktopPath = [Environment]::GetFolderPath("CommonDesktopDirectory")
        if ([string]::IsNullOrWhiteSpace($desktopPath) -or -not (Test-Path -LiteralPath $desktopPath)) {
            $desktopPath = [Environment]::GetFolderPath("Desktop")
        }
        if (-not [string]::IsNullOrWhiteSpace($desktopPath) -and (Test-Path -LiteralPath $desktopPath)) {
            $resumeShortcutPath = Join-Path $desktopPath $Script:ResumeShortcutName
            $wshShell = New-Object -ComObject WScript.Shell
            $shortcut = $wshShell.CreateShortcut($resumeShortcutPath)
            $shortcut.TargetPath = "powershell.exe"
            $shortcut.Arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$orchestratorPath`" -Resume -InstallDir `"$root`""
            $shortcut.WorkingDirectory = $root
            $shortcut.Description = "Lanjutkan Instalasi Sugeng Rawuh Billing & POS Pasca-Restart"
            $shortcut.Save()
            Write-SRLog -Level "INFO" -Message "Shortcut penyelamat pasca-restart dibuat: $resumeShortcutPath" -InstallDir $root
        }
    } catch {
        Write-SRLog -Level "WARN" -Message "Tidak dapat membuat shortcut penyelamat Lanjutkan Instalasi di Desktop: $($_.Exception.Message)" -InstallDir $root
    }
}

function Unregister-SRRunOnceResume {
    if (Test-Path -LiteralPath $Script:RunOnceKeyPath) {
        $prop = Get-ItemProperty -Path $Script:RunOnceKeyPath -Name $Script:RunOnceValueName -ErrorAction SilentlyContinue
        if ($null -ne $prop) {
            Remove-ItemProperty -Path $Script:RunOnceKeyPath -Name $Script:RunOnceValueName -Force -ErrorAction SilentlyContinue
        }
    }

    foreach ($folderName in @("CommonDesktopDirectory", "Desktop")) {
        try {
            $desk = [Environment]::GetFolderPath($folderName)
            if (-not [string]::IsNullOrWhiteSpace($desk)) {
                $lnk = Join-Path $desk $Script:ResumeShortcutName
                if (Test-Path -LiteralPath $lnk) {
                    Remove-Item -LiteralPath $lnk -Force -ErrorAction SilentlyContinue
                }
            }
        } catch {}
    }
}

function Start-SRDockerDesktop {
    <#
    .DESCRIPTION
        Starts Docker Desktop safely in the interactive user session.
        IMPORTANT (Windows 11 Home / 10 Home): Docker Desktop requires an interactive Windows user login session.
        It cannot run as a pre-login background service before the cashier logs into Windows.
    #>
    param([string]$InstallDir = "")
    $root = Get-SRInstallDir -InstallDir $InstallDir

    try {
        $null = & docker info 2>$null
        if ($LASTEXITCODE -eq 0) {
            return $true
        }
    } catch {}

    $dockerDesktopExe = "C:\Program Files\Docker\Docker\Docker Desktop.exe"
    if (-not (Test-Path -LiteralPath $dockerDesktopExe)) {
        Write-SRLog -Level "ERROR" -Message "Docker Desktop tidak ditemukan di $dockerDesktopExe." -InstallDir $root
        return $false
    }

    Write-SRLog -Level "INFO" -Message "Menjalankan Docker Desktop pada sesi login Windows interaktif (Catatan Windows Home: Docker Desktop membutuhkan sesi user login aktif)..." -InstallDir $root
    try {
        $existingProc = Get-Process -Name "Docker Desktop" -ErrorAction SilentlyContinue
        if ($null -eq $existingProc) {
            try {
                Start-Process -FilePath "explorer.exe" -ArgumentList "`"$dockerDesktopExe`"" -ErrorAction Stop | Out-Null
            } catch {
                Start-Process -FilePath $dockerDesktopExe -ErrorAction SilentlyContinue | Out-Null
            }
        }
        return $true
    } catch {
        Write-SRLog -Level "WARN" -Message "Gagal memicu proses Docker Desktop: $($_.Exception.Message)" -InstallDir $root
        return $false
    }
}

function Wait-SRDockerEngine {
    param(
        [string]$InstallDir = "",
        [int]$MaxWaitSeconds = 180,
        [switch]$AllowInteractiveRetry
    )
    $root = Get-SRInstallDir -InstallDir $InstallDir
    $interval = 5

    while ($true) {
        $elapsed = 0
        while ($elapsed -lt $MaxWaitSeconds) {
            try {
                $null = & docker info 2>$null
                if ($LASTEXITCODE -eq 0) {
                    Write-SRLog -Level "SUCCESS" -Message "Docker Engine siap digunakan." -InstallDir $root
                    return $true
                }
            } catch {}

            Start-Sleep -Seconds $interval
            $elapsed += $interval
            Write-SRLog -Level "INFO" -Message "Menunggu Docker Engine siap... ($elapsed / $MaxWaitSeconds detik)" -InstallDir $root
        }

        if (-not $AllowInteractiveRetry) {
            return $false
        }

        try {
            Add-Type -AssemblyName System.Windows.Forms
            $retryAns = [System.Windows.Forms.MessageBox]::Show(
                "Docker Desktop sedang memulai mesin WSL2 dan membutuhkan waktu lebih lama (umum terjadi pada PC 8 GB RAM saat pertama kali menyala).`r`n`r`nPastikan jendela Docker Desktop sudah terbuka dan sesi user Windows sudah login.`r`nKlik 'Retry' untuk menunggu $MaxWaitSeconds detik lagi, atau 'Cancel' untuk membatalkan.",
                "Sugeng Rawuh Billing & POS - Menunggu Docker Engine",
                [System.Windows.Forms.MessageBoxButtons]::RetryCancel,
                [System.Windows.Forms.MessageBoxIcon]::Warning
            )
            if ($retryAns -eq [System.Windows.Forms.DialogResult]::Retry) {
                Start-SRDockerDesktop -InstallDir $root | Out-Null
                continue
            }
        } catch {}

        return $false
    }
}

function Get-SRNetworkInfo {
    param([string]$InstallDir = "")
    $root = Get-SRInstallDir -InstallDir $InstallDir
    $netInfoPath = Join-Path $root "data\network-info.json"
    if (-not (Test-Path -LiteralPath $netInfoPath)) {
        return $null
    }
    try {
        $raw = Get-Content -LiteralPath $netInfoPath -Raw -Encoding UTF8 -ErrorAction Stop
        return ($raw | ConvertFrom-Json)
    } catch {
        return $null
    }
}

function Save-SRNetworkInfo {
    param(
        [string]$InstallDir = "",
        [string]$InterfaceAlias = "",
        [int]$InterfaceIndex = 0,
        [string]$IPv4Address = "",
        [int]$PrefixLength = 24,
        [string]$DefaultGateway = "-",
        [string]$MacAddress = "00:00:00:00:00:00",
        [bool]$DhcpEnabled = $true,
        [bool]$IpChanged = $false,
        [string]$PreviousIPv4Address = "",
        [int]$AppPort = 8008,
        [int]$WolPort = 18009
    )
    $root = Get-SRInstallDir -InstallDir $InstallDir
    Ensure-SRDirectories -InstallDir $root
    $netInfoPath = Join-Path $root "data\network-info.json"

    $netObj = [ordered]@{
        InterfaceAlias      = $InterfaceAlias
        InterfaceIndex      = $InterfaceIndex
        IPv4Address         = $IPv4Address
        PrefixLength        = $PrefixLength
        DefaultGateway      = $DefaultGateway
        MacAddress          = $MacAddress
        DhcpEnabled         = $DhcpEnabled
        IpChanged           = $IpChanged
        PreviousIPv4Address = $PreviousIPv4Address
        AppPort             = $AppPort
        WolPort             = $WolPort
        UpdatedAt           = (Get-Date).ToString("o")
    }

    $json = $netObj | ConvertTo-Json -Depth 4
    Set-Content -LiteralPath $netInfoPath -Value $json -Encoding UTF8 -Force
    return $netObj
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
