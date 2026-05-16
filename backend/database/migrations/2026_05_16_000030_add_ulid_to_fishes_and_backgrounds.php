<?php

use App\Models\Background;
use App\Models\Fish;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fishes', function (Blueprint $t) {
            $t->char('ulid', 26)->nullable()->after('id');
        });
        Schema::table('backgrounds', function (Blueprint $t) {
            $t->char('ulid', 26)->nullable()->after('id');
        });

        DB::transaction(function () {
            Fish::withTrashed()->whereNull('ulid')->cursor()->each(function ($f) {
                $f->forceFill(['ulid' => (string) Str::ulid()])->save();
            });
            Background::withTrashed()->whereNull('ulid')->cursor()->each(function ($b) {
                $b->forceFill(['ulid' => (string) Str::ulid()])->save();
            });
        });

        Schema::table('fishes', function (Blueprint $t) {
            $t->char('ulid', 26)->nullable(false)->change();
            $t->unique('ulid');
        });
        Schema::table('backgrounds', function (Blueprint $t) {
            $t->char('ulid', 26)->nullable(false)->change();
            $t->unique('ulid');
        });
    }

    public function down(): void
    {
        Schema::table('fishes', function (Blueprint $t) {
            $t->dropUnique(['ulid']);
            $t->dropColumn('ulid');
        });
        Schema::table('backgrounds', function (Blueprint $t) {
            $t->dropUnique(['ulid']);
            $t->dropColumn('ulid');
        });
    }
};
