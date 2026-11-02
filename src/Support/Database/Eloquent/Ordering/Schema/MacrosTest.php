<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Schema;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{Closure(Blueprint): void, list<string>, string}>
     */
    public static function rankKeyIndexes(): iterable
    {
        yield 'a table that soft deletes' => [function (Blueprint $table): void {
            $table->foreignId('section_id');
            $table->rankKeyIndex('section_id'); // @phpstan-ignore staticMethod.dynamicCall
            $table->softDeletes();
        }, ['section_id', 'rank_key'], ' WHERE (deleted_at IS NULL)'];

        yield 'a table that does not soft delete' => [function (Blueprint $table): void {
            $table->foreignId('section_id');
            $table->rankKeyIndex('section_id'); // @phpstan-ignore staticMethod.dynamicCall
        }, ['section_id', 'rank_key'], ''];

        yield 'a morph parent' => [function (Blueprint $table): void {
            $table->morphs('sceneable');
            $table->softDeletes();
            $table->rankKeyIndex('sceneable_type', 'sceneable_id'); // @phpstan-ignore staticMethod.dynamicCall
        }, ['sceneable_type', 'sceneable_id', 'rank_key'], ' WHERE (deleted_at IS NULL)'];
    }

    /**
     * @param  Closure(Blueprint): void  $columns
     * @param  list<string>  $indexed
     */
    #[DataProvider('rankKeyIndexes')]
    #[Test]
    public function it_registers_a_rank_key_index_macro_for_a_unique_rank_key_per_parent_among_live_rows(Closure $columns, array $indexed, string $where): void
    {
        Schema::create('ranked', function (Blueprint $table) use ($columns): void {
            $table->id();
            $table->rankKey();
            $columns($table);
        });

        $name = 'ranked_'.implode('_', $indexed).'_unique';

        $this->assertSame(
            sprintf('CREATE UNIQUE INDEX %s ON public.ranked USING btree (%s)%s', $name, implode(', ', $indexed), $where),
            DB::scalar('select indexdef from pg_indexes where tablename = ? and indexname = ?', ['ranked', $name]),
        );
    }

    #[Test]
    public function it_drops_a_rank_key_index_through_drop_index_and_not_through_drop_unique(): void
    {
        Schema::create('ranked', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('section_id');
            $table->rankKey();
            $table->rankKeyIndex('section_id'); // @phpstan-ignore staticMethod.dynamicCall
        });

        try {
            DB::transaction(static function (): void {
                Schema::table('ranked', static function (Blueprint $table): void {
                    $table->dropUnique(['section_id', 'rank_key']);
                });
            });
            $this->fail('dropUnique() dropped an index that is no constraint.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('constraint "ranked_section_id_rank_key_unique" of relation "ranked" does not exist', $exception->getMessage());
        }

        Schema::table('ranked', static function (Blueprint $table): void {
            $table->dropIndex('ranked_section_id_rank_key_unique');
        });

        $this->assertSame([], DB::select('select indexname from pg_indexes where tablename = ? and indexname = ?', ['ranked', 'ranked_section_id_rank_key_unique']));
    }

    #[Test]
    public function it_adds_a_rank_key_index_to_an_existing_table_that_soft_deletes(): void
    {
        Schema::create('ranked', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('section_id');
            $table->rankKey();
            $table->softDeletes();
        });

        Schema::table('ranked', function (Blueprint $table): void {
            $table->rankKeyIndex('section_id'); // @phpstan-ignore staticMethod.dynamicCall
        });

        $this->assertSame(
            'CREATE UNIQUE INDEX ranked_section_id_rank_key_unique ON public.ranked USING btree (section_id, rank_key) WHERE (deleted_at IS NULL)',
            DB::scalar('select indexdef from pg_indexes where tablename = ? and indexname = ?', ['ranked', 'ranked_section_id_rank_key_unique']),
        );
    }
}
