; =============================================================================
; Sugeng Rawuh Billing & POS - Inno Setup 6 Installer Script
; Target OS: Windows 10/11 64-bit (x86_64 / AMD64)
; Output   : dist/SugengRawuh-v2.0.0/SugengRawuh-Setup-v2.0.0.exe
; Supports : Custom drive selection (C:\SugengRawuh, D:\SugengRawuh, etc.),
;            multi-reboot resume, non-destructive service repair, and
;            Hardware Setup & Test Center shortcuts.
; =============================================================================

#define MyAppName "Sugeng Rawuh Billing & POS"
#define MyAppVersion "2.0.0"
#define MyAppPublisher "Sugeng Rawuh Enterprise"
#define MyAppURL "http://localhost:8008"

[Setup]
AppId={{8F9E2A3C-64D1-4B8E-9A5C-7D1B3C4E5F60}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
DefaultDirName=C:\SugengRawuh
DefaultGroupName={#MyAppName}
DisableDirPage=no
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0.19041
OutputDir=..\dist\SugengRawuh-v2.0.0
OutputBaseFilename=SugengRawuh-Setup-v{#MyAppVersion}
Compression=lzma2/fast
SolidCompression=no
WizardStyle=modern
UninstallDisplayName={#MyAppName}

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Dirs]
Name: "{app}\data"; Permissions: users-modify; Flags: uninsneveruninstall
Name: "{app}\data\logs"; Permissions: users-modify; Flags: uninsneveruninstall
Name: "{app}\data\backups"; Permissions: users-modify; Flags: uninsneveruninstall
Name: "{app}\config"; Permissions: users-modify; Flags: uninsneveruninstall
Name: "{app}\installer\scripts"
Name: "{app}\installer\prereqs"
Name: "{app}\offline-bundle\images"

[Files]
; Core Docker & Environment Template Files (NEVER overwrite .env.production!)
Source: "..\docker-compose.yml"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\.env.production.example"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\docker\*"; DestDir: "{app}\docker"; Flags: ignoreversion recursesubdirs createallsubdirs

; PowerShell Installer, Service Setup/Repair, Launcher, Backup/Restore, Network & WOL Helper Suite
Source: "scripts\*.ps1"; DestDir: "{app}\installer\scripts"; Flags: ignoreversion

; Offline Docker Images & Checksums (packaged by CI/CD or release builder)
Source: "..\offline-bundle\images\*"; DestDir: "{app}\offline-bundle\images"; Flags: ignoreversion recursesubdirs createallsubdirs skipifsourcedoesntexist nocompression
Source: "..\offline-bundle\SHA256SUMS.txt"; DestDir: "{app}\offline-bundle"; Flags: ignoreversion skipifsourcedoesntexist

; Optional Bundled Prerequisites (WSL2 Kernel MSI & Docker Desktop Installer if placed in installer\prereqs\)
Source: "prereqs\*"; DestDir: "{app}\installer\prereqs"; Flags: ignoreversion skipifsourcedoesntexist nocompression

[Icons]
; Desktop Shortcuts for Cashier & Technician
Name: "{commondesktop}\Sugeng Rawuh Billing & POS"; Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\installer\scripts\startup-launcher.ps1"" -InstallDir ""{app}"""; WorkingDir: "{app}"; Comment: "Buka Aplikasi Kasir Sugeng Rawuh Billing & POS"
Name: "{commondesktop}\Sugeng Rawuh - Hardware Setup & Test Center"; Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\installer\scripts\startup-launcher.ps1"" -InstallDir ""{app}"" -TargetPath ""/admin/hardware/setup"""; WorkingDir: "{app}"; Comment: "Buka Hardware Setup & Onboarding Wizard (Samsung TV, Shelly Relay & WOL)"

; Start Menu Shortcuts
Name: "{group}\Sugeng Rawuh Billing & POS"; Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\installer\scripts\startup-launcher.ps1"" -InstallDir ""{app}"""; WorkingDir: "{app}"; Comment: "Buka Aplikasi Kasir Sugeng Rawuh Billing & POS"
Name: "{group}\Sugeng Rawuh - Hardware Setup & Test Center"; Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\installer\scripts\startup-launcher.ps1"" -InstallDir ""{app}"" -TargetPath ""/admin/hardware/setup"""; WorkingDir: "{app}"; Comment: "Buka Hardware Setup & Onboarding Wizard (Samsung TV, Shelly Relay & WOL)"
Name: "{group}\Repair Layanan Sugeng Rawuh"; Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\installer\scripts\setup-services.ps1"" -InstallDir ""{app}"" -Repair -Interactive"; WorkingDir: "{app}"; Comment: "Perbaiki Layanan Docker, Firewall, WOL Helper & Sinkronisasi Billing Sugeng Rawuh"
Name: "{group}\Backup Database Sekarang"; Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\installer\scripts\backup-db.ps1"" -InstallDir ""{app}"" -Reason ""manual-shortcut"""; WorkingDir: "{app}"; Comment: "Buat Backup Database MySQL Sugeng Rawuh"
Name: "{group}\Panduan Jaringan & MikroTik"; Filename: "notepad.exe"; Parameters: """{app}\data\INFORMASI-JARINGAN-MIKROTIK.txt"""; WorkingDir: "{app}"; Comment: "Lihat IP PC Kasir dan Perintah DHCP Statis MikroTik"

[Run]
; Execute Master Installation Orchestrator (Preflight -> Prereqs -> App -> Network/Firewall -> Launcher)
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\installer\scripts\install-orchestrator.ps1"" -InstallDir ""{app}"" -InnoSetupRun"; StatusMsg: "Menyiapkan layanan Sugeng Rawuh Billing & POS (Docker, Database, Firewall & Jaringan)..."; Flags: runhidden waituntilterminated

[UninstallRun]
; Stop containers gracefully on uninstall WITHOUT removing sr_mysql_data volume or .env.production
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -Command ""Set-Location '{app}'; docker compose --env-file .env.production stop"""; Flags: runhidden; RunOnceId: "StopSugengRawuhContainers"
Filename: "schtasks.exe"; Parameters: "/Delete /TN ""SugengRawuh-WOLHelper"" /F"; Flags: runhidden; RunOnceId: "RemoveWolTask"
Filename: "schtasks.exe"; Parameters: "/Delete /TN ""SugengRawuh-DailyBackup"" /F"; Flags: runhidden; RunOnceId: "RemoveBackupTask"
