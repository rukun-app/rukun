<?php

namespace Core\Http;

use App\Models\User;
use Modules\Billing\Models\PaymentType;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\Vendor;

/** Explicit public query contracts; never infer selectable columns from the database schema. */
class CollectionProfile
{
    public static function for(string $table): array
    {
        $columns = match ($table) {
            'areas' => 'public_id kind code name',
            'households' => 'public_id reference status address block house_number occupancy_status',
            'residents' => 'public_id reference status name birth_date phone',
            'household_memberships' => 'public_id relationship starts_at ends_at',
            'role_assignments' => 'public_id scope_type status starts_at ends_at',
            'vendors' => 'public_id name status created_at updated_at',
            'payment_types' => 'public_id code name collection_policy fund_classification',
            'tariffs' => 'public_id amount starts_at ends_at',
            'invoices' => 'public_id period subject amount due_date settle_by collection_policy fund_classification',
            'billing_bank_accounts' => 'public_id bank_name account_number account_holder',
            'payment_submissions' => 'public_id amount transferred_at note status reviewed_at review_note',
            'receipts' => 'public_id number amount paid_on paid_at channel',
            'expenses' => 'public_id amount description fund_classification channel status approved_at posted_on',
            'ledger_entries' => 'public_id posted_on kind amount fund_classification channel reason transfer_group',
            'accounting_periods' => 'public_id period closed_at',
            'gateway_checkouts' => 'public_id amount status expires_at review_reason',
            'wifi_packages' => 'public_id name due_day settle_day remit_day allow_advance gallon_quota claim_days',
            'wifi_customers' => 'public_id starts_on ends_on',
            'wifi_bills' => 'public_id period',
            'gallon_benefits' => 'public_id quota available reserved confirmed expires_on status',
            'gallon_claims' => 'public_id reference quantity status',
            'gallon_entries' => 'public_id event available_delta reserved_delta confirmed_delta created_at',
            'wifi_finance' => 'public_id kind amount advance channel reference posted_on',
            'civic_announcements' => 'public_id title body status publish_at published_at created_at',
            'civic_cases' => 'public_id kind category description status version created_at updated_at',
            'civic_timeline' => 'public_id action from_status to_status note version created_at',
            'engagement_teams' => 'public_id name created_at',
            'engagement_team_members' => '',
            'engagement_events' => 'public_id kind title notes starts_at ends_at status fee_amount due_days version created_at',
            'engagement_participants' => 'public_id status attendance attendance_note attendance_at leave_status leave_reason leave_review_note waived version',
            'engagement_incidents' => 'public_id description created_at',
            'engagement_history' => 'public_id action version created_at',
            'population_import_results' => 'id',
            'users' => 'public_id name email phone status created_at updated_at',
            'roles' => 'id name guard_name created_at updated_at',
            'permissions' => 'id name',
            'personal_access_tokens' => 'id name last_used_at expires_at created_at',
            'files' => 'original_name display_name mime_type extension size visibility created_at updated_at',
            'data_transfers' => 'type direction status failure_message started_at finished_at created_at updated_at',
            'notifications' => 'id read_at created_at',
            'audit_events' => 'id event created_at',
            default => throw new \LogicException('Missing collection query profile: '.$table),
        };
        $fields = array_values(array_filter(explode(' ', $columns)));
        $relations = [];
        foreach (['area' => ['areas', 'households', 'residents', 'role_assignments', 'payment_types', 'invoices', 'billing_bank_accounts', 'payment_submissions', 'receipts', 'expenses', 'ledger_entries', 'accounting_periods', 'wifi_packages', 'wifi_customers', 'civic_announcements', 'civic_cases', 'engagement_teams', 'engagement_events'], 'household' => ['residents', 'household_memberships', 'role_assignments', 'invoices', 'payment_submissions', 'receipts', 'wifi_customers', 'civic_cases', 'engagement_participants', 'engagement_team_members'], 'vendor' => ['role_assignments', 'wifi_packages', 'wifi_customers', 'gallon_claims'], 'payment_type' => ['tariffs', 'invoices', 'wifi_packages'], 'user' => ['residents', 'role_assignments', 'engagement_participants', 'engagement_team_members']] as $relation => $tables) {
            if (in_array($table, $tables, true) && ! ($relation === 'area' && $table === 'areas')) {
                $relations[$relation] = $relation.'_id';
            }
        }
        if ($table === 'areas') {
            $relations['parent'] = 'parent_id';
        }
        $extra = match ($table) {
            'households' => 'area_id',
            'residents' => 'area_id household_id user_id',
            'areas' => 'parent_id',
            'household_memberships' => 'household_id',
            'role_assignments' => 'user_id role household_id vendor_id area_id',
            'users' => 'id roles permissions email_verified_at locale must_change_password',
            'roles' => 'permissions',
            'personal_access_tokens' => 'is_current',
            'files' => 'id metadata attachments_count',
            'data_transfers' => 'id format progress input_file_id output_file_id errors',
            'notifications' => 'category title message action context',
            'audit_events' => 'actor_id subject_type subject_id metadata ip_address user_agent',
            'invoices' => 'area_id household_id payment_type_id tariff_id paid_amount outstanding_amount status overdue reserved_amount payable_amount',
            'payment_submissions' => 'area_id household_id destination_account_id submitted_by reviewed_by proof_file_id allocations',
            'receipts' => 'area_id household_id submission_id gateway_payment_id created_by reversal_id allocations',
            'expenses' => 'area_id created_by approved_by proof_file_id reversed',
            'ledger_entries' => 'area_id household_id receipt_id expense_id reverses_id created_by',
            'accounting_periods' => 'area_id report closed_by',
            'payment_types', 'billing_bank_accounts' => 'area_id',
            'tariffs' => 'payment_type_id',
            'gateway_checkouts' => 'invoice_id payment_id currency payment_status checkout_url paid_at receipt_id settlement',
            'wifi_packages' => 'area_id vendor_id payment_type_id',
            'wifi_customers' => 'area_id vendor_id package_id household_id household_reference status',
            'wifi_bills' => 'customer_id eligible',
            'gallon_benefits' => 'bill_id claimable',
            'gallon_claims' => 'benefit_id vendor_id',
            'gallon_entries' => 'benefit_id claim_id',
            'wifi_finance' => 'bill_id parent_id reverses_id reversed_by source_receipts ledger_ids',
            'civic_announcements' => 'area_id author_id read_at documents',
            'civic_cases' => 'area_id household_id reporter_id assigned_to documents',
            'civic_timeline' => 'actor_id assigned_to',
            'engagement_teams' => 'area_id',
            'engagement_team_members' => 'user_id household_id',
            'engagement_events' => 'area_id team_id documents',
            'engagement_participants' => 'event_id user_id household_id invoice_id leave_recorded_by leave_reviewed_by attendance_by billing',
            'engagement_incidents' => 'event_id reported_by documents',
            'engagement_history' => 'actor_id',
            'population_import_results' => 'resident_id operation_id credential_url expires_at',
            default => '',
        };
        $query = array_combine($fields, $fields);
        if (in_array($table, ['files', 'data_transfers'], true)) {
            $query['id'] = 'public_id';
        }

        return ['table' => $table, 'columns' => $query, 'fields' => array_values(array_unique([...$fields, ...array_filter(explode(' ', $extra))])), 'relations' => $relations];
    }

    public static function type(string $table, string $column): string
    {
        if ($column === 'public_id' || $column === 'transfer_group' || ($table === 'notifications' && $column === 'id')) {
            return 'uuid';
        }
        if (in_array($column, ['allow_advance', 'advance', 'waived'], true)) {
            return 'boolean';
        }
        if (in_array($column, ['id', 'amount', 'fee_amount', 'due_days', 'version', 'size', 'quota', 'available', 'reserved', 'confirmed', 'quantity', 'available_delta', 'reserved_delta', 'confirmed_delta', 'due_day', 'settle_day', 'remit_day', 'gallon_quota', 'claim_days'], true)) {
            return 'number';
        }
        if (str_ends_with($column, '_at') || str_ends_with($column, '_on') || in_array($column, ['birth_date', 'due_date', 'settle_by', 'period'], true)) {
            return 'date';
        }

        return 'string';
    }

    public static function relation(string $name): array
    {
        return match ($name) {
            'area', 'parent' => [Area::class, ['public_id', 'kind', 'code', 'name']],
            'household' => [Household::class, ['public_id', 'reference', 'address', 'block', 'house_number', 'status']],
            'vendor' => [Vendor::class, ['public_id', 'name']],
            'payment_type' => [PaymentType::class, ['public_id', 'code', 'name']],
            'user' => [User::class, ['public_id', 'name']],
        };
    }

    public static function partialColumns(): array
    {
        return ['name', 'address', 'title', 'body', 'description', 'reference', 'account_holder', 'note', 'display_name', 'original_name'];
    }
}
