<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customerables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained();
            $table->morphs('customerable');
            $table->rankKey();
            $table->string('title')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'customerable_type']);
        });

        Schema::getConnection()->statement(
            'create unique index customerables_customerable_customer_id_unique on customerables (customerable_type, customerable_id, customer_id) where deleted_at is null',
        );

        Schema::getConnection()->statement(
            'create unique index customerables_customerable_rank_key_unique on customerables (customerable_type, customerable_id, rank_key) where deleted_at is null',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('customerables');
    }
};
