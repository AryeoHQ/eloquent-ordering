<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reels', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('frames', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reel_id')->constrained();
            $table->rankKey();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('modified_at')->nullable();
            $table->rankKeyIndex('reel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frames');
        Schema::dropIfExists('reels');
    }
};
