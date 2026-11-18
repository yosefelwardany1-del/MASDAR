<?php

use App\Http\Controllers\Hr\AttendanceController;
use App\Http\Controllers\Hr\EmployeeController;
use App\Http\Controllers\Hr\LeaveController;
use App\Http\Controllers\Hr\MyLeaveController;
use App\Http\Controllers\Hr\PayrollController;
use Illuminate\Support\Facades\Route;

// HR (spec §5.7). Required inside the auth group of routes/web.php, where $pres is in scope.
// Employees: the file (employees.view|edit); salaries need payroll.edit (checked by the service too).
$pres('employees', EmployeeController::class, 'employees', except: ['destroy']);
Route::post('employees/{employee}/salaries', [EmployeeController::class, 'storeSalary'])->whereNumber('employee')->middleware('can:payroll.edit')->name('employees.salaries.store');
Route::post('employees/{employee}/documents', [EmployeeController::class, 'storeDocument'])->whereNumber('employee')->middleware('can:employees.edit')->name('employees.documents.store');
Route::post('employees/{employee}/leave-allowances', [EmployeeController::class, 'storeAllowance'])->whereNumber('employee')->middleware('can:leave.edit')->name('employees.allowances.store');

// Leave & attendance (screen `leave`): view lists, edit records, approve decides.
Route::get('leave', [LeaveController::class, 'index'])->middleware('can:leave.view')->name('leave.index');
Route::post('leave', [LeaveController::class, 'store'])->middleware('can:leave.edit')->name('leave.store');
Route::post('leave/{leaveRequest}/approve', [LeaveController::class, 'approve'])->whereNumber('leaveRequest')->middleware('can:leave.approve')->name('leave.approve');
Route::post('leave/{leaveRequest}/reject', [LeaveController::class, 'reject'])->whereNumber('leaveRequest')->middleware('can:leave.approve')->name('leave.reject');
Route::post('leave/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->whereNumber('leaveRequest')->middleware('can:leave.edit')->name('leave.cancel');
Route::get('attendance', [AttendanceController::class, 'index'])->middleware('can:leave.view')->name('attendance.index');
Route::post('attendance', [AttendanceController::class, 'store'])->middleware('can:leave.edit')->name('attendance.store');
Route::post('attendance/holidays', [AttendanceController::class, 'storeHoliday'])->middleware('can:leave.edit')->name('attendance.holidays.store');
Route::delete('attendance/holidays/{holiday}', [AttendanceController::class, 'destroyHoliday'])->whereNumber('holiday')->middleware('can:leave.edit')->name('attendance.holidays.destroy');

// My leave: sign-in only; the controller acts on the caller's own employee file.
Route::get('my-leave', [MyLeaveController::class, 'index'])->name('my-leave.index');
Route::post('my-leave', [MyLeaveController::class, 'store'])->name('my-leave.store');
Route::post('my-leave/{leaveRequest}/cancel', [MyLeaveController::class, 'cancel'])->whereNumber('leaveRequest')->name('my-leave.cancel');

// Payroll: view, edit prepares, approve approves and voids.
Route::get('payroll', [PayrollController::class, 'index'])->middleware('can:payroll.view')->name('payroll.index');
Route::post('payroll', [PayrollController::class, 'store'])->middleware('can:payroll.edit')->name('payroll.store');
Route::get('payroll/{run}', [PayrollController::class, 'show'])->whereNumber('run')->middleware('can:payroll.view')->name('payroll.show');
Route::put('payroll/{run}', [PayrollController::class, 'update'])->whereNumber('run')->middleware('can:payroll.edit')->name('payroll.update');
Route::delete('payroll/{run}', [PayrollController::class, 'destroy'])->whereNumber('run')->middleware('can:payroll.edit')->name('payroll.destroy');
Route::post('payroll/{run}/recalculate', [PayrollController::class, 'recalculate'])->whereNumber('run')->middleware('can:payroll.edit')->name('payroll.recalculate');
Route::get('payroll/{run}/lines/{line}/edit', [PayrollController::class, 'editLine'])->whereNumber(['run', 'line'])->middleware('can:payroll.edit')->name('payroll.lines.edit');
Route::put('payroll/{run}/lines/{line}', [PayrollController::class, 'updateLine'])->whereNumber(['run', 'line'])->middleware('can:payroll.edit')->name('payroll.lines.update');
Route::post('payroll/{run}/approve', [PayrollController::class, 'approve'])->whereNumber('run')->middleware('can:payroll.approve')->name('payroll.approve');
Route::post('payroll/{run}/void', [PayrollController::class, 'void'])->whereNumber('run')->middleware('can:payroll.approve')->name('payroll.void');
Route::get('payroll/{run}/payslips', [PayrollController::class, 'payslips'])->whereNumber('run')->middleware('can:payroll.view')->name('payroll.payslips');
