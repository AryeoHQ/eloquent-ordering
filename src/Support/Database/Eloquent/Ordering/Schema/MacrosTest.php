<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(Macros::class)]
class MacrosTest extends TestCase
{
    #[Test]
    public function it_registers_a_rank_key_blueprint_macro_for_a_nullable_byte_collated_column(): void
    {
        Schema::create('ranked', function (Blueprint $table): void {
            $table->id();
            $table->rankKey();
        });

        $column = collect(Schema::getColumns('ranked'))->firstWhere('name', 'rank_key');

        $this->assertIsArray($column);
        $this->assertSame('character varying(64)', $column['type']);
        $this->assertSame('C', $column['collation']);
        $this->assertTrue($column['nullable']);
    }
}
