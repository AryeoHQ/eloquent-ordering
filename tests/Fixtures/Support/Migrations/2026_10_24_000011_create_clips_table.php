<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_id')->constrained();
            $table->rankKey();
            $table->string('title')->nullable();
            $table->timestamps();

            $table->unique(['video_id', 'rank_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clips');
    }
};
