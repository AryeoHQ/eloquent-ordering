<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shelves', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('binders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shelf_id')->constrained();
            $table->string('kind')->nullable();
            $table->rankKey();
            $table->timestamps();

            $table->unique(['shelf_id', 'rank_key']);
        });

        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('binder_id')->constrained();
            $table->string('kind')->nullable();
            $table->rankKey();
            $table->timestamps();

            $table->unique(['binder_id', 'rank_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
        Schema::dropIfExists('binders');
        Schema::dropIfExists('shelves');
    }
};
