<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Tests\Fixtures\Support\Listings\ListingRevision;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_customer', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('listing_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->rankKey();
            $table->string('title')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::getConnection()->statement(
            'create unique index listing_customer_listing_id_customer_id_unique on listing_customer (listing_id, customer_id) where deleted_at is null',
        );

        Schema::getConnection()->statement(
            'create unique index listing_customer_listing_id_rank_key_unique on listing_customer (listing_id, rank_key) where deleted_at is null',
        );

        OrderedRelations::createTrigger('listing_customer', 'listings', 'listing_id', ListingRevision::CustomersOrder->value);
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_customer');
    }
};
