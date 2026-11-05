<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drawers', function (Blueprint $table): void {
            $table->id();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('drawer_id')->constrained();
            $table->rankKey();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->rankKeyIndex('drawer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
        Schema::dropIfExists('drawers');
    }
};
