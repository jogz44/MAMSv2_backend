<?php

namespace App\Http\Controllers;

use App\Models\PatientHistory;
use App\Models\PatientList;
use App\Models\YearlyBudget;
use App\Models\SupplementaryBonus;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function getTotalPatientsAndAmountReleased()
    {
        $year = Carbon::now()->year;

        $totalPatients = PatientHistory::whereYear('date_issued', $year)->count();
        $totalAmount = PatientHistory::whereYear('date_issued', $year)->sum('issued_amount');

        return response()->json([
            'totalPatients' => $totalPatients,
            'totalAmount' => $totalAmount,
        ]);
    }

    public function getCategoryData()
    {
        $year = Carbon::now()->year;

        $categories = ['MEDICINE', 'LABORATORY', 'HOSPITAL'];
        $data = [];

        foreach ($categories as $category) {
            $totalBudget = YearlyBudget::where('year', $year)->sum(strtolower($category) . '_budget')
                + SupplementaryBonus::where('year', $year)->sum(strtolower($category) . '_supplementary_bonus');

            $totalPatients = PatientHistory::where('category', $category)
                ->whereYear('date_issued', $year)
                ->count();

            $totalReleased = PatientHistory::where('category', $category)
                ->whereYear('date_issued', $year)
                ->sum('issued_amount');

            $invoiceAmount = PatientHistory::where('category', $category)
                ->whereYear('date_issued', $year)
                ->sum('invoice_amount');

            // Add back only the released-over-invoice excess for rows with a real invoice.
            // This keeps totalReleased deducted from remaining while returning valid excess.
            $returnedToBalance = PatientHistory::where('category', $category)
                ->whereYear('date_issued', $year)
                ->whereNotNull('invoice_amount')
                ->where('invoice_amount', '>', 0)
                ->whereColumn('issued_amount', '>', 'invoice_amount')
                ->sum(DB::raw('issued_amount - invoice_amount'));

            $remainingBal = $totalBudget - $totalReleased - $invoiceAmount + $returnedToBalance;

            $data[strtolower($category) . 'Data'] = [
                'totalBudget' => $totalBudget,
                'totalPatients' => $totalPatients,
                'totalReleased' => $totalReleased,
                'invoiceAmount' => $invoiceAmount,
                'returnedToBalance' => $returnedToBalance,
                'remaining' => $remainingBal,
            ];
        }

        return response()->json($data);
    }

    public function getAmountGiven()
    {
        $year = Carbon::now()->year;

        // per category
        $categories = ['Medicine', 'Laboratory', 'Hospital'];
        $amountPerCategory = [];
        foreach ($categories as $category) {
            $amountPerCategory[strtolower($category)] = PatientHistory::where('category', $category)
                ->whereYear('date_issued', $year)
                ->sum('issued_amount');
        }

        // per sex
        $amountPerSex = [
            'perMale' => PatientHistory::join('patient_list', 'patient_list.patient_id', '=', 'patient_history.patient_id')
                ->where('patient_list.sex', 'Male')
                ->whereYear('patient_history.date_issued', $year)
                ->sum('patient_history.issued_amount'),
            'perFemale' => PatientHistory::join('patient_list', 'patient_list.patient_id', '=', 'patient_history.patient_id')
                ->where('patient_list.sex', 'Female')
                ->whereYear('patient_history.date_issued', $year)
                ->sum('patient_history.issued_amount'),
        ];

        // per age bracket
        $ageBrackets = [
            '0to1'       => [0, 1],
            '2to5'       => [2, 5],
            '6to12'      => [6, 12],
            '13to19'     => [13, 19],
            '20to39'     => [20, 39],
            '40to64'     => [40, 64],
            '65AndAbove' => [65, null],
        ];

        $amountPerAge = [];
        foreach ($ageBrackets as $key => $range) {
            $query = PatientHistory::join('patient_list', 'patient_list.patient_id', '=', 'patient_history.patient_id')
                ->selectRaw('SUM(patient_history.issued_amount) as total')
                ->whereYear('patient_history.date_issued', $year)
                ->whereRaw('TIMESTAMPDIFF(YEAR, patient_list.birthdate, CURDATE()) >= ?', [$range[0]]);

            if ($range[1] !== null) {
                $query->whereRaw('TIMESTAMPDIFF(YEAR, patient_list.birthdate, CURDATE()) <= ?', [$range[1]]);
            }

            $amountPerAge[$key] = $query->first()->total ?? 0;
        }

        $sectorRows = DB::table('patient_history')
            ->join('patient_list', 'patient_list.patient_id', '=', 'patient_history.patient_id')
            ->join('user_sectors', 'user_sectors.patient_id', '=', 'patient_list.patient_id')
            ->join('sectors', 'sectors.id', '=', 'user_sectors.sector_id')
            ->selectRaw('sectors.sector, SUM(patient_history.issued_amount) as total')
            ->whereYear('patient_history.date_issued', $year)
            ->groupBy('sectors.sector')
            ->orderByDesc('total')
            ->get();

        $amountPerSector = [];
        foreach ($sectorRows as $row) {
            $amountPerSector['sector_' . $row->sector] = (float) $row->total;
        }

        // patients with no entry in user_sectors counted as N/A
        $naTotal = DB::table('patient_history')
            ->join('patient_list', 'patient_list.patient_id', '=', 'patient_history.patient_id')
            ->leftJoin('user_sectors', 'user_sectors.patient_id', '=', 'patient_list.patient_id')
            ->whereYear('patient_history.date_issued', $year)
            ->whereNull('user_sectors.patient_id')
            ->sum('patient_history.issued_amount');

        if ($naTotal > 0) {
            $amountPerSector['sector_N/A'] = (float) $naTotal;
        }

        return response()->json(array_merge(
            $amountPerCategory,
            $amountPerSex,
            $amountPerAge,
            $amountPerSector
        ));
    }

    public function getMonthlyPatients()
    {
        $year = date('Y');
        $categories = ['Medicine', 'Laboratory', 'Hospital'];
        $monthlyCounts = [];
        $totalCounts = [];

        for ($month = 1; $month <= 12; $month++) {
            $monthTotal = 0;
            foreach ($categories as $category) {
                $count = PatientHistory::whereYear('date_issued', $year)
                    ->whereMonth('date_issued', $month)
                    ->where('category', $category)
                    ->count();

                $monthlyCounts[$category][$month] = $count;
                $monthTotal += $count;
            }
            $totalCounts[$month] = $monthTotal;
        }

        return response()->json([
            'monthlyCounts' => $monthlyCounts,
            'totalCounts' => $totalCounts
        ]);
    }

    public function getBarangayData()
    {
        $currentYear = Carbon::now()->year;

        $data = DB::table('patient_history')
            ->join('patient_list', 'patient_list.patient_id', '=', 'patient_history.patient_id')
            ->select(
                'patient_list.barangay',
                DB::raw("SUM(CASE WHEN patient_history.category = 'Medicine' THEN 1 ELSE 0 END) AS medicinePatients"),
                DB::raw("SUM(CASE WHEN patient_history.category = 'Laboratory' THEN 1 ELSE 0 END) AS laboratoryPatients"),
                DB::raw("SUM(CASE WHEN patient_history.category = 'Hospital' THEN 1 ELSE 0 END) AS hospitalPatients"),
                DB::raw("COUNT(patient_history.gl_no) AS totalPatients"),
                DB::raw("SUM(patient_history.issued_amount) AS totalAmount"),
                DB::raw("SUM(CASE WHEN patient_history.category = 'Medicine' THEN patient_history.issued_amount ELSE 0 END) AS medicineAmount"),
                DB::raw("SUM(CASE WHEN patient_history.category = 'Laboratory' THEN patient_history.issued_amount ELSE 0 END) AS laboratoryAmount"),
                DB::raw("SUM(CASE WHEN patient_history.category = 'Hospital' THEN patient_history.issued_amount ELSE 0 END) AS hospitalAmount")
            )
            ->whereYear('patient_history.date_issued', $currentYear)
            ->groupBy('patient_list.barangay')
            ->orderByDesc('totalAmount')
            ->get();

        return response()->json($data);
    }
}
