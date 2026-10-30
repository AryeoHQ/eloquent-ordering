<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Concurrency\Schema\ConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Tests\Fixtures\Support\Conferences\ConferenceRevision;
use Tests\Fixtures\Support\Panels\PanelRevision;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panels', function (Blueprint $table): void {
            $table->id();
            $table->concurrencyRevision();
            $table->namedConcurrencyRevision(PanelRevision::SpeakersOrder->value);
            $table->timestamps();
        });

        ConcurrencyRevisions::createTrigger('panels');

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

        OrderedRelations::createTrigger('speakers', 'panels', 'panel_id', PanelRevision::SpeakersOrder->value);

        Schema::create('conferences', function (Blueprint $table): void {
            $table->id();
            $table->concurrencyRevision();
            $table->namedConcurrencyRevision(ConferenceRevision::SpeakersOrder->value);
            $table->timestamps();
        });

        ConcurrencyRevisions::createTrigger('conferences');

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

        OrderedRelations::createTrigger('conference_speaker', 'conferences', 'conference_id', ConferenceRevision::SpeakersOrder->value, relatedKey: 'speaker_id');
        OrderedRelations::createRelatedTrigger('speakers', 'conferences', 'conference_speaker', 'speaker_id', 'conference_id', ConferenceRevision::SpeakersOrder->value);
    }

    public function down(): void
    {
        OrderedRelations::dropTrigger('speakers', ConferenceRevision::SpeakersOrder->value);
        Schema::dropIfExists('conference_speaker');
        Schema::dropIfExists('conferences');
        Schema::dropIfExists('speakers');
        Schema::dropIfExists('panels');
    }
};
