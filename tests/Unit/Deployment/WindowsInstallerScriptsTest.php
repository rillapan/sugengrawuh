<?php

namespace Tests\Unit\Deployment;

use Tests\TestCase;

class WindowsInstallerScriptsTest extends TestCase
{
    /** @test */
    public function all_required_installer_scripts_inno_setup_and_ci_workflow_exist()
    {
        $requiredFiles = [
            'installer/SugengRawuh.iss',
            'installer/scripts/common.ps1',
            'installer/scripts/preflight-check.ps1',
            'installer/scripts/install-prerequisites.ps1',
            'installer/scripts/install-app.ps1',
            'installer/scripts/configure-network.ps1',
            'installer/scripts/wol-helper.ps1',
            'installer/scripts/startup-launcher.ps1',
            'installer/scripts/backup-db.ps1',
            'installer/scripts/restore-db.ps1',
            'installer/scripts/update-app.ps1',
            'installer/scripts/setup-services.ps1',
            'installer/scripts/install-orchestrator.ps1',
            '.github/workflows/build-windows-installer.yml',
        ];

        foreach ($requiredFiles as $relativePath) {
            $this->assertFileExists(
                base_path($relativePath),
                "Required installer artifact missing: {$relativePath}"
            );
        }
    }

    /** @test */
    public function preflight_check_verifies_windows_build_ram_disk_bios_virtualization_and_detects_install_mode()
    {
        $content = file_get_contents(base_path('installer/scripts/preflight-check.ps1'));

        // Windows build >= 19041 & 64-bit check
        $this->assertStringContainsString('19041', $content);
        $this->assertStringContainsString('Is64BitOperatingSystem', $content);

        // BIOS Virtualization check without claiming automatic BIOS modification
        $this->assertStringContainsString('VirtualizationFirmwareEnabled', $content);
        $this->assertStringContainsString('HypervisorPresent', $content);
        $this->assertStringContainsString('VT-x', $content);
        $this->assertStringContainsString('SVM', $content);
        $this->assertStringContainsString('TIDAK memodifikasi BIOS secara otomatis', $content);

        // Fresh install vs Upgrade/Repair mode detection
        $this->assertStringContainsString('UPGRADE_OR_REPAIR', $content);
        $this->assertStringContainsString('FRESH_INSTALL', $content);
        $this->assertStringContainsString('sr_mysql_data', $content);

        // Exit codes 0, 10, 20, 1
        $this->assertStringContainsString('$exitCode = 10', $content);
        $this->assertStringContainsString('$exitCode = 20', $content);
    }

    /** @test */
    public function prerequisites_script_handles_runonce_reboot_resume_and_honest_docker_installer_check()
    {
        $common = file_get_contents(base_path('installer/scripts/common.ps1'));
        $prereqs = file_get_contents(base_path('installer/scripts/install-prerequisites.ps1'));

        // RunOnce & state file persistence
        $this->assertStringContainsString('HKLM:\Software\Microsoft\Windows\CurrentVersion\RunOnce', $common);
        $this->assertStringContainsString('SugengRawuhResumeInstall', $common);
        $this->assertStringContainsString('install-state.json', $common);

        // WSL2 optional features without installing unnecessary separate Ubuntu distro
        $this->assertStringContainsString('Microsoft-Windows-Subsystem-Linux', $prereqs);
        $this->assertStringContainsString('VirtualMachinePlatform', $prereqs);
        $this->assertStringContainsString('--no-distribution', $prereqs);

        // Honest Docker Desktop binary check (never claims offline Docker install if binary not in prereqs/)
        $this->assertStringContainsString('DockerDesktopInstaller.exe', $prereqs);
        $this->assertStringContainsString('--accept-license', $prereqs);
        $this->assertStringContainsString('--backend=wsl-2', $prereqs);
        $this->assertStringContainsString('WAITING_FOR_DOCKER_INSTALLER', $prereqs);
    }

    /** @test */
    public function install_app_preserves_existing_env_and_enforces_hardware_safety_flags_on_fresh_install()
    {
        $installApp = file_get_contents(base_path('installer/scripts/install-app.ps1'));

        // Preserves existing .env.production on upgrade/repair and triggers pre-update backup
        $this->assertStringContainsString('MODE UPGRADE / REPAIR', $installApp);
        $this->assertStringContainsString('pre-update', $installApp);

        // Fresh install sets random secrets and enforces safety flags false
        $this->assertStringContainsString('New-SRAppKey', $installApp);
        $this->assertStringContainsString('New-SRRandomSecret', $installApp);
        $this->assertStringContainsString('HARDWARE_REAL_EXECUTION_ENABLED=false', $installApp);
        $this->assertStringContainsString('CONSOLE_REAL_EXECUTION_ENABLED=false', $installApp);

        // Never runs destructive artisan commands
        $this->assertStringNotContainsString('migrate:fresh', $installApp);
        $this->assertStringNotContainsString('db:wipe', $installApp);
        $this->assertStringNotContainsString('db:seed', $installApp);

        // Verifies /api/health and registers WOL helper & daily backup
        $this->assertStringContainsString('/api/health', $installApp);
        $this->assertStringContainsString('SugengRawuh-WOLHelper', $installApp);
        $this->assertStringContainsString('SugengRawuh-DailyBackup', $installApp);
    }

    /** @test */
    public function configure_network_generates_mikrotik_dhcp_commands_separates_ips_and_scopes_firewall_to_lan()
    {
        $net = file_get_contents(base_path('installer/scripts/configure-network.ps1'));

        // Filters virtual adapters
        $this->assertStringContainsString('vEthernet|WSL|Docker|Loopback|VMware|VirtualBox', $net);

        // Exact MikroTik RouterOS DHCP lease commands & WinBox instructions
        $this->assertStringContainsString('/ip dhcp-server lease add address=', $net);
        $this->assertStringContainsString('/ip dhcp-server lease make-static', $net);
        $this->assertStringContainsString('PC-KASIR-SUGENG-RAWUH', $net);
        $this->assertStringContainsString('INFORMASI-JARINGAN-MIKROTIK.txt', $net);

        // Explicitly warns technician not to confuse Cashier PC IP with Samsung TV / PS3 / PS4 IP
        $this->assertStringContainsString('BUKAN IP Samsung Smart TV', $net);

        // Firewall rules scoped to Private/Domain & RFC 1918 LAN ranges (never open to public internet)
        $this->assertStringContainsString('LocalSubnet', $net);
        $this->assertStringContainsString('192.168.0.0/16', $net);
        $this->assertStringContainsString('-Profile Private, Domain', $net);
        $this->assertStringContainsString('18009', $net);
    }

    /** @test */
    public function wol_helper_verifies_token_and_dispatches_102_byte_magic_packet_on_physical_nics()
    {
        $wol = file_get_contents(base_path('installer/scripts/wol-helper.ps1'));

        $this->assertStringContainsString('18009', $wol);
        $this->assertStringContainsString('X-WOL-Token', $wol);
        $this->assertStringContainsString('HARDWARE_WOL_HELPER_TOKEN', $wol);
        $this->assertStringContainsString('Test-ConstantTimeEquals', $wol);
        $this->assertStringContainsString('New-Object byte[] 102', $wol);
        $this->assertStringContainsString('0xFF', $wol);
        $this->assertStringContainsString('EnableBroadcast = $true', $wol);
    }

    /** @test */
    public function startup_launcher_and_backup_restore_update_scripts_protect_operational_continuity()
    {
        $launcher = file_get_contents(base_path('installer/scripts/startup-launcher.ps1'));
        $backup = file_get_contents(base_path('installer/scripts/backup-db.ps1'));
        $restore = file_get_contents(base_path('installer/scripts/restore-db.ps1'));
        $update = file_get_contents(base_path('installer/scripts/update-app.ps1'));
        $iss = file_get_contents(base_path('installer/SugengRawuh.iss'));

        // Launcher checks /api/health, shows Windows.Forms UI on recovery (without TopMost obscuring UAC/Docker prompts), and runs billing:reconcile
        $this->assertStringContainsString('/api/health', $launcher);
        $this->assertStringContainsString('System.Windows.Forms', $launcher);
        $this->assertStringContainsString('$form.TopMost = $false', $launcher);
        $this->assertStringContainsString('billing:reconcile', $launcher);

        // Backup uses cmd.exe /c for raw UTF-8 output, --single-transaction, --default-character-set=utf8mb4, and generates .sql.gz + .sha256
        $this->assertStringContainsString('cmd.exe /c', $backup);
        $this->assertStringContainsString('--single-transaction', $backup);
        $this->assertStringContainsString('--default-character-set=utf8mb4', $backup);
        $this->assertStringContainsString('GZipStream', $backup);
        $this->assertStringContainsString('.sha256', $backup);

        // Restore takes pre-restore-safety snapshot and runs migrate --force & billing:reconcile
        $this->assertStringContainsString('pre-restore-safety', $restore);
        $this->assertStringContainsString('migrate', $restore);
        $this->assertStringContainsString('billing:reconcile', $restore);

        // Update takes pre-update backup and never wipes volumes
        $this->assertStringContainsString('pre-update', $update);
        $this->assertStringNotContainsString('down -v', $update);
        $this->assertStringNotContainsString('migrate:fresh', $update);

        // Inno Setup targets SugengRawuh-Setup-v1.0.0 and preserves data directory on uninstall
        $this->assertStringContainsString('OutputBaseFilename=SugengRawuh-Setup-v{#MyAppVersion}', $iss);
        $this->assertStringContainsString('uninsneveruninstall', $iss);
        $this->assertStringContainsString('install-orchestrator.ps1', $iss);
    }

    /** @test */
    public function g1_all_scripts_resolve_install_dir_dynamically_and_persist_installed_location()
    {
        $common = file_get_contents(base_path('installer/scripts/common.ps1'));

        $this->assertStringContainsString('installed-location.json', $common);
        $this->assertStringContainsString('Save-SRInstalledLocation', $common);
        $this->assertStringContainsString('.env.production', $common);
        $this->assertStringContainsString('Lanjutkan Instalasi Sugeng Rawuh.lnk', $common);
        $this->assertStringContainsString('Start-SRDockerDesktop', $common);
        $this->assertStringContainsString('Wait-SRDockerEngine', $common);
        $this->assertStringContainsString('AllowInteractiveRetry', $common);
        $this->assertStringContainsString('RetryCancel', $common);
        $this->assertStringContainsString('Save-SRNetworkInfo', $common);
        $this->assertStringContainsString('Get-SRNetworkInfo', $common);
        $this->assertStringContainsString('network-info.json', $common);

        $scriptsRequiringDynamicInstallDir = [
            'installer/scripts/preflight-check.ps1',
            'installer/scripts/install-prerequisites.ps1',
            'installer/scripts/install-app.ps1',
            'installer/scripts/install-orchestrator.ps1',
            'installer/scripts/configure-network.ps1',
            'installer/scripts/wol-helper.ps1',
            'installer/scripts/startup-launcher.ps1',
            'installer/scripts/backup-db.ps1',
            'installer/scripts/restore-db.ps1',
            'installer/scripts/update-app.ps1',
            'installer/scripts/setup-services.ps1',
        ];

        foreach ($scriptsRequiringDynamicInstallDir as $relativePath) {
            $scriptContent = file_get_contents(base_path($relativePath));
            $this->assertStringContainsString(
                'Get-SRInstallDir -InstallDir $InstallDir',
                $scriptContent,
                "Script {$relativePath} must resolve \$InstallDir dynamically via Get-SRInstallDir"
            );
        }
    }

    /** @test */
    public function g1_preflight_checks_dual_disk_space_and_separates_windows_update_reboot_from_wsl_prereq()
    {
        $preflight = file_get_contents(base_path('installer/scripts/preflight-check.ps1'));

        // Dual-disk capacity check (target drive D: vs system drive C:)
        $this->assertStringContainsString('$appDriveLetter', $preflight);
        $this->assertStringContainsString('$sysDriveLetter', $preflight);
        $this->assertStringContainsString('$appDiskFreeGB', $preflight);
        $this->assertStringContainsString('$sysDiskFreeGB', $preflight);
        $this->assertStringContainsString('Docker Desktop -> Settings -> Resources -> Advanced -> Disk image location', $preflight);
        $this->assertStringContainsString('jangan memindahkan file .vhdx secara manual', $preflight);

        // Separate Windows Update reboot pending from WSL2 prerequisite reboot pending
        $this->assertStringContainsString('$windowsUpdateRebootPending', $preflight);
        $this->assertStringContainsString('$wslRebootPending', $preflight);
        $this->assertStringContainsString('$wslInstalled -and $dockerInstalled', $preflight);
    }

    /** @test */
    public function g1_setup_services_prevents_duplicate_wol_listeners_and_preserves_backup_schedule_and_data_on_repair()
    {
        $setupServices = file_get_contents(base_path('installer/scripts/setup-services.ps1'));

        // Parameters
        $this->assertStringContainsString('[switch]$Repair', $setupServices);
        $this->assertStringContainsString('[switch]$Interactive', $setupServices);
        $this->assertStringContainsString('[switch]$ForceResetBackupSchedule', $setupServices);

        // Single-instance WOL helper on port 18009
        $this->assertStringContainsString('http://127.0.0.1:18009/health', $setupServices);
        $this->assertStringContainsString('MultipleInstances IgnoreNew', $setupServices);
        $this->assertStringContainsString('SugengRawuh-WOLHelper', $setupServices);

        // Preserve existing DailyBackup schedule unless ForceResetBackupSchedule
        $this->assertStringContainsString('Get-ScheduledTask -TaskName "SugengRawuh-DailyBackup"', $setupServices);
        $this->assertStringContainsString('$ForceResetBackupSchedule', $setupServices);

        // Shortcuts creation
        $this->assertStringContainsString('Sugeng Rawuh Billing & POS.lnk', $setupServices);
        $this->assertStringContainsString('Sugeng Rawuh - Hardware Setup & Test Center.lnk', $setupServices);
        $this->assertStringContainsString('/admin/hardware/setup', $setupServices);
        $this->assertStringContainsString('Repair Layanan Sugeng Rawuh.lnk', $setupServices);

        // Repair mode safety: never destructive
        $this->assertStringContainsString('Start-SRDockerDesktop', $setupServices);
        $this->assertStringContainsString('Wait-SRDockerEngine', $setupServices);
        $this->assertStringContainsString('billing:reconcile', $setupServices);
        $this->assertStringNotContainsString('down -v', $setupServices);
        $this->assertStringNotContainsString('migrate:fresh', $setupServices);
        $this->assertStringNotContainsString('db:wipe', $setupServices);
        $this->assertStringNotContainsString('db:seed', $setupServices);
    }

    /** @test */
    public function g1_startup_launcher_and_network_scripts_handle_restart_ip_change_and_hardware_test_center_shortcut()
    {
        $net = file_get_contents(base_path('installer/scripts/configure-network.ps1'));
        $wol = file_get_contents(base_path('installer/scripts/wol-helper.ps1'));
        $launcher = file_get_contents(base_path('installer/scripts/startup-launcher.ps1'));
        $orchestrator = file_get_contents(base_path('installer/scripts/install-orchestrator.ps1'));
        $iss = file_get_contents(base_path('installer/SugengRawuh.iss'));

        // Network script detects DHCP and IP change and saves network-info.json
        $this->assertStringContainsString('Get-NetIPInterface', $net);
        $this->assertStringContainsString('Get-SRNetworkInfo', $net);
        $this->assertStringContainsString('Save-SRNetworkInfo', $net);
        $this->assertStringContainsString('IpChanged', $net);

        // WOL helper exposes GET /network-info
        $this->assertStringContainsString('GET\s+/network-info', $wol);
        $this->assertStringContainsString('Get-SRNetworkInfo', $wol);

        // Startup launcher supports TargetPath, refreshes network info, notes Windows Home session requirement, and offers Repair
        $this->assertStringContainsString('[string]$TargetPath = ""', $launcher);
        $this->assertStringContainsString('setup-services.ps1', $launcher);
        $this->assertStringContainsString('-Repair', $launcher);
        $this->assertStringContainsString('Windows Home', $launcher);

        // Orchestrator & Inno Setup support -Repair and -InnoSetupRun
        $this->assertStringContainsString('[switch]$Repair', $orchestrator);
        $this->assertStringContainsString('[switch]$InnoSetupRun', $orchestrator);
        $this->assertStringContainsString('-InnoSetupRun', $iss);
        $this->assertStringContainsString('Sugeng Rawuh - Hardware Setup & Test Center', $iss);
        $this->assertStringContainsString('Repair Layanan Sugeng Rawuh', $iss);
    }
}
