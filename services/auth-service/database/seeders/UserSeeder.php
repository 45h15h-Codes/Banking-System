<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds test users for all three roles: admin, bank_officer, customer.
 *
 * Run with: php artisan db:seed --class=UserSeeder
 *
 * Default password for all test users: Password@123
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $defaultPassword = 'Password@123';

        // ─── Admin user ───────────────────────────────────────
        User::updateOrCreate(
            ['email' => 'admin@bankcore.test'],
            [
                'uuid' => Str::uuid()->toString(),
                'name' => 'BankCore Admin',
                'email' => 'admin@bankcore.test',
                'phone' => '+91-9000000001',
                'password' => Hash::make($defaultPassword),
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // ─── Bank Officer user ────────────────────────────────
        User::updateOrCreate(
            ['email' => 'officer@bankcore.test'],
            [
                'uuid' => Str::uuid()->toString(),
                'name' => 'Rajesh Kumar (Officer)',
                'email' => 'officer@bankcore.test',
                'phone' => '+91-9000000002',
                'password' => Hash::make($defaultPassword),
                'role' => 'bank_officer',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // ─── Customer user ───────────────────────────────────
        User::updateOrCreate(
            ['email' => 'customer@bankcore.test'],
            [
                'uuid' => Str::uuid()->toString(),
                'name' => 'Priya Sharma',
                'email' => 'customer@bankcore.test',
                'phone' => '+91-9000000003',
                'password' => Hash::make($defaultPassword),
                'role' => 'customer',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // ─── Deactivated customer (for testing) ──────────────
        User::updateOrCreate(
            ['email' => 'blocked@bankcore.test'],
            [
                'uuid' => Str::uuid()->toString(),
                'name' => 'Blocked User',
                'email' => 'blocked@bankcore.test',
                'phone' => '+91-9000000004',
                'password' => Hash::make($defaultPassword),
                'role' => 'customer',
                'is_active' => false,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('✅ Test users seeded successfully!');
        $this->command->table(
            ['Role', 'Email', 'Password', 'Active'],
            [
                ['admin', 'admin@bankcore.test', $defaultPassword, '✅'],
                ['bank_officer', 'officer@bankcore.test', $defaultPassword, '✅'],
                ['customer', 'customer@bankcore.test', $defaultPassword, '✅'],
                ['customer', 'blocked@bankcore.test', $defaultPassword, '❌'],
            ]
        );
    }
}
