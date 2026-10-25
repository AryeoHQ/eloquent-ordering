<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Concurrency\Schema\ConcurrencyRevisions;
use Tests\Fixtures\Support\Tours\TourRevision;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tours', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->concurrencyRevision();
            $table->namedConcurrencyRevision(TourRevision::ScenesOrder->value);
            $table->namedConcurrencyRevision(TourRevision::CustomersOrder->value);
            $table->timestamps();
        });

        ConcurrencyRevisions::createTrigger('tours');
    }

    public function down(): void
    {
        Schema::dropIfExists('tours');
    }
};
