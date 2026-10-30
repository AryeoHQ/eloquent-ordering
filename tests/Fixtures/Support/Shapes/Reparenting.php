<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Shapes;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Tests\Fixtures\Support\Albums\Album;
use Tests\Fixtures\Support\Boards\Board;
use Tests\Fixtures\Support\Photos\Photo;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Slides\Slide;

final class Reparenting
{
    /**
     * @return iterable<string, array{Closure(): array{Model&OrdersRelations&Revisioned, Model&Ordered, list<Model&Ordered>, array<string, mixed>}}>
     */
    public static function departures(): iterable
    {
        yield 'has many, a save that clears the foreign key' => [static function (): array {
            $board = Board::create();
            $leaving = self::photoIn($board);

            return [$board, $leaving, [self::photoIn($board)], ['board_id' => null]];
        }];

        foreach (self::morphs() as $name => [$shape, $type, $elsewhere]) {
            yield "{$name}, a save that names a type that orders nothing" => [static function () use ($shape, $type, $elsewhere): array {
                [$parent, [$leaving, $staying]] = $shape->with(2);

                return [$parent, $leaving, [$staying], [$type => $elsewhere]];
            }];
        }
    }

    /**
     * @return iterable<string, array{Closure(): array{Model&OrdersRelations&Revisioned, Model&Ordered, list<Model&Ordered>, array<string, mixed>}}>
     */
    public static function arrivals(): iterable
    {
        yield 'has many, a save that names a parent' => [static function (): array {
            $album = Album::create();
            $holder = new Photo;
            $holder->album()->associate($album);
            $holder->save();
            $arriving = self::photoIn(Board::create());
            DB::table('photos')->where('id', $arriving->id)->update(['board_id' => null]);

            return [$album, Photo::query()->findOrFail($arriving->id), [$holder], ['album_id' => $album->id]];
        }];

        foreach (self::morphs() as $name => [$shape, $type, $elsewhere]) {
            yield "{$name}, a save that names the parent type again" => [static function () use ($shape, $type, $elsewhere): array {
                [$parent, [$arriving, $staying]] = $shape->with(2);
                $key = $arriving->getKey();
                assert(is_int($key) || is_string($key));
                DB::table($shape->memberTable())->where('id', $key)->update([$type => $elsewhere]);

                return [$parent, $shape->find($key), [$staying], [$type => $parent->getMorphClass()]];
            }];
        }
    }

    /**
     * @return array<string, array{Shape, string, string}>
     */
    private static function morphs(): array
    {
        return [
            'morph many' => [new MorphManyShape, 'sceneable_type', (new Section)->getMorphClass()],
            'morph to many' => [new MorphToManyShape, 'customerable_type', (new Section)->getMorphClass()],
            UuidMorphManyShape::NAME => [new UuidMorphManyShape, 'slideable_type', Slide::MORPH_ALIAS],
        ];
    }

    private static function photoIn(Board $board): Photo
    {
        $photo = new Photo;
        $photo->board()->associate($board);
        $photo->save();

        return $photo;
    }
}
