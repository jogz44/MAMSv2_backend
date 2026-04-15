<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WebsiteSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    private const PHARMACIST_CATEGORIES = ['MEDICINE', 'LABORATORY', 'HOSPITAL'];

    private ?array $pharmasysUsersColumnsCache = null;

    private function logActivity(Request $request, string $action, string $changes = '', string $target = 'SETTINGS'): void
    {
        ActivityLog::create([
            'performed_by' => $request->input('performed_by') ?? 'Unknown',
            'action'       => $action,
            'target'       => $target,
            'changes'      => $changes,
        ]);
    }

    private function isPharmacistPayload(Request $request): bool
    {
        $role = strtoupper((string) $request->input('role', ''));
        $partners = $request->input('partners');

        return in_array($role, self::PHARMACIST_CATEGORIES, true)
            || (is_array($partners) && count($partners) > 0);
    }

    private function resolveTargetDbSource(Request $request): string
    {
        $requested = strtolower((string) $request->input('db_source', ''));

        if ($requested === 'pharmasysdb') {
            return 'pharmasysdb';
        }

        if ($requested === 'dummymamsdb') {
            return 'dummymamsdb';
        }

        return $this->isPharmacistPayload($request) ? 'pharmasysdb' : 'dummymamsdb';
    }

    private function pharmasysUsersColumns(): array
    {
        if ($this->pharmasysUsersColumnsCache !== null) {
            return $this->pharmasysUsersColumnsCache;
        }

        try {
            $this->pharmasysUsersColumnsCache = DB::connection('pharmasys')
                ->getSchemaBuilder()
                ->getColumnListing('users');
        } catch (\Throwable $e) {
            $this->pharmasysUsersColumnsCache = [];
        }

        return $this->pharmasysUsersColumnsCache;
    }

    private function pharmasysColumn(array $candidates): ?string
    {
        $columns = $this->pharmasysUsersColumns();
        $indexByLower = [];

        foreach ($columns as $idx => $col) {
            $indexByLower[strtolower($col)] = $idx;
        }

        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (array_key_exists($key, $indexByLower)) {
                return $columns[$indexByLower[$key]];
            }
        }

        return null;
    }

    private function pharmasysIdColumn(): string
    {
        return $this->pharmasysColumn(['id', 'ID']) ?? 'id';
    }

    private function pharmasysUsernameColumn(): string
    {
        return $this->pharmasysColumn(['username', 'USERNAME']) ?? 'username';
    }

    private function pharmasysPasswordColumn(): string
    {
        return $this->pharmasysColumn(['password', 'PASSWORD']) ?? 'password';
    }

    private function pharmasysRoleColumn(): ?string
    {
        return $this->pharmasysColumn(['role', 'ROLE', 'category', 'CATEGORY']);
    }

    private function pharmasysPartnerColumn(): ?string
    {
        return $this->pharmasysColumn(['partner', 'PARTNER', 'partners', 'PARTNERS']);
    }

    private function buildPartnerValue(string $role, ?array $partners): ?string
    {
        $partners = is_array($partners) ? array_values(array_filter($partners, fn($p) => (string) $p !== '')) : [];
        $partnersStr = implode(', ', $partners);

        if ($partnersStr === '') {
            return $role !== '' ? $role : null;
        }

        return $role !== '' ? "{$role} | {$partnersStr}" : $partnersStr;
    }

    public function getEligibilityCooldown()
    {
        $eligibilityCooldown = WebsiteSettings::where('id', 1)->value('eligibility_cooldown');
        return response()->json([
            'days' => $eligibilityCooldown ?? 90,
        ]);
    }

    public function updateEligibilityCooldown(Request $request)
    {
        $old = WebsiteSettings::where('id', 1)->value('eligibility_cooldown') ?? 90;
        $new = $request->input('days');

        WebsiteSettings::where('id', 1)->update(['eligibility_cooldown' => $new]);

        $this->logActivity(
            $request,
            'ELIGIBILITY UPDATED',
            "Cooldown: '{$old} days' -> '{$new} days'",
            'ELIGIBILITY COOLDOWN'
        );

        return response()->json(['success' => true]);
    }

    public function getAccounts()
    {
<<<<<<< Updated upstream
        $accounts = User::select('ID', 'USERNAME', 'PASSWORD', 'ROLE', 'CATEGORY', 'PARTNER')->orderBy('ID')->get();
        return response()->json([$accounts]);
=======
        $localAccounts = User::select('ID', 'USERNAME', 'PASSWORD', 'ROLE')
            ->orderBy('ID')
            ->get()
            ->map(fn($row) => [
                'ID' => $row->ID,
                'USERNAME' => $row->USERNAME,
                'PASSWORD' => $row->PASSWORD,
                'ROLE' => $row->ROLE,
                'DB_SOURCE' => 'dummymamsdb',
            ]);

        $pharmasysAccounts = collect();

        try {
            $idCol = $this->pharmasysIdColumn();
            $usernameCol = $this->pharmasysUsernameColumn();
            $passwordCol = $this->pharmasysPasswordColumn();
            $roleCol = $this->pharmasysRoleColumn();
            $partnerCol = $this->pharmasysPartnerColumn();

            $selects = [
                "{$idCol} as ID",
                "{$usernameCol} as USERNAME",
                "{$passwordCol} as PASSWORD",
            ];

            if ($roleCol) {
                $selects[] = "{$roleCol} as ROLE";
            } elseif ($partnerCol) {
                $selects[] = "{$partnerCol} as ROLE";
            } else {
                $selects[] = DB::raw("'' as ROLE");
            }

            $pharmasysAccounts = DB::connection('pharmasys')
                ->table('users')
                ->select($selects)
                ->orderBy($idCol)
                ->get()
                ->map(fn($row) => [
                    'ID' => $row->ID,
                    'USERNAME' => $row->USERNAME,
                    'PASSWORD' => $row->PASSWORD,
                    'ROLE' => $row->ROLE,
                    'DB_SOURCE' => 'pharmasysdb',
                ]);
        } catch (\Throwable $e) {
            // Keep screen usable if pharmasysdb has transient issues.
        }

        return response()->json([$localAccounts->concat($pharmasysAccounts)->values()]);
>>>>>>> Stashed changes
    }

    public function createAccount(Request $request)
    {
<<<<<<< Updated upstream
        $request->validate([
            'username' => 'required|string|max:255',
            'password' => 'required|string|min:1',
            'role' => 'required|string|max:255',
            'category' => 'nullable|in:MEDICINE,LABORATORY,HOSPITAL',
            'partner' => 'nullable|string|max:255',
        ]);

        if ($request->input('role') === 'PHARMACIST') {
            $request->validate([
                'category' => 'required|in:MEDICINE,LABORATORY,HOSPITAL',
                'partner' => 'required|string|max:255',
            ]);
=======
        $targetDb = $this->resolveTargetDbSource($request);
        $role = strtoupper((string) $request->input('role'));
        $partners = $request->input('partners');

        if ($targetDb === 'pharmasysdb') {
            $usernameCol = $this->pharmasysUsernameColumn();
            $passwordCol = $this->pharmasysPasswordColumn();
            $roleCol = $this->pharmasysRoleColumn();
            $partnerCol = $this->pharmasysPartnerColumn();

            $insertData = [
                $usernameCol => $request->input('username'),
                $passwordCol => Hash::make($request->input('password')),
            ];

            if ($roleCol) {
                $insertData[$roleCol] = $role;
            }

            if ($partnerCol) {
                $insertData[$partnerCol] = $this->buildPartnerValue($roleCol ? '' : $role, is_array($partners) ? $partners : null);
            }

            DB::connection('pharmasys')->table('users')->insert($insertData);

            $partnersLog = is_array($partners) ? implode(', ', $partners) : 'None';
            $this->logActivity(
                $request,
                'ACCOUNT CREATED',
                "Username: '{$request->input('username')}' | Category: '{$role}' | Partners: '{$partnersLog}' | DB: pharmasysdb",
                'ACCOUNT OPTIONS'
            );

            return response()->json(['success' => true]);
>>>>>>> Stashed changes
        }

        $account = User::create([
            'USERNAME' => $request->input('username'),
            'PASSWORD' => Hash::make($request->input('password')),
<<<<<<< Updated upstream
            'ROLE'     => $request->input('role'),
            'CATEGORY' => $request->input('category'),
            'PARTNER'  => $request->input('partner'),
        ]);

        $changes = "Username: '{$account->USERNAME}' | Role: '{$account->ROLE}'";
        if ($account->CATEGORY) {
            $changes .= " | Category: '{$account->CATEGORY}'";
        }
        if ($account->PARTNER) {
            $changes .= " | Partner: '{$account->PARTNER}'";
        }

        $this->logActivity($request, 'ACCOUNT CREATED', $changes, 'ACCOUNT OPTIONS');

=======
            'ROLE'     => $role,
        ]);

        $this->logActivity(
            $request,
            'ACCOUNT CREATED',
            "Username: '{$account->USERNAME}' | Role: '{$account->ROLE}' | DB: dummymamsdb",
            'ACCOUNT OPTIONS'
        );
>>>>>>> Stashed changes

        return response()->json(['success' => true]);
    }

    public function updateAccount(Request $request)
    {
        $userId = $request->input('id');
        $targetDb = $this->resolveTargetDbSource($request);
        $newRole = strtoupper((string) $request->input('role'));
        $partners = $request->input('partners');

        if ($targetDb === 'pharmasysdb') {
            $idCol = $this->pharmasysIdColumn();
            $usernameCol = $this->pharmasysUsernameColumn();
            $passwordCol = $this->pharmasysPasswordColumn();
            $roleCol = $this->pharmasysRoleColumn();
            $partnerCol = $this->pharmasysPartnerColumn();

            $existing = DB::connection('pharmasys')->table('users')->where($idCol, $userId)->first();
            if (!$existing) {
                return response()->json(['success' => false, 'message' => 'Account not found in pharmasysdb'], 404);
            }

            $updateData = [
                $usernameCol => $request->input('username'),
            ];

            if ($roleCol) {
                $updateData[$roleCol] = $newRole;
            }

            if ($request->has('password') && $request->input('password') !== null && $request->input('password') !== '') {
                $updateData[$passwordCol] = Hash::make($request->input('password'));
            }

            if ($partnerCol) {
                $updateData[$partnerCol] = $this->buildPartnerValue($roleCol ? '' : $newRole, is_array($partners) ? $partners : null);
            }

            DB::connection('pharmasys')->table('users')->where($idCol, $userId)->update($updateData);

            $this->logActivity(
                $request,
                'ACCOUNT UPDATED',
                "Username: '{$request->input('username')}' | Category: '{$newRole}' | DB: pharmasysdb",
                'ACCOUNT OPTIONS'
            );

            return response()->json(['success' => true]);
        }

        $existing = User::where('ID', $userId)->first();
        if (!$existing) {
            return response()->json(['success' => false, 'message' => 'Account not found in dummymamsdb'], 404);
        }

        $updateData = [
            'USERNAME' => $request->input('username'),
            'ROLE'     => $newRole,
        ];

        $changes = "Username: '{$existing->USERNAME}' -> '{$updateData['USERNAME']}' | Role: '{$existing->ROLE}' -> '{$updateData['ROLE']}' | DB: dummymamsdb";

        if ($request->has('password') && $request->input('password') !== null && $request->input('password') !== '') {
            $updateData['PASSWORD'] = Hash::make($request->input('password'));
            $changes .= ' | Password: changed';
        }

        User::where('ID', $userId)->update($updateData);
        $this->invalidateUserSessions($userId);
        $this->logActivity($request, 'ACCOUNT UPDATED', $changes, 'ACCOUNT OPTIONS');

        return response()->json(['success' => true]);
    }

    public function deleteAccount(Request $request)
    {
        $userId = $request->input('id');
        $targetDb = $this->resolveTargetDbSource($request);

        if ($targetDb === 'pharmasysdb') {
            $idCol = $this->pharmasysIdColumn();
            $existing = DB::connection('pharmasys')->table('users')->where($idCol, $userId)->first();
            if (!$existing) {
                return response()->json(['success' => false, 'message' => 'Account not found in pharmasysdb'], 404);
            }

            DB::connection('pharmasys')->table('users')->where($idCol, $userId)->delete();

            $usernameCol = $this->pharmasysUsernameColumn();
            $roleCol = $this->pharmasysRoleColumn();
            $partnerCol = $this->pharmasysPartnerColumn();
            $roleOrPartnerValue = $roleCol && isset($existing->{$roleCol})
                ? $existing->{$roleCol}
                : ($partnerCol && isset($existing->{$partnerCol}) ? $existing->{$partnerCol} : 'N/A');

            $this->logActivity(
                $request,
                'ACCOUNT DELETED',
                "Username: '{$existing->{$usernameCol}}' | Role/Partner: '{$roleOrPartnerValue}' | DB: pharmasysdb",
                'ACCOUNT OPTIONS'
            );

            return response()->json(['success' => true]);
        }

        $existing = User::where('ID', $userId)->first();
        if (!$existing) {
            return response()->json(['success' => false, 'message' => 'Account not found in dummymamsdb'], 404);
        }

        $this->invalidateUserSessions($userId);
        User::where('ID', $userId)->delete();

        $this->logActivity(
            $request,
            'ACCOUNT DELETED',
            "Username: '{$existing->USERNAME}' | Role: '{$existing->ROLE}' | DB: dummymamsdb",
            'ACCOUNT OPTIONS'
        );

        return response()->json(['success' => true]);
    }

    /**
     * Invalidate all sessions for a specific user
     */
    private function invalidateUserSessions($userId)
    {
        DB::table('sessions')
            ->where('user_id', $userId)
            ->delete();
    }
}
