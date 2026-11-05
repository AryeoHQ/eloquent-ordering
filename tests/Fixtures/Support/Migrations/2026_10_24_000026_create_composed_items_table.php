<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('composed_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->rankKey();
            $table->boolean('hidden')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->rankKeyIndex('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composed_items');
    }
};
