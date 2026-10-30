<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Concurrency\Schema\ConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Tests\Fixtures\Support\Agencies\AgencyRevision;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table): void {
            $table->id();
            $table->concurrencyRevision();
            $table->namedConcurrencyRevision(AgencyRevision::CustomersOrder->value);
            $table->timestamps();
        });

        ConcurrencyRevisions::createTrigger('agencies');

        Schema::create('agency_customer', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('agency_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->rankKey();
            $table->softDeletes();
        });

        Schema::getConnection()->statement(
            'create unique index agency_customer_agency_id_customer_id_unique on agency_customer (agency_id, customer_id) where deleted_at is null',
        );

        Schema::getConnection()->statement(
            'create unique index agency_customer_agency_id_rank_key_unique on agency_customer (agency_id, rank_key) where deleted_at is null',
        );

        OrderedRelations::createTrigger('agency_customer', 'agencies', 'agency_id', AgencyRevision::CustomersOrder->value, relatedKey: 'customer_id');
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_customer');
        Schema::dropIfExists('agencies');
    }
};
