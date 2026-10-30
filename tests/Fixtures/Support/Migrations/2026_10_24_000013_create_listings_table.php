<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Concurrency\Schema\ConcurrencyRevisions;
use Tests\Fixtures\Support\Listings\ListingRevision;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->concurrencyRevision();
            $table->namedConcurrencyRevision(ListingRevision::CustomersOrder->value);
            $table->namedConcurrencyRevision(ListingRevision::ScenesOrder->value);
            $table->timestamps();
        });

        ConcurrencyRevisions::createTrigger('listings');
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
