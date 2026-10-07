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
        $db->statement("CREATE TABLE civic_announcements (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, area_id bigint NOT NULL REFERENCES areas(id), author_id bigint NOT NULL REFERENCES {$users}(id), title varchar(200) NOT NULL, body text NOT NULL, status varchar(12) NOT NULL CHECK(status IN ('draft','scheduled','published','archived')), publish_at timestamp, published_at timestamp, created_at timestamp NOT NULL, updated_at timestamp NOT NULL)");
        $db->statement("CREATE TABLE civic_reads (id bigserial PRIMARY KEY, announcement_id bigint NOT NULL REFERENCES civic_announcements(id), user_id bigint NOT NULL REFERENCES {$users}(id), read_at timestamp NOT NULL, UNIQUE(announcement_id,user_id))");
        $db->statement("CREATE TABLE civic_cases (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, kind varchar(10) NOT NULL CHECK(kind IN ('report','letter')), area_id bigint NOT NULL REFERENCES areas(id), household_id bigint NOT NULL REFERENCES households(id), reporter_id bigint NOT NULL REFERENCES {$users}(id), category varchar(80) NOT NULL, description text NOT NULL, status varchar(15) NOT NULL DEFAULT 'submitted', assigned_to bigint REFERENCES {$users}(id), version integer NOT NULL DEFAULT 1 CHECK(version>0), created_at timestamp NOT NULL, updated_at timestamp NOT NULL, CHECK((kind='report' AND status IN ('submitted','in_progress','resolved','rejected','cancelled')) OR (kind='letter' AND status IN ('submitted','reviewing','approved','rejected','cancelled'))))");
        $db->statement("CREATE TABLE civic_timeline (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, case_id bigint NOT NULL REFERENCES civic_cases(id), actor_id bigint NOT NULL REFERENCES {$users}(id), action varchar(20) NOT NULL, from_status varchar(15), to_status varchar(15) NOT NULL, assigned_to bigint REFERENCES {$users}(id), note text, version integer NOT NULL, created_at timestamp NOT NULL, UNIQUE(case_id,version))");
        $db->statement("CREATE TABLE civic_documents (id bigserial PRIMARY KEY, file_id bigint UNIQUE NOT NULL REFERENCES {$files}(id), announcement_id bigint REFERENCES civic_announcements(id), case_id bigint REFERENCES civic_cases(id), purpose varchar(10) NOT NULL CHECK(purpose IN ('attachment','output')), CHECK((announcement_id IS NOT NULL AND case_id IS NULL AND purpose='attachment') OR (announcement_id IS NULL AND case_id IS NOT NULL)))");
        $db->statement("CREATE TABLE civic_commands (id bigserial PRIMARY KEY, actor_id bigint NOT NULL REFERENCES {$users}(id), operation varchar(50) NOT NULL, request_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, result uuid NOT NULL, UNIQUE(actor_id,operation,request_key))");
        $db->statement('CREATE INDEX civic_cases_scope ON civic_cases(kind,area_id,status)');
        $db->statement('CREATE INDEX civic_cases_reporter ON civic_cases(reporter_id,kind)');
        $db->statement('CREATE INDEX civic_announcements_due ON civic_announcements(status,publish_at)');
        $db->statement('CREATE TRIGGER civic_timeline_immutable BEFORE UPDATE OR DELETE ON civic_timeline FOR EACH ROW EXECUTE FUNCTION billing_immutable_history()');
    }

    public function down(): void
    {
        DB::connection('rukun')->statement('DROP TABLE civic_commands,civic_documents,civic_timeline,civic_reads,civic_cases,civic_announcements');
    }
};
