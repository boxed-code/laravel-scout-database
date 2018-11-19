<?php

namespace Tests;

use BoxedCode\Laravel\Scout\DatabaseEngine;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;
use Mockery;
use Tests\Fixtures\TestModel;

/**
 * @internal
 * @coversNothing
 */
final class DatabaseEngineTest extends AbstractTestCase
{
    public function test_update_adds_objects_to_index()
    {
        $manager = Mockery::mock('Illuminate\Database\DatabaseManager');
        $queryBuilder = Mockery::mock('Illuminate\Database\Query\Builder');
        $modelReturningEmpty = Mockery::mock('Testing\Fixtures\TestModel');
        $modelReturningEmpty->shouldReceive('toSearchableArray')->andReturn([]);
        $modelReturningEmpty->shouldReceive('scoutMetadata')->andReturn([]);
        $manager->shouldReceive('table')->with('scout_index')->andReturn($queryBuilder);
        $queryBuilder->shouldReceive('updateOrInsert')->with([
            'index'    => 'table',
            'objectID' => 1,
        ], [
            'objectID' => 1,
            'index'    => 'table',
            'entry'    => '{"id":1}',
        ]);

        $engine = new DatabaseEngine($manager);
        $engine->update(Collection::make([new TestModel(), $modelReturningEmpty]));
    }

    public function test_delete_removes_objects_to_index()
    {
        $manager = Mockery::mock('Illuminate\Database\DatabaseManager');
        $queryBuilder = Mockery::mock('\Illuminate\Database\Query\Builder');
        $manager->shouldReceive('table')->with('scout_index')->andReturn($queryBuilder);
        $queryBuilder
            ->shouldReceive('where')
            ->with('index', 'table')
            ->andReturn(Mockery::self())
            ->shouldReceive('whereIn')
            ->with('objectID', [1])
            ->andReturn(Mockery::self())
            ->shouldReceive('delete');

        $engine = new DatabaseEngine($manager);
        $engine->delete(Collection::make([new TestModel()]));
    }

    public function test_search_sends_correct_parameters_to_builder_callback()
    {
        $manager = Mockery::mock('Illuminate\Database\DatabaseManager');
        $queryBuilder = Mockery::mock('\Illuminate\Database\Query\Builder');
        $builder = Mockery::mock(new Builder(new TestModel(), 'zonda'));
        $builder->index = 'table';
        $builder->callback = function () use ($queryBuilder) {
            return $queryBuilder;
        };
        $manager->shouldReceive('table')->with('scout_index')->andReturn($queryBuilder);

        $queryBuilder
            ->shouldReceive('get')
            ->andReturn([1, 2, 3]);

        $engine = new DatabaseEngine($manager);
        //$builder = new Builder(new TestModel, 'zonda');
        //$builder->where('foo', 1);
        $result = $engine->search($builder);
        $this->assertSame($result, [
            'results' => [1, 2, 3],
            'total'   => 3,
        ]);
    }

    public function test_search_sends_correct_parameters_to_builder()
    {
        $manager = Mockery::mock('Illuminate\Database\DatabaseManager');
        $queryBuilder = Mockery::mock('\Illuminate\Database\Query\Builder');
        $manager->shouldReceive('table')->with('scout_index')->andReturn($queryBuilder);

        $queryBuilder
            ->shouldReceive('select')
            ->with('objectID')
            ->andReturn($queryBuilder)
            ->shouldReceive('where')
            ->with('index', '=', 'table')
            ->andReturn($queryBuilder)
            ->shouldReceive('where')
            ->with('entry', 'like', '%zonda%')
            ->andReturn($queryBuilder)
            ->shouldReceive('where')
            ->with(
                Mockery::on(
                    function ($closure) use ($queryBuilder) {
                        return true;
                    }
                )
            )
            ->andReturn($queryBuilder)
            ->shouldReceive('where')
            ->with('entry', 'like', '%"foo":1%')
            ->andReturn($queryBuilder)
            ->shouldReceive('where')
            ->with('entry', 'like', '%"bar":"string"%')
            ->andReturn($queryBuilder)
            ->shouldReceive('get')
            ->andReturn([1, 2, 3]);

        $engine = new DatabaseEngine($manager);
        $builder = new Builder(new TestModel(), 'zonda');
        $builder->where('foo', 1);
        $builder->where('bar', 'string');
        $result = $engine->search($builder);
        $this->assertSame($result, [
            'results' => [1, 2, 3],
            'total'   => 3,
        ]);
    }

    public function test_map_correctly_maps_results_to_models()
    {
        $manager = Mockery::mock('Illuminate\Database\DatabaseManager');
        $queryBuilder = Mockery::mock('\Illuminate\Database\Query\Builder');
        $manager->shouldReceive('table')->with('scout_index')->andReturn($queryBuilder);

        $model = Mockery::mock('StdClass');
        $model->shouldReceive('newQuery')->andReturn($model);
        $model->shouldReceive('whereIn', 'table.id', [1])->andReturn($model);
        $model->shouldReceive('getQualifiedKeyName')->andReturn('table.id');
        $model->shouldReceive('get')->andReturn($model);
        $model->shouldReceive('getKeyName')->andReturn('id');
        $model->shouldReceive('keyBy')->with('id')->andReturn(Collection::make([
            1 => new TestModel(),
        ]));

        $builder = Mockery::mock(Builder::class);

        $record = new \StdClass();
        $record->objectID = 1;

        $engine = new DatabaseEngine($manager);
        $results = $engine->map($builder, ['results' => collect([
           $record,
        ]), 'total' => 1], $model);

        $this->assertCount(1, $results);
    }

    public function test_map_correctly_maps_empty()
    {
        $manager = Mockery::mock('Illuminate\Database\DatabaseManager');
        $queryBuilder = Mockery::mock('\Illuminate\Database\Query\Builder');
        $builder = Mockery::mock(Builder::class);
        $model = Mockery::mock('StdClass');

        $engine = new DatabaseEngine($manager);
        $results = $engine->map($builder, ['results' => collect([]), 'total' => 0], $model);

        $this->assertCount(0, $results);
    }

    public function test_paginate_returns_correctly()
    {
        $queryBuilder = Mockery::mock('\Illuminate\Database\Query\Builder');
        $scoutBuilder = Mockery::mock('\Laravel\Scout\Builder');
        $engine = Mockery::mock('BoxedCode\Laravel\Scout\DatabaseEngine')
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $engine->shouldReceive('performSearch')->with($scoutBuilder)->andReturn($queryBuilder)->twice();
        $queryBuilder->shouldReceive('forPage')->with(1, 3)->andReturn($queryBuilder);
        $queryBuilder->shouldReceive('get')->andReturn([1, 2, 3]);
        $queryBuilder->shouldReceive('count')->andReturn(3);

        $this->assertSame([
            'results' => [1, 2, 3],
            'total'   => 3,
        ], $engine->paginate($scoutBuilder, 3, 1));
    }

    public function test_get_total_count_returns_correctly()
    {
        $manager = Mockery::mock('Illuminate\Database\DatabaseManager');
        $engine = new DatabaseEngine($manager);
        $total = $engine->getTotalCount(['results' => collect([
            ['objectID' => 1],
            ['objectID' => 2],
            ['objectID' => 3],
        ]), 'total' => 3]);

        $this->assertSame(3, $total);
    }
}
