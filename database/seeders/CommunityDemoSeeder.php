<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\HouseholdMembership;
use Modules\Community\Models\Resident;
use Modules\Community\Models\RoleAssignment;
use Modules\Community\Models\Vendor;
use Spatie\Permission\Models\Role;

class CommunityDemoSeeder extends Seeder
{
    public const CREDENTIAL_FILE = 'community-demo-accounts.json';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Community demo seeding is only available in local/testing.');
        }
        $this->call(DatabaseSeeder::class);
        $path = Storage::disk('local')->path(self::CREDENTIAL_FILE);
        $specs = $this->accounts();
        DB::connection('rukun')->transaction(function () use ($path, $specs): void {
            DB::connection('rukun')->select('SELECT pg_advisory_xact_lock(?)', [854020]);
            $manifest = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : ['dataset' => 'community-demo-v1', 'password' => Str::password(24), 'accounts' => []];
            if (($manifest['dataset'] ?? null) !== 'community-demo-v1' || empty($manifest['password'])) {
                throw new \RuntimeException('Invalid community demo credential manifest.');
            }
            foreach ($specs as $email => $spec) {
                $manifest['accounts'][$email] ??= ['public_id' => (string) Str::uuid(), ...$spec];
            }
            $this->saveManifest($path, $manifest);
            $rw1 = Area::query()->firstOrCreate(['kind' => 'rw', 'parent_id' => null, 'code' => 'DEMO-RW01'], ['name' => 'RW 01 Melati (Demo)']);
            $rw2 = Area::query()->firstOrCreate(['kind' => 'rw', 'parent_id' => null, 'code' => 'DEMO-RW02'], ['name' => 'RW 02 Kenanga (Demo)']);
            $areas = ['rw01' => $rw1, 'rw02' => $rw2];
            foreach ([1, 2, 3] as $number) {
                $areas[sprintf('rt%02d', $number)] = Area::query()->firstOrCreate(['kind' => 'rt', 'parent_id' => $number === 3 ? $rw2->id : $rw1->id, 'code' => sprintf('DEMO-RT%02d', $number)], ['name' => sprintf('RT %02d %s (Demo)', $number, $number === 3 ? 'Kenanga' : 'Melati')]);
            }
            $families = [
                ['Budi Santoso', 'Siti Lestari', 'Andi Santoso', 'Dina Santoso'],
                ['Agus Wijaya', 'Rina Wijaya', 'Fajar Wijaya', 'Nadia Wijaya'],
                ['Dedi Kurniawan', 'Maya Kurniawan', 'Rizky Kurniawan', 'Tika Kurniawan'],
                ['Hendra Saputra', 'Dewi Saputra', 'Arif Saputra', 'Nisa Saputra'],
                ['Joko Prasetyo', 'Wati Prasetyo', 'Bayu Prasetyo', 'Lia Prasetyo'],
                ['Rudi Hartono', 'Yuni Hartono', 'Dimas Hartono', 'Rara Hartono'],
                ['Tono Setiawan', 'Indah Setiawan', 'Rafi Setiawan', 'Putri Setiawan'],
                ['Wahyu Nugroho', 'Ratna Nugroho', 'Ilham Nugroho', 'Alya Nugroho'],
                ['Eko Firmansyah', 'Lina Firmansyah', 'Galih Firmansyah', 'Citra Firmansyah'],
                ['Feri Maulana', 'Nur Maulana', 'Adit Maulana', 'Salma Maulana'],
            ];
            $households = $residents = [];
            foreach ($families as $index => $names) {
                $number = $index + 1;
                $area = $areas[$number <= 4 ? 'rt01' : ($number <= 8 ? 'rt02' : 'rt03')];
                $household = Household::query()->firstOrCreate(['reference' => sprintf('DEMO-COM-H%02d', $number)], ['area_id' => $area->id, 'address' => 'Jl. '.($number <= 8 ? 'Melati' : 'Kenanga').' Demo No. '.$number, 'block' => $number <= 8 ? 'A' : 'B', 'house_number' => sprintf('%02d', $number), 'occupancy_status' => $number % 3 === 0 ? 'rented' : 'occupied', 'status' => 'active']);
                $households[$number] = $household;
                foreach ($names as $member => $name) {
                    $resident = Resident::query()->firstOrCreate(['reference' => sprintf('DEMO-COM-H%02d-P%d', $number, $member + 1)], ['name' => $name.' (Demo)', 'birth_date' => sprintf('%d-%02d-%02d', [1980 + $number, 1982 + $number, $number === 3 ? 2002 : 2009, 2013][$member], $number, $member + 10), 'area_id' => $area->id, 'household_id' => $household->id, 'status' => 'active']);
                    if ($resident->wasRecentlyCreated) {
                        HouseholdMembership::query()->create(['resident_id' => $resident->id, 'household_id' => $household->id, 'relationship' => ['head', 'spouse', 'child', 'child'][$member], 'starts_at' => '2026-01-01 00:00:00']);
                    }
                    $residents[$number][$member] = $resident;
                }
            }
            $vendor = Vendor::query()->firstOrCreate(['name' => 'WiFi Komunitas Demo'], ['status' => 'active']);
            $actorId = null;
            foreach ($specs as $email => $spec) {
                $identity = $manifest['accounts'][$email]['public_id'];
                $model = (new User)->setConnection('rukun')->setTable(config('database.connections.core.prefix').'users');
                $user = $model->newQuery()->where('email', $email)->first();
                if ($user && $user->public_id !== $identity) {
                    throw new \RuntimeException('Reserved demo email already belongs to a different account: '.$email);
                }
                if (! $user) {
                    $user = $model->newInstance();
                    $user->forceFill(['public_id' => $identity, 'email' => $email, 'name' => isset($spec['house']) ? $residents[$spec['house']][$spec['member']]->name : $spec['label'], 'password' => $manifest['password'], 'locale' => 'id', 'email_verified_at' => now(), 'must_change_password' => false, 'status' => 'active'])->save();
                }
                $actorId ??= $user->id;
                $role = Role::findByName($spec['role'], 'web');
                if ($spec['scope'] === 'global') {
                    if ($user->wasRecentlyCreated) {
                        DB::connection('rukun')->table(config('database.connections.core.prefix').'model_has_roles')->insertOrIgnore(['role_id' => $role->id, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id]);
                    }

                    continue;
                }
                $household = isset($spec['house']) ? $households[$spec['house']] : null;
                if ($household) {
                    $resident = $residents[$spec['house']][$spec['member']];
                    if ($resident->wasRecentlyCreated) {
                        $resident->update(['user_id' => $user->id]);
                        DB::connection('rukun')->table('account_scopes')->insertOrIgnore(['user_id' => $user->id, 'area_id' => $household->area_id, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                $scope = $spec['scope'];
                $scopeType = str_starts_with($scope, 'rt') ? 'rt' : (str_starts_with($scope, 'rw') ? 'rw' : $scope);
                RoleAssignment::query()->firstOrCreate(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $scopeType, 'area_id' => $areas[$scope]->id ?? null, 'household_id' => $scope === 'household' ? $household->id : null, 'vendor_id' => $scope === 'vendor' ? $vendor->id : null], ['starts_at' => '2026-01-01 00:00:00', 'status' => 'active', 'assigned_by' => $actorId]);
            }
        }, 3);
        $this->command?->info('Community demo ready: 2 RW, 3 RT, 10 households, 40 residents, '.count($specs).' login accounts.');
        $this->command?->info('Initial credentials (private, never reset on rerun): '.$path);
    }

    private function accounts(): array
    {
        $accounts = ['demo.admin@rukun.test' => ['label' => 'Administrator Community Demo', 'role' => 'super-admin', 'scope' => 'global']];
        $spouses = [
            ['ketua.rt01', 'ketua-rt', 'rt01'], ['sekretaris.rt01', 'sekretaris-rt', 'rt01'], ['bendahara.rt01', 'bendahara-rt', 'rt01'], ['ketua.rw01', 'ketua-rw', 'rw01'], ['ketua.rt02', 'ketua-rt', 'rt02'], ['sekretaris.rw01', 'sekretaris-rw', 'rw01'], ['bendahara.rw01', 'bendahara-rw', 'rw01'], ['pengelola.kk08', 'household-account-manager', 'household'], ['ketua.rw02', 'ketua-rw', 'rw02'], ['ketua.rt03', 'ketua-rt', 'rt03'],
        ];
        foreach ($spouses as $index => [$login, $role, $scope]) {
            $house = $index + 1;
            $accounts[sprintf('demo.warga%02d@rukun.test', $house)] = ['label' => 'Warga rumah '.sprintf('%02d', $house), 'role' => 'warga', 'scope' => 'household', 'house' => $house, 'member' => 0];
            $accounts['demo.'.$login.'@rukun.test'] = ['label' => $login, 'role' => $role, 'scope' => $scope, 'house' => $house, 'member' => 1];
        }
        foreach (['02', '03'] as $rt) {
            $accounts['demo.sekretaris.rt'.$rt.'@rukun.test'] = ['label' => 'Sekretaris RT '.$rt.' Demo', 'role' => 'sekretaris-rt', 'scope' => 'rt'.$rt];
        }
        $accounts['demo.ronda@rukun.test'] = ['label' => 'Koordinator Ronda Demo', 'role' => 'koordinator-ronda', 'scope' => 'rt01', 'house' => 3, 'member' => 2];
        $accounts['demo.vendor@rukun.test'] = ['label' => 'Vendor WiFi Demo', 'role' => 'vendor-wifi', 'scope' => 'vendor'];

        return $accounts;
    }

    private function saveManifest(string $path, array $manifest): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $mask = umask(0077);
        try {
            $temporary = tempnam(dirname($path), '.community-demo-');
            if ($temporary === false || file_put_contents($temporary, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL) === false || ! rename($temporary, $path)) {
                throw new \RuntimeException('Unable to save private demo credentials.');
            }
            chmod($path, 0600);
        } finally {
            umask($mask);
        }
    }
}
