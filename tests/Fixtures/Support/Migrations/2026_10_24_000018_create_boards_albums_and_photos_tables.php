<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('albums', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('board_id')->nullable()->constrained();
            $table->foreignId('album_id')->nullable()->constrained();
            $table->rankKey();
            $table->timestamps();

            $table->unique(['board_id', 'rank_key']);
            $table->unique(['album_id', 'rank_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photos');
        Schema::dropIfExists('albums');
        Schema::dropIfExists('boards');
    }
};
