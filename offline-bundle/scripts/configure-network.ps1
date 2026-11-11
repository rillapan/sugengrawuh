#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Stage D.D Network Discovery, MikroTik DHCP Reservation Guide & Windows Firewall
.DESCRIPTION
    Detects physical LAN adapters (filtering out WSL/Docker/Hyper-V virtual adapters), supports multi-NIC selection,
    strictly distinguishes Cashier PC IP from Samsung Smart TV / PlayStation IPs, generates copy-pasteable
    MikroTik RouterOS CLI & WinBox DHCP Static Lease instructions, and configures Windows Defender Firewall
    strictly scoped to Private/LAN subnets (TCP 8008 for LAN, TCP 18009 for localhost/Docker WOL helper).
#>

param(
    [string]$InstallDir = "C:\SugengRawuh",
    [int]$AppPort = 8008,
    [int]$WolPort = 18009,
    [switch]$Interactive
)

$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "common.ps1")

Assert-SRAdmin -ScriptPath $MyInvocation.MyCommand.Path -ScriptArgs @("-InstallDir", $InstallDir, "-AppPort", $AppPort)
Ensure-SRDirectories -InstallDir $InstallDir

Write-SRLog -Level "INFO" -Message "=== Memulai Konfigurasi Jaringan LAN, MikroTik & Firewall (Stage D.D) ===" -InstallDir $InstallDir

$envProdPath = Join-Path $InstallDir ".env.production"
if (Test-Path -LiteralPath $envProdPath) {
    $configuredPort = Get-SREnvValue -EnvFilePath $envProdPath -Key "APP_PORT" -DefaultValue "$AppPort"
    if ($configuredPort -match '^\d+$') {
        $AppPort = [int]$configuredPort
    }
}

# 1. Enumerate Active Physical IPv4 Network Adapters (Filter out vEthernet, WSL, Docker, Loopback, VMware, VirtualBox)
$physicalConfigs = @(
    Get-NetIPConfiguration -ErrorAction SilentlyContinue | Where-Object {
        $null -ne $_.IPv4Address -and
        $null -ne $_.NetAdapter -and
        $_.NetAdapter.Status -eq "Up" -and
        $_.InterfaceAlias -notmatch "vEthernet|WSL|Docker|Loopback|VMware|VirtualBox|Bluetooth|Tailscale|ZeroTier" -and
        $_.NetAdapter.InterfaceDescription -notmatch "Hyper-V|Virtual|Loopback|VMware|VBox|TAP-Windows"
    }
)

$selectedAdapterInfo = $null

if ($physicalConfigs.Count -eq 0) {
    Write-SRLog -Level "WARN" -Message "Tidak ditemukan adaptor jaringan fisik yang aktif. Menggunakan fallback localhost (127.0.0.1)." -InstallDir $InstallDir
    $selectedAdapterInfo = [pscustomobject]@{
        InterfaceAlias = "Localhost (Belum Terhubung ke LAN MikroTik)"
        InterfaceIndex = 0
        IPv4Address    = "192.168.88.10"
        PrefixLength   = 24
        DefaultGateway = "192.168.88.1"
        MacAddress     = "00:00:00:00:00:00"
    }
} else {
    $chosenConfig = $physicalConfigs[0]

    # If multiple physical adapters exist (e.g., Ethernet + Wi-Fi), allow technician to pick the MikroTik LAN interface
    if ($physicalConfigs.Count -gt 1 -and $Interactive) {
        Write-Host ""
        Write-Host "Ditemukan lebih dari satu adaptor jaringan fisik aktif pada PC Kasir:" -ForegroundColor Yellow
        for ($i = 0; $i -lt $physicalConfigs.Count; $i++) {
            $cfg = $physicalConfigs[$i]
            $ip = $cfg.IPv4Address[0].IPAddress
            $gw = if ($null -ne $cfg.IPv4DefaultGateway) { $cfg.IPv4DefaultGateway.NextHop } else { "-" }
            $mac = ($cfg.NetAdapter.MacAddress -replace '-', ':').ToUpperInvariant()
            Write-Host "  [$($i + 1)] $($cfg.InterfaceAlias) ($($cfg.NetAdapter.InterfaceDescription)) -> IP: $ip | Gateway: $gw | MAC: $mac"
        }
        $selection = Read-Host "Pilih nomor adaptor yang terhubung ke jaringan LAN MikroTik/TV/PS (1-$($physicalConfigs.Count), default=1)"
        if ($selection -match '^\d+$') {
            $idx = [int]$selection - 1
            if ($idx -ge 0 -and $idx -lt $physicalConfigs.Count) {
                $chosenConfig = $physicalConfigs[$idx]
            }
        }
    } elseif ($physicalConfigs.Count -gt 1) {
        # Prefer wired Ethernet over Wi-Fi when running non-interactively
        $ethernetCandidate = $physicalConfigs | Where-Object { $_.InterfaceAlias -match "Ethernet|LAN" } | Select-Object -First 1
        if ($null -ne $ethernetCandidate) {
            $chosenConfig = $ethernetCandidate
        }
    }

    $rawMac = [string]$chosenConfig.NetAdapter.MacAddress
    $formattedMac = ($rawMac -replace '-', ':').ToUpperInvariant()
    $ipAddr = [string]$chosenConfig.IPv4Address[0].IPAddress
    $prefixLen = [int]$chosenConfig.IPv4Address[0].PrefixLength
    $gateway = if ($null -ne $chosenConfig.IPv4DefaultGateway) { [string]$chosenConfig.IPv4DefaultGateway.NextHop } else { "-" }

    $selectedAdapterInfo = [pscustomobject]@{
        InterfaceAlias = $chosenConfig.InterfaceAlias
        InterfaceIndex = $chosenConfig.InterfaceIndex
        IPv4Address    = $ipAddr
        PrefixLength   = $prefixLen
        DefaultGateway = $gateway
        MacAddress     = $formattedMac
    }

    # Ensure active LAN connection profile is set to Private so LAN firewall rules apply cleanly
    try {
        $connProfile = Get-NetConnectionProfile -InterfaceIndex $chosenConfig.InterfaceIndex -ErrorAction SilentlyContinue
        if ($null -ne $connProfile -and $connProfile.NetworkCategory -eq "Public") {
            Write-SRLog -Level "INFO" -Message "Mengubah profil jaringan '$($chosenConfig.InterfaceAlias)' dari Public ke Private untuk keamanan LAN..." -InstallDir $InstallDir
            Set-NetConnectionProfile -InterfaceIndex $chosenConfig.InterfaceIndex -NetworkCategory Private -ErrorAction SilentlyContinue
        }
    } catch {
        Write-SRLog -Level "WARN" -Message "Tidak dapat mengubah kategori profil jaringan secara otomatis: $($_.Exception.Message)" -InstallDir $InstallDir
    }
}

# 2. Configure Windows Defender Firewall Rules (Strictly Scoped to Private/LocalSubnet & RFC 1918 LAN)
$lanRemoteAddresses = @("LocalSubnet", "192.168.0.0/16", "10.0.0.0/8", "172.16.0.0/12")
$wolRemoteAddresses = @("127.0.0.1", "172.16.0.0/12", "192.168.65.0/24")

$webRuleName = "SugengRawuh-POS-Web-LAN-TCP-$AppPort"
$wolRuleName = "SugengRawuh-WOL-Helper-Local-TCP-$WolPort"

try {
    # Remove old rules with same name before recreating idempotently
    Remove-NetFirewallRule -Name $webRuleName -ErrorAction SilentlyContinue
    Remove-NetFirewallRule -Name $wolRuleName -ErrorAction SilentlyContinue

    New-NetFirewallRule `
        -Name $webRuleName `
        -DisplayName "Sugeng Rawuh Billing & POS (HTTP LAN Port $AppPort)" `
        -Description "Mengizinkan akses aplikasi kasir Sugeng Rawuh pada port TCP $AppPort khusus jaringan lokal (Private/LAN RFC 1918)." `
        -Direction Inbound `
        -Action Allow `
        -Protocol TCP `
        -LocalPort $AppPort `
        -Profile Private, Domain `
        -RemoteAddress $lanRemoteAddresses | Out-Null

    Write-SRLog -Level "SUCCESS" -Message "Aturan Firewall LAN dibuat: $webRuleName (TCP $AppPort, Profile=Private,Domain, RemoteAddress=LAN)." -InstallDir $InstallDir

    New-NetFirewallRule `
        -Name $wolRuleName `
        -DisplayName "Sugeng Rawuh WOL Helper (Docker Bridge Port $WolPort)" `
        -Description "Mengizinkan container Docker Sugeng Rawuh mengirim perintah Wake-on-LAN terautentikasi ke host Windows (TCP $WolPort)." `
        -Direction Inbound `
        -Action Allow `
        -Protocol TCP `
        -LocalPort $WolPort `
        -Profile Any `
        -RemoteAddress $wolRemoteAddresses | Out-Null

    Write-SRLog -Level "SUCCESS" -Message "Aturan Firewall WOL Helper dibuat: $wolRuleName (TCP $WolPort, khusus localhost & subnet Docker)." -InstallDir $InstallDir
} catch {
    Write-SRLog -Level "WARN" -Message "Peringatan saat membuat aturan Windows Firewall: $($_.Exception.Message)" -InstallDir $InstallDir
}

# 3. Generate Clear Technician & Cashier Network Guide (MikroTik Static DHCP Lease + IP Separation Warning)
$pcIp  = $selectedAdapterInfo.IPv4Address
$pcMac = $selectedAdapterInfo.MacAddress
$pcGw  = $selectedAdapterInfo.DefaultGateway
$pcNic = $selectedAdapterInfo.InterfaceAlias

$networkGuideContent = @"
================================================================================
 PANDUAN JARINGAN LAN & MIKROTIK - SUGENG RAWUH BILLING & POS
 Dibuat pada: $((Get-Date).ToString("yyyy-MM-dd HH:mm:ss"))
================================================================================

1. IDENTITAS JARINGAN PC KASIR (SERVER SUGENG RAWUH)
   -----------------------------------------------------------------------------
   Adaptor Jaringan Aktif : $pcNic
   IPv4 Address PC Kasir  : $pcIp (Prefix /$($selectedAdapterInfo.PrefixLength))
   MAC Address PC Kasir   : $pcMac
   Default Gateway (Router): $pcGw

   URL Akses di PC Kasir  : http://localhost:$AppPort
   URL Akses LAN (HP/Tab) : http://${pcIp}:$AppPort

   [PENTING - JANGAN TERTUKAR!]
   IP $pcIp di atas adalah IP PC KASIR (Server Aplikasi), BUKAN IP Samsung Smart TV
   dan BUKAN IP Konsol PS3/PS4!
   Setiap unit Samsung Smart TV, PS3 (webMAN), dan PS4 (GoldHEN) memiliki IP dan
   MAC Address masing-masing yang didaftarkan melalui menu 'Perangkat Fisik' di
   dalam aplikasi Sugeng Rawuh.

2. CARA MENGUNCI IP STATIS PC KASIR DI ROUTER MIKROTIK (DHCP RESERVATION)
   -----------------------------------------------------------------------------
   Agar IP PC Kasir ($pcIp) tidak berubah saat PC atau Router MikroTik mati lampu,
   kunci DHCP Lease PC Kasir menggunakan salah satu cara berikut:

   CARA A — Via Terminal MikroTik (Copy-Paste Perintah Ini):
   ---------------------------------------------------------
   /ip dhcp-server lease add address=$pcIp mac-address=$pcMac comment="PC-KASIR-SUGENG-RAWUH"

   (Jika IP sudah terdaftar sebagai Dynamic Lease di MikroTik, jalankan perintah ini):
   /ip dhcp-server lease make-static [find mac-address="$pcMac"]
   /ip dhcp-server lease set [find mac-address="$pcMac"] comment="PC-KASIR-SUGENG-RAWUH"

   CARA B — Via Aplikasi WinBox MikroTik:
   --------------------------------------
   1. Buka WinBox dan login ke Router MikroTik rental Anda.
   2. Klik menu 'IP' -> 'DHCP Server' -> tab 'Leases'.
   3. Cari baris dengan Address '$pcIp' atau MAC Address '$pcMac'.
   4. Klik kanan baris tersebut -> pilih 'Make Static'.
   5. Klik kanan lagi -> pilih 'Comment' -> isi: PC-KASIR-SUGENG-RAWUH -> klik OK.

3. REKOMENDASI DHCP LEASE UNTUK SAMSUNG SMART TV & KONSOL PS3/PS4
   -----------------------------------------------------------------------------
   Lakukan langkah 'Make Static' yang sama di menu IP -> DHCP Server -> Leases
   untuk setiap unit Samsung Smart TV, PS3, dan PS4 agar IP perangkat di rental
   tidak pernah berubah.
================================================================================
"@

$guideFilePath = Join-Path $InstallDir "data\INFORMASI-JARINGAN-MIKROTIK.txt"
Set-Content -LiteralPath $guideFilePath -Value $networkGuideContent -Encoding UTF8 -Force

Write-Host ""
Write-Host $networkGuideContent -ForegroundColor Green
Write-SRLog -Level "SUCCESS" -Message "Informasi jaringan & panduan MikroTik disimpan di: $guideFilePath" -InstallDir $InstallDir
exit 0

