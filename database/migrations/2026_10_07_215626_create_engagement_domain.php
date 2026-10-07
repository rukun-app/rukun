<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'rukun';

    public function up(): void
    {
        $db = DB::connection('rukun');
        $users = DB::connection('core')->getQueryGrammar()->wrapTable('users');
        $files = DB::connection('core')->getQueryGrammar()->wrapTable('files');
        $db->statement("CREATE TABLE engagement_teams (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, area_id bigint NOT NULL REFERENCES areas(id), name varchar(120) NOT NULL, created_at timestamp NOT NULL, updated_at timestamp NOT NULL, UNIQUE(area_id,name))");
        $db->statement("CREATE TABLE engagement_team_members (id bigserial PRIMARY KEY, team_id bigint NOT NULL REFERENCES engagement_teams(id), user_id bigint NOT NULL REFERENCES {$users}(id), household_id bigint NOT NULL REFERENCES households(id), UNIQUE(team_id,user_id))");
        $db->statement("CREATE TABLE patrol_policies (id bigserial PRIMARY KEY, area_id bigint UNIQUE NOT NULL REFERENCES areas(id), payment_type_id bigint REFERENCES payment_types(id), due_days integer NOT NULL CHECK(due_days BETWEEN 1 AND 90), updated_by bigint NOT NULL REFERENCES {$users}(id), updated_at timestamp NOT NULL)");
        $db->statement("CREATE TABLE engagement_events (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, kind varchar(10) NOT NULL CHECK(kind IN ('patrol','activity')), area_id bigint NOT NULL REFERENCES areas(id), team_id bigint REFERENCES engagement_teams(id), title varchar(200) NOT NULL, notes text, starts_at timestamp NOT NULL, ends_at timestamp NOT NULL CHECK(ends_at>starts_at), status varchar(12) NOT NULL DEFAULT 'scheduled' CHECK(status IN ('scheduled','cancelled')), payment_type_id bigint REFERENCES payment_types(id), tariff_id bigint REFERENCES tariffs(id), fee_amount bigint NOT NULL DEFAULT 0 CHECK(fee_amount>=0), due_days integer NOT NULL CHECK(due_days BETWEEN 1 AND 90), version integer NOT NULL DEFAULT 1 CHECK(version>0), created_by bigint NOT NULL REFERENCES {$users}(id), created_at timestamp NOT NULL, updated_at timestamp NOT NULL, CHECK((fee_amount=0 AND payment_type_id IS NULL AND tariff_id IS NULL) OR (fee_amount>0 AND payment_type_id IS NOT NULL AND tariff_id IS NOT NULL)), CHECK((kind='patrol' AND team_id IS NOT NULL) OR (kind='activity' AND team_id IS NULL)))");
        $db->statement("CREATE TABLE engagement_participants (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, event_id bigint NOT NULL REFERENCES engagement_events(id), user_id bigint NOT NULL REFERENCES {$users}(id), household_id bigint NOT NULL REFERENCES households(id), status varchar(12) NOT NULL DEFAULT 'active' CHECK(status IN ('active','withdrawn')), attendance varchar(12) NOT NULL DEFAULT 'pending' CHECK(attendance IN ('pending','present','absent','excused')), attendance_note text, attendance_by bigint REFERENCES {$users}(id), attendance_at timestamp, leave_status varchar(12) NOT NULL DEFAULT 'none' CHECK(leave_status IN ('none','pending','approved','rejected')), leave_reason text, leave_recorded_by bigint REFERENCES {$users}(id), leave_reviewed_by bigint REFERENCES {$users}(id), leave_review_note text, waived boolean NOT NULL DEFAULT false, invoice_id bigint UNIQUE REFERENCES invoices(id), version integer NOT NULL DEFAULT 1 CHECK(version>0), created_at timestamp NOT NULL, updated_at timestamp NOT NULL, UNIQUE(event_id,user_id))");
        $db->statement("CREATE TABLE engagement_incidents (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, event_id bigint NOT NULL REFERENCES engagement_events(id), reported_by bigint NOT NULL REFERENCES {$users}(id), description text NOT NULL, created_at timestamp NOT NULL, updated_at timestamp NOT NULL)");
        $db->statement("CREATE TABLE engagement_documents (id bigserial PRIMARY KEY, file_id bigint UNIQUE NOT NULL REFERENCES {$files}(id), event_id bigint REFERENCES engagement_events(id), incident_id bigint REFERENCES engagement_incidents(id), CHECK((event_id IS NULL) <> (incident_id IS NULL)))");
        $db->statement("CREATE TABLE engagement_history (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, participant_id bigint NOT NULL REFERENCES engagement_participants(id), actor_id bigint NOT NULL REFERENCES {$users}(id), action varchar(30) NOT NULL, version integer NOT NULL, created_at timestamp NOT NULL, UNIQUE(participant_id,version))");
        $db->statement('CREATE TRIGGER engagement_history_immutable BEFORE UPDATE OR DELETE ON engagement_history FOR EACH ROW EXECUTE FUNCTION billing_immutable_history()');
        $db->statement('CREATE INDEX engagement_event_scope ON engagement_events(kind,area_id,starts_at)');
        $db->statement('CREATE INDEX engagement_participant_user ON engagement_participants(user_id,status)');
    }

    public function down(): void
    {
        DB::connection('rukun')->statement('DROP TABLE engagement_history,engagement_documents,engagement_incidents,engagement_participants,engagement_events,patrol_policies,engagement_team_members,engagement_teams');
    }
};
