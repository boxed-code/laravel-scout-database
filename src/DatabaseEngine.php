<?php

namespace BoxedCode\Laravel\Scout;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;

class DatabaseEngine extends Engine
{
    /**
     * Database connection.
     *
     * @var \Illuminate\Database\DatabaseManager
     */
    protected $database;

    /**
     * Spin up a new instance of the engine.
     *
     * @param DatabaseManager $database
     */
    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
    }

    /**
     * Get a base query builder.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function query()
    {
        return $this->database->table('scout_index');
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        $models
            ->map(function ($model) {
                $array = array_values($model->toSearchableArray());

                if (empty($array)) {
                    return;
                }

                return [
                    'objectID' => $model->getKey(),
                    'index' => $model->searchableAs(),
                    'entry' => json_encode($array),
                ];
            })
            ->filter()
            ->each(function($record) {
                $attributes = [
                    'index' => $record['index'],
                    'objectID' => $record['objectID']
                ];
                $this->query()->updateOrInsert($attributes, $record);
            });
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $index = $models->first()->searchableAs();

        $ids = $models->map(function ($model) {
            return $model->getKey();
        })->values()->all();

        $this->query()
            ->where('index', $index)
            ->whereIn('objectID', $ids)
            ->delete();
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $index = $builder->index ?: $builder->model->searchableAs();

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $this->query(),
                $builder->query,
                $options
            );
        }

        $query = $this->query()
            ->select('objectID')
            ->where('index', '=', $index)
            ->where('entry', 'like', '%'.$builder->query.'%');

        foreach ($builder->wheres as $column => $value) {
            $search = sprintf('%%"%s":"%s"%%', $column, $value);

            $query->where('entry', 'like', $search);
        }

        return $query;
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        $results = $this->performSearch($builder)->get();

        return ['results' => $results, 'total' => count($results)];
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $results = $this->performSearch($builder)
            ->forPage($page, $perPage)
            ->get();

        $total = $this->performSearch($builder)->count();

        return ['results' => $results, 'total' => $total];
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if ($results['total'] === 0) {
            return Collection::make();
        }

        $keys = $this->mapIds($results);

        $models = $model->whereIn(
            $model->getQualifiedKeyName(), $keys
        )->get()->keyBy($model->getKeyName());

        return Collection::make($results['results'])
            ->map(function ($record) use ($model, $models) {
                $key = $record->objectID;

                if (isset($models[$key])) {
                    return $models[$key];
                }
            })->filter();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['total'];
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return $results['results']->pluck('objectID')->values();
    }
}
