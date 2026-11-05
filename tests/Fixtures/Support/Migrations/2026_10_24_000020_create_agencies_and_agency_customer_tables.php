<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

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
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_customer');
        Schema::dropIfExists('agencies');
    }
};
