<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Concurrency\Schema\ConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Videos\VideoRevision;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('section_id')->constrained();
            $table->rankKey();
            $table->string('title')->nullable();
            $table->concurrencyRevision();
            $table->namedConcurrencyRevision(VideoRevision::ClipsOrder->value);
            $table->timestamps();

            $table->unique(['section_id', 'rank_key']);
        });

        ConcurrencyRevisions::createTrigger('videos');
        OrderedRelations::createTrigger('videos', 'sections', 'section_id', SectionRevision::VideosOrder->value);
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
