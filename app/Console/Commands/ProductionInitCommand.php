<?php

namespace App\Console\Commands;

use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductionInitCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:production-init
                            {--reconcile : Run billing session reconciliation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely initialize production database defaults on first boot without overwriting existing users or transactions, and reconcile active sessions';

    /**
     * Execute the console command.
     */
    public function handle(BillingService $billingService): int
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Database connection failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (!Schema::hasTable('users') || !Schema::hasTable('work_shifts')) {
            $this->error('Required database tables (users, work_shifts) do not exist yet. Run php artisan migrate --force first.');
            return Command::FAILURE;
        }

        $hasAdminOrOwner = User::whereIn('role', ['admin', 'owner'])->exists();

        if (!$hasAdminOrOwner) {
            $this->info('First-time empty installation detected (no Admin/Owner accounts found). Bootstrapping initial baseline data...');

            // 1. Seed default work shifts safely via firstOrCreate
            $shiftPagi = WorkShift::firstOrCreate(
                ['nama_shift' => 'Shift Pagi'],
                [
                    'jam_mulai' => '08:00:00',
                    'jam_selesai' => '16:00:00',
                    'keterangan' => 'Shift Pagi (08:00 - 16:00)',
                ]
            );

            $shiftMalam = WorkShift::firstOrCreate(
                ['nama_shift' => 'Shift Malam'],
                [
                    'jam_mulai' => '16:00:00',
                    'jam_selesai' => '23:59:59',
                    'keterangan' => 'Shift Malam (16:00 - 24:00)',
                ]
            );

            // 2. Seed default PlayStation / Billiard categories only if playstations table is empty
            if (Schema::hasTable('playstations') && Playstation::count() === 0) {
                $defaultCategories = [
                    ['nama' => 'PS3', 'harga' => 5000, 'image' => 'post-images/ps3.jpg'],
                    ['nama' => 'PS4', 'harga' => 8000, 'image' => 'post-images/ps4.jpg'],
                    ['nama' => 'PS5', 'harga' => 15000, 'image' => 'post-images/ps5.jpg'],
                    ['nama' => 'BILLIARD pagi', 'harga' => 25000, 'image' => 'post-images/billiard.jpg'],
                    ['nama' => 'BILLIARD sore', 'harga' => 30000, 'image' => 'post-images/billiard.jpg'],
                ];

                foreach ($defaultCategories as $cat) {
                    Playstation::firstOrCreate(
                        ['nama' => $cat['nama']],
                        [
                            'harga' => $cat['harga'],
                            'image' => $cat['image'],
                        ]
                    );
                }
                $this->line('  -> Default PlayStation & Billiard categories created.');
            }

            // 3. Seed default user accounts via firstOrCreate (never overwrites existing users/passwords)
            $defaultUsers = [
                [
                    'email' => 'admin@gmail.com',
                    'name' => 'Admin',
                    'password' => bcrypt('password'),
                    'role' => 'admin',
                    'work_shift_id' => null,
                ],
                [
                    'email' => 'owner@gmail.com',
                    'name' => 'Owner',
                    'password' => bcrypt('password'),
                    'role' => 'owner',
                    'work_shift_id' => null,
                ],
                [
                    'email' => 'operator1@gmail.com',
                    'name' => 'Operator 1',
                    'password' => bcrypt('password'),
                    'role' => 'kasir',
                    'work_shift_id' => $shiftPagi->id,
                ],
                [
                    'email' => 'operator2@gmail.com',
                    'name' => 'Operator 2',
                    'password' => bcrypt('password'),
                    'role' => 'kasir',
                    'work_shift_id' => $shiftMalam->id,
                ],
            ];

            foreach ($defaultUsers as $u) {
                User::firstOrCreate(
                    ['email' => $u['email']],
                    [
                        'name' => $u['name'],
                        'password' => $u['password'],
                        'role' => $u['role'],
                        'work_shift_id' => $u['work_shift_id'],
                    ]
                );
            }

            $this->info('Initial accounts and shifts bootstrapped. IMPORTANT: Please change default passwords immediately after first login.');
        } else {
            $this->info('Existing Admin/Owner account(s) detected. Skipping initial account/shift seeding to preserve production data.');
        }

        // 4. Reconcile billing sessions if G2 schema is present
        $shouldReconcile = $this->option('reconcile') || Transaction::hasG2Schema();
        if ($shouldReconcile && Transaction::hasG2Schema()) {
            try {
                $result = $billingService->reconcileSessions(Carbon::now());
                $this->info(sprintf(
                    'Billing reconciliation complete: %d expired transitioned, %d postpaid retained, %d devices healed.',
                    $result['expired_processed'] ?? 0,
                    $result['postpaid_survived'] ?? 0,
                    $result['devices_healed'] ?? 0
                ));
            } catch (\Throwable $e) {
                $this->warn('Billing reconciliation warning: ' . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}

