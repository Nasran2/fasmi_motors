<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\ActivityLog;
use App\Models\LoginHistory;
use Illuminate\Database\Seeder;

class UserManagementSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('name', 'Fasmir Admin')->first() ?? User::first();
        if (!$admin) return;

        // 1. Seed Login History
        $logins = [
            [
                'user_id' => $admin->id,
                'username_or_email' => $admin->username ?: $admin->email,
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'device' => 'Desktop',
                'browser' => 'Chrome',
                'status' => 'Success',
                'logged_in_at' => now()->subHours(2),
            ],
            [
                'user_id' => null,
                'username_or_email' => 'unknown_staff',
                'ip_address' => '192.168.1.105',
                'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
                'device' => 'Mobile',
                'browser' => 'Safari',
                'status' => 'Failed',
                'failure_reason' => 'Invalid credentials',
                'logged_in_at' => now()->subHours(4),
            ],
            [
                'user_id' => null,
                'username_or_email' => 'admin_guess',
                'ip_address' => '45.120.80.22',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/119.0',
                'device' => 'Desktop',
                'browser' => 'Firefox',
                'status' => 'Locked',
                'failure_reason' => 'Account temporarily locked due to too many failed attempts',
                'logged_in_at' => now()->subHours(6),
            ]
        ];

        foreach ($logins as $login) {
            LoginHistory::create($login);
        }

        // 2. Seed Activity Logs
        $activities = [
            [
                'user_id' => $admin->id,
                'module' => 'User Management',
                'action' => 'Login',
                'description' => 'User logged in successfully.',
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Chrome 120 / macOS',
                'created_at' => now()->subHours(2),
            ],
            [
                'user_id' => $admin->id,
                'module' => 'Sales / Billing',
                'action' => 'Approved',
                'description' => 'Approved invoice INV-20260630-2',
                'old_values' => ['status' => 'pending'],
                'new_values' => ['status' => 'paid'],
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Chrome 120 / macOS',
                'created_at' => now()->subHour(),
            ],
            [
                'user_id' => $admin->id,
                'module' => 'Accounts',
                'action' => 'Daily Closing',
                'description' => 'Completed daily account closing for 2026-06-30.',
                'old_values' => null,
                'new_values' => ['closing_balance' => 45000],
                'ip_address' => '192.168.1.100',
                'user_agent' => 'Chrome 120 / macOS',
                'created_at' => now()->subMinutes(30),
            ]
        ];

        foreach ($activities as $act) {
            ActivityLog::create($act);
        }
    }
}
