<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('remote_backup_host')->nullable();
            $table->string('remote_backup_folder')->nullable();
            $table->string('remote_backup_username')->nullable();
            $table->text('remote_backup_password')->nullable();
            $table->unsignedSmallInteger('remote_backup_port')->default(21);
            $table->boolean('remote_backup_ssl')->default(false);
            $table->boolean('remote_backup_enabled')->default(false);
            $table->unsignedInteger('remote_backup_interval_hours')->default(24);
            $table->timestamp('remote_backup_last_at')->nullable();
            $table->string('remote_backup_last_status', 32)->nullable();
            $table->text('remote_backup_last_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'remote_backup_host',
                'remote_backup_folder',
                'remote_backup_username',
                'remote_backup_password',
                'remote_backup_port',
                'remote_backup_ssl',
                'remote_backup_enabled',
                'remote_backup_interval_hours',
                'remote_backup_last_at',
                'remote_backup_last_status',
                'remote_backup_last_message',
            ]);
        });
    }
};
