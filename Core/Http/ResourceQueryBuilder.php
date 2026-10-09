<?php

namespace Core\Http;

use Spatie\QueryBuilder\QueryBuilder;

class ResourceQueryBuilder extends QueryBuilder
{
    /**
     * Spatie validates sparse fields; CollectionPage projects the domain DTO afterwards.
     * Domain serializers need full attributes for public UUIDs, derived balances and cursors.
     */
    protected function addRequestedModelFieldsToQuery(): void {}
}
