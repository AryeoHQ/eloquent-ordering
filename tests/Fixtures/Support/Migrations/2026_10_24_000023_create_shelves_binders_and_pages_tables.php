<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Concurrency\Schema\ConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Tests\Fixtures\Support\Binders\BinderRevision;
use Tests\Fixtures\Support\Shelves\ShelfRevision;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shelves', function (Blueprint $table): void {
            $table->id();
            $table->concurrencyRevision();
            $table->namedConcurrencyRevision(ShelfRevision::BindersOrder->value);
            $table->timestamps();
        });

        ConcurrencyRevisions::createTrigger('shelves');

        Schema::create('binders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shelf_id')->constrained();
            $table->string('kind')->nullable();
            $table->rankKey();
            $table->concurrencyRevision();
            $table->namedConcurrencyRevision(BinderRevision::PagesOrder->value);
            $table->timestamps();

            $table->unique(['shelf_id', 'rank_key']);
        });

        ConcurrencyRevisions::createTrigger('binders', ignore: [OrderedRelation::RANK_KEY]);
        OrderedRelations::createTrigger('binders', 'shelves', 'shelf_id', ShelfRevision::BindersOrder->value);

        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('binder_id')->constrained();
            $table->string('kind')->nullable();
            $table->rankKey();
            $table->timestamps();

            $table->unique(['binder_id', 'rank_key']);
        });

        OrderedRelations::createTrigger('pages', 'binders', 'binder_id', BinderRevision::PagesOrder->value, membership: ['kind']);
        OrderedRelations::createParentTrigger('binders', BinderRevision::PagesOrder->value, membership: ['kind']);
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
        Schema::dropIfExists('binders');
        Schema::dropIfExists('shelves');
    }
};
