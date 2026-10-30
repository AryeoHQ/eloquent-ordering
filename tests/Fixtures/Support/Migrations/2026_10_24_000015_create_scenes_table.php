<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingRevision;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Tours\TourRevision;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->morphs('sceneable');
            $table->rankKey();
            $table->string('title')->nullable();
            $table->boolean('visible')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::getConnection()->statement(
            'create unique index scenes_sceneable_rank_key_unique on scenes (sceneable_type, sceneable_id, rank_key) where deleted_at is null',
        );

        OrderedRelations::createMorphTrigger('scenes', 'tours', 'sceneable', (new Tour)->getMorphClass(), TourRevision::ScenesOrder->value);
        OrderedRelations::createMorphTrigger('scenes', 'listings', 'sceneable', (new Listing)->getMorphClass(), ListingRevision::ScenesOrder->value);
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
