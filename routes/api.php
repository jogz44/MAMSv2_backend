<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\GeneralSummaryController;
use App\Http\Controllers\DropdownOptionsController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Auth\AuthController;

// Wrap auth routes in web middleware for sessions
Route::middleware('web')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Protect the user endpoint - require authentication
    Route::middleware('auth')->get('/user', [AuthController::class, 'user']);
});

// PatientController
Route::post('/patients', [PatientController::class, 'addPatient']);
Route::post('/patients/existing', [PatientController::class, 'existingPatientList']);
Route::post('/patients/check-eligibility', [PatientController::class, 'checkEligibility']);
Route::post('/patients/check-eligibility-by-id', [PatientController::class, 'checkEligibilityById']);
Route::get('/patients', [PatientController::class, 'getPatients']);
Route::get('/patients/search', [PatientController::class, 'search']);
Route::get('/patients/all-with-eligibility', [PatientController::class, 'getAllPatientsWithEligibility']);
Route::get('/patients/{patientId}/previous-categories', [PatientController::class, 'getPreviousCategories']); // NEW
Route::get('/patient-details/{identifier}', [PatientController::class, 'getPatientDetails']);
Route::get('/patient-history/{identifier}', [PatientController::class, 'getPatientHistory']);
Route::post('/patient-details/delete/{identifier}', [PatientController::class, 'deleteLetter']);
Route::post('/patient-details/update', [PatientController::class, 'updatePatientDetails']);
Route::post('/patient-details/update-name', [PatientController::class, 'updatePatientName']);
Route::post('/patient-name/update', [PatientController::class, 'updatePatientName']);

// BudgetController
Route::post('/create-yearly-budget', [BudgetController::class, 'createYearlyBudget']);
Route::post('/add-supplementary-bonus', [BudgetController::class, 'addSupplementaryBonus']);
Route::get('/yearly-budget', [BudgetController::class, 'getYearlyBudget']);
Route::get('/supplementary-bonus', [BudgetController::class, 'getSupplementaryBonus']);
Route::get('/issued-amounts-by-year', [BudgetController::class, 'getIssuedAmountByYear']);
Route::post('/validate-transfer', [BudgetController::class, 'validateTransfer']);
Route::get('/budget/current', [BudgetController::class, 'getCurrentBudget']);

// DashboardController
Route::get('/total-patients-and-amount', [DashboardController::class, 'getTotalPatientsAndAmountReleased']);
Route::get('/category-cards', [DashboardController::class, 'getCategoryData']);
Route::get('/amount-given', [DashboardController::class, 'getAmountGiven']);
Route::get('/monthly-patients', [DashboardController::class, 'getMonthlyPatients']);
Route::get('/barangay-records', [DashboardController::class, 'getBarangayData']);

// SettingsController
Route::get('/get-eligibility-cooldown', [SettingsController::class, 'getEligibilityCooldown']);
Route::post('/update-eligibility-cooldown', [SettingsController::class, 'updateEligibilityCooldown']);
Route::get('/accounts', [SettingsController::class, 'getAccounts']);
Route::post('/new-account', [SettingsController::class, 'createAccount']);
Route::post('/update-account', [SettingsController::class, 'updateAccount']);
Route::post('/delete-account', [SettingsController::class, 'deleteAccount']);

// GeneralSummaryController
Route::get('/general-summary-records', [GeneralSummaryController::class, 'filterByDate']);

// DropdownOptionsController
Route::get('/all', [DropdownOptionsController::class, 'getAllOptions']);
Route::get('/preferences', [DropdownOptionsController::class, 'getPreferenceOptions']);
Route::get('/partners', [DropdownOptionsController::class, 'getPartnerOptions']);
Route::get('/sectors', [DropdownOptionsController::class, 'getSectorOptions']);
Route::get('/sectors/all', [DropdownOptionsController::class, 'getAllSectors']);
Route::get('/partners/all', [DropdownOptionsController::class, 'getAllPartners']);
Route::get('/preferences/all', [DropdownOptionsController::class, 'getAllPreferences']);

Route::post('/preferences', [DropdownOptionsController::class, 'addPreferenceOption']);
Route::post('/partners', [DropdownOptionsController::class, 'addPartnerOption']);
Route::post('/sectors', [DropdownOptionsController::class, 'addSectorOption']);

Route::post('/preferences/{id}/toggle', [DropdownOptionsController::class, 'togglePreferenceOption']);
Route::post('/partners/{id}/toggle', [DropdownOptionsController::class, 'togglePartnerOption']);
Route::post('/sectors/{id}/toggle', [DropdownOptionsController::class, 'toggleSectorOption']);

// ActivityLogController
Route::get('/activity-logs', [ActivitylogController::class, 'getLogs']);
Route::get('/activity-logs/actions', [ActivitylogController::class, 'getActionTypes']);