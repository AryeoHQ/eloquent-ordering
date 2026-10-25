<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Tests\Fixtures\Support\Videos\VideoRevision;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_id')->constrained();
            $table->rankKey();
            $table->string('title')->nullable();
            $table->timestamps();

            $table->unique(['video_id', 'rank_key']);
        });

        OrderedRelations::createTrigger('clips', 'videos', 'video_id', VideoRevision::ClipsOrder->value);
    }

    public function down(): void
    {
        Schema::dropIfExists('clips');
    }
};
