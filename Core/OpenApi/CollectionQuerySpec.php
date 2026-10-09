<?php

namespace Core\OpenApi;

use Core\Http\CollectionProfile;
use Illuminate\Support\Str;

class CollectionQuerySpec
{
    public static function resources(): array
    {
        $paths = [
            '/api/users' => 'users', '/api/roles' => 'roles', '/api/permissions' => 'permissions',
            '/api/auth/tokens' => 'personal_access_tokens', '/api/files' => 'files',
            '/api/notifications' => 'notifications', '/api/data-transfers' => 'data_transfers', '/api/audit-events' => 'audit_events',
            '/api/community/residents/{resident}/memberships' => 'household_memberships',
            '/api/community/population/transfers/{transfer}/results' => 'population_import_results',
            '/api/billing/payment-types/{type}/tariffs' => 'tariffs',
            '/api/engagement/teams/{team}/members' => 'engagement_team_members',
            '/api/engagement/events/{id}/participants' => 'engagement_participants',
            '/api/engagement/events/{eventId}/participants/{participantId}/history' => 'engagement_history',
            '/api/engagement/events/{id}/incidents' => 'engagement_incidents',
        ];
        foreach (['areas', 'households', 'residents', 'vendors', 'role-assignments'] as $resource) {
            $paths['/api/community/'.$resource] = str_replace('-', '_', $resource);
        }
        foreach (['payment-types' => 'payment_types', 'invoices' => 'invoices', 'bank-accounts' => 'billing_bank_accounts', 'submissions' => 'payment_submissions', 'receipts' => 'receipts', 'expenses' => 'expenses', 'ledger' => 'ledger_entries', 'periods' => 'accounting_periods', 'gateway-checkouts' => 'gateway_checkouts'] as $resource => $table) {
            $paths['/api/billing/'.$resource] = $table;
        }
        foreach (['packages' => 'wifi_packages', 'customers' => 'wifi_customers', 'bills' => 'wifi_bills', 'benefits' => 'gallon_benefits', 'claims' => 'gallon_claims', 'gallon-ledger' => 'gallon_entries', 'finance' => 'wifi_finance'] as $resource => $table) {
            $paths['/api/wifi/'.$resource] = $table;
        }
        foreach (['teams' => 'engagement_teams', 'events' => 'engagement_events'] as $resource => $table) {
            $paths['/api/engagement/'.$resource] = $table;
        }
        $paths['/api/civic/announcements'] = 'civic_announcements';
        foreach (['reports', 'letter-requests'] as $resource) {
            $paths['/api/civic/'.$resource] = 'civic_cases';
            $paths['/api/civic/'.$resource.'/{id}/timeline'] = 'civic_timeline';
        }

        return $paths;
    }

    public static function apply(array &$document): void
    {
        $document['components']['schemas']['CollectionQueryResponse'] = [
            'type' => 'object', 'required' => ['success', 'data'],
            'description' => 'Sparse domain DTOs and safe includes. Cursor or Laravel page metadata; legacy flat collections remain arrays.',
            'properties' => ['success' => ['type' => 'boolean', 'const' => true], 'data' => ['anyOf' => [
                ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                ['type' => 'object', 'required' => ['data', 'per_page'], 'properties' => [
                    'data' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                    'per_page' => ['type' => 'integer'], 'current_page' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'], 'next_cursor' => ['type' => ['string', 'null']],
                    'prev_cursor' => ['type' => ['string', 'null']],
                ], 'additionalProperties' => true],
            ]]],
        ];
        foreach (self::resources() as $path => $table) {
            if (! isset($document['paths'][$path]['get'])) {
                throw new \LogicException('Collection query documentation path is missing: '.$path);
            }
            $operation = &$document['paths'][$path]['get'];
            $profile = CollectionProfile::for($table);
            $partial = array_keys(array_filter($profile['columns'], fn ($column) => in_array($column, CollectionProfile::partialColumns(), true)));
            $filterable = [...array_keys($profile['columns']), ...array_values($profile['relations']), ...($partial ? ['search'] : [])];
            $operation['x-collection-query'] = ['engine' => 'spatie/laravel-query-builder', 'fields' => $profile['fields'], 'filterable' => $filterable, 'partial_filters' => $partial, 'sortable' => array_keys($profile['columns']), 'includes' => array_keys($profile['relations'])];
            $operation['description'] = ($operation['description'] ?? '')."\nSpatie query syntax. Filters are ANDed; comma-separated values use OR within a field. filter[search] uses the native Spatie OR group across the documented text fields. Existing authorization always applies. fields[resource] projects the domain DTO after serialization, preserving derived fields and opaque cursor keys. include returns only safe summaries; inaccessible households are null. Custom sort selects Laravel page pagination. Roles, permissions and tokens keep flat arrays. The former join, s, or, limit, offset and cache parameters are rejected with 422.";
            $parameters = [];
            $parameters[] = self::parameter('fields['.$table.']', 'Comma-separated response fields: '.implode(', ', $profile['fields']).'.');
            foreach ($profile['relations'] as $name => $foreignKey) {
                [, $safeFields] = CollectionProfile::relation($name);
                $namespace = Str::plural(Str::snake($name));
                if ($namespace !== $table) {
                    $parameters[] = self::parameter('fields['.$namespace.']', 'Fields for include='.$name.': '.implode(', ', $safeFields).'. Only applied when included.');
                }
            }
            foreach ($filterable as $field) {
                $description = match (true) {
                    $field === 'search' => 'OR text search across: '.implode(', ', $partial).'.',
                    in_array($field, $partial, true) => 'Case-insensitive partial match; literal % and _. Comma-separated alternatives.',
                    in_array($field, $profile['relations'], true) => 'Exact public UUID or comma-separated public UUIDs; never internal numeric IDs.',
                    default => 'Exact match or comma-separated alternatives. Invalid scalar types return 422.',
                };
                $parameters[] = self::parameter('filter['.$field.']', $description);
            }
            $parameters[] = self::parameter('sort', 'Comma-separated fields; prefix - for descending. Allowed: '.implode(', ', array_keys($profile['columns'])).'. Stable ID tie-breaker; custom sort cannot be used with cursor.');
            $parameters[] = self::parameter('include', 'Comma-separated safe relations (one level): '.(implode(', ', array_keys($profile['relations'])) ?: 'none').'. Unlisted relationships/counts/nested includes are rejected.');
            $parameters[] = self::parameter('per_page', 'Laravel page size, default 20; optional bound for flat lists.', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]);
            $parameters[] = self::parameter('page', 'One-based Laravel page (default 1 with custom sort); mutually exclusive with cursor.', ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000]);
            $parameters[] = self::parameter('cursor', 'Opaque cursor for default ordering only. Not supported by flat lists.');
            $operation['parameters'] = collect([...($operation['parameters'] ?? []), ...$parameters])->keyBy(fn ($parameter) => ($parameter['in'] ?? '').':'.($parameter['name'] ?? ''))->values()->all();
            $operation['responses']['422'] = ['description' => 'Invalid query, old custom syntax, or private/unsupported field, filter or include.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]];
            $existing = $operation['responses']['200']['content']['application/json']['schema'] ?? null;
            $operation['responses']['200']['content']['application/json']['schema'] = $existing
                ? ['anyOf' => [$existing, ['$ref' => '#/components/schemas/CollectionQueryResponse']]]
                : ['$ref' => '#/components/schemas/CollectionQueryResponse'];
            unset($operation);
        }
    }

    private static function parameter(string $name, string $description, array $schema = ['type' => 'string']): array
    {
        return ['name' => $name, 'in' => 'query', 'description' => $description, 'schema' => $schema];
    }
}
