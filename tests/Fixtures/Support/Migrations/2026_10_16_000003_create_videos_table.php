<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->rankKey();
            $table->timestamps();

            $table->unique(['section_id', 'rank_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
