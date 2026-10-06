<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('remote_backup_port', 21)
            ->update(['remote_backup_port' => 22]);
    }

    public function down(): void
    {
        // Do not change an existing SSH/SFTP port back automatically.
    }
};
