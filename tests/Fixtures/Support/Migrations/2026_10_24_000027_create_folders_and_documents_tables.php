<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('kind')->nullable();
            $table->timestampsTz();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('folder_id')->constrained();
            $table->unsignedBigInteger('home_folder_id')->nullable();
            $table->string('kind')->nullable();
            $table->string('locale')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->rankKey();
            $table->timestampsTz();

            $table->unique(['folder_id', 'rank_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('folders');
    }
};
