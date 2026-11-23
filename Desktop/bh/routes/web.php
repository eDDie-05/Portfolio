<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AttendanceCsvImportController;
use App\Http\Controllers\AttendanceDashboardController;
use App\Http\Controllers\AttendanceDeviceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CareerController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DeviceMappingController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeDocumentController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\MyAttendanceController;
use App\Http\Controllers\OffboardingController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProbationController;
use App\Http\Controllers\RecruitmentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\WorkShiftController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::get('/careers', [CareerController::class, 'index'])->name('careers.index');
Route::get('/careers/submitted', [CareerController::class, 'submitted'])->name('careers.submitted');
Route::get('/careers/offer-response', [RecruitmentController::class, 'offerResponse'])->name('careers.offer.response');
Route::get('/careers/offers/{recruitmentOffer}', [RecruitmentController::class, 'viewOffer'])->middleware('signed')->name('careers.offer');
Route::get('/careers/offers/{recruitmentOffer}/document', [RecruitmentController::class, 'downloadOfferLetter'])->middleware('signed')->name('careers.offer.document');
Route::post('/careers/offers/{recruitmentOffer}/response', [RecruitmentController::class, 'respondToOffer'])->middleware('signed')->name('careers.offer.respond');
Route::get('/careers/{jobVacancy:slug}', [CareerController::class, 'show'])->name('careers.show');
Route::post('/careers/{jobVacancy:slug}/applications', [CareerController::class, 'apply'])->middleware('throttle:3,1')->name('careers.apply');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::middleware('auth')->group(function () {
    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::get('/account/profile-photo', [AccountController::class, 'profilePhoto'])->name('account.profile-photo');
    Route::put('/account', [AccountController::class, 'update'])->middleware('throttle:6,1')->name('account.update');
    Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::get('/announcements/{announcement}/download', [AnnouncementController::class, 'download'])->name('announcements.download');
});
Route::get('/my-attendance', MyAttendanceController::class)->middleware(['auth', 'role:employee'])->name('my-attendance');
Route::post('/my-password', [MyAttendanceController::class, 'updatePassword'])->middleware(['auth', 'role:employee', 'throttle:6,1'])->name('my-password.update');
Route::middleware(['auth', 'role:employee'])->group(function () {
    Route::get('/my-documents', [EmployeeDocumentController::class, 'myDocuments'])->name('my-documents.index');
    Route::get('/my-documents/{employeeDocument}', [EmployeeDocumentController::class, 'downloadForEmployee'])->name('my-documents.download');
});
Route::middleware('auth')->get('/my-onboarding-tasks', [OnboardingController::class, 'myTasks'])->name('onboarding.mine');
Route::middleware(['auth', 'role:employee'])->group(function () {
    Route::get('/my-payroll', [PayrollController::class, 'mine'])->name('payroll.mine');
    Route::get('/my-payroll/payslips/{payrollEntry}', [PayrollController::class, 'payslip'])->name('payroll.payslip.mine');
});
Route::middleware(['auth', 'role:system_admin,hr_director,payroll_officer,finance_officer'])->group(function () {
    Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::get('/payroll/periods/{payrollPeriod}', [PayrollController::class, 'showPeriod'])->name('payroll.periods.show');
    Route::get('/payroll/periods/{payrollPeriod}/export', [PayrollController::class, 'export'])->name('payroll.periods.export');
    Route::get('/payroll/payslips/{payrollEntry}', [PayrollController::class, 'payslip'])->name('payroll.payslip');
});
Route::middleware(['auth', 'role:system_admin,hr_director,payroll_officer'])->group(function () {
    Route::put('/payroll/employees/{employee}/statutory-profile', [PayrollController::class, 'updateStatutoryProfile'])->name('payroll.employees.statutory-profile');
    Route::post('/payroll/salary-structures', [PayrollController::class, 'storeStructure'])->name('payroll.structures.store');
    Route::post('/payroll/salary-structures/{salaryStructure}/deactivate', [PayrollController::class, 'deactivateStructure'])->name('payroll.structures.deactivate');
    Route::post('/payroll/salary-structures/{salaryStructure}/components', [PayrollController::class, 'storeComponent'])->name('payroll.components.store');
    Route::post('/payroll/components/{salaryStructureComponent}/deactivate', [PayrollController::class, 'deactivateComponent'])->name('payroll.components.deactivate');
    Route::post('/payroll/salary-assignments', [PayrollController::class, 'assignStructure'])->name('payroll.assignments.store');
    Route::post('/payroll/periods', [PayrollController::class, 'storePeriod'])->name('payroll.periods.store');
    Route::post('/payroll/periods/{payrollPeriod}/adjustments', [PayrollController::class, 'storeAdjustment'])->name('payroll.adjustments.store');
    Route::post('/payroll/periods/{payrollPeriod}/process', [PayrollController::class, 'process'])->name('payroll.periods.process');
});
Route::middleware(['auth', 'role:system_admin,hr_director,payroll_officer,finance_officer'])->post('/payroll/periods/{payrollPeriod}/approve', [PayrollController::class, 'approve'])->name('payroll.periods.approve');
Route::middleware(['auth', 'role:system_admin,finance_officer'])->post('/payroll/periods/{payrollPeriod}/paid', [PayrollController::class, 'markPaid'])->name('payroll.periods.paid');
Route::middleware(['auth', 'role:employee'])->get('/my-offboarding', [OffboardingController::class, 'myCases'])->name('offboarding.mine');
Route::middleware(['auth', 'role:employee'])->group(function () {
    Route::get('/my-onboarding-documents', [OnboardingController::class, 'myDocuments'])->name('onboarding.documents.mine');
    Route::post('/my-onboarding-documents/{onboardingDocumentRequest}', [OnboardingController::class, 'submitDocument'])->name('onboarding.documents.submit');
});
Route::middleware(['auth', 'role:system_admin,hr_director,hr_manager'])->group(function () {
    Route::get('/offboarding', [OffboardingController::class, 'index'])->name('offboarding.index');
    Route::get('/offboarding/create', [OffboardingController::class, 'create'])->name('offboarding.create');
    Route::post('/offboarding', [OffboardingController::class, 'store'])->name('offboarding.store');
    Route::post('/offboarding/{offboardingCase}/clearances', [OffboardingController::class, 'storeClearance'])->name('offboarding.clearances.store');
    Route::post('/offboarding/{offboardingCase}/exit-interview', [OffboardingController::class, 'recordExitInterview'])->name('offboarding.exit-interview');
    Route::post('/offboarding/{offboardingCase}/final-payroll', [OffboardingController::class, 'updateFinalPayroll'])->name('offboarding.final-payroll');
    Route::post('/offboarding/{offboardingCase}/close', [OffboardingController::class, 'close'])->name('offboarding.close');
    Route::post('/offboarding/{offboardingCase}/cancel', [OffboardingController::class, 'cancel'])->name('offboarding.cancel');
    Route::get('/offboarding/{offboardingCase}', [OffboardingController::class, 'show'])->name('offboarding.show');
});
Route::middleware('auth')->post('/offboarding/clearances/{offboardingClearanceItem}/complete', [OffboardingController::class, 'completeClearance'])->name('offboarding.clearances.complete');
Route::middleware(['auth', 'role:system_admin,hr_director,hr_manager'])->group(function () {
    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
    Route::get('/onboarding/{onboardingCase}', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('/onboarding/{onboardingCase}/tasks', [OnboardingController::class, 'storeTask'])->name('onboarding.tasks.store');
    Route::post('/onboarding/{onboardingCase}/document-requests', [OnboardingController::class, 'storeDocumentRequest'])->name('onboarding.document-requests.store');
    Route::get('/onboarding/document-requests/{onboardingDocumentRequest}/download', [OnboardingController::class, 'downloadOnboardingDocument'])->name('onboarding.document-requests.download');
    Route::post('/onboarding/{onboardingCase}/contract', [OnboardingController::class, 'issueContract'])->name('onboarding.contracts.issue');
});
Route::middleware('auth')->group(function () {
    Route::get('/onboarding/contracts/{onboardingContract}/document', [OnboardingController::class, 'downloadContract'])->name('onboarding.contracts.download');
    Route::post('/onboarding/contracts/{onboardingContract}/response', [OnboardingController::class, 'respondToContract'])->name('onboarding.contracts.respond');
});
Route::middleware(['auth', 'role:system_admin,hr_director,hr_manager'])->get('/onboarding/contracts/{onboardingContract}/signed-document', [OnboardingController::class, 'downloadSignedContract'])->name('onboarding.contracts.signed-download');
Route::middleware(['auth', 'role:system_admin,hr_director'])->group(function () {
    Route::get('/recruitment/applications/{jobApplication}/onboarding', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('/recruitment/applications/{jobApplication}/onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');
});
Route::middleware('auth')->post('/onboarding/tasks/{onboardingTask}/complete', [OnboardingController::class, 'completeTask'])->name('onboarding.tasks.complete');
Route::middleware('auth')->group(function () {
    Route::get('/my-leave', [LeaveController::class, 'index'])->name('leave.index');
    Route::post('/my-leave', [LeaveController::class, 'store'])->name('leave.store');
    Route::post('/my-leave/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('leave.cancel');
});
Route::middleware(['auth', 'role:system_admin,hr_director,hr_manager'])->group(function () {
    Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
    Route::get('/leave/approvals', [LeaveController::class, 'approvals'])->name('leave.approvals');
    Route::post('/leave/approvals/{leaveRequest}/{decision}', [LeaveController::class, 'review'])->name('leave.review');
});
Route::middleware(['auth', 'role:system_admin,hr_director'])->group(function () {
    Route::get('/recruitment', [RecruitmentController::class, 'index'])->name('recruitment.index');
    Route::get('/recruitment/vacancies/create', [RecruitmentController::class, 'createVacancy'])->name('recruitment.vacancies.create');
    Route::post('/recruitment/vacancies', [RecruitmentController::class, 'storeVacancy'])->name('recruitment.vacancies.store');
    Route::get('/recruitment/vacancies/{jobVacancy}/edit', [RecruitmentController::class, 'editVacancy'])->name('recruitment.vacancies.edit');
    Route::put('/recruitment/vacancies/{jobVacancy}', [RecruitmentController::class, 'updateVacancy'])->name('recruitment.vacancies.update');
    Route::get('/recruitment/applications', [RecruitmentController::class, 'applications'])->name('recruitment.applications.index');
    Route::get('/recruitment/applications/export', [RecruitmentController::class, 'exportApplications'])->name('recruitment.applications.export');
    Route::get('/recruitment/applications/{jobApplication}', [RecruitmentController::class, 'showApplication'])->name('recruitment.applications.show');
    Route::get('/recruitment/applications/{jobApplication}/resume', [RecruitmentController::class, 'downloadResume'])->name('recruitment.applications.resume');
    Route::get('/recruitment/applications/{jobApplication}/offers/{recruitmentOffer}/document', [RecruitmentController::class, 'downloadOfferForHr'])->name('recruitment.applications.offers.download');
    Route::post('/recruitment/applications/{jobApplication}/stage', [RecruitmentController::class, 'updateStage'])->name('recruitment.applications.stage');
    Route::post('/recruitment/applications/{jobApplication}/interviews', [RecruitmentController::class, 'scheduleInterview'])->name('recruitment.applications.interviews.store');
    Route::post('/recruitment/applications/{jobApplication}/interviews/{interview}/feedback', [RecruitmentController::class, 'recordInterviewFeedback'])->name('recruitment.applications.interviews.feedback');
    Route::post('/recruitment/applications/{jobApplication}/offer', [RecruitmentController::class, 'issueOffer'])->name('recruitment.applications.offer');
});
Route::middleware(['auth', 'role:system_admin,hr_director'])->group(function () {
    Route::get('/leave/settings', [LeaveController::class, 'settings'])->name('leave.settings');
    Route::post('/leave/types', [LeaveController::class, 'storeType'])->name('leave.types.store');
    Route::put('/leave/types/{leaveType}', [LeaveController::class, 'updateType'])->name('leave.types.update');
    Route::post('/leave/balances', [LeaveController::class, 'storeBalance'])->name('leave.balances.store');
});
Route::middleware(['auth', 'role:system_admin,hr_director,hr_manager'])->group(function () {
    Route::get('/probation', [ProbationController::class, 'index'])->name('probation.index');
    Route::post('/probation/{employee}/review', [ProbationController::class, 'review'])->name('probation.review');
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/export', [EmployeeController::class, 'export'])->name('employees.export');
    Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('throttle:10,1')->name('employees.store');
    Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->middleware('throttle:10,1')->name('employees.update');
    Route::get('/employees/{employee}/documents', [EmployeeDocumentController::class, 'index'])->name('employees.documents');
    Route::post('/employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->name('employees.documents.store');
    Route::get('/employees/{employee}/documents/{employeeDocument}', [EmployeeDocumentController::class, 'downloadForHr'])->name('employees.documents.download');
    Route::post('/employees/{employee}/documents/{employeeDocument}/archive', [EmployeeDocumentController::class, 'archive'])->name('employees.documents.archive');
});
Route::middleware(['auth', 'role:system_admin,hr_director'])->group(function () {
    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
    Route::post('/branches/{branch}/deactivate', [BranchController::class, 'deactivate'])->name('branches.deactivate');
    Route::post('/branches/{branch}/activate', [BranchController::class, 'activate'])->name('branches.activate');
    Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::post('/departments/{department}/deactivate', [DepartmentController::class, 'deactivate'])->name('departments.deactivate');
    Route::post('/departments/{department}/activate', [DepartmentController::class, 'activate'])->name('departments.activate');
});
Route::middleware(['auth', 'role:system_admin,hr_director,hr_manager,attendance_officer'])->group(function () {
    Route::get('/', AttendanceDashboardController::class)->name('dashboard');
    Route::get('/attendance/export', [AttendanceDashboardController::class, 'export'])->name('attendance.export');
    Route::get('/devices', [AttendanceDeviceController::class, 'index'])->middleware('role:system_admin,attendance_officer')->name('devices.index');
    Route::post('/devices/{device}/connectivity-check', [AttendanceDeviceController::class, 'connectivity'])->middleware('role:system_admin,attendance_officer')->name('devices.connectivity');
    Route::post('/devices/{device}/import', [AttendanceCsvImportController::class, 'store'])->middleware('role:system_admin,attendance_officer')->name('devices.import');
    Route::middleware('role:system_admin,hr_manager,attendance_officer')->group(function () {
        Route::get('/devices/{device}/mappings', [DeviceMappingController::class, 'index'])->name('devices.mappings');
        Route::post('/devices/{device}/mappings', [DeviceMappingController::class, 'store'])->name('devices.mappings.store');
        Route::post('/devices/{device}/mappings/{mapping}/end', [DeviceMappingController::class, 'end'])->name('devices.mappings.end');
    });
    Route::middleware('role:system_admin')->group(function () {
        Route::get('/devices/create', [AttendanceDeviceController::class, 'create'])->name('devices.create');
        Route::post('/devices', [AttendanceDeviceController::class, 'store'])->name('devices.store');
        Route::get('/devices/{device}/edit', [AttendanceDeviceController::class, 'edit'])->name('devices.edit');
        Route::put('/devices/{device}', [AttendanceDeviceController::class, 'update'])->name('devices.update');
        Route::delete('/devices/{device}', [AttendanceDeviceController::class, 'destroy'])->name('devices.destroy');
        Route::post('/devices/{device}/verify', [AttendanceDeviceController::class, 'verify'])->name('devices.verify');
    });
});
Route::middleware(['auth', 'role:system_admin'])->group(function () {
    Route::get('/settings', [SystemSettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SystemSettingsController::class, 'update'])->name('settings.update');
});
Route::middleware(['auth', 'role:system_admin,hr_director,payroll_officer,finance_officer'])->group(function () {
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/attendance/export', [ReportController::class, 'exportAttendance'])->name('reports.attendance.export');
});
Route::middleware(['auth', 'role:system_admin,hr_director'])->group(function () {
    Route::get('/shifts', [WorkShiftController::class, 'index'])->name('shifts.index');
    Route::post('/shifts', [WorkShiftController::class, 'store'])->name('shifts.store');
    Route::put('/shifts/{workShift}', [WorkShiftController::class, 'update'])->name('shifts.update');
    Route::post('/shifts/assignments', [WorkShiftController::class, 'assign'])->name('shifts.assignments.store');
    Route::post('/shifts/assignments/{employeeShift}/end', [WorkShiftController::class, 'endAssignment'])->name('shifts.assignments.end');
});
