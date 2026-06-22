<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies existing legacy `admin` accounts into `employees` (role=admin) so the
 * panel — now authenticating employees — keeps working for current admins. The
 * bcrypt hash is portable, so passwords carry over unchanged. Idempotent:
 * matched by email, never duplicates, never touches the `admin` table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin') || ! Schema::hasTable('employees')) {
            return;
        }

        foreach (DB::table('admin')->get() as $admin) {
            $exists = DB::table('employees')->where('email', $admin->admin_email)->exists();
            if ($exists) {
                continue;
            }

            DB::table('employees')->insert([
                'name' => $admin->admin_name,
                'email' => $admin->admin_email,
                'password' => $admin->admin_pwd,
                'role' => 'admin',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive: leave migrated employees in place.
    }
};
