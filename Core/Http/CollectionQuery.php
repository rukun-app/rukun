<?php

namespace Core\Http;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Community\Services\ScopeResolver;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\Exceptions\InvalidQuery;
use Spatie\QueryBuilder\QueryBuilderRequest;

class CollectionQuery
{
    public static function register(): void
    {
        foreach ([EloquentBuilder::class, Builder::class] as $builder) {
            $builder::macro('collectionPaginate', function (int $perPage = 20): CollectionPage {
                return app(CollectionQuery::class)->run($this, request(), $perPage);
            });
            $builder::macro('collectionGet', function (): CollectionPage {
                return app(CollectionQuery::class)->run($this, request(), 20, true);
            });
        }
    }

    public function run(EloquentBuilder|Builder $query, Request $request, int $perPage, bool $flat = false): CollectionPage
    {
        $input = validator($request->query(), [
            'filter' => 'sometimes|array|max:30', 'filter.*' => 'required|string|max:1000',
            'fields' => 'sometimes|array|max:6', 'fields.*' => 'required|string|max:1000',
            'include' => 'sometimes|required|string|max:500', 'sort' => 'sometimes|required|string|max:500',
            'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1|max:10000',
            'cursor' => 'sometimes|required|string|max:4000',
            'join' => 'missing', 's' => 'missing', 'or' => 'missing', 'limit' => 'missing', 'offset' => 'missing', 'cache' => 'missing',
        ])->validate();
        if (isset($input['fields']) && array_is_list($input['fields'])) {
            throw ValidationException::withMessages(['fields' => 'Use fields[resource]=field1,field2.']);
        }
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $profile = CollectionProfile::for($base->from);
        // History controllers use raw queries. Hydrate them without introducing business models or changing their scoped SQL.
        if ($query instanceof Builder) {
            $model = new class extends Model
            {
                public $timestamps = false;
            };
            $model->setConnection($query->getConnection()->getName())->setTable($base->from);
            $query = $model->newEloquentBuilder($query)->setModel($model);
        }
        $parameters = QueryBuilderRequest::fromRequest($request);
        validator(['sort' => $parameters->sorts()->all(), 'include' => $parameters->includes()->all()], ['sort' => 'array|max:5', 'include' => 'array|max:5'])->validate();
        foreach ($parameters->fields() as $fields) {
            validator(['fields' => $fields], ['fields' => 'array|max:50'])->validate();
        }
        $pageMode = isset($input['page']) || $parameters->sorts()->isNotEmpty();
        if (isset($input['cursor']) && ($pageMode || $flat)) {
            throw ValidationException::withMessages(['cursor' => 'Cursor cannot be combined with page or custom sort, or used on flat lists.']);
        }
        if (isset($input['cursor'])) {
            $this->validateCursor($input['cursor'], $base, $profile['table']);
        }
        // Keep any existing authorization OR inside an outer AND constraint.
        if ($base->wheres !== []) {
            $nested = $base->forNestedWhere();
            $nested->wheres = $base->wheres;
            $base->wheres = [['type' => 'Nested', 'query' => $nested, 'boolean' => 'and']];
        }
        $allowedFields = $profile['fields'];
        $includes = [];
        $summaries = [];
        foreach ($profile['relations'] as $name => $foreignKey) {
            [$class, $safeFields] = CollectionProfile::relation($name);
            $namespace = Str::plural(Str::snake($name));
            $allowedFields = [...$allowedFields, ...array_map(fn ($field) => $namespace.'.'.$field, $safeFields)];
            $selected = array_values(array_intersect($parameters->fields()->get($namespace, $safeFields), $safeFields));
            $alias = 'collection'.Str::studly($name);
            $query->getModel()::resolveRelationUsing($alias, fn (Model $model) => $model->belongsTo($class, $foreignKey, 'id'));
            $includes[] = AllowedInclude::callback($name, function ($relation) use ($name, $selected, $request): void {
                $relation->select(['id', ...$selected]);
                if ($name === 'household') {
                    app(ScopeResolver::class)->households($relation->getQuery(), $request->user());
                }
            }, $alias);
            $summaries[$name] = [$alias, $selected];
        }
        if ($parameters->sorts()->isNotEmpty()) {
            $query->reorder();
        }
        try {
            $query = ResourceQueryBuilder::for($query, $request)
                ->allowedFields(...$allowedFields)
                ->allowedFilters(...$this->filters($profile))
                ->allowedSorts(...collect($profile['columns'])->map(fn ($column, $field) => AllowedSort::field($field, $column))->values()->all())
                ->allowedIncludes(...$includes);
        } catch (InvalidQuery $exception) {
            throw ValidationException::withMessages(['query' => $exception->getMessage()]);
        }
        if ($parameters->sorts()->isNotEmpty() || $flat) {
            $query->orderBy('id');
        }
        $metadata = [];
        $perPage = $request->integer('per_page', $perPage);
        if ($flat) {
            if (isset($input['per_page']) || isset($input['page'])) {
                $query->offset(((int) ($input['page'] ?? 1) - 1) * $perPage)->limit($perPage);
            }
            $items = $query->get();
        } else {
            $page = $pageMode ? $query->paginate($perPage, page: (int) ($input['page'] ?? 1)) : $query->cursorPaginate($perPage);
            $page->withQueryString();
            $items = $page->getCollection();
            $metadata = $page->toArray();
            unset($metadata['data']);
        }
        $joined = [];
        foreach ($items->values() as $index => $item) {
            foreach ($parameters->includes() as $name) {
                [$alias, $fields] = $summaries[$name];
                $related = $item->getRelation($alias);
                $joined[$index][$name] = $related ? array_intersect_key($related->toArray(), array_flip($fields)) : null;
                $item->unsetRelation($alias);
            }
        }

        return new CollectionPage($items, $metadata, $parameters->fields()->get($profile['table'], $parameters->fields()->get('_', [])), $joined, $flat);
    }

    private function filters(array $profile): array
    {
        $filters = [];
        foreach ($profile['columns'] as $field => $column) {
            $filter = in_array($column, CollectionProfile::partialColumns(), true)
                ? AllowedFilter::partial($field, $column, false)
                : AllowedFilter::exact($field, $column, false);
            $filters[] = AllowedFilter::custom($field, new ValidatedFilter($filter->getFilterClass(), $this->rule($profile['table'], $column)), $column);
        }
        $search = array_values(array_filter($filters, fn (AllowedFilter $filter) => in_array($filter->getInternalName(), CollectionProfile::partialColumns(), true)));
        if ($search !== []) {
            $filters[] = AllowedFilter::groupOr('search', $search);
        }
        foreach ($profile['relations'] as $name => $foreignKey) {
            [$class] = CollectionProfile::relation($name);
            $filter = AllowedFilter::callback($foreignKey, function (EloquentBuilder $builder, mixed $value) use ($class, $foreignKey): void {
                $builder->whereIn($foreignKey, $class::query()->whereIn('public_id', (array) $value)->select('id'));
            });
            $filters[] = AllowedFilter::custom($foreignKey, new ValidatedFilter($filter->getFilterClass(), 'uuid'));
        }

        return $filters;
    }

    private function rule(string $table, string $column): string
    {
        return match (CollectionProfile::type($table, $column)) {
            'uuid' => 'uuid', 'number' => 'integer|between:-1000000000000000,1000000000000000',
            'boolean' => 'boolean', 'date' => 'date', default => 'string|max:1000',
        };
    }

    private function validateCursor(string $encoded, Builder $query, string $table): void
    {
        try {
            $cursor = Cursor::fromEncoded($encoded);
            foreach ($query->orders ?? [] as $order) {
                validator(['value' => $cursor?->parameter($order['column'])], ['value' => ['required', ...explode('|', $this->rule($table, $order['column']))]])->validate();
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
        }
    }
}
