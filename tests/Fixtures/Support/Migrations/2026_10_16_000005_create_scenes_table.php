<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tour_id')->constrained()->cascadeOnDelete();
            $table->rankKey();
            $table->timestamps();

            $table->unique(['tour_id', 'rank_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
