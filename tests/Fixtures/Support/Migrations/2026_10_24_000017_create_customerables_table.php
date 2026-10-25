<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Tours\TourRevision;

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
        });

        Schema::getConnection()->statement(
            'create unique index customerables_customerable_customer_id_unique on customerables (customerable_type, customerable_id, customer_id) where deleted_at is null',
        );

        Schema::getConnection()->statement(
            'create unique index customerables_customerable_rank_key_unique on customerables (customerable_type, customerable_id, rank_key) where deleted_at is null',
        );

        OrderedRelations::createMorphTrigger('customerables', 'tours', 'customerable', (new Tour)->getMorphClass(), TourRevision::CustomersOrder->value);
    }

    public function down(): void
    {
        Schema::dropIfExists('customerables');
    }
};
