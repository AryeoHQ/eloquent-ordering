<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Tests\Fixtures\Support\Sections\SectionRevision;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('section_id')->constrained();
            $table->rankKey();
            $table->string('title')->nullable();
            $table->boolean('visible')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::getConnection()->statement(
            'create unique index assets_section_id_rank_key_unique on assets (section_id, rank_key) where deleted_at is null',
        );

        OrderedRelations::createTrigger('assets', 'sections', 'section_id', SectionRevision::AssetsOrder->value);
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
