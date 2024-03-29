<?php

namespace BoxedCode\Laravel\Scout;

use Exception;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
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
     * Update the given model in the index.
     *
     * @param \Illuminate\Database\Eloquent\Collection $models
     */
    public function update($models)
    {
        if ($this->usesSoftDelete($models->first()) && config('scout.soft_delete', false)) {
            $models->each->pushSoftDeleteMetadata();
        }

        $models
            ->map(function ($model) {
                $array = array_merge(
                    $model->toSearchableArray(), $model->scoutMetadata()
                );

                if (empty($array)) {
                    return;
                }

                return [
                    'objectID' => $model->getKey(),
                    'index'    => $model->searchableAs(),
                    'entry'    => json_encode($array),
                ];
            })
            ->filter()
            ->each(function ($record) {
                $attributes = [
                    'index'    => $record['index'],
                    'objectID' => $record['objectID'],
                ];
                $this->query()->updateOrInsert($attributes, $record);
            });
    }

    /**
     * Remove the given model from the index.
     *
     * @param \Illuminate\Database\Eloquent\Collection $models
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
     * @param \Laravel\Scout\Builder $builder
     *
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
     * @param \Laravel\Scout\Builder $builder
     * @param int                    $perPage
     * @param int                    $page
     *
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
     * @param \Laravel\Scout\Builder              $builder
     * @param mixed                               $results
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (0 === $results['total']) {
            return Collection::make();
        }

        $keys = $this->mapIds($results);

        $query = $model->whereIn(
            $model->getQualifiedKeyName(),
            $keys
        );

        if ($this->usesSoftDelete($model)) {
            $query = $query->withTrashed();
        }

        $models = $query->get()->keyBy($model->getKeyName());

        return Collection::make($results['results'])
            ->map(function ($record) use ($model, $models) {
                $key = $record->objectID;

                if (isset($models[$key])) {
                    return $models[$key];
                }
            })->filter();
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        if (intval($results['total']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = $this->mapIds($results);

        $objectIdPositions = $objectIds->flip();

        return $model->queryScoutModelsByIds(
                $builder, $objectIds
            )->cursor()->filter(function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds);
            })->sortBy(function ($model) use ($objectIdPositions) {
                return $objectIdPositions[$model->getScoutKey()];
            })->values();
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @param  array  $options
     * @return mixed
     */
    public function createIndex($name, array $options = [])
    {
        throw new Exception('Database indexes are created automatically upon adding objects.');
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    public function deleteIndex($name)
    {
        $this->query()->where('index', $name)->delete();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param mixed $results
     *
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['total'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $index = $model->searchableAs();

        $this->query()
            ->where('index', $index)
            ->delete();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param mixed $results
     *
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return $results['results']->pluck('objectID')->values();
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
     * Perform the given search on the engine.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param array                  $options
     *
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
            ->where('entry', 'like', '%' . $builder->query . '%');

        foreach ($builder->wheres as $column => $value) {
            $search = preg_replace('/^\{|\}$/', '%', json_encode([$column => $value]));

            $query->where('entry', 'like', $search);
        }

        return $query;
    }

    /**
     * Determine if the given model uses soft deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }
}
