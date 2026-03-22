<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@verp.local'],
            [
                'user_id' => 'admin',
                'password' => Hash::make('Admin@123456'),
                'pin' => Hash::make('1234'),
                'real_name' => 'System Administrator',
                'phone' => '+1234567890',
                'role_id' => 1,
                'language' => 'en',
                'date_format' => 0,
                'date_sep' => 0,
                'tho_sep' => 0,
                'dec_sep' => 0,
                'theme' => 'light',
                'page_size' => 'A4',
                'prices_dec' => 2,
                'qty_dec' => 2,
                'rates_dec' => 4,
                'percent_dec' => 1,
                'show_gl' => true,
                'show_codes' => false,
                'show_hints' => false,
                'graphic_links' => true,
                'rep_popup' => true,
                'sticky_doc_date' => false,
                'print_profile' => 'default',
                'def_print_destination' => 0,
                'def_print_orientation' => 0,
                'pos' => 1,
                'startup_tab' => 'dashboard',
                'transaction_days' => 30,
                'save_report_selections' => 0,
                'use_date_picker' => true,
                'default_store' => 'default',
                'query_size' => 100,
                'inactive' => false,
            ]
        );
        $admin->assignRole('admin');

        // Manager user
        $manager = User::firstOrCreate(
            ['email' => 'manager@verp.local'],
            [
                'user_id' => 'manager',
                'password' => Hash::make('Manager@123456'),
                'pin' => Hash::make('1234'),
                'real_name' => 'Department Manager',
                'phone' => '+1234567891',
                'role_id' => 2,
                'language' => 'en',
                'date_format' => 0,
                'date_sep' => 0,
                'tho_sep' => 0,
                'dec_sep' => 0,
                'theme' => 'light',
                'page_size' => 'A4',
                'prices_dec' => 2,
                'qty_dec' => 2,
                'rates_dec' => 4,
                'percent_dec' => 1,
                'show_gl' => true,
                'show_codes' => false,
                'show_hints' => false,
                'graphic_links' => true,
                'rep_popup' => true,
                'sticky_doc_date' => false,
                'print_profile' => 'default',
                'def_print_destination' => 0,
                'def_print_orientation' => 0,
                'pos' => 1,
                'startup_tab' => 'sales',
                'transaction_days' => 30,
                'save_report_selections' => 0,
                'use_date_picker' => true,
                'default_store' => 'default',
                'query_size' => 100,
                'inactive' => false,
            ]
        );
        $manager->assignRole('manager');

        // Supervisor user
        $supervisor = User::firstOrCreate(
            ['email' => 'supervisor@verp.local'],
            [
                'user_id' => 'supervisor',
                'password' => Hash::make('Supervisor@123456'),
                'pin' => Hash::make('1234'),
                'real_name' => 'Team Supervisor',
                'phone' => '+1234567892',
                'role_id' => 3,
                'language' => 'en',
                'date_format' => 0,
                'date_sep' => 0,
                'tho_sep' => 0,
                'dec_sep' => 0,
                'theme' => 'light',
                'page_size' => 'A4',
                'prices_dec' => 2,
                'qty_dec' => 2,
                'rates_dec' => 4,
                'percent_dec' => 1,
                'show_gl' => true,
                'show_codes' => false,
                'show_hints' => false,
                'graphic_links' => true,
                'rep_popup' => true,
                'sticky_doc_date' => false,
                'print_profile' => 'default',
                'def_print_destination' => 0,
                'def_print_orientation' => 0,
                'pos' => 1,
                'startup_tab' => 'sales',
                'transaction_days' => 30,
                'save_report_selections' => 0,
                'use_date_picker' => true,
                'default_store' => 'default',
                'query_size' => 50,
                'inactive' => false,
            ]
        );
        $supervisor->assignRole('supervisor');

        // Regular user
        $user = User::firstOrCreate(
            ['email' => 'user@verp.local'],
            [
                'user_id' => 'user',
                'password' => Hash::make('User@123456'),
                'pin' => Hash::make('1234'),
                'real_name' => 'Regular User',
                'phone' => '+1234567893',
                'role_id' => 4,
                'language' => 'en',
                'date_format' => 0,
                'date_sep' => 0,
                'tho_sep' => 0,
                'dec_sep' => 0,
                'theme' => 'light',
                'page_size' => 'A4',
                'prices_dec' => 2,
                'qty_dec' => 2,
                'rates_dec' => 4,
                'percent_dec' => 1,
                'show_gl' => true,
                'show_codes' => false,
                'show_hints' => false,
                'graphic_links' => true,
                'rep_popup' => true,
                'sticky_doc_date' => false,
                'print_profile' => 'default',
                'def_print_destination' => 0,
                'def_print_orientation' => 0,
                'pos' => 1,
                'startup_tab' => 'sales',
                'transaction_days' => 30,
                'save_report_selections' => 0,
                'use_date_picker' => true,
                'default_store' => 'default',
                'query_size' => 50,
                'inactive' => false,
            ]
        );
        $user->assignRole('user');

        // Viewer user
        $viewer = User::firstOrCreate(
            ['email' => 'viewer@verp.local'],
            [
                'user_id' => 'viewer',
                'password' => Hash::make('Viewer@123456'),
                'pin' => Hash::make('1234'),
                'real_name' => 'Read-Only Viewer',
                'phone' => '+1234567894',
                'role_id' => 5,
                'language' => 'en',
                'date_format' => 0,
                'date_sep' => 0,
                'tho_sep' => 0,
                'dec_sep' => 0,
                'theme' => 'light',
                'page_size' => 'A4',
                'prices_dec' => 2,
                'qty_dec' => 2,
                'rates_dec' => 4,
                'percent_dec' => 1,
                'show_gl' => true,
                'show_codes' => false,
                'show_hints' => false,
                'graphic_links' => true,
                'rep_popup' => true,
                'sticky_doc_date' => false,
                'print_profile' => 'default',
                'def_print_destination' => 0,
                'def_print_orientation' => 0,
                'pos' => 1,
                'startup_tab' => 'dashboard',
                'transaction_days' => 30,
                'save_report_selections' => 0,
                'use_date_picker' => true,
                'default_store' => 'default',
                'query_size' => 50,
                'inactive' => false,
            ]
        );
        $viewer->assignRole('viewer');

        // Guest user
        $guest = User::firstOrCreate(
            ['email' => 'guest@verp.local'],
            [
                'user_id' => 'guest',
                'password' => Hash::make('Guest@123456'),
                'pin' => Hash::make('1234'),
                'real_name' => 'Guest User',
                'phone' => '+1234567895',
                'role_id' => 6,
                'language' => 'en',
                'date_format' => 0,
                'date_sep' => 0,
                'tho_sep' => 0,
                'dec_sep' => 0,
                'theme' => 'light',
                'page_size' => 'A4',
                'prices_dec' => 2,
                'qty_dec' => 2,
                'rates_dec' => 4,
                'percent_dec' => 1,
                'show_gl' => true,
                'show_codes' => false,
                'show_hints' => false,
                'graphic_links' => true,
                'rep_popup' => true,
                'sticky_doc_date' => false,
                'print_profile' => 'default',
                'def_print_destination' => 0,
                'def_print_orientation' => 0,
                'pos' => 1,
                'startup_tab' => 'dashboard',
                'transaction_days' => 30,
                'save_report_selections' => 0,
                'use_date_picker' => true,
                'default_store' => 'default',
                'query_size' => 25,
                'inactive' => false,
            ]
        );
        $guest->assignRole('guest');

        $this->command->info('✅ Users created successfully');
        $this->command->info('');
        $this->command->info('📋 Test User Accounts:');
        $this->command->line('  Admin:      admin@verp.local / Admin@123456');
        $this->command->line('  Manager:    manager@verp.local / Manager@123456');
        $this->command->line('  Supervisor: supervisor@verp.local / Supervisor@123456');
        $this->command->line('  User:       user@verp.local / User@123456');
        $this->command->line('  Viewer:     viewer@verp.local / Viewer@123456');
        $this->command->line('  Guest:      guest@verp.local / Guest@123456');
    }
}
