<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backgrounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('storage_key', 255);
            $table->integer('width');
            $table->integer('height');
            $table->text('prompt')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'deleted_at']);
        });

        DB::statement("ALTER TABLE backgrounds ADD CONSTRAINT backgrounds_kind_chk CHECK (kind IN ('upload','generated','preset'))");
        DB::statement('CREATE UNIQUE INDEX one_active_bg_per_user ON backgrounds(user_id) WHERE is_active = true AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('backgrounds');
    }
};
