<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Concurrency\Schema\ConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Tests\Fixtures\Support\Albums\AlbumRevision;
use Tests\Fixtures\Support\Boards\BoardRevision;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table): void {
            $table->id();
            $table->concurrencyRevision();
            $table->namedConcurrencyRevision(BoardRevision::PhotosOrder->value);
            $table->timestamps();
        });

        ConcurrencyRevisions::createTrigger('boards');

        Schema::create('albums', function (Blueprint $table): void {
            $table->id();
            $table->concurrencyRevision();
            $table->namedConcurrencyRevision(AlbumRevision::PhotosOrder->value);
            $table->timestamps();
        });

        ConcurrencyRevisions::createTrigger('albums');

        Schema::create('photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('board_id')->nullable()->constrained();
            $table->foreignId('album_id')->nullable()->constrained();
            $table->rankKey();
            $table->timestamps();

            $table->unique(['board_id', 'rank_key']);
            $table->unique(['album_id', 'rank_key']);
        });

        OrderedRelations::createTrigger('photos', 'boards', 'board_id', BoardRevision::PhotosOrder->value);
        OrderedRelations::createTrigger('photos', 'albums', 'album_id', AlbumRevision::PhotosOrder->value);
    }

    public function down(): void
    {
        Schema::dropIfExists('photos');
        Schema::dropIfExists('albums');
        Schema::dropIfExists('boards');
    }
};
