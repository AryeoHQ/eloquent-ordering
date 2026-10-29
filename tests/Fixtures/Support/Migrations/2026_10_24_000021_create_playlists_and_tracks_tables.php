<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Concurrency\Schema\ConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Tests\Fixtures\Support\Playlists\PlaylistRevision;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table): void {
            $table->id();
            $table->string('kind')->nullable();
            $table->concurrencyRevision();
            $table->namedConcurrencyRevision(PlaylistRevision::TracksOrder->value);
            $table->timestamps();
        });

        ConcurrencyRevisions::createTrigger('playlists');

        Schema::create('tracks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('playlist_id')->constrained();
            $table->string('kind')->nullable();
            $table->rankKey();
            $table->timestamps();

            $table->unique(['playlist_id', 'rank_key']);
        });

        OrderedRelations::createTrigger('tracks', 'playlists', 'playlist_id', PlaylistRevision::TracksOrder->value);
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
        Schema::dropIfExists('playlists');
    }
};
