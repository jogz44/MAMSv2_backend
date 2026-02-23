<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class GeneralSummaryController extends Controller
{
    public function filterByDate(Request $request)
    {
        $query = DB::table('patient_history')
            ->join('patient_list', 'patient_history.patient_id', '=', 'patient_list.patient_id')
            ->leftJoin('client_name', 'patient_history.uuid', '=', 'client_name.uuid') // Changed to use UUID
            ->select(
                'patient_history.uuid',
                'patient_history.gl_no',
                'patient_history.patient_id',
                'patient_history.category',
                'patient_history.partner',
                'patient_history.hospital_bill',
                'patient_history.issued_amount',
                'patient_history.issued_by',
                'patient_history.date_issued',
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
                'client_name.lastname as client_lastname',
                'client_name.firstname as client_firstname',
                'client_name.middlename as client_middlename',
                'client_name.suffix as client_suffix'
            );

        // Check if we have date filter
        if ($request->has('from') && $request->has('to')) {
            // Date range
            $from = Carbon::createFromFormat('d/m/Y', $request->input('from'))->startOfDay();
            $to = Carbon::createFromFormat('d/m/Y', $request->input('to'))->endOfDay();
            
            $query->whereBetween('patient_history.date_issued', [$from, $to]);
        } elseif ($request->has('date')) {
            // Single date
            $date = Carbon::createFromFormat('d/m/Y', $request->input('date'));
            $query->whereDate('patient_history.date_issued', $date);
        }

        $results = $query->orderBy('patient_history.date_issued', 'desc')->get();

        // Get all unique patient IDs from results
        $patientIds = $results->pluck('patient_id')->unique()->toArray();
        $patientSectors = DB::table('user_sectors')
            ->whereIn('patient_id', $patientIds)
            ->select('patient_id', 'sector_id')
            ->get()
            ->groupBy('patient_id')
            ->map(function ($sectors) {
                return $sectors->pluck('sector_id')->toArray();
            });

        // Attach sector_ids to each result
        foreach ($results as $result) {
            $result->sector_ids = $patientSectors->get($result->patient_id, []);
        }

        return response()->json($results);
    }
}