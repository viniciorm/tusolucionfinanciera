<?php

namespace Database\Seeders;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $adminEmail = config('services.crm.initial_admin_email') ?: env('INITIAL_ADMIN_EMAIL');
        $adminName = config('services.crm.initial_admin_name') ?: env('INITIAL_ADMIN_NAME', 'Administrador TSF');

        if ($adminEmail) {
            $normalizedEmail = mb_strtolower(trim($adminEmail), 'UTF-8');
            $user = User::firstOrCreate(
                ['email' => $normalizedEmail],
                [
                    'name' => $adminName,
                    'role' => 'admin',
                    'active' => true,
                    'google_subject' => null,
                ]
            );

            AuditEvent::log('system.seed_initial_admin', null, null, [
                'user_id' => $user->id,
                'role' => $user->role,
                'active' => $user->active,
            ]);
        }
    }
}
