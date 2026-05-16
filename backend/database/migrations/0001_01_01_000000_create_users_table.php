<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // citext columns - added via raw statements after table creation.
            $table->string('username')->nullable(false);
            $table->string('email')->nullable(false);
            $table->string('password', 255)->nullable();
            $table->string('google_id', 64)->nullable();
            $table->boolean('is_admin')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // Convert username + email to citext.
        DB::statement('ALTER TABLE users ALTER COLUMN username TYPE citext');
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
            $table->unique('email');
            $table->unique('google_id');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
        // We deliberately do NOT drop the citext extension - other schemas may rely on it.
    }
};
