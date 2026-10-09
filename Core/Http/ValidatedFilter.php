<?php

namespace Core\Http;

use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\Filters\Filter;

/** Reject invalid PostgreSQL scalar types before delegating SQL generation to Spatie. */
class ValidatedFilter implements Filter
{
    public function __construct(private Filter $filter, private string $rule) {}

    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        validator(['filter' => [$property => is_array($value) ? $value : [$value]]], [
            'filter.*' => 'array|min:1|max:50',
            'filter.*.*' => ['required', ...explode('|', $this->rule)],
        ])->validate();
        ($this->filter)($query, $value, $property);
    }
}
