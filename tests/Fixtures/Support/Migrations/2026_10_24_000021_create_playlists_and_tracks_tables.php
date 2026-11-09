<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Support\Playlists\Playlist;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table): void {
            $table->id();
            $table->string('kind')->nullable();
            $table->timestamps();
        });

        Schema::create('tracks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('playlist_id')->constrained();
            $table->string('kind')->nullable();
            $table->integer('disc')->default(Playlist::DISC);
            $table->string('code')->default((string) Playlist::CODE);
            $table->string('mood')->default(Playlist::MOOD);
            $table->boolean('explicit')->default(false);
            $table->string('format')->default(Playlist::FORMATS[0]);
            $table->string('source')->default(Playlist::SOURCE);
            $table->timestamp('released_at')->default(Playlist::RELEASED_BY);
            $table->uuid('label_id')->default(Playlist::LABEL);
            $table->rankKey();
            $table->timestamps();

            $table->unique(['playlist_id', 'rank_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
        Schema::dropIfExists('playlists');
    }
};
