<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('galleries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('slides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidMorphs('slideable');
            $table->rankKey();
            $table->string('title')->nullable();
            $table->boolean('visible')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::getConnection()->statement(
            'create unique index slides_slideable_rank_key_unique on slides (slideable_type, slideable_id, rank_key) where deleted_at is null',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('slides');
        Schema::dropIfExists('galleries');
    }
};
