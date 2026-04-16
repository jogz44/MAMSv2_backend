<?php

namespace App\Http\Controllers;

use App\Models\PatientList;
use App\Models\ClientName;
use App\Models\PatientHistory;
use App\Models\WebsiteSettings;
use App\Models\ActivityLog;
use App\Models\Partners;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PatientController extends Controller
{
    /**
     * Get the eligibility cooldown period in days from settings
     */
    private function getEligibilityCooldownDays()
    {
        return WebsiteSettings::where('id', 1)->value('eligibility_cooldown') ?? 90;
    }

    /**
     * Log an activity action (ADDED, EDIT, DELETE)
     */
    private function logActivity(Request $request, string $action, string $uuid, string $changes = ''): void
    {
        ActivityLog::create([
            'performed_by' => $request->input('performed_by') ?? 'Unknown',
            'action'       => $action,
            'target'       => $uuid,
            'changes'      => $changes,
        ]);
    }

    private function syncPatientSectors(int $patientId, $sectorIdsJson): void
    {
        $sectorIds = json_decode($sectorIdsJson, true);
        if (!is_array($sectorIds)) return;

        DB::table('user_sectors')->where('patient_id', $patientId)->delete();

        if (count($sectorIds) > 0) {
            $inserts = array_map(fn($id) => [
                'patient_id' => $patientId,
                'sector_id'  => (int) $id,
            ], $sectorIds);
            DB::table('user_sectors')->insert($inserts);
        }
    }

    private function attachSectorIds($patients)
    {
        $patientIds = $patients->pluck('patient_id')->unique()->toArray();

        $patientSectors = DB::table('user_sectors')
            ->whereIn('patient_id', $patientIds)
            ->select('patient_id', 'sector_id')
            ->get()
            ->groupBy('patient_id')
            ->map(fn($sectors) => $sectors->pluck('sector_id')->toArray());

        foreach ($patients as $patient) {
            $patient->sector_ids = $patientSectors->get($patient->patient_id, []);
        }

        return response()->json($patients);
    }

    private function normalizePhoneNumber($phoneNumber)
    {
        if (empty($phoneNumber) || $phoneNumber === 'null' || strtolower($phoneNumber) === 'n/a') {
            return null;
        }

        $cleaned = preg_replace('/\D/', '', $phoneNumber);

        if (substr($cleaned, 0, 2) === '63') {
            $cleaned = '0' . substr($cleaned, 2);
        }

        if (substr($cleaned, 0, 2) !== '09' || strlen($cleaned) !== 11) {
            return null;
        }

        return $cleaned;
    }

    private function normalizeBirthdate($birthdate): ?string
    {
        if (is_null($birthdate)) {
            return null;
        }

        $value = trim((string) $birthdate);
        if ($value === '' || strtolower($value) === 'null' || strtolower($value) === 'n/a') {
            return null;
        }

        foreach (['Y-m-d', 'Y-d-m', 'm/d/Y', 'd/m/Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
                if ($parsed && $parsed->format($format) === $value) {
                    return $parsed->format('Y-m-d');
                }
            } catch (\Exception $e) {
                // Try next format
            }
        }

        return null;
    }

    private function isPartnerValidForCategory(string $category, string $partner): bool
    {
        return Partners::where('is_active', true)
            ->where('category', $category)
            ->where('partner', $partner)
            ->exists();
    }

    /**
     * Snapshot current sector IDs for a patient before syncing.
     * Returns a sorted array of integer sector IDs.
     */
    private function getCurrentSectorIds(int $patientId): array
    {
        return DB::table('user_sectors')
            ->where('patient_id', $patientId)
            ->pluck('sector_id')
            ->map(fn($id) => (int) $id)
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Parse and sort sector IDs from a JSON string for comparison.
     * Returns a sorted array of integer sector IDs.
     */
    private function parseNewSectorIds(?string $sectorIdsJson): array
    {
        return collect(json_decode($sectorIdsJson, true) ?? [])
            ->map(fn($id) => (int) $id)
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Build a sector change log string if the sector IDs differ.
     * Resolves sector IDs to names for readability.
     * Returns null if no change detected.
     */
    private function buildSectorChangeLog(array $oldSectorIds, array $newSectorIds): ?string
    {
        if ($oldSectorIds === $newSectorIds) {
            return null;
        }

        // Resolve all relevant IDs to names in one query
        $allIds = array_unique(array_merge($oldSectorIds, $newSectorIds));
        $sectorNames = DB::table('sectors')
            ->whereIn('id', $allIds)
            ->pluck('sector', 'id');

        $resolveName = fn($id) => $sectorNames[$id] ?? "ID:{$id}";

        $oldStr = count($oldSectorIds)
            ? implode(', ', array_map($resolveName, $oldSectorIds))
            : 'None';
        $newStr = count($newSectorIds)
            ? implode(', ', array_map($resolveName, $newSectorIds))
            : 'None';

        return "sectors: '[{$oldStr}]' → '[{$newStr}]'";
    }

    public function addPatient(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $nullify = fn($value) => (is_null($value) || $value === '' || $value === 'null' || (is_string($value) && strtolower($value) === 'n/a')) ? null : $value;

            $patientID = $request->input('patient_id');
            $updatePatientInfo = $request->boolean('update_patient_info');

            $phoneNumber = $this->normalizePhoneNumber($request->input('phone_number'));
            $birthdate = $this->normalizeBirthdate($request->input('birthdate'));
            $category = strtoupper(trim((string) $request->input('category', '')));
            $partnerInput = $nullify($request->input('partner'));
            $partner = is_null($partnerInput) ? null : strtoupper(trim((string) $partnerInput));

            if ($request->filled('phone_number') && $phoneNumber === null) {
                return response()->json([
                    'error' => 'Invalid phone number format. Must be 11 digits starting with 09 or +63'
                ], 422);
            }

            if ($request->filled('birthdate') && $birthdate === null) {
                return response()->json([
                    'error' => 'Invalid birthdate format. Use MM/DD/YYYY, DD/MM/YYYY, or YYYY-MM-DD.'
                ], 422);
            }

            if (!in_array($category, ['MEDICINE', 'LABORATORY', 'HOSPITAL'], true)) {
                return response()->json([
                    'error' => 'Invalid category selected.'
                ], 422);
            }

            if (is_null($partner) || $partner === '') {
                return response()->json([
                    'error' => 'Partner is required.'
                ], 422);
            }

            if (!$this->isPartnerValidForCategory($category, $partner)) {
                return response()->json([
                    'error' => 'Selected partner does not belong to the selected category.'
                ], 422);
            }

            if ($patientID == null) {
                $patient = PatientList::create([
                    'lastname'      => $request->input('lastname'),
                    'firstname'     => $request->input('firstname'),
                    'middlename'    => $nullify($request->input('middlename')),
                    'suffix'        => $nullify($request->input('suffix')),
                    'birthdate'     => $birthdate,
                    'sex'           => $nullify($request->input('sex')),
                    'preference'    => $nullify($request->input('preference')),
                    'province'      => $nullify($request->input('province')),
                    'city'          => $nullify($request->input('city')),
                    'barangay'      => $nullify($request->input('barangay')),
                    'house_address' => $nullify($request->input('house_address')),
                    'phone_number'  => $phoneNumber,
                ]);
                $patientID = $patient->patient_id;
            } elseif ($updatePatientInfo) {
                $patient = PatientList::where('patient_id', $patientID)->firstOrFail();
                $patient->update([
                    'lastname'      => $request->input('lastname'),
                    'firstname'     => $request->input('firstname'),
                    'middlename'    => $nullify($request->input('middlename')),
                    'suffix'        => $nullify($request->input('suffix')),
                    'birthdate'     => $birthdate,
                    'sex'           => $nullify($request->input('sex')),
                    'preference'    => $nullify($request->input('preference')),
                    'province'      => $nullify($request->input('province')),
                    'city'          => $nullify($request->input('city')),
                    'barangay'      => $nullify($request->input('barangay')),
                    'house_address' => $nullify($request->input('house_address')),
                    'phone_number'  => $phoneNumber,
                ]);
            }

            if ($request->filled('sector_ids')) {
                $this->syncPatientSectors($patientID, $request->input('sector_ids'));
            }

            $hospitalBillInput = $request->input('hospital_bill');
            $hospitalBillRaw = is_null($hospitalBillInput) ? null : trim((string) $hospitalBillInput);
            $hospitalBill = (is_null($hospitalBillRaw) || $hospitalBillRaw === '' || strtolower($hospitalBillRaw) === 'null' || strtolower($hospitalBillRaw) === 'n/a')
                ? null
                : (float) $hospitalBillRaw;

            $patientHistory = PatientHistory::create([
                'patient_id'    => $patientID,
                'category'      => $category,
                'partner'       => $partner,
                'hospital_bill' => $hospitalBill,
                'issued_amount' => $nullify($request->input('issued_amount')),
                'issued_by'     => $nullify($request->input('issued_by')),
                'date_issued'   => $request->input('issued_at'),
            ]);

            if (!$request->boolean('is_checked')) {
                ClientName::create([
                    'uuid'         => $patientHistory->uuid,
                    'lastname'     => $request->input('client_lastname'),
                    'firstname'    => $request->input('client_firstname'),
                    'middlename'   => $nullify($request->input('client_middlename')),
                    'suffix'       => $nullify($request->input('client_suffix')),
                    'relationship' => $nullify($request->input('relationship')),
                ]);
            }

            // --- Log ADDED action with all fields ---
            $formattedAmount = '₱' . number_format((float) $patientHistory->issued_amount, 2);
            $formattedBill   = $patientHistory->hospital_bill !== null
                ? '₱' . number_format((float) $patientHistory->hospital_bill, 2)
                : 'N/A';

            // Patient full name
            $patientName = $request->input('lastname') . ', ' . $request->input('firstname') .
                ($request->input('middlename') ? ' ' . $request->input('middlename') : '') .
                ($request->input('suffix')     ? ' ' . $request->input('suffix')     : '');

            // Client info (if different from patient)
            $clientInfo = 'N/A';
            if (!$request->boolean('is_checked')) {
                $clientInfo = $request->input('client_lastname') . ', ' . $request->input('client_firstname') .
                    ($request->input('client_middlename') ? ' ' . $request->input('client_middlename') : '') .
                    ($request->input('client_suffix')     ? ' ' . $request->input('client_suffix')     : '');
                if ($request->input('relationship')) {
                    $clientInfo .= ' (' . $request->input('relationship') . ')';
                }
            }

            // Sector names
            $sectorNames = 'N/A';
            if ($request->filled('sector_ids')) {
                $sectorIdsParsed = json_decode($request->input('sector_ids'), true) ?? [];
                if (!empty($sectorIdsParsed)) {
                    $resolvedNames = DB::table('sectors')
                        ->whereIn('id', $sectorIdsParsed)
                        ->pluck('sector')
                        ->toArray();
                    $sectorNames = implode(', ', $resolvedNames);
                }
            }

            $logParts = [
                "GL No: {$patientHistory->gl_no}",
                "Patient: {$patientName}",
                "Category: {$patientHistory->category}",
                "Partner: " . ($patientHistory->partner ?? 'N/A'),
            ];

            if ($patientHistory->category === 'HOSPITAL') {
                $logParts[] = "Hospital Bill: {$formattedBill}";
            }

            $logParts[] = "Issued Amount: {$formattedAmount}";
            $logParts[] = "Issued By: " . ($patientHistory->issued_by ?? 'N/A');
            $logParts[] = "Date: {$patientHistory->date_issued}";
            $logParts[] = "Client: {$clientInfo}";
            $logParts[] = "Sectors: {$sectorNames}";

            $this->logActivity(
                $request,
                'ADDED',
                $patientHistory->uuid,
                implode(' | ', $logParts)
            );

            return response()->json([
                'uuid'  => $patientHistory->uuid,
                'gl_no' => $patientHistory->gl_no
            ]);
        });
    }

    public function existingPatientList(Request $request)
    {
        $existingPatient = DB::table('patient_list')
            ->join('patient_history', 'patient_history.patient_id', '=', 'patient_list.patient_id')
            ->where(function ($query) use ($request) {
                $query->where('patient_list.lastname', $request->input('lastname'))
                    ->where('patient_list.firstname', $request->input('firstname'))
                    ->when($request->filled('middlename'), fn($q) => $q->where('patient_list.middlename', $request->input('middlename')))
                    ->when($request->filled('suffix'), fn($q) => $q->where('patient_list.suffix', $request->input('suffix')));
            })
            ->select(
                'patient_list.patient_id',
                'patient_list.lastname',
                'patient_list.firstname',
                'patient_list.middlename',
                'patient_list.suffix',
                DB::raw('GROUP_CONCAT(patient_history.uuid) as uuids'),
                DB::raw('GROUP_CONCAT(patient_history.gl_no) as gl_numbers')
            )
            ->groupBy('patient_list.patient_id', 'patient_list.lastname', 'patient_list.firstname', 'patient_list.middlename', 'patient_list.suffix')
            ->get();

        return response()->json($existingPatient);
    }

    public function getPatients()
    {
        $currentYear = now()->year;

        $patientList = DB::table('patient_list')
            ->join('patient_history', 'patient_history.patient_id', '=', 'patient_list.patient_id')
            ->select(
                'patient_list.patient_id',
                'patient_list.lastname',
                'patient_list.firstname',
                'patient_list.middlename',
                'patient_list.suffix',
                'patient_list.barangay',
                'patient_history.category',
                'patient_history.uuid',
                'patient_history.gl_no',
                'patient_history.date_issued'
            )
            ->whereYear('patient_history.date_issued', $currentYear)
            ->orderBy('patient_history.gl_no', 'desc')
            ->get();

        $patientIds = $patientList->pluck('patient_id')->unique()->toArray();

        $patientSectors = DB::table('user_sectors')
            ->whereIn('patient_id', $patientIds)
            ->select('patient_id', 'sector_id')
            ->get()
            ->groupBy('patient_id')
            ->map(function ($sectors) {
                return $sectors->pluck('sector_id')->toArray();
            });

        foreach ($patientList as $patient) {
            $patient->sector_ids = $patientSectors->get($patient->patient_id, []);
        }

        return response()->json($patientList);
    }

    public function search(Request $request)
    {
        $search = trim($request->query('q'));
        $currentYear = now()->year;

        $baseQuery = DB::table('patient_list')
            ->join('patient_history', 'patient_history.patient_id', '=', 'patient_list.patient_id')
            ->leftJoin('user_sectors', 'user_sectors.patient_id', '=', 'patient_list.patient_id')
            ->leftJoin('sectors', 'sectors.id', '=', 'user_sectors.sector_id')
            ->select(
                'patient_list.patient_id',
                'patient_list.lastname',
                'patient_list.firstname',
                'patient_list.middlename',
                'patient_list.suffix',
                'patient_list.barangay',
                'patient_history.category',
                'patient_history.uuid',
                'patient_history.gl_no',
                'patient_history.date_issued'
            )
            ->distinct();

        if (!$search) {
            $results = $baseQuery
                ->whereYear('patient_history.date_issued', $currentYear)
                ->orderBy('patient_history.gl_no', 'desc')
                ->get();

            return $this->attachSectorIds($results);
        }

        $isUuidSearch = strpos($search, 'MAMS-') === 0;
        $isPureNumber = is_numeric($search);
        $searchNoComma = str_replace(',', '', $search);

        $query = $baseQuery->where(function ($q) use ($search, $searchNoComma, $isPureNumber, $currentYear) {
            $q->whereRaw(
                "CONCAT_WS(' ', patient_list.lastname, patient_list.firstname, patient_list.middlename, patient_list.suffix) = ?",
                [$searchNoComma]
            )
                ->orWhereRaw(
                    "CONCAT_WS(' ', patient_list.lastname, patient_list.firstname, patient_list.middlename, patient_list.suffix) LIKE ?",
                    ["%{$searchNoComma}%"]
                )
                ->orWhere('patient_list.lastname', 'LIKE', "%{$search}%")
                ->orWhere('patient_list.firstname', 'LIKE', "%{$search}%")
                ->orWhere('patient_list.middlename', 'LIKE', "%{$search}%")
                ->orWhere('patient_list.suffix', 'LIKE', "%{$search}%");

            if ($isPureNumber) {
                $q->orWhere(function ($subQ) use ($search, $currentYear) {
                    $subQ->where('patient_history.gl_no', '=', $search)
                        ->whereYear('patient_history.date_issued', $currentYear);
                });
            } else {
                $q->orWhere('patient_list.barangay', 'LIKE', "%{$search}%")
                    ->orWhere('patient_history.category', 'LIKE', "%{$search}%")
                    ->orWhere('patient_history.uuid', 'LIKE', "%{$search}%")
                    ->orWhere('patient_history.date_issued', 'LIKE', "%{$search}%")
                    ->orWhere('sectors.sector', 'LIKE', "%{$search}%");
            }
        });

        $results = $query
            ->orderByRaw("
            CASE
                WHEN CONCAT_WS(' ', patient_list.lastname, patient_list.firstname, patient_list.middlename, patient_list.suffix) = ? THEN 1
                WHEN patient_history.gl_no = ? AND YEAR(patient_history.date_issued) = ? THEN 2
                WHEN CONCAT_WS(' ', patient_list.lastname, patient_list.firstname, patient_list.middlename, patient_list.suffix) LIKE ? THEN 3
                WHEN patient_history.category = ? THEN 4
                WHEN patient_history.uuid = ? THEN 5
                WHEN patient_history.date_issued LIKE ? THEN 6
                WHEN sectors.sector LIKE ? THEN 7
                ELSE 8
            END
        ", [$searchNoComma, $search, $currentYear, "%{$searchNoComma}%", $search, $search, "%{$search}%", "%{$search}%"])
            ->orderBy('patient_list.lastname')
            ->orderBy('patient_list.firstname')
            ->orderBy('patient_history.gl_no', 'desc')
            ->get();

        return $this->attachSectorIds($results);
    }

    public function getPatientDetails($identifier)
    {
        $isUuid = strpos($identifier, 'MAMS-') === 0;

        $query = DB::table('patient_history')
            ->join('patient_list', 'patient_list.patient_id', '=', 'patient_history.patient_id')
            ->leftJoin('client_name', 'client_name.uuid', '=', 'patient_history.uuid');

        if ($isUuid) {
            $query->where('patient_history.uuid', $identifier);
        } else {
            $query->where('patient_history.gl_no', $identifier);
        }

        $row = $query->select(
            'patient_history.uuid',
            'patient_history.gl_no',
            'patient_history.category',
            'patient_history.date_issued',
            'patient_list.patient_id',
            'patient_list.lastname as patient_lastname',
            'patient_list.firstname as patient_firstname',
            'patient_list.middlename as patient_middlename',
            'patient_list.suffix as patient_suffix',
            'patient_list.birthdate',
            'patient_list.sex',
            'patient_list.preference',
            'patient_list.province',
            'patient_list.city',
            'patient_list.barangay',
            'patient_list.house_address',
            'patient_list.phone_number',
            'patient_history.partner',
            'patient_history.hospital_bill',
            'patient_history.issued_amount',
            'patient_history.issued_by',
            'client_name.lastname as client_lastname',
            'client_name.firstname as client_firstname',
            'client_name.middlename as client_middlename',
            'client_name.suffix as client_suffix',
            'client_name.relationship'
        )
            ->first();

        if ($row) {
            $row->sector_ids = DB::table('user_sectors')
                ->where('patient_id', $row->patient_id)
                ->pluck('sector_id')
                ->toArray();
        }

        return response()->json($row);
    }

    public function getPatientHistory($identifier)
    {
        $isUuid = strpos($identifier, 'MAMS-') === 0;

        $query = DB::table('patient_history');

        if ($isUuid) {
            $query->where('uuid', $identifier);
        } else {
            $query->where('gl_no', $identifier);
        }

        $current = $query->select('patient_id', 'date_issued')->first();

        if (!$current) {
            return response()->json(['message' => 'Record not found'], 404);
        }

        $cooldownDays = $this->getEligibilityCooldownDays();

        $eligibilityDate = Carbon::parse($current->date_issued)
            ->addDays($cooldownDays);

        $history = DB::table('patient_history')
            ->where('patient_id', $current->patient_id)
            ->select(
                'uuid',
                'gl_no',
                'category',
                'date_issued',
                'issued_by',
                'issued_amount'
            )
            ->orderBy('date_issued', 'desc')
            ->get();

        return response()->json([
            'eligibility_date' => $eligibilityDate->toDateString(),
            'history'          => $history
        ]);
    }

    public function updatePatientDetails(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $nullify = fn($value) => ($value === '' || $value === 'null' || strtolower($value) === 'n/a') ? null : $value;

            $identifier = $request->input('identifier');
            $isUuid = strpos($identifier, 'MAMS-') === 0;

            $phoneNumber = $this->normalizePhoneNumber($request->input('phone_number'));

            if ($request->filled('phone_number') && $phoneNumber === null) {
                return response()->json([
                    'error' => 'Invalid phone number format. Must be 11 digits starting with 09 or +63'
                ], 422);
            }

            if ($isUuid) {
                $history = PatientHistory::where('uuid', $identifier)->firstOrFail();
            } else {
                $history = PatientHistory::where('gl_no', $identifier)->firstOrFail();
            }

            // Snapshot of original history values for change tracking
            $originalHistory = $history->toArray();
            $originalPatient = null;

            // CASE 1: Update ONLY transaction details
            if ($request->has('update_transaction_only') && $request->input('update_transaction_only') == '1') {
                $hospitalBillInput = $request->input('hospital_bill');
                $hospitalBill = (is_null($hospitalBillInput) || $hospitalBillInput === '' ||
                    strtolower($hospitalBillInput) === 'null' ||
                    strtolower($hospitalBillInput) === 'n/a')
                    ? null
                    : (float) $hospitalBillInput;

                $updateData = [
                    'category'      => $request->input('category'),
                    'partner'       => $nullify($request->input('partner')),
                    'hospital_bill' => $hospitalBill,
                    'issued_amount' => $nullify($request->input('issued_amount')),
                ];

                if ($request->has('gl_no')) {
                    $updateData['gl_no'] = $request->input('gl_no');
                }

                if ($request->has('issued_by')) {
                    $updateData['issued_by'] = $nullify($request->input('issued_by'));
                }

                if ($request->has('date_issued')) {
                    $updateData['date_issued'] = $nullify($request->input('date_issued'));
                }

                $history->update($updateData);

                $isChecked = $request->boolean('is_checked');

                // --- Snapshot old client BEFORE syncing ---
                $oldClient = ClientName::where('uuid', $history->uuid)->first();

                if (!$isChecked) {
                    ClientName::updateOrCreate(
                        ['uuid' => $history->uuid],
                        [
                            'lastname'     => $request->input('client_lastname'),
                            'firstname'    => $request->input('client_firstname'),
                            'middlename'   => $nullify($request->input('client_middlename')),
                            'suffix'       => $nullify($request->input('client_suffix')),
                            'relationship' => $nullify($request->input('relationship')),
                        ]
                    );
                } else {
                    ClientName::where('uuid', $history->uuid)->delete();
                }

                // --- Snapshot old sector IDs BEFORE syncing (only when sector_ids was sent) ---
                $sectorIdsProvided = $request->has('sector_ids');
                $oldSectorIds = $sectorIdsProvided ? $this->getCurrentSectorIds($history->patient_id) : [];

                if ($sectorIdsProvided) {
                    $this->syncPatientSectors($history->patient_id, $request->input('sector_ids'));
                }

                // --- Build changed fields list ---
                $normalize = fn($v) => (is_null($v) || $v === '') ? null : $v;
                $formatVal = function ($v, $k) {
                    if (in_array($k, ['issued_amount', 'hospital_bill']) && is_numeric($v) && $v !== null) {
                        return '₱' . number_format((float)$v, 2);
                    }
                    return $v ?? 'N/A';
                };

                $changedFields = [];

                // Diff transaction fields
                foreach ($updateData as $key => $newVal) {
                    $oldVal = $originalHistory[$key] ?? null;
                    if ($normalize($oldVal) != $normalize($newVal)) {
                        $changedFields[] = "{$key}: '{$formatVal($oldVal, $key)}' → '{$formatVal($newVal, $key)}'";
                    }
                }

                // --- Detect client name changes ---
                $oldHasClient = $oldClient !== null;
                $newHasClient = !$isChecked;

                if ($oldHasClient !== $newHasClient) {
                    if ($newHasClient) {
                        $newFullName = $request->input('client_lastname') . ', ' .
                            $request->input('client_firstname') .
                            ($request->input('client_middlename') ? ' ' . $request->input('client_middlename') : '') .
                            ($request->input('client_suffix') ? ' ' . $request->input('client_suffix') : '');
                        $changedFields[] = "client: 'None' → '{$newFullName}'";
                    } else {
                        $oldFullName = $oldClient->lastname . ', ' .
                            $oldClient->firstname .
                            ($oldClient->middlename ? ' ' . $oldClient->middlename : '') .
                            ($oldClient->suffix ? ' ' . $oldClient->suffix : '');
                        $changedFields[] = "client: '{$oldFullName}' → 'None'";
                    }
                } elseif ($oldHasClient && $newHasClient) {
                    $clientFieldMap = [
                        'lastname'     => 'client_lastname',
                        'firstname'    => 'client_firstname',
                        'middlename'   => 'client_middlename',
                        'suffix'       => 'client_suffix',
                        'relationship' => 'relationship',
                    ];

                    foreach ($clientFieldMap as $modelField => $requestField) {
                        $oldVal = $oldClient->$modelField;
                        $newVal = $nullify($request->input($requestField));
                        if ($normalize($oldVal) != $normalize($newVal)) {
                            $changedFields[] = "client_{$modelField}: '" . ($oldVal ?? 'N/A') . "' → '" . ($newVal ?? 'N/A') . "'";
                        }
                    }
                }

                // --- Detect sector changes (only when sector_ids was sent) ---
                if ($sectorIdsProvided) {
                    $newSectorIds = $this->parseNewSectorIds($request->input('sector_ids'));
                    $sectorLog = $this->buildSectorChangeLog($oldSectorIds, $newSectorIds);
                    if ($sectorLog !== null) {
                        $changedFields[] = $sectorLog;
                    }
                }

                $changesStr = count($changedFields) > 0
                    ? implode(' | ', $changedFields)
                    : 'No changes detected';
                $this->logActivity($request, 'EDIT', $history->uuid, $changesStr);

                return response()->json(['success' => true]);
            }

            // CASE 2: Create new patient
            if ($request->has('force_new_patient') && $request->input('force_new_patient') == '1') {
                $newPatient = PatientList::create([
                    'lastname'      => $request->input('lastname'),
                    'firstname'     => $request->input('firstname'),
                    'middlename'    => $nullify($request->input('middlename')),
                    'suffix'        => $nullify($request->input('suffix')),
                    'birthdate'     => $nullify($request->input('birthdate')),
                    'sex'           => $nullify($request->input('sex')),
                    'preference'    => $nullify($request->input('preference')),
                    'province'      => $nullify($request->input('province')),
                    'city'          => $nullify($request->input('city')),
                    'barangay'      => $nullify($request->input('barangay')),
                    'house_address' => $nullify($request->input('house_address')),
                    'phone_number'  => $phoneNumber,
                ]);

                $history->patient_id = $newPatient->patient_id;
            }
            // CASE 3: Use existing patient
            elseif ($request->has('use_existing_patient_id')) {
                $history->patient_id = $request->input('use_existing_patient_id');
            }
            // CASE 4: Update existing patient — snapshot BEFORE update, then track changes
            else {
                $patient = PatientList::where('patient_id', $history->patient_id)->firstOrFail();
                $originalPatient = $patient->toArray();

                $patient->update([
                    'lastname'      => $request->input('lastname'),
                    'firstname'     => $request->input('firstname'),
                    'middlename'    => $nullify($request->input('middlename')),
                    'suffix'        => $nullify($request->input('suffix')),
                    'birthdate'     => $nullify($request->input('birthdate')),
                    'sex'           => $nullify($request->input('sex')),
                    'preference'    => $nullify($request->input('preference')),
                    'province'      => $nullify($request->input('province')),
                    'city'          => $nullify($request->input('city')),
                    'barangay'      => $nullify($request->input('barangay')),
                    'house_address' => $nullify($request->input('house_address')),
                    'phone_number'  => $phoneNumber,
                ]);
            }

            $hospitalBillInput = $request->input('hospital_bill');
            $hospitalBill = (is_null($hospitalBillInput) || $hospitalBillInput === '' ||
                strtolower($hospitalBillInput) === 'null' ||
                strtolower($hospitalBillInput) === 'n/a')
                ? null
                : (float) $hospitalBillInput;

            $updateData = [
                'category'      => $request->input('category'),
                'partner'       => $nullify($request->input('partner')),
                'hospital_bill' => $hospitalBill,
                'issued_amount' => $nullify($request->input('issued_amount')),
                'issued_by'     => $nullify($request->input('issued_by')),
            ];

            if ($request->has('date_issued')) {
                $updateData['date_issued'] = $nullify($request->input('date_issued'));
            }

            $history->update($updateData);

            $isChecked = $request->boolean('is_checked');

            if (!$isChecked) {
                ClientName::updateOrCreate(
                    ['uuid' => $history->uuid],
                    [
                        'lastname'     => $request->input('client_lastname'),
                        'firstname'    => $request->input('client_firstname'),
                        'middlename'   => $nullify($request->input('client_middlename')),
                        'suffix'       => $nullify($request->input('client_suffix')),
                        'relationship' => $nullify($request->input('relationship')),
                    ]
                );
            } else {
                ClientName::where('uuid', $history->uuid)->delete();
            }

            // --- Snapshot old sector IDs BEFORE syncing ---
            $oldSectorIds = $this->getCurrentSectorIds($history->patient_id);

            if ($request->has('sector_ids')) {
                $this->syncPatientSectors($history->patient_id, $request->input('sector_ids'));
            }

            // --- Build changed fields list ---
            $changedFields = [];
            $normalize = fn($v) => (is_null($v) || $v === '') ? null : $v;
            $formatVal = function ($v, $k) {
                if (in_array($k, ['issued_amount', 'hospital_bill']) && is_numeric($v) && $v !== null) {
                    return '₱' . number_format((float)$v, 2);
                }
                return $v ?? 'N/A';
            };

            // Diff history/transaction fields
            foreach ($updateData as $key => $newVal) {
                $oldVal = $originalHistory[$key] ?? null;
                if ($normalize($oldVal) != $normalize($newVal)) {
                    $changedFields[] = "{$key}: '{$formatVal($oldVal,$key)}' → '{$formatVal($newVal,$key)}'";
                }
            }

            // Diff patient info fields (CASE 4 only)
            if ($originalPatient !== null) {
                $newPatientData = [
                    'lastname'      => $request->input('lastname'),
                    'firstname'     => $request->input('firstname'),
                    'middlename'    => $nullify($request->input('middlename')),
                    'suffix'        => $nullify($request->input('suffix')),
                    'birthdate'     => $nullify($request->input('birthdate')),
                    'sex'           => $nullify($request->input('sex')),
                    'preference'    => $nullify($request->input('preference')),
                    'barangay'      => $nullify($request->input('barangay')),
                    'house_address' => $nullify($request->input('house_address')),
                    'phone_number'  => $phoneNumber,
                ];

                foreach ($newPatientData as $key => $newVal) {
                    $oldVal = $originalPatient[$key] ?? null;

                    if ($key === 'birthdate' && $normalize($oldVal) !== null && $normalize($newVal) !== null) {
                        $oldVal = \Carbon\Carbon::parse($oldVal)->format('Y-m-d');
                        $newVal = \Carbon\Carbon::parse($newVal)->format('Y-m-d');
                    }

                    if ($normalize($oldVal) != $normalize($newVal)) {
                        $displayOld = $originalPatient[$key] ?? 'N/A';
                        $displayNew = $newPatientData[$key] ?? 'N/A';
                        $changedFields[] = "{$key}: '{$displayOld}' → '{$displayNew}'";
                    }
                }
            }

            // --- Detect sector changes ---
            $newSectorIds = $this->parseNewSectorIds($request->input('sector_ids'));
            $sectorLog = $this->buildSectorChangeLog($oldSectorIds, $newSectorIds);
            if ($sectorLog !== null) {
                $changedFields[] = $sectorLog;
            }

            $changesStr = count($changedFields) > 0
                ? implode(' | ', $changedFields)
                : 'No changes detected';
            $this->logActivity($request, 'EDIT', $history->uuid, $changesStr);

            return response()->json(['success' => true]);
        });
    }

    public function updatePatientName(Request $request)
    {
        $nullify = fn($value) => ($value === '' || $value === 'null' || strtolower($value) === 'n/a') ? null : $value;

        $phoneNumber = $this->normalizePhoneNumber($request->input('phone_number'));

        if ($request->filled('phone_number') && $phoneNumber === null) {
            return response()->json([
                'error' => 'Invalid phone number format. Must be 11 digits starting with 09 or +63'
            ], 422);
        }

        $patient = PatientList::where('patient_id', $request->input('patient_id'))->firstOrFail();

        $patient->update([
            'lastname'      => $request->input('lastname'),
            'firstname'     => $request->input('firstname'),
            'middlename'    => $nullify($request->input('middlename')),
            'suffix'        => $nullify($request->input('suffix')),
            'birthdate'     => $nullify($request->input('birthdate')),
            'sex'           => $nullify($request->input('sex')),
            'preference'    => $nullify($request->input('preference')),
            'province'      => $nullify($request->input('province')),
            'city'          => $nullify($request->input('city')),
            'barangay'      => $nullify($request->input('barangay')),
            'house_address' => $nullify($request->input('house_address')),
            'phone_number'  => $phoneNumber,
        ]);

        return response()->json(['success' => true]);
    }

    public function deleteLetter(Request $request, $identifier)
    {
        $isUuid = strpos($identifier, 'MAMS-') === 0;

        if ($isUuid) {
            $history = PatientHistory::where('uuid', $identifier)->firstOrFail();
        } else {
            $history = PatientHistory::where('gl_no', $identifier)->firstOrFail();
        }

        $uuid = $history->uuid;
        $gl_no = $history->gl_no;

        $history->delete();

        // Log DELETE action
        $this->logActivity(
            $request,
            'DELETE',
            $uuid,
            "GL No: {$gl_no} has been deleted"
        );

        return response()->json(['success' => true]);
    }

    public function checkEligibility(Request $request)
    {
        $patient = DB::table('patient_list')
            ->where('lastname', $request->input('lastname'))
            ->where('firstname', $request->input('firstname'))
            ->when($request->filled('middlename'), fn($q) => $q->where('middlename', $request->input('middlename')))
            ->when($request->filled('suffix'), fn($q) => $q->where('suffix', $request->input('suffix')))
            ->first();

        if (!$patient) {
            return response()->json(['eligible' => true]);
        }

        $latestRecord = DB::table('patient_history')
            ->where('patient_id', $patient->patient_id)
            ->orderBy('date_issued', 'desc')
            ->orderBy('gl_no', 'desc')
            ->first();

        if (!$latestRecord) {
            return response()->json(['eligible' => true]);
        }

        $cooldownDays = $this->getEligibilityCooldownDays();

        $eligibilityDate = Carbon::parse($latestRecord->date_issued)
            ->startOfDay()
            ->addDays($cooldownDays);

        $today = Carbon::today()->startOfDay();

        if ($today->greaterThanOrEqualTo($eligibilityDate)) {
            return response()->json(['eligible' => true]);
        }

        $daysRemaining = $today->diffInDays($eligibilityDate);

        return response()->json([
            'eligible'         => false,
            'uuid'             => $latestRecord->uuid,
            'last_gl_no'       => $latestRecord->gl_no,
            'last_issued_at'   => $latestRecord->date_issued,
            'eligibility_date' => $eligibilityDate->toDateString(),
            'days_remaining'   => $daysRemaining
        ]);
    }

    public function checkEligibilityById(Request $request)
    {
        $patientId = $request->input('patient_id');

        if (!$patientId) {
            return response()->json(['eligible' => true]);
        }

        $latestRecord = DB::table('patient_history')
            ->where('patient_id', $patientId)
            ->orderBy('date_issued', 'desc')
            ->orderBy('gl_no', 'desc')
            ->first();

        if (!$latestRecord) {
            return response()->json(['eligible' => true]);
        }

        $cooldownDays = $this->getEligibilityCooldownDays();

        $eligibilityDate = Carbon::parse($latestRecord->date_issued)
            ->startOfDay()
            ->addDays($cooldownDays);

        $today = Carbon::today()->startOfDay();

        if ($today->greaterThanOrEqualTo($eligibilityDate)) {
            return response()->json(['eligible' => true]);
        }

        $daysRemaining = $today->diffInDays($eligibilityDate);

        return response()->json([
            'eligible'         => false,
            'uuid'             => $latestRecord->uuid,
            'last_gl_no'       => $latestRecord->gl_no,
            'last_issued_at'   => $latestRecord->date_issued,
            'eligibility_date' => $eligibilityDate->toDateString(),
            'days_remaining'   => $daysRemaining
        ]);
    }

    public function getAllPatientsWithEligibility()
    {
        $cooldownDays = $this->getEligibilityCooldownDays();

        // FIX: Use uuid-based subquery to guarantee exactly ONE row per patient,
        // breaking date ties with gl_no DESC so the highest gl_no wins.
        $patients = DB::table('patient_list')
            ->leftJoin('patient_history as ph_latest', function ($join) {
                $join->on('patient_list.patient_id', '=', 'ph_latest.patient_id')
                    ->whereRaw('ph_latest.uuid = (
                        SELECT ph2.uuid
                        FROM patient_history ph2
                        WHERE ph2.patient_id = patient_list.patient_id
                        ORDER BY ph2.date_issued DESC, ph2.gl_no DESC
                        LIMIT 1
                    )');
            })
            ->select(
                'patient_list.patient_id',
                'patient_list.lastname',
                'patient_list.firstname',
                'patient_list.middlename',
                'patient_list.suffix',
                'patient_list.birthdate',
                'patient_list.sex',
                'patient_list.preference',
                'patient_list.province',
                'patient_list.city',
                'patient_list.barangay',
                'patient_list.house_address',
                'patient_list.phone_number',
                'ph_latest.uuid',
                'ph_latest.gl_no',
                'ph_latest.category as last_category',
                'ph_latest.date_issued as last_issued_at'
            )
            ->get();

        $today = Carbon::today()->startOfDay();

        // FIX: Use uuid-based subquery per category to guarantee exactly ONE row
        // per patient+category combination, again breaking ties with gl_no DESC.
        $latestPerCategory = DB::table('patient_history as ph1')
            ->whereRaw('ph1.uuid = (
                SELECT ph2.uuid
                FROM patient_history ph2
                WHERE ph2.patient_id = ph1.patient_id
                  AND ph2.category = ph1.category
                ORDER BY ph2.date_issued DESC, ph2.gl_no DESC
                LIMIT 1
            )')
            ->select('ph1.patient_id', 'ph1.category', 'ph1.date_issued', 'ph1.gl_no')
            ->get()
            ->groupBy('patient_id');

        $allSectorMappings = DB::table('user_sectors')
            ->select('patient_id', 'sector_id')
            ->get()
            ->groupBy('patient_id');

        $patientsWithEligibility = $patients->map(function ($patient) use ($today, $cooldownDays, $allSectorMappings, $latestPerCategory) {
            $sectorIds = isset($allSectorMappings[$patient->patient_id])
                ? $allSectorMappings[$patient->patient_id]->pluck('sector_id')->toArray()
                : [];

            // Build per-category eligibility map
            $categoryEligibility = [];
            if (isset($latestPerCategory[$patient->patient_id])) {
                foreach ($latestPerCategory[$patient->patient_id] as $record) {
                    $eligDate = Carbon::parse($record->date_issued)->startOfDay()->addDays($cooldownDays);
                    $eligible = $today->greaterThanOrEqualTo($eligDate);
                    $categoryEligibility[$record->category] = [
                        'eligible'         => $eligible,
                        'eligibility_date' => $eligDate->toDateString(),
                        'days_remaining'   => $eligible ? null : $today->diffInDays($eligDate),
                        'gl_no'            => $record->gl_no,
                        'last_issued_at'   => $record->date_issued,
                    ];
                }
            }

            if (!$patient->last_issued_at) {
                return array_merge((array)$patient, [
                    'eligible'             => true,
                    'eligibility_date'     => null,
                    'days_remaining'       => null,
                    'last_category'        => null,
                    'sector_ids'           => $sectorIds,
                    'category_eligibility' => $categoryEligibility,
                ]);
            }

            $eligibilityDate = Carbon::parse($patient->last_issued_at)->startOfDay()->addDays($cooldownDays);
            $eligible = $today->greaterThanOrEqualTo($eligibilityDate);
            $daysRemaining = $eligible ? null : max(0, $today->diffInDays($eligibilityDate));

            return array_merge((array)$patient, [
                'eligible'             => $eligible,
                'eligibility_date'     => $eligibilityDate->toDateString(),
                'days_remaining'       => $daysRemaining,
                'last_category'        => $patient->last_category,
                'sector_ids'           => $sectorIds,
                'category_eligibility' => $categoryEligibility,
            ]);
        });

        return response()->json($patientsWithEligibility);
    }

    /**
     * Returns all categories (excluding the one currently being issued) where
     * the patient still has an active cooldown (i.e., not yet eligible).
     * One entry per category showing the latest GL for that category.
     * Used by the frontend to warn the user before issuing a cross-category GL.
     *
     * Query param: ?exclude_category=MEDICINE  (the category being issued right now)
     */
    public function getPreviousCategories(Request $request, $patientId)
    {
        $cooldownDays    = $this->getEligibilityCooldownDays();
        $today           = Carbon::today()->startOfDay();
        $excludeCategory = $request->query('exclude_category');

        // FIX: Use uuid-based subquery per category to guarantee exactly ONE row
        // per category, breaking date ties with gl_no DESC.
        $query = DB::table('patient_history as ph1')
            ->where('ph1.patient_id', $patientId)
            ->whereRaw('ph1.uuid = (
                SELECT ph2.uuid
                FROM patient_history ph2
                WHERE ph2.patient_id = ph1.patient_id
                  AND ph2.category = ph1.category
                ORDER BY ph2.date_issued DESC, ph2.gl_no DESC
                LIMIT 1
            )')
            ->select(
                'ph1.category',
                'ph1.gl_no',
                'ph1.date_issued as last_issued_at'
            );

        if ($excludeCategory) {
            $query->where('ph1.category', '!=', $excludeCategory);
        }

        $latestPerCategory = $query->get();

        $nonEligibleCategories = $latestPerCategory
            ->map(function ($record) use ($today, $cooldownDays) {
                $eligibilityDate = Carbon::parse($record->last_issued_at)
                    ->startOfDay()
                    ->addDays($cooldownDays);

                if ($today->greaterThanOrEqualTo($eligibilityDate)) {
                    return null;
                }

                return [
                    'category'         => $record->category,
                    'gl_no'            => $record->gl_no,
                    'issued_at'        => $record->last_issued_at,
                    'eligibility_date' => $eligibilityDate->toDateString(),
                    'days_remaining'   => $today->diffInDays($eligibilityDate),
                ];
            })
            ->filter()
            ->values();

        return response()->json($nonEligibleCategories);
    }
}
