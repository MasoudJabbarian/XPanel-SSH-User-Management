<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'password')) {
            return;
        }

        // Encrypted values are longer than a normal password; expand before rewriting data.
        DB::statement('ALTER TABLE users MODIFY password TEXT NOT NULL');

        DB::table('users')
            ->select(['id', 'password'])
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    try {
                        Crypt::decryptString($user->password);
                        continue;
                    } catch (\Throwable) {
                        // Existing installations contain plaintext passwords.
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['password' => Crypt::encryptString($user->password)]);
                }
            });
    }

    public function down(): void
    {
        throw new \RuntimeException('Refusing to downgrade encrypted user passwords to plaintext.');
    }
};
