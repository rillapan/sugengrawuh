#Requires -Version 5.1
<#
.SYNOPSIS
    Sugeng Rawuh Billing & POS - Stage D.E Authenticated Physical-LAN Wake-on-LAN Helper
.DESCRIPTION
    Bridges Docker Desktop for Windows NAT limitation by listening on TCP port 18009,
    validating source IP (localhost / Docker bridge) and X-WOL-Token header against
    HARDWARE_WOL_HELPER_TOKEN in .env.production, constructing the 102-byte IEEE 802.3
    Magic Packet, and broadcasting UDP ports 9 & 7 out of every active physical LAN NIC.
#>

param(
    [string]$InstallDir = "C:\SugengRawuh",
    [int]$Port = 18009
)

$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "common.ps1")

Ensure-SRDirectories -InstallDir $InstallDir

function Write-WolLog {
    param([string]$Message, [string]$Level = "INFO")
    Write-SRLog -Level $Level -Message $Message -InstallDir $InstallDir -LogFileName "wol-helper.log"
}

function Test-ConstantTimeEquals {
    param([string]$Expected, [string]$Actual)
    if ([string]::IsNullOrEmpty($Expected) -or [string]::IsNullOrEmpty($Actual)) {
        return $false
    }
    $b1 = [System.Text.Encoding]::UTF8.GetBytes($Expected)
    $b2 = [System.Text.Encoding]::UTF8.GetBytes($Actual)
    if ($b1.Length -ne $b2.Length) {
        return $false
    }
    $diff = 0
    for ($i = 0; $i -lt $b1.Length; $i++) {
        $diff = $diff -bor ($b1[$i] -bxor $b2[$i])
    }
    return ($diff -eq 0)
}

function Test-AllowedWolClientIp {
    param([string]$RemoteIp)
    if ([string]::IsNullOrWhiteSpace($RemoteIp)) {
        return $false
    }
    if ($RemoteIp -eq "127.0.0.1" -or $RemoteIp -eq "::1") {
        return $true
    }
    # Allow Docker Desktop / WSL2 host-gateway subnets (172.16.0.0/12 and 192.168.65.0/24)
    if ($RemoteIp -match '^172\.(1[6-9]|2[0-9]|3[0-1])\.\d{1,3}\.\d{1,3}$') {
        return $true
    }
    if ($RemoteIp -match '^192\.168\.65\.\d{1,3}$') {
        return $true
    }
    return $false
}

function New-MagicPacketBytes {
    param([Parameter(Mandatory = $true)][string]$MacAddress)
    $cleanHex = ($MacAddress -replace '[^0-9A-Fa-f]', '')
    if ($cleanHex.Length -ne 12) {
        throw "Format MAC address tidak valid: $MacAddress"
    }

    $macBytes = New-Object byte[] 6
    for ($i = 0; $i -lt 6; $i++) {
        $macBytes[$i] = [Convert]::ToByte($cleanHex.Substring($i * 2, 2), 16)
    }

    # 102-byte IEEE 802.3 Magic Packet: 6 bytes of 0xFF followed by 16 repetitions of 6-byte MAC
    $packet = New-Object byte[] 102
    for ($i = 0; $i -lt 6; $i++) {
        $packet[$i] = 0xFF
    }
    for ($rep = 1; $rep -le 16; $rep++) {
        [Array]::Copy($macBytes, 0, $packet, $rep * 6, 6)
    }
    return $packet
}

function Send-PhysicalLanMagicPacket {
    param(
        [Parameter(Mandatory = $true)][string]$MacAddress,
        [string]$TargetIp = ""
    )

    $packet = New-MagicPacketBytes -MacAddress $MacAddress
    $interfacesUsed = New-Object System.Collections.Generic.List[string]

    # Discover all active physical IPv4 LAN addresses on the Windows host
    $physicalIps = @(
        Get-NetIPConfiguration -ErrorAction SilentlyContinue | Where-Object {
            $null -ne $_.IPv4Address -and
            $null -ne $_.NetAdapter -and
            $_.NetAdapter.Status -eq "Up" -and
            $_.InterfaceAlias -notmatch "vEthernet|WSL|Docker|Loopback|VMware|VirtualBox"
        } | ForEach-Object {
            $_.IPv4Address[0].IPAddress
        }
    )

    # Always include 0.0.0.0 fallback so routing table default interface is also used
    $bindIps = @($physicalIps) + @("0.0.0.0") | Select-Object -Unique

    $destinations = New-Object System.Collections.Generic.List[string]
    $destinations.Add("255.255.255.255")

    if (-not [string]::IsNullOrWhiteSpace($TargetIp) -and $TargetIp -match '^(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}$') {
        $subnetBcast = "$($Matches[1]).255"
        $destinations.Add($subnetBcast)
        $destinations.Add($TargetIp)
    }

    foreach ($localIp in $bindIps) {
        if ($localIp -match '^(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}$' -and $localIp -ne "0.0.0.0") {
            $nicBcast = "$($Matches[1]).255"
            if (-not $destinations.Contains($nicBcast)) {
                $destinations.Add($nicBcast)
            }
        }

        $udp = $null
        try {
            $localEndpoint = New-Object System.Net.IPEndPoint([System.Net.IPAddress]::Parse($localIp), 0)
            $udp = New-Object System.Net.Sockets.UdpClient($localEndpoint)
            $udp.EnableBroadcast = $true

            foreach ($destIp in $destinations) {
                foreach ($wolPort in @(9, 7)) {
                    $null = $udp.Send($packet, $packet.Length, $destIp, $wolPort)
                }
            }
            $interfacesUsed.Add($localIp)
        } catch {
            Write-WolLog -Level "WARN" -Message "Gagal mengirim UDP WOL pada interface ${localIp}: $($_.Exception.Message)"
        } finally {
            if ($null -ne $udp) {
                $udp.Close()
            }
        }
    }

    return $interfacesUsed
}

function Send-HttpResponse {
    param(
        [System.IO.Stream]$Stream,
        [int]$StatusCode = 200,
        [string]$StatusText = "OK",
        [hashtable]$BodyObj
    )
    $json = $BodyObj | ConvertTo-Json -Compress
    $bodyBytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $header = "HTTP/1.1 $StatusCode $StatusText`r`nContent-Type: application/json; charset=utf-8`r`nContent-Length: $($bodyBytes.Length)`r`nConnection: close`r`n`r`n"
    $headerBytes = [System.Text.Encoding]::ASCII.GetBytes($header)
    $Stream.Write($headerBytes, 0, $headerBytes.Length)
    $Stream.Write($bodyBytes, 0, $bodyBytes.Length)
    $Stream.Flush()
}

# Prevent duplicate listener if port 18009 is already bound
$existingListener = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if ($null -ne $existingListener) {
    Write-WolLog -Level "INFO" -Message "WOL Helper sudah berjalan pada port TCP $Port. Keluar dengan aman."
    exit 0
}

$listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Any, $Port)
$listener.Start()
Write-WolLog -Level "SUCCESS" -Message "Sugeng Rawuh WOL Helper aktif mendengarkan pada 0.0.0.0:$Port (/wol)..."

try {
    while ($true) {
        $client = $listener.AcceptTcpClient()
        try {
            $client.ReceiveTimeout = 3000
            $client.SendTimeout = 3000
            $remoteEndPoint = [System.Net.IPEndPoint]$client.Client.RemoteEndPoint
            $remoteIp = $remoteEndPoint.Address.ToString()
            $stream = $client.GetStream()

            if (-not (Test-AllowedWolClientIp -RemoteIp $remoteIp)) {
                Write-WolLog -Level "WARN" -Message "Menolak koneksi WOL dari IP tidak diizinkan: $remoteIp"
                Send-HttpResponse -Stream $stream -StatusCode 403 -StatusText "Forbidden" -BodyObj @{ success = $false; error = "Forbidden IP" }
                continue
            }

            # Read HTTP request header & body
            $buffer = New-Object byte[] 8192
            $bytesRead = $stream.Read($buffer, 0, $buffer.Length)
            if ($bytesRead -le 0) {
                continue
            }

            $rawRequest = [System.Text.Encoding]::UTF8.GetString($buffer, 0, $bytesRead)
            $parts = $rawRequest -split "`r`n`r`n", 2
            $headerBlock = $parts[0]
            $bodyText = if ($parts.Length -gt 1) { $parts[1] } else { "" }

            $headerLines = $headerBlock -split "`r`n"
            $requestLine = $headerLines[0]

            if ($requestLine -match '^GET\s+/health') {
                Send-HttpResponse -Stream $stream -StatusCode 200 -StatusText "OK" -BodyObj @{ success = $true; status = "ok" }
                continue
            }

            if ($requestLine -notmatch '^POST\s+/wol') {
                Send-HttpResponse -Stream $stream -StatusCode 404 -StatusText "Not Found" -BodyObj @{ success = $false; error = "Not Found" }
                continue
            }

            $providedToken = ""
            foreach ($hline in $headerLines) {
                if ($hline -match '^X-WOL-Token:\s*(.+)$') {
                    $providedToken = $Matches[1].Trim()
                }
            }

            $envProdPath = Join-Path $InstallDir ".env.production"
            $expectedToken = Get-SREnvValue -EnvFilePath $envProdPath -Key "HARDWARE_WOL_HELPER_TOKEN"

            if (-not [string]::IsNullOrWhiteSpace($expectedToken) -and $expectedToken -ne "CHANGE_ME_WOL_SHARED_SECRET") {
                if (-not (Test-ConstantTimeEquals -Expected $expectedToken -Actual $providedToken)) {
                    Write-WolLog -Level "WARN" -Message "Token X-WOL-Token tidak valid dari $remoteIp"
                    Send-HttpResponse -Stream $stream -StatusCode 401 -StatusText "Unauthorized" -BodyObj @{ success = $false; error = "Invalid X-WOL-Token" }
                    continue
                }
            }

            $payload = $bodyText | ConvertFrom-Json
            $macAddr = [string]$payload.mac_address
            $targetIp = [string]$payload.ip_address

            if ([string]::IsNullOrWhiteSpace($macAddr)) {
                Send-HttpResponse -Stream $stream -StatusCode 400 -StatusText "Bad Request" -BodyObj @{ success = $false; error = "Missing mac_address" }
                continue
            }

            $usedNics = Send-PhysicalLanMagicPacket -MacAddress $macAddr -TargetIp $targetIp
            Write-WolLog -Level "SUCCESS" -Message "Magic Packet WOL terkirim untuk MAC=$macAddr, TargetIP=$targetIp melalui interface: $($usedNics -join ', ')"

            Send-HttpResponse -Stream $stream -StatusCode 200 -StatusText "OK" -BodyObj @{
                success         = $true
                mac             = $macAddr
                ip              = $targetIp
                interfaces_used = $usedNics
            }
        } catch {
            Write-WolLog -Level "ERROR" -Message "Kesalahan pemrosesan request WOL: $($_.Exception.Message)"
        } finally {
            $client.Close()
        }
    }
} finally {
    $listener.Stop()
}

