<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panels', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('speakers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('panel_id')->constrained();
            $table->string('name')->nullable();
            $table->rankKey();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::getConnection()->statement(
            'create unique index speakers_panel_id_rank_key_unique on speakers (panel_id, rank_key) where deleted_at is null',
        );

        Schema::create('conferences', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('conference_speaker', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('conference_id')->constrained();
            $table->foreignId('speaker_id')->constrained();
            $table->rankKey();
            $table->softDeletes();
        });

        Schema::getConnection()->statement(
            'create unique index conference_speaker_conference_id_speaker_id_unique on conference_speaker (conference_id, speaker_id) where deleted_at is null',
        );

        Schema::getConnection()->statement(
            'create unique index conference_speaker_conference_id_rank_key_unique on conference_speaker (conference_id, rank_key) where deleted_at is null',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('conference_speaker');
        Schema::dropIfExists('conferences');
        Schema::dropIfExists('speakers');
        Schema::dropIfExists('panels');
    }
};
