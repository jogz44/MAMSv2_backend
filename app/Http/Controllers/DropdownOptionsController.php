<?php

namespace App\Http\Controllers;

use App\Models\Preferences;
use App\Models\Partners;
use App\Models\Sectors;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DropdownOptionsController extends Controller
{
    /**
     * Log an activity action for dropdown changes
     */
    private function logActivity(Request $request, string $action, string $changes = ''): void
    {
        ActivityLog::create([
            'performed_by' => $request->input('performed_by') ?? 'Unknown',
            'action'       => $action,
            'target'         => 'DROPDOWN',
            'changes'      => $changes,
        ]);
    }

    /**
     * Restore default sectors when none are active.
     */
    private function ensureDefaultSectors(): void
    {
        if (Sectors::where('is_active', true)->exists()) {
            return;
        }

        $defaults = ['SENIOR', 'PWD', 'SOLO PARENT'];

        foreach ($defaults as $sectorName) {
            $existing = Sectors::whereRaw('UPPER(sector) = ?', [strtoupper($sectorName)])->first();

            if ($existing) {
                if (!$existing->is_active) {
                    $existing->is_active = true;
                    $existing->save();
                }
                continue;
            }

            Sectors::create([
                'sector'    => $sectorName,
                'is_active' => true,
            ]);
        }
    }

    // ===================== GET ALL OPTIONS =====================

    public function getPreferenceOptions()
    {
        try {
            $options = Preferences::where('is_active', true)->orderBy('preference')->get();
            return response()->json($options);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch preference options'], 500);
        }
    }

    public function getPartnerOptions()
    {
        try {
            $options = Partners::where('is_active', true)->orderBy('category')->orderBy('partner')->get();
            return response()->json($options);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch partner options'], 500);
        }
    }

    public function getSectorOptions()
    {
        try {
            $this->ensureDefaultSectors();
            $options = Sectors::where('is_active', true)->orderBy('sector')->get();
            return response()->json($options);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch sector options'], 500);
        }
    }

    public function getAllOptions()
    {
        try {
            $this->ensureDefaultSectors();
            $preferences = Preferences::where('is_active', true)->orderBy('preference')->get();
            $partners = Partners::where('is_active', true)->orderBy('category')->orderBy('partner')->get();
            $sectors = Sectors::where('is_active', true)->orderBy('sector')->get();

            return response()->json([
                'preferences' => $preferences,
                'partners'    => $partners,
                'sectors'     => $sectors
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch options'], 500);
        }
    }

    // Returns ALL sectors regardless of is_active (for displaying existing inactive selections)
    public function getAllSectors()
    {
        try {
            $options = Sectors::orderBy('sector')->get();
            return response()->json($options);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch all sectors'], 500);
        }
    }

    // Returns ALL partners regardless of is_active (for displaying existing inactive selections)
    public function getAllPartners()
    {
        try {
            $options = Partners::orderBy('category')->orderBy('partner')->get();
            return response()->json($options);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch all partners'], 500);
        }
    }

    public function getAllPreferences()
    {
        try {
            $options = Preferences::orderBy('preference')->get();
            return response()->json($options);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch all preferences'], 500);
        }
    }

    // ===================== ADD OPTIONS =====================

    public function addPreferenceOption(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'value' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $existing = Preferences::where('preference', trim($request->value))->first();
        if ($existing) {
            if ($existing->is_active) {
                return response()->json(['error' => 'This option already exists and is active'], 422);
            }
            $existing->is_active = true;
            $existing->save();

            $this->logActivity($request, 'DROPDOWN REACTIVATED', "Table: Preference | Option: '{$existing->preference}' reactivated");

            return response()->json(['message' => 'Option reactivated successfully', 'option' => $existing], 200);
        }

        $option = Preferences::create(['preference' => trim($request->value), 'is_active' => true]);

        $this->logActivity($request, 'DROPDOWN ADDED', "Table: Preference | Option: '{$option->preference}' added");

        return response()->json(['message' => 'Preference option added successfully', 'option' => $option], 201);
    }

    public function addPartnerOption(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category' => 'required|in:MEDICINE,LABORATORY,HOSPITAL',
            'value'    => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $existing = Partners::where('category', $request->category)
            ->where('partner', trim($request->value))
            ->first();

        if ($existing) {
            if ($existing->is_active) {
                return response()->json(['error' => 'This partner already exists and is active in the selected category'], 422);
            }
            $existing->is_active = true;
            $existing->save();

            $this->logActivity($request, 'DROPDOWN REACTIVATED', "Table: Partner | Category: {$existing->category} | Option: '{$existing->partner}' reactivated");

            return response()->json(['message' => 'Partner reactivated successfully', 'option' => $existing], 200);
        }

        $option = Partners::create(['category' => $request->category, 'partner' => trim($request->value), 'is_active' => true]);

        $this->logActivity($request, 'DROPDOWN ADDED', "Table: Partner | Category: {$option->category} | Option: '{$option->partner}' added");

        return response()->json(['message' => 'Partner option added successfully', 'option' => $option], 201);
    }

    public function addSectorOption(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'value' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $existing = Sectors::where('sector', trim($request->value))->first();
        if ($existing) {
            if ($existing->is_active) {
                return response()->json(['error' => 'This option already exists and is active'], 422);
            }
            $existing->is_active = true;
            $existing->save();

            $this->logActivity($request, 'DROPDOWN REACTIVATED', "Table: Sector | Option: '{$existing->sector}' reactivated");

            return response()->json(['message' => 'Option reactivated successfully', 'option' => $existing], 200);
        }

        $option = Sectors::create(['sector' => trim($request->value), 'is_active' => true]);

        $this->logActivity($request, 'DROPDOWN ADDED', "Table: Sector | Option: '{$option->sector}' added");

        return response()->json(['message' => 'Sector option added successfully', 'option' => $option], 201);
    }

    // ===================== TOGGLE ACTIVE =====================

    public function togglePreferenceOption(Request $request, $id)
    {
        try {
            $option = Preferences::findOrFail($id);
            $oldStatus = $option->is_active ? 'Active' : 'Inactive';
            $option->is_active = !$option->is_active;
            $option->save();
            $newStatus = $option->is_active ? 'Active' : 'Inactive';

            $this->logActivity(
                $request,
                'DROPDOWN TOGGLED',
                "Table: Preference | Option: '{$option->preference}' | Status: '{$oldStatus}' → '{$newStatus}'"
            );

            return response()->json([
                'message' => 'Preference option ' . ($option->is_active ? 'activated' : 'deactivated') . ' successfully',
                'option'  => $option
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Option not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update preference option'], 500);
        }
    }

    public function togglePartnerOption(Request $request, $id)
    {
        try {
            $option = Partners::findOrFail($id);
            $oldStatus = $option->is_active ? 'Active' : 'Inactive';
            $option->is_active = !$option->is_active;
            $option->save();
            $newStatus = $option->is_active ? 'Active' : 'Inactive';

            $this->logActivity(
                $request,
                'DROPDOWN TOGGLED',
                "Table: Partner | Category: {$option->category} | Option: '{$option->partner}' | Status: '{$oldStatus}' → '{$newStatus}'"
            );

            return response()->json([
                'message' => 'Partner option ' . ($option->is_active ? 'activated' : 'deactivated') . ' successfully',
                'option'  => $option
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Option not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update partner option'], 500);
        }
    }

    public function toggleSectorOption(Request $request, $id)
    {
        try {
            $option = Sectors::findOrFail($id);
            $oldStatus = $option->is_active ? 'Active' : 'Inactive';
            $option->is_active = !$option->is_active;
            $option->save();
            $newStatus = $option->is_active ? 'Active' : 'Inactive';

            $this->logActivity(
                $request,
                'DROPDOWN TOGGLED',
                "Table: Sector | Option: '{$option->sector}' | Status: '{$oldStatus}' → '{$newStatus}'"
            );

            return response()->json([
                'message' => 'Sector option ' . ($option->is_active ? 'activated' : 'deactivated') . ' successfully',
                'option'  => $option
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Option not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update sector option'], 500);
        }
    }
}
