<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nickname', 40);
            $table->string('breed', 40);
            $table->char('color_hex', 7);
            $table->smallInteger('size');
            $table->string('source', 20)->default('manual');
            $table->string('source_ref', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'deleted_at']);
            $table->index(['user_id', 'breed']);
            $table->index(['user_id', 'created_at']);
        });

        // DB-level safety nets (FormRequest is the primary validator).
        DB::statement("ALTER TABLE fishes ADD CONSTRAINT fishes_color_hex_chk CHECK (color_hex ~ '^#[0-9A-Fa-f]{6}$')");
        DB::statement('ALTER TABLE fishes ADD CONSTRAINT fishes_size_chk CHECK (size BETWEEN 1 AND 100)');
        DB::statement("ALTER TABLE fishes ADD CONSTRAINT fishes_source_chk CHECK (source IN ('manual','github_repo'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('fishes');
    }
};
