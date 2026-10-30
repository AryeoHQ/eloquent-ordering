<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Concurrency\Schema\ConcurrencyRevisions;
use Tests\Fixtures\Support\Sections\SectionRevision;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->concurrencyRevision();
            $table->namedConcurrencyRevision(SectionRevision::AssetsOrder->value);
            $table->namedConcurrencyRevision(SectionRevision::VideosOrder->value);
            $table->timestamps();
        });

        ConcurrencyRevisions::createTrigger('sections');
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
