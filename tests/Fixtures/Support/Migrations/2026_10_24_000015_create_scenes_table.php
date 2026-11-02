<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->morphs('sceneable');
            $table->rankKey();
            $table->string('title')->nullable();
            $table->boolean('visible')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::getConnection()->statement(
            'create unique index scenes_sceneable_rank_key_unique on scenes (sceneable_type, sceneable_id, rank_key) where deleted_at is null',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
