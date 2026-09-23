<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->after('name');
        });

        // Existing accounts get a username from their email's local part (admin@orlena.test → admin), kept unique.
        $taken = [];
        foreach (DB::table('users')->orderBy('id')->get(['id', 'email', 'name']) as $user) {
            $base = Str::of(Str::before((string) $user->email, '@') ?: $user->name)->lower()->ascii()->replaceMatches('/[^a-z0-9._-]+/', '')->limit(26, '')->value();
            $base = strlen($base) >= 3 ? $base : 'user'.$user->id;
            $username = $base;
            for ($n = 2; in_array($username, $taken, true); $n++) {
                $username = $base.$n;
            }
            $taken[] = $username;
            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable(false)->unique()->change();
            // Login uses the username; email is optional contact information.
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
