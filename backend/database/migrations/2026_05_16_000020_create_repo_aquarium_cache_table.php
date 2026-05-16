<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repo_aquarium_cache', function (Blueprint $table) {
            $table->id();
            $table->string('owner', 100);
            $table->string('repo', 100);
            $table->jsonb('stats_json');
            $table->jsonb('fish_set_json');
            $table->timestamp('fetched_at');

            $table->unique(['owner', 'repo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repo_aquarium_cache');
    }
};
