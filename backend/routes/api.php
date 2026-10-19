<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\RecruitmentController;
use App\Http\Controllers\Api\ReportsCenterController;
use App\Http\Controllers\Api\InsuranceController;
use App\Http\Controllers\Api\LetterController;
use App\Http\Controllers\Api\MailController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\CustodyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DevelopmentController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\ExportFileController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TerminationController;
use App\Http\Controllers\Api\ViolationController;
use App\Http\Controllers\Api\Employee360Controller;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeExtrasController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\KudosController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/invite/check', [AuthController::class, 'inviteCheck'])->middleware('throttle:30,1');
    Route::post('auth/invite/accept', [AuthController::class, 'inviteAccept'])->middleware('throttle:10,1');
    Route::post('auth/forgot', [AuthController::class, 'forgot'])->middleware('throttle:10,1');
    Route::post('integrations/odoo/webhook', [IntegrationController::class, 'webhook'])->middleware('throttle:60,1');
    Route::get('careers', [RecruitmentController::class, 'publicOpenings']);
    Route::post('careers/{opening}/apply', [RecruitmentController::class, 'publicApply'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/password', [AuthController::class, 'changePassword']);
        Route::post('auth/locale', [AuthController::class, 'locale']);

        Route::get('meta', [AdminController::class, 'meta']);
        Route::post('push/register', [PushController::class, 'register']);
        Route::delete('push/register', [PushController::class, 'unregister']);
        Route::get('dashboard', [DashboardController::class, 'index']);

        // Employees
        Route::get('employees/options', [EmployeeController::class, 'options']);
        Route::get('reports/template-for/{employee}', [ReportController::class, 'templateFor']);
        Route::get('employees/next-number', [EmployeeExtrasController::class, 'nextNumber']);
        Route::post('employees/{employee}/leave-adjust', [EmployeeExtrasController::class, 'adjustLeave']);
        Route::get('employees/{employee}/leave-ledger', [EmployeeExtrasController::class, 'leaveLedger']);
        Route::post('employees/{employee}/career', [EmployeeExtrasController::class, 'addCareer']);
        Route::delete('employees/{employee}/career/{event}', [EmployeeExtrasController::class, 'deleteCareer']);
        Route::post('evaluation/{employee}/{period}/manager', [EvaluationController::class, 'managerRating']);
        Route::get('employees/{employee}/360', [Employee360Controller::class, 'show']);
        Route::apiResource('employees', EmployeeController::class)->except('destroy');

        // Attendance
        Route::post('attendance/punch', [AttendanceController::class, 'punch'])->middleware('throttle:20,1');
        Route::get('attendance/me', [AttendanceController::class, 'me']);
        Route::get('attendance/today', [AttendanceController::class, 'today']);
        Route::get('attendance/month', [AttendanceController::class, 'month']);
        Route::get('attendance/pending', [AttendanceController::class, 'pending']);
        Route::get('attendance/employee/{employee}', [AttendanceController::class, 'employee']);
        Route::post('attendance/{record}/review', [AttendanceController::class, 'review']);

        // Leaves
        Route::get('leaves/types', [LeaveController::class, 'types']);
        Route::get('leaves/balances', [LeaveController::class, 'myBalances']);
        Route::get('leaves/balances/{employee}', [LeaveController::class, 'balances']);
        Route::get('leaves/report', [LeaveController::class, 'report']);
        Route::get('leaves/calendar', [LeaveController::class, 'calendar']);
        Route::get('leaves/past', [LeaveController::class, 'pastIndex']);
        Route::post('leaves/balances/import', [LeaveController::class, 'importBalances']);
        Route::post('leaves/balances/{employee}/set', [LeaveController::class, 'setBalance']);
        Route::post('leaves/past', [LeaveController::class, 'pastStore']);
        Route::delete('leaves/past/{entry}', [LeaveController::class, 'pastDestroy']);

        // Unified requests & approvals
        Route::get('requests/inbox', [RequestController::class, 'inbox']);
        Route::get('requests/mine', [RequestController::class, 'mine']);
        Route::post('requests/bulk-approve', [RequestController::class, 'bulkApprove']);
        Route::get('requests', [RequestController::class, 'index']);
        Route::post('requests', [RequestController::class, 'store']);
        Route::get('requests/{approvalRequest}', [RequestController::class, 'show']);
        Route::post('requests/{approvalRequest}/decide', [RequestController::class, 'decide']);
        Route::post('requests/{approvalRequest}/cancel', [RequestController::class, 'cancel']);
        Route::post('requests/{approvalRequest}/trip-report', [RequestController::class, 'tripReport']);
        Route::get('files', [FileController::class, 'download']);

        // Documents & expiry
        Route::get('documents/mine', [DocumentController::class, 'mine']);
        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::post('documents/{document}', [DocumentController::class, 'store']);
        Route::post('documents/{document}/renew', [DocumentController::class, 'renew']);
        Route::delete('documents/{document}', [DocumentController::class, 'destroy']);

        // Announcements
        Route::get('announcements/pending-ack', [AnnouncementController::class, 'pendingAck']);
        Route::get('announcements', [AnnouncementController::class, 'index']);
        Route::post('announcements', [AnnouncementController::class, 'store']);
        Route::post('announcements/{announcement}/read', [AnnouncementController::class, 'read']);
        Route::post('announcements/{announcement}/ack', [AnnouncementController::class, 'ack']);
        Route::post('announcements/{announcement}/react', [AnnouncementController::class, 'react']);
        Route::get('announcements/{announcement}/tracking', [AnnouncementController::class, 'tracking']);
        Route::post('announcements/{announcement}/remind', [AnnouncementController::class, 'remind']);

        // Internal mail
        Route::post('reports/weekly/{week}/plan', [ReportController::class, 'weeklyPlan']);
        Route::post('reports/weekly/{week}/plan/approve', [ReportController::class, 'weeklyPlanApprove']);
        Route::get('reports/weekly/{week}/from-monthly', [ReportController::class, 'weeklyFromMonthly']);
        Route::put('reports/weekly/employee/{employee}', [ReportController::class, 'weeklyToggle']);
        Route::get('mail/unread', [MailController::class, 'unread']);
        Route::get('mail/recipients', [MailController::class, 'recipients']);
        Route::get('mail/all', [MailController::class, 'all']);
        Route::get('mail', [MailController::class, 'threads']);
        Route::post('mail', [MailController::class, 'store']);
        Route::get('mail/{thread}', [MailController::class, 'show']);
        Route::post('mail/{thread}/reply', [MailController::class, 'reply']);

        // Letters, templates, e-signature
        Route::get('letters/templates', [LetterController::class, 'templates']);
        Route::post('letters/templates', [LetterController::class, 'saveTemplate']);
        Route::put('letters/templates/{template}', [LetterController::class, 'saveTemplate']);
        Route::get('letters/mine', [LetterController::class, 'mine']);
        Route::get('letters', [LetterController::class, 'index']);
        Route::post('letters', [LetterController::class, 'store']);
        Route::get('letters/{letter}', [LetterController::class, 'show']);
        Route::post('letters/{letter}/sign', [LetterController::class, 'sign']);
        Route::get('signature', [LetterController::class, 'mySignature']);
        Route::put('signature', [LetterController::class, 'saveSignature']);
        Route::get('signed-docs', [LetterController::class, 'signedDocs']);

        // Recruitment
        Route::get('recruitment/stages', [RecruitmentController::class, 'stages']);
        Route::get('recruitment/openings', [RecruitmentController::class, 'openings']);
        Route::post('recruitment/openings', [RecruitmentController::class, 'saveOpening']);
        Route::put('recruitment/openings/{opening}', [RecruitmentController::class, 'saveOpening']);
        Route::get('recruitment/applications', [RecruitmentController::class, 'applications']);
        Route::post('recruitment/applications', [RecruitmentController::class, 'saveApplication']);
        Route::post('recruitment/applications/{application}', [RecruitmentController::class, 'saveApplication']);
        Route::get('recruitment/applications/{application}/convert', [RecruitmentController::class, 'convert']);
        Route::post('recruitment/applications/{application}/link', [RecruitmentController::class, 'linkEmployee']);

        // Projects
        Route::get('projects', [ProjectController::class, 'index']);
        Route::post('projects', [ProjectController::class, 'store']);
        Route::get('projects/{project}', [ProjectController::class, 'show']);
        Route::put('projects/{project}', [ProjectController::class, 'store']);
        Route::post('projects/{project}/week', [ProjectController::class, 'week']);
        Route::post('projects/{project}/obstacles', [ProjectController::class, 'obstacle']);
        Route::put('projects/{project}/obstacles/{obstacle}', [ProjectController::class, 'obstacle']);

        // Import
        Route::get('import/templates', [ImportController::class, 'templates']);
        Route::get('import/templates/{key}', [ImportController::class, 'template']);
        Route::post('import/preview', [ImportController::class, 'preview']);
        Route::post('import/commit', [ImportController::class, 'commit']);
        Route::get('import', [ImportController::class, 'history']);
        Route::post('import/{batch}/undo', [ImportController::class, 'undo']);

        // Reports centre
        Route::get('reports-center', [ReportsCenterController::class, 'catalog']);
        Route::post('reports-center/{key}/favorite', [ReportsCenterController::class, 'favorite']);
        Route::get('reports-center/{key}', [ReportsCenterController::class, 'run']);

        // Insurance
        Route::get('insurance/mine', [InsuranceController::class, 'mine']);
        Route::get('insurance', [InsuranceController::class, 'index']);
        Route::post('insurance', [InsuranceController::class, 'save']);
        Route::put('insurance/{policy}', [InsuranceController::class, 'save']);
        Route::post('insurance/members/{employee}', [InsuranceController::class, 'member']);

        // Notifications
        Route::get('exports', [ExportFileController::class, 'index']);
        Route::post('exports', [ExportFileController::class, 'store']);
        Route::get('exports/{file}/download', [ExportFileController::class, 'download']);
        Route::delete('exports/{file}', [ExportFileController::class, 'destroy']);
        Route::get('notifications', [AdminController::class, 'notifications']);
        Route::get('notifications/unread', [AdminController::class, 'unread']);
        Route::post('notifications/read', [AdminController::class, 'markRead']);

        // Administration
        Route::get('companies', [AdminController::class, 'companies']);
        Route::post('companies', [AdminController::class, 'saveCompany']);
        Route::put('companies/{company}', [AdminController::class, 'saveCompany']);
        Route::delete('companies/{company}', [AdminController::class, 'deleteCompany']);
        Route::post('companies/{company}/logo', [AdminController::class, 'uploadLogo']);
        Route::delete('companies/{company}/logo', [AdminController::class, 'deleteLogo']);
        Route::get('tiers', [AdminController::class, 'tiers']);
        Route::put('tiers/{tier}', [AdminController::class, 'updateTier']);
        Route::post('tiers', [AdminController::class, 'storeTier']);
        Route::delete('tiers/{tier}', [AdminController::class, 'deleteTier']);
        Route::get('audit/entities', [AdminController::class, 'auditEntities']);
        Route::get('users/options', [UserController::class, 'options']);
        Route::get('settings', [AdminController::class, 'settings']);
        Route::put('settings', [AdminController::class, 'updateSetting']);
        Route::get('audit', [AdminController::class, 'audit']);

        // Users, permissions, delegations
        Route::get('users/roles', [UserController::class, 'roles']);
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);
        Route::post('users/bulk', [UserController::class, 'bulk']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::post('users/{user}/invite', [UserController::class, 'invite']);
        Route::put('users/{user}/perms', [UserController::class, 'updatePerms']);
        Route::post('users/{user}/handover', [UserController::class, 'handover']);
        Route::get('delegations', [UserController::class, 'delegations']);
        Route::post('delegations', [UserController::class, 'storeDelegation']);
        Route::post('delegations/{delegation}/revoke', [UserController::class, 'revokeDelegation']);

        // Approval flows editor
        Route::get('flows', [UserController::class, 'flows']);
        Route::get('flows/roles', [UserController::class, 'flowRoles']);
        Route::put('flows/{type}', [UserController::class, 'saveFlow']);

        // Dynamic configuration: request types, setting definitions, lookup lists
        Route::get('request-types', [ConfigController::class, 'requestTypes']);
        Route::post('request-types', [ConfigController::class, 'saveRequestType']);
        Route::put('request-types/{type}', [ConfigController::class, 'saveRequestType']);
        Route::delete('request-types/{type}', [ConfigController::class, 'deleteRequestType']);
        Route::post('settings/definitions', [ConfigController::class, 'saveSettingDefinition']);
        Route::put('settings/definitions/{def}', [ConfigController::class, 'saveSettingDefinition']);
        Route::delete('settings/definitions/{def}', [ConfigController::class, 'deleteSettingDefinition']);
        Route::get('lookup-groups', [ConfigController::class, 'lookupGroups']);
        Route::post('lookup-groups', [ConfigController::class, 'saveLookupGroup']);
        Route::put('lookup-groups/{group}', [ConfigController::class, 'saveLookupGroup']);
        Route::get('lookup-groups/{group}/items', [ConfigController::class, 'lookupItems']);
        Route::post('lookup-groups/{group}/items', [ConfigController::class, 'saveLookupItem']);
        Route::put('lookup-groups/{group}/items/{item}', [ConfigController::class, 'saveLookupItem']);
        Route::delete('lookup-groups/{group}/items/{item}', [ConfigController::class, 'deleteLookupItem']);

        // Integrations (Odoo)
        Route::get('integrations/odoo', [IntegrationController::class, 'status']);
        Route::put('integrations/odoo', [IntegrationController::class, 'saveSettings']);
        Route::post('integrations/odoo/test', [IntegrationController::class, 'test']);
        Route::get('integrations/odoo/mappings', [IntegrationController::class, 'mappings']);
        Route::put('integrations/odoo/mappings', [IntegrationController::class, 'saveMappings']);
        Route::get('integrations/odoo/fields', [IntegrationController::class, 'fields']);
        Route::get('integrations/odoo/outbox', [IntegrationController::class, 'outbox']);
        Route::post('integrations/odoo/outbox/{row}/retry', [IntegrationController::class, 'retry']);
        Route::post('integrations/odoo/push', [IntegrationController::class, 'push']);
        Route::post('integrations/odoo/push-all', [IntegrationController::class, 'pushAll']);
        Route::post('integrations/odoo/pull', [IntegrationController::class, 'pull']);
        Route::post('integrations/odoo/run', [IntegrationController::class, 'runNow']);

        // Master data: leave-types | job-titles | schedules | locations
        Route::post('lookups/locations/resolve', [LookupController::class, 'resolveMapLink']);
        Route::get('lookups/{kind}', [LookupController::class, 'index']);
        Route::post('lookups/{kind}', [LookupController::class, 'store']);
        Route::put('lookups/{kind}/{id}', [LookupController::class, 'update']);
        Route::delete('lookups/{kind}/{id}', [LookupController::class, 'destroy']);

        // Payroll & payslips
        Route::get('payroll/companies', [PayrollController::class, 'companies']);
        Route::get('payroll/runs', [PayrollController::class, 'runs']);
        Route::post('payroll/runs/generate', [PayrollController::class, 'generate']);
        Route::get('payroll/runs/{run}', [PayrollController::class, 'showRun']);
        Route::post('payroll/runs/{run}/regenerate', [PayrollController::class, 'regenerate']);
        Route::post('payroll/runs/{run}/adjust-late', [PayrollController::class, 'adjustLate']);
        Route::post('payroll/runs/{run}/stage', [PayrollController::class, 'stage']);
        Route::get('payroll/payslips/mine', [PayrollController::class, 'mine']);
        Route::get('payroll/payslips', [PayrollController::class, 'payslips']);
        Route::get('payroll/payslips/{payslip}', [PayrollController::class, 'show']);
        Route::post('payroll/payslips/{payslip}/approve', [PayrollController::class, 'approve']);
        Route::get('payroll/employees/{employee}/annual-summary', [PayrollController::class, 'annualSummary']);
        Route::get('payroll/advances/mine', [PayrollController::class, 'myAdvances']);
        Route::get('payroll/advances', [PayrollController::class, 'advances']);
        Route::get('payroll/advances/{advance}', [PayrollController::class, 'advanceShow']);

        // Monthly & weekly reports
        Route::get('reports/templates', [ReportController::class, 'templates']);
        Route::post('reports/templates', [ReportController::class, 'saveTemplate']);
        Route::put('reports/templates/{template}', [ReportController::class, 'saveTemplate']);
        Route::get('reports/monthly/mine', [ReportController::class, 'mine']);
        Route::get('reports/monthly/history', [ReportController::class, 'myHistory']);
        Route::get('reports/monthly', [ReportController::class, 'index']);
        Route::post('reports/monthly/export', [ReportController::class, 'exportData']);
        Route::get('reports/monthly/{report}', [ReportController::class, 'show']);
        Route::put('reports/monthly/{report}', [ReportController::class, 'save']);
        Route::post('reports/monthly/{report}/submit', [ReportController::class, 'submit']);
        Route::post('reports/monthly/{report}/review', [ReportController::class, 'review']);
        Route::post('reports/monthly/{report}/adjust', [ReportController::class, 'adjust']);
        Route::post('reports/remind', [ReportController::class, 'remind']);
        Route::post('kudos', [KudosController::class, 'store']);
        Route::get('kudos/leaderboard', [KudosController::class, 'leaderboard']);
        Route::get('kudos/{employee}', [KudosController::class, 'forEmployee']);
        Route::get('reports/weekly/mine', [ReportController::class, 'weeklyMine']);
        Route::get('reports/weekly/team', [ReportController::class, 'weeklyTeam']);
        Route::post('reports/weekly/{week}', [ReportController::class, 'weeklySubmit']);

        // Evaluation
        Route::get('evaluation/templates', [EvaluationController::class, 'templates']);
        Route::post('evaluation/templates', [EvaluationController::class, 'saveTemplate']);
        Route::put('evaluation/templates/{template}', [EvaluationController::class, 'saveTemplate']);
        Route::get('evaluation/mine', [EvaluationController::class, 'mine']);
        Route::get('evaluation/monthly', [EvaluationController::class, 'monthly']);
        Route::get('evaluation/annual', [EvaluationController::class, 'annual']);
        Route::post('evaluation/{employee}/goals', [EvaluationController::class, 'goals']);
        Route::get('evaluation/{employee}/{period}', [EvaluationController::class, 'show']);
        Route::post('evaluation/{employee}/{period}/publish', [EvaluationController::class, 'publish']);
        Route::post('evaluations/{evaluation}/object', [EvaluationController::class, 'object']);
        Route::post('evaluations/{evaluation}/objection', [EvaluationController::class, 'decideObjection']);

        // Development & training
        Route::get('development/mine', [DevelopmentController::class, 'mine']);
        Route::get('development', [DevelopmentController::class, 'index']);
        Route::post('development', [DevelopmentController::class, 'store']);
        Route::post('development/{record}/complete', [DevelopmentController::class, 'complete']);
        Route::post('development/{record}/verify', [DevelopmentController::class, 'verify']);

        // Violations
        Route::get('violations/rules', [ViolationController::class, 'rules']);
        Route::get('violations/suggestions', [ViolationController::class, 'suggestions']);
        Route::get('violations/mine', [ViolationController::class, 'mine']);
        Route::get('violations', [ViolationController::class, 'index']);
        Route::post('violations', [ViolationController::class, 'store']);
        Route::get('violations/{violation}', [ViolationController::class, 'show']);
        Route::post('violations/{violation}/statement', [ViolationController::class, 'statement']);
        Route::post('violations/{violation}/recommend', [ViolationController::class, 'recommend']);
        // §88 incentives & praise register
        Route::get('incentives/mine', [App\Http\Controllers\Api\IncentiveController::class, 'mine']);
        Route::get('incentives/board', [App\Http\Controllers\Api\IncentiveController::class, 'board']);
        Route::get('incentives', [App\Http\Controllers\Api\IncentiveController::class, 'index']);
        Route::post('incentives', [App\Http\Controllers\Api\IncentiveController::class, 'store']);
        Route::delete('incentives/{incentive}', [App\Http\Controllers\Api\IncentiveController::class, 'destroy']);
        Route::get('incentives/employee/{employee}', [App\Http\Controllers\Api\IncentiveController::class, 'forEmployee']);
        // §75 improvement plans
        Route::get('improvement/mine', [App\Http\Controllers\Api\ImprovementPlanController::class, 'mine']);
        Route::get('improvement', [App\Http\Controllers\Api\ImprovementPlanController::class, 'index']);
        Route::post('improvement', [App\Http\Controllers\Api\ImprovementPlanController::class, 'store']);
        Route::post('improvement/{plan}/approve', [App\Http\Controllers\Api\ImprovementPlanController::class, 'approve']);
        Route::post('improvement/{plan}/review', [App\Http\Controllers\Api\ImprovementPlanController::class, 'review']);
        Route::post('improvement/{plan}/close', [App\Http\Controllers\Api\ImprovementPlanController::class, 'close']);
        Route::post('violations/{violation}/sign', [ViolationController::class, 'sign']);
        Route::post('violations/{violation}/approve', [ViolationController::class, 'approve']);
        Route::post('violations/{violation}/cancel', [ViolationController::class, 'cancel']);
        Route::post('violations/{violation}/object', [ViolationController::class, 'object']);
        Route::post('violations/{violation}/objection', [ViolationController::class, 'decideObjection']);

        // Termination, EOSB & settlement
        Route::get('terminations', [TerminationController::class, 'index']);
        Route::post('terminations', [TerminationController::class, 'store']);
        Route::get('terminations/simulate/{employee}', [TerminationController::class, 'simulate']);
        Route::get('terminations/{termination}', [TerminationController::class, 'show']);
        Route::post('terminations/{termination}/task', [TerminationController::class, 'task']);
        Route::post('terminations/{termination}/eosb', [TerminationController::class, 'adjustEosb']);
        Route::post('terminations/{termination}/rebuild', [TerminationController::class, 'rebuild']);
        Route::post('terminations/{termination}/approve', [TerminationController::class, 'approve']);
        Route::post('terminations/{termination}/pay', [TerminationController::class, 'pay']);
        Route::post('terminations/{termination}/cancel', [TerminationController::class, 'cancel']);

        // Custody, secrets vault, cash-custody settlement
        Route::get('custody/types', [CustodyController::class, 'types']);
        Route::post('custody/types', [CustodyController::class, 'saveType']);
        Route::put('custody/types/{type}', [CustodyController::class, 'saveType']);
        Route::get('custody/mine', [CustodyController::class, 'mine']);
        Route::get('custody/claims/mine', [CustodyController::class, 'myClaims']);
        Route::get('custody/claims', [CustodyController::class, 'claims']);
        Route::post('custody/claims/{claim}/decide', [CustodyController::class, 'decideClaim']);
        Route::get('custody', [CustodyController::class, 'index']);
        Route::post('custody', [CustodyController::class, 'store']);
        Route::get('custody/{item}', [CustodyController::class, 'show']);
        Route::put('custody/{item}', [CustodyController::class, 'update']);
        Route::post('custody/{item}/hand', [CustodyController::class, 'hand']);
        Route::post('custody/{item}/ack', [CustodyController::class, 'ack']);
        Route::post('custody/{item}/request-return', [CustodyController::class, 'requestReturn']);
        Route::post('custody/{item}/receive', [CustodyController::class, 'receive']);
        Route::post('custody/{item}/close', [CustodyController::class, 'close']);
        Route::post('custody/{item}/transfer', [CustodyController::class, 'transfer']);
        Route::post('custody/{item}/secret', [CustodyController::class, 'setSecret']);
        Route::post('custody/{item}/reveal', [CustodyController::class, 'reveal']);
        Route::post('custody/{item}/claims', [CustodyController::class, 'storeClaim']);
    });
});
