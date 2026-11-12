#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Stage E Cashier Startup, Health Check & Recovery GUI Launcher
.DESCRIPTION
    Invoked by Desktop Shortcuts ("Sugeng Rawuh Billing & POS" and "Sugeng Rawuh - Hardware Setup & Test Center").
    Note on Windows Home (Windows 11 Home / 10 Home): Docker Desktop requires an interactive Windows user login
    session; this launcher automatically starts Docker Desktop within the active cashier login session if needed.
    Fast path: If http://localhost:8008/api/health is already healthy, ensures WOL helper & network info are synced
    and opens the browser immediately to $appUrl$TargetPath.
    Recovery path: Displays a clean Windows Forms progress window while starting WOL Helper,
    Docker Desktop, Sugeng Rawuh containers (db, app, web, daemon, scheduler), reconciling billing
    sessions after power loss/reboot, and then opening the browser once /api/health returns 200 OK.
#>

param(
    [string]$InstallDir = "",
    [string]$TargetPath = "",
    [switch]$Headless
)

$ErrorActionPreference = "Continue"
. (Join-Path $PSScriptRoot "common.ps1")

$InstallDir = Get-SRInstallDir -InstallDir $InstallDir
Ensure-SRDirectories -InstallDir $InstallDir

$envProdPath = Join-Path $InstallDir ".env.production"
$appPort = Get-SREnvValue -EnvFilePath $envProdPath -Key "APP_PORT" -DefaultValue "8008"
$appUrl = "http://localhost:$appPort"
$healthUrl = "$appUrl/api/health"
$targetUrl = if ([string]::IsNullOrWhiteSpace($TargetPath)) {
    $appUrl
} elseif ($TargetPath.StartsWith("/")) {
    "$appUrl$TargetPath"
} else {
    "$appUrl/$TargetPath"
}

function Test-SRAppHealth {
    param([string]$Url, [int]$TimeoutSec = 2)
    try {
        $resp = Invoke-RestMethod -Uri $Url -Method Get -TimeoutSec $TimeoutSec -ErrorAction Stop
        if ($resp.status -eq "ok" -and $resp.database -eq "connected") {
            return $true
        }
    } catch {}
    return $false
}

function Ensure-WolHelperRunning {
    $wolScript = Join-Path $PSScriptRoot "wol-helper.ps1"
    $wolListening = Get-NetTCPConnection -LocalPort 18009 -State Listen -ErrorAction SilentlyContinue
    if ($null -eq $wolListening -and (Test-Path -LiteralPath $wolScript)) {
        Start-Process -FilePath "powershell.exe" -ArgumentList @(
            "-NoProfile",
            "-ExecutionPolicy", "Bypass",
            "-WindowStyle", "Hidden",
            "-File", "`"$wolScript`"",
            "-InstallDir", "`"$InstallDir`""
        ) -WindowStyle Hidden -ErrorAction SilentlyContinue | Out-Null
    }
}

function Sync-LauncherNetworkInfo {
    try {
        $phys = @(
            Get-NetIPConfiguration -ErrorAction SilentlyContinue | Where-Object {
                $null -ne $_.IPv4Address -and
                $null -ne $_.NetAdapter -and
                $_.NetAdapter.Status -eq "Up" -and
                $_.InterfaceAlias -notmatch "vEthernet|WSL|Docker|Loopback|VMware|VirtualBox|Bluetooth|Tailscale|ZeroTier"
            }
        )
        if ($phys.Count -gt 0) {
            $active = $phys[0]
            $curIp = [string]$active.IPv4Address[0].IPAddress
            $prevInfo = Get-SRNetworkInfo -InstallDir $InstallDir
            $prevIp = if ($null -ne $prevInfo -and -not [string]::IsNullOrWhiteSpace([string]$prevInfo.IPv4Address)) { [string]$prevInfo.IPv4Address } else { "" }

            if ([string]::IsNullOrWhiteSpace($prevIp) -or $prevIp -ne $curIp) {
                $networkScript = Join-Path $PSScriptRoot "configure-network.ps1"
                if ((Test-SRAdmin) -and (Test-Path -LiteralPath $networkScript)) {
                    & powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File $networkScript -InstallDir $InstallDir | Out-Null
                } else {
                    $rawMac = ([string]$active.NetAdapter.MacAddress -replace '-', ':').ToUpperInvariant()
                    $gw = if ($null -ne $active.IPv4DefaultGateway) { [string]$active.IPv4DefaultGateway.NextHop } else { "-" }
                    Save-SRNetworkInfo `
                        -InstallDir $InstallDir `
                        -InterfaceAlias $active.InterfaceAlias `
                        -InterfaceIndex $active.InterfaceIndex `
                        -IPv4Address $curIp `
                        -PrefixLength ([int]$active.IPv4Address[0].PrefixLength) `
                        -DefaultGateway $gw `
                        -MacAddress $rawMac `
                        -DhcpEnabled $true `
                        -IpChanged (-not [string]::IsNullOrWhiteSpace($prevIp) -and $prevIp -ne $curIp) `
                        -PreviousIPv4Address $prevIp `
                        -AppPort ([int]$appPort) `
                        -WolPort 18009 | Out-Null
                }
            }
        }
    } catch {}
}

# FAST PATH: If server is already up & healthy, ensure WOL helper is running, sync network info, and open browser immediately!
if (Test-SRAppHealth -Url $healthUrl -TimeoutSec 2) {
    Ensure-WolHelperRunning
    Sync-LauncherNetworkInfo
    Write-SRLog -Level "INFO" -Message "Fast-path launcher: Server sudah siap, membuka browser $targetUrl" -InstallDir $InstallDir
    if (-not $Headless) {
        Start-Process $targetUrl
    }
    exit 0
}

# RECOVERY / BOOT PATH: Build Windows Forms Status Window for Cashier
$form = $null
$lblTitle = $null
$lblStatus = $null
$progressBar = $null

if (-not $Headless) {
    try {
        Add-Type -AssemblyName System.Windows.Forms
        Add-Type -AssemblyName System.Drawing
        [System.Windows.Forms.Application]::EnableVisualStyles()

        $form = New-Object System.Windows.Forms.Form
        $form.Text = "Sugeng Rawuh Billing & POS — Menyiapkan Server..."
        $form.Size = New-Object System.Drawing.Size(520, 210)
        $form.StartPosition = "CenterScreen"
        $form.FormBorderStyle = "FixedDialog"
        $form.MaximizeBox = $false
        $form.MinimizeBox = $false
        $form.TopMost = $false
        $form.BackColor = [System.Drawing.Color]::FromArgb(18, 24, 38)
        $form.ForeColor = [System.Drawing.Color]::White

        $lblTitle = New-Object System.Windows.Forms.Label
        $lblTitle.Text = "Sugeng Rawuh Billing & POS"
        $lblTitle.Font = New-Object System.Drawing.Font("Segoe UI", 14, [System.Drawing.FontStyle]::Bold)
        $lblTitle.Location = New-Object System.Drawing.Point(24, 20)
        $lblTitle.Size = New-Object System.Drawing.Size(460, 30)
        $form.Controls.Add($lblTitle)

        $lblStatus = New-Object System.Windows.Forms.Label
        $lblStatus.Text = "Memeriksa layanan sistem dan menyiapkan database..."
        $lblStatus.Font = New-Object System.Drawing.Font("Segoe UI", 10, [System.Drawing.FontStyle]::Regular)
        $lblStatus.Location = New-Object System.Drawing.Point(24, 62)
        $lblStatus.Size = New-Object System.Drawing.Size(460, 44)
        $form.Controls.Add($lblStatus)

        $progressBar = New-Object System.Windows.Forms.ProgressBar
        $progressBar.Location = New-Object System.Drawing.Point(24, 118)
        $progressBar.Size = New-Object System.Drawing.Size(456, 26)
        $progressBar.Minimum = 0
        $progressBar.Maximum = 100
        $progressBar.Value = 10
        $form.Controls.Add($progressBar)

        $form.Show()
        [System.Windows.Forms.Application]::DoEvents()
    } catch {
        $form = $null
    }
}

function Update-LauncherUi {
    param([string]$StatusText, [int]$Percent)
    Write-SRLog -Level "INFO" -Message "[Launcher] ($Percent%) $StatusText" -InstallDir $InstallDir
    if ($null -ne $form) {
        $lblStatus.Text = $StatusText
        $progressBar.Value = [Math]::Min(100, [Math]::Max(0, $Percent))
        [System.Windows.Forms.Application]::DoEvents()
    }
}

try {
    # Step 1: Ensure Physical-LAN Wake-on-LAN Helper is running on port 18009 & sync network info
    Update-LauncherUi -StatusText "Menjalankan layanan Wake-on-LAN Helper (Port 18009) & memeriksa IP LAN..." -Percent 15
    Ensure-WolHelperRunning
    Sync-LauncherNetworkInfo

    # Step 2: Check Docker Engine readiness; start Docker Desktop in user login session if not running
    Update-LauncherUi -StatusText "Memeriksa status Docker Engine (sesi user Windows Home/Pro)..." -Percent 25
    $dockerReady = $false
    try {
        $null = & docker info 2>$null
        if ($LASTEXITCODE -eq 0) {
            $dockerReady = $true
        }
    } catch {}

    if (-not $dockerReady) {
        Update-LauncherUi -StatusText "Menyalakan Docker Desktop (mohon tunggu 30-90 detik)..." -Percent 30
        Start-SRDockerDesktop -InstallDir $InstallDir | Out-Null

        $maxWait = 180
        $waited = 0
        while ($waited -lt $maxWait) {
            for ($i = 0; $i -lt 30; $i++) {
                Start-Sleep -Milliseconds 100
                if ($null -ne $form) { [System.Windows.Forms.Application]::DoEvents() }
            }
            $waited += 3
            try {
                $null = & docker info 2>$null
                if ($LASTEXITCODE -eq 0) {
                    $dockerReady = $true
                    break
                }
            } catch {}
            $pct = [Math]::Min(55, 30 + [int](($waited / $maxWait) * 25))
            Update-LauncherUi -StatusText "Menunggu Docker Engine siap... ($waited detik)" -Percent $pct
        }

        if (-not $dockerReady) {
            throw "Docker Engine belum siap setelah $maxWait detik. Pastikan sesi user Windows sudah login dan aplikasi Docker Desktop berjalan normal."
        }
    }

    # Step 3: Start containers via docker compose up -d
    Update-LauncherUi -StatusText "Menjalankan layanan Database MySQL, Aplikasi, dan Billing Daemon..." -Percent 65
    Invoke-SRDockerCompose -InstallDir $InstallDir -ComposeArgs @("up", "-d") -IgnoreExitCode | Out-Null

    # Step 4: Poll /api/health until MySQL & PHP-FPM respond 200 OK
    Update-LauncherUi -StatusText "Menunggu server aplikasi siap melayani kasir..." -Percent 75
    $healthReady = $false
    $healthWait = 0
    $healthMax = 120
    while ($healthWait -lt $healthMax) {
        if (Test-SRAppHealth -Url $healthUrl -TimeoutSec 3) {
            $healthReady = $true
            break
        }
        for ($i = 0; $i -lt 20; $i++) {
            Start-Sleep -Milliseconds 100
            if ($null -ne $form) { [System.Windows.Forms.Application]::DoEvents() }
        }
        $healthWait += 2
        $pct = [Math]::Min(90, 75 + [int](($healthWait / $healthMax) * 15))
        Update-LauncherUi -StatusText "Menyiapkan koneksi database dan sistem billing... ($healthWait detik)" -Percent $pct
    }

    if (-not $healthReady) {
        throw "Server belum merespons status sehat di $healthUrl setelah $healthMax detik."
    }

    # Step 5: Reconcile active billing sessions safely after power restoration / restart
    Update-LauncherUi -StatusText "Sinkronisasi & rekonsiliasi sesi rental aktif..." -Percent 95
    Invoke-SRDockerCompose -InstallDir $InstallDir -ComposeArgs @(
        "exec", "-T", "app", "gosu", "www-data", "php", "artisan", "billing:reconcile"
    ) -IgnoreExitCode | Out-Null

    # Step 6: Open Browser & Close Status Window
    Update-LauncherUi -StatusText "Server siap! Membuka aplikasi di browser..." -Percent 100
    Start-Sleep -Milliseconds 500
    if ($null -ne $form) {
        $form.Close()
        $form.Dispose()
    }
    if (-not $Headless) {
        Start-Process $targetUrl
    }
    exit 0
} catch {
    $errMsg = $_.Exception.Message
    Write-SRLog -Level "ERROR" -Message "Startup Launcher gagal: $errMsg" -InstallDir $InstallDir
    if ($null -ne $form) {
        $form.Close()
        $form.Dispose()
    }
    if (-not $Headless) {
        $logFolder = Join-Path $InstallDir "data\logs"
        $setupServicesScript = Join-Path $PSScriptRoot "setup-services.ps1"
        $dialogMsg = "Sistem Sugeng Rawuh membutuhkan waktu lebih lama atau mengalami kendala saat menyala:`r`n`r`n$errMsg`r`n`r`nKlik 'Yes' untuk menjalankan Repair Layanan Otomatis (setup-services.ps1 -Repair), 'No' untuk membuka folder log diagnostik ($logFolder), atau 'Cancel' untuk menutup."
        $choice = [System.Windows.Forms.MessageBox]::Show(
            $dialogMsg,
            "Sugeng Rawuh Billing & POS - Pemulihan & Diagnostik",
            [System.Windows.Forms.MessageBoxButtons]::YesNoCancel,
            [System.Windows.Forms.MessageBoxIcon]::Warning
        )
        if ($choice -eq [System.Windows.Forms.DialogResult]::Yes -and (Test-Path -LiteralPath $setupServicesScript)) {
            Start-Process -FilePath "powershell.exe" -ArgumentList @(
                "-NoProfile", "-ExecutionPolicy", "Bypass",
                "-File", "`"$setupServicesScript`"",
                "-InstallDir", "`"$InstallDir`"",
                "-Repair", "-Interactive"
            ) -Verb RunAs
        } elseif ($choice -eq [System.Windows.Forms.DialogResult]::No) {
            Start-Process "explorer.exe" -ArgumentList "`"$logFolder`""
        }
    }
    exit 1
}
