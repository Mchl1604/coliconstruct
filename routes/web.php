<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\ClientProjectController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectTypeController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SystemContentController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TechnicianController;
use App\Http\Controllers\TechnicianPortalController;
use App\Http\Controllers\TechnicianReportArchiveController;
use App\Http\Controllers\TechnicianReportController;
use App\Http\Controllers\TermsController;
use App\Http\Controllers\UploadedFileController;
use Illuminate\Support\Facades\Route;

// The public website. Every page here is readable by anyone; My Projects is
// the exception in content rather than in access - a guest reaches it and is
// invited to sign in.
Route::get('/', [PublicSiteController::class, 'home'])->name('landing.home');
Route::get('/about', [PublicSiteController::class, 'about'])->name('public.about');
Route::get('/contact', [PublicSiteController::class, 'contact'])->name('public.contact');

// The one thing a stranger can make this application do.
//
// The throttle here is a ceiling on requests, not on enquiries: it exists so a
// script cannot hammer the endpoint, and it is set well above anything a
// person does so that somebody who simply clicked twice is answered by the
// form with a toast rather than by a 429 page. The limits that actually decide
// how many enquiries an address or a connection may send live in
// InquirySpamGuard, alongside the honeypot - the same layering the email
// verification routes use over OtpService's own per-address limits.
Route::post('/contact', [PublicSiteController::class, 'sendInquiry'])
    ->middleware('throttle:30,10')
    ->name('public.contact.send');
Route::get('/my-projects', [PublicSiteController::class, 'myProjects'])->name('public.projects');
Route::get('/my-projects/{project}', [PublicSiteController::class, 'projectDetails'])
    ->whereNumber('project')
    ->name('public.projects.show');

// The one action a client takes rather than reads: signing off a project the
// company has reported as finished. Behind `auth` because it writes, and the
// controller additionally proves the project is theirs - a client has no
// portal, so this is the only POST route they ever reach.
Route::post('/my-projects/{project}/confirm', [ClientProjectController::class, 'confirm'])
    ->middleware(['auth', 'password.changed'])
    ->whereNumber('project')
    ->name('public.projects.confirm');

// Authentication
Route::get('/login', [AuthController::class, 'showLogin'])->name('auth.login');
Route::post('/login', [AuthController::class, 'login'])->name('auth.login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('auth.logout');

// Public registration, which only ever opens a client account. Every employee
// account is created by an admin or super admin in Configuration.
Route::get('/register', [AuthController::class, 'showRegister'])->name('auth.register');
Route::post('/register', [AuthController::class, 'register'])->name('auth.register.store');

// Proving a newly registered address is real. Guest routes by necessity: the
// account exists but cannot sign in until the code sent to it comes back.
//
// Throttled on top of the per-address limits OtpService already applies, so a
// script cannot burn through somebody's attempts from one machine.
Route::get('/verify-email', [EmailVerificationController::class, 'show'])->name('auth.verify');
Route::post('/verify-email', [EmailVerificationController::class, 'verify'])
    ->middleware('throttle:10,1')
    ->name('auth.verify.store');
Route::post('/verify-email/resend', [EmailVerificationController::class, 'resend'])
    ->middleware('throttle:6,1')
    ->name('auth.verify.resend');

// Forgotten password. Open to guests by definition - somebody who cannot sign
// in is exactly who needs it.
//
// Three steps: name the address, enter the code emailed to it, choose a new
// password. Nothing that grants access is left sitting in a mailbox.
Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('auth.password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'sendCode'])
    ->middleware('throttle:6,1')
    ->name('auth.password.email');
Route::get('/forgot-password/verify', [PasswordResetController::class, 'showVerify'])->name('auth.password.verify');
Route::post('/forgot-password/verify', [PasswordResetController::class, 'verify'])
    ->middleware('throttle:10,1')
    ->name('auth.password.verify.store');
Route::post('/forgot-password/resend', [PasswordResetController::class, 'resend'])
    ->middleware('throttle:6,1')
    ->name('auth.password.resend');
Route::get('/reset-password', [PasswordResetController::class, 'showReset'])->name('auth.password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('auth.password.store');

// ---------------------------------------------------------------------------
// Terms and Conditions
// ---------------------------------------------------------------------------
//
// Reading them, and agreeing to them. There is deliberately no page here: the
// document is shown in a modal by the registration form, by the website footer
// and by the dialog a client meets after signing in, because in all three the
// reader is in the middle of something and sending them away would cost them
// it. `show` is for anything that wants the current text and its version
// without a page around it.
//
// Reading is open to anybody, signed in or not - the footer link is for
// visitors as much as for clients, and opening the document has never been an
// acceptance of it.
//
// Accepting is behind `auth` and records the agreement against the
// authenticated account, never against an id in the body. It sits outside
// `password.changed` and outside the terms middleware itself for the same
// reason the password-change screen does: the way through a gate cannot be
// behind that gate.
Route::get('/terms', [TermsController::class, 'show'])->name('terms.show');
Route::post('/terms/accept', [TermsController::class, 'accept'])
    ->middleware('auth')
    ->name('terms.accept');

// ---------------------------------------------------------------------------
// Uploaded files
// ---------------------------------------------------------------------------
//
// Nothing a person uploaded is served by the web server any longer - see
// UploadedFileController. These routes are how every picture, photograph and
// document is read, and each one asks who is requesting it before handing
// anything over. A URL is no longer a key.
//
// System content is the deliberate exception: logos and illustrations on the
// public website have to load for people who are not signed in.
Route::prefix('media')->name('media.')->group(function () {
    Route::get('/system/{key}', [UploadedFileController::class, 'systemContent'])
        ->where('key', '[A-Za-z0-9_.\\-]+')
        ->name('system');

    Route::middleware('auth')->group(function () {
        Route::get('/avatars/{user}', [UploadedFileController::class, 'profilePhoto'])
            ->whereNumber('user')
            ->name('avatar');
        Route::get('/documents/{document}', [UploadedFileController::class, 'document'])
            ->whereNumber('document')
            ->name('document');
        Route::get('/completion-photos/{photo}', [UploadedFileController::class, 'completionPhoto'])
            ->whereNumber('photo')
            ->name('completion-photo');
        Route::get('/task-images/{image}', [UploadedFileController::class, 'taskImage'])
            ->whereNumber('image')
            ->name('task-image');
        Route::get('/report-images/{image}', [UploadedFileController::class, 'reportImage'])
            ->whereNumber('image')
            ->name('report-image');
    });
});

// The forced first password change. Sits inside `auth` but outside
// `password.changed`, which is the middleware that redirects here.
Route::middleware('auth')->group(function () {
    Route::get('/password/change', [AuthController::class, 'showPasswordChange'])->name('auth.password.change');
    Route::post('/password/change', [AuthController::class, 'updatePassword'])->name('auth.password.update');
});

// The signed-in account's own details, reachable from every header.
//
// Every endpoint acts on the signed-in account and takes no id, which is the
// whole of "a user may edit only their own profile" - there is no other
// profile these routes can reach.
Route::middleware(['auth', 'password.changed'])
    ->prefix('profile')
    ->name('profile.')
    ->group(function () {
        Route::get('/', [ProfileController::class, 'edit'])->name('edit');

        // POST rather than PUT for the picture: it is multipart, which PHP
        // does not parse from a PUT body.
        Route::post('/photo', [ProfileController::class, 'updatePhoto'])->name('photo.update');
        Route::delete('/photo', [ProfileController::class, 'destroyPhoto'])->name('photo.destroy');

        Route::put('/information', [ProfileController::class, 'updateInformation'])->name('information');
        Route::put('/password', [ProfileController::class, 'updatePassword'])->name('password');

        // Changing the address you sign in with. The new one is parked on the
        // account until a code sent to it comes back, so a typo cannot lock
        // somebody out of their own account - see ProfileService.
        Route::post('/email/verify', [ProfileController::class, 'verifyEmailChange'])
            ->middleware('throttle:10,1')
            ->name('email.verify');
        Route::post('/email/resend', [ProfileController::class, 'resendEmailChange'])
            ->middleware('throttle:6,1')
            ->name('email.resend');
        Route::delete('/email', [ProfileController::class, 'cancelEmailChange'])->name('email.cancel');

        // A technician asking for different specialties. The change itself is
        // an administrator's to make - see the Technicians page.
        Route::post('/specialties', [ProfileController::class, 'requestSpecialties'])
            ->middleware('role:technician,lead_technician')
            ->name('specialties.request');
    });

// Notifications. Shared by every portal rather than duplicated per role: a
// notification is addressed to a person, and "mine" is the whole of the
// visibility rule, so one set of routes serves all of them.
Route::middleware(['auth', 'password.changed'])
    ->prefix('notifications')
    ->name('notifications.')
    ->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/feed', [NotificationController::class, 'feed'])->name('feed');
        Route::get('/list', [NotificationController::class, 'list'])->name('list');
        Route::get('/{notification}/open', [NotificationController::class, 'open'])->name('open');
        Route::put('/{notification}/read', [NotificationController::class, 'markAsRead'])->name('read');
        Route::put('/read-all', [NotificationController::class, 'markAllAsRead'])->name('read-all');
        Route::delete('/{notification}', [NotificationController::class, 'destroy'])->name('destroy');
    });

// Super Admin routes. Admin shares this portal entirely - same sidebar, same
// pages - so both roles are admitted here.
Route::prefix('super-admin')
    ->name('super-admin.')
    ->middleware(['auth', 'password.changed', 'role:super_admin,admin'])
    ->group(function () {

        // The landing page after sign-in. Everything it shows arrives with the
        // page; `summary` re-reads only the figures, for a refresh without a
        // reload.
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');

        // ROUTE FOR SUPER ADMIN PROJECTS PAGE
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects');

        // Archiving is a Super Admin privilege. An Admin creates, reads,
        // edits, activates and deactivates; taking a record out of the active
        // system - and putting it back - belongs to the system's owner.
        //
        // Declared here rather than in a group of its own so /projects/archived
        // keeps being matched before /projects/{id}.
        Route::get('/projects/archived', [ProjectController::class, 'archivedIndex'])
            ->middleware('role:super_admin')
            ->name('projects.archived');
        Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
        Route::post('/projects/create', [ProjectController::class, 'store'])->name('projects.create.store');

        // The teams that could be copied onto a project, for the Import Team
        // dialog in the wizard and in the assigned-team editor. Declared
        // before /projects/{id} so the literal segment wins.
        Route::get('/projects/importable-teams', [ProjectController::class, 'importableTeams'])
            ->name('projects.importable-teams');
        Route::get('/projects/{id}', [ProjectController::class, 'show'])->name('projects.show');
        // What has changed about a project's team, or about its dates. Read by
        // the two history buttons on the project details page; `section` says
        // which of the two is asking.
        Route::get('/projects/{id}/history/{section}', [ProjectController::class, 'history'])
            ->whereIn('section', ['team', 'schedule'])
            ->name('projects.history');
        // Which Registered User account a project is connected to. Admin and
        // Super Admin both manage this - the group above admits exactly those
        // two - and the controller asks the same question again rather than
        // trusting the group, because this decides who may read a project's
        // whole history on the public website.
        Route::put('/projects/{id}/registered-user', [ProjectController::class, 'updateRegisteredUser'])
            ->name('projects.registered-user.update');
        Route::delete('/projects/{id}/registered-user', [ProjectController::class, 'removeRegisteredUser'])
            ->name('projects.registered-user.destroy');

        Route::put('/projects/{id}/hold', [ProjectController::class, 'putOnHold'])->name('projects.hold');
        Route::put('/projects/{id}/resume', [ProjectController::class, 'resume'])->name('projects.resume');
        Route::post('/projects/{id}/complete', [ProjectController::class, 'complete'])->name('projects.complete');

        // Putting a project that is waiting on its client back to work. The
        // only action allowed on a locked project, and only on that one status
        // - a completed project is refused by ProjectReopen itself, so the
        // rule does not depend on which page drew the button.
        Route::post('/projects/{id}/reopen', [ProjectController::class, 'reopen'])->name('projects.reopen');
        Route::post('/projects/{id}/cancel', [ProjectController::class, 'cancel'])->name('projects.cancel');
        Route::post('/projects/{id}/archive', [ProjectController::class, 'archive'])
            ->middleware('role:super_admin')
            ->name('projects.archive');
        Route::put('/projects/{id}/restore', [ProjectController::class, 'restore'])
            ->middleware('role:super_admin')
            ->name('projects.restore');

        // What stands between an archived project and its old dates, read by
        // the Schedule Conflict dialog when it opens and again every time it
        // rechecks. Behind the same privilege as the restore itself: it names
        // other projects and the people on them, so it is not a wider door
        // than the action it belongs to.
        Route::get('/projects/{id}/restore-conflicts', [ProjectController::class, 'restoreConflicts'])
            ->middleware('role:super_admin')
            ->name('projects.restore-conflicts');

        // Moving or dropping one range of an archived project's own schedule,
        // which is how a restore conflict is resolved: the archived project
        // gives way, and the live work it clashed with is left alone.
        Route::put('/projects/{id}/restore-schedule', [ProjectController::class, 'updateRestoreSchedule'])
            ->middleware('role:super_admin')
            ->name('projects.restore-schedule');
        // Documents are addressed one at a time: a project may hold several of
        // each type, so the type alone no longer names a file.
        Route::get('/projects/{id}/documents/{document}', [ProjectController::class, 'previewDocument'])
            ->whereNumber('document')
            ->name('projects.documents.preview');
        Route::delete('/projects/{id}/documents/{document}', [ProjectController::class, 'destroyDocument'])
            ->whereNumber('document')
            ->name('projects.documents.destroy');
        Route::put('/projects/{id}', [ProjectController::class, 'update'])->name('projects.update');
        Route::put('/projects/{id}/team', [ProjectController::class, 'updateAssignedTeam'])->name('projects.team.update');
        Route::post('/projects/{id}/reports', [TechnicianReportController::class, 'store'])->name('technician.reports.store');
        Route::post('/projects/{id}/task', [TaskController::class, 'store'])->name('task.store');
        Route::patch('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
        Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

        // ROUTE FOR SUPER ADMIN SCHEDULES PAGE
        Route::get('/schedules', [ScheduleController::class, 'index'])->name('schedules.index');
        Route::get('/schedules/assignable', [ScheduleController::class, 'assignableProjects'])->name('schedules.assignable');
        Route::post('/schedules/assign', [ScheduleController::class, 'assign'])->name('schedules.assign');
        Route::get('/schedules/date/{date}', [ScheduleController::class, 'dateDetails'])->name('schedules.date');

        // Taking a single date off one booking, from the panel that opens
        // when a calendar date is clicked. Declared before /schedules/{id} so
        // the longer path is matched first.
        Route::delete('/schedules/{schedule}/dates/{date}', [ScheduleController::class, 'removeDate'])
            ->whereNumber('schedule')
            ->name('schedules.dates.destroy');
        Route::put('/schedules/{id}', [ScheduleController::class, 'update'])->name('schedules.update');

        // ROUTE FOR SUPER ADMIN REPORTS PAGE
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

        // The archived technician reports, on the same terms as the archived
        // projects and archived accounts pages. Declared before the
        // /reports/technician-reports/{report} route below so the literal
        // segment is matched first.
        //
        // Admin reaches this as well as Super Admin: an Admin may archive any
        // report, and a list they can fill but not read would be a strange
        // half of a feature. Who may put one back is decided per report by
        // TechnicianReportPolicy, not by the door.
        Route::get('/reports/archived', [ReportController::class, 'archivedIndex'])->name('reports.archived');
        Route::get('/reports/technician-reports', [ReportController::class, 'technicianReports'])->name('reports.technician');
        Route::get('/reports/technician-reports/{report}', [ReportController::class, 'showTechnicianReport'])->name('reports.technician.show');
        Route::get('/reports/reportable-projects', [ReportController::class, 'reportableProjects'])->name('reports.reportable');
        Route::get('/reports/system', [ReportController::class, 'systemReports'])->name('reports.system');
        Route::get('/reports/system/chart', [ReportController::class, 'systemChart'])->name('reports.system.chart');
        Route::post('/reports/export', [ReportController::class, 'export'])->name('reports.export');

        // ROUTE FOR SUPER ADMIN TECHNICIANS PAGE
        Route::get('/technicians', [TechnicianController::class, 'index'])->name('technicians.index');
        // The specialty approval queue. Declared before /technicians/{technician}
        // so the literal segment wins.
        Route::put('/technicians/specialty-requests/{specialtyRequest}/approve', [TechnicianController::class, 'approveSpecialtyRequest'])
            ->name('technicians.specialty-requests.approve');
        Route::put('/technicians/specialty-requests/{specialtyRequest}/reject', [TechnicianController::class, 'rejectSpecialtyRequest'])
            ->name('technicians.specialty-requests.reject');

        Route::get('/technicians/{technician}', [TechnicianController::class, 'show'])->name('technicians.show');
        Route::put('/technicians/{technician}/specialties', [TechnicianController::class, 'syncSpecialties'])->name('technicians.specialties.sync');
        Route::get('/technicians/{technician}/calendar', [TechnicianController::class, 'calendar'])->name('technicians.calendar');
        Route::get('/technicians/{technician}/assignable-projects', [TechnicianController::class, 'assignableProjects'])->name('technicians.assignable');
        Route::post('/technicians/{technician}/projects', [TechnicianController::class, 'assignToProjects'])->name('technicians.projects.store');
        Route::get('/technicians/{technician}/projects/{project}', [TechnicianController::class, 'assignment'])->name('technicians.assignment');
        Route::delete('/technicians/{technician}/projects/{project}', [TechnicianController::class, 'removeFromProject'])->name('technicians.projects.destroy');

        // ROUTE FOR SUPER ADMIN TASKS PAGE
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('/projects/{id}/task-form-data', [TaskController::class, 'projectFormData'])->name('projects.task-form-data');

        // ROUTE FOR SUPER ADMIN CONFIGURATION PAGE
        Route::prefix('configuration')->name('configuration.')->group(function () {
            Route::get('/', [ConfigurationController::class, 'index'])->name('index');

            // User Management tables.
            Route::get('/users/employees', [ConfigurationController::class, 'employees'])->name('users.employees');
            Route::get('/users/clients', [ConfigurationController::class, 'clients'])->name('users.clients');
            Route::get('/users/generated-password', [ConfigurationController::class, 'generatePassword'])->name('users.password');

            // Archived Accounts. Declared before /users/{user} so the literal
            // segment wins, and Super Admin only for the same reason project
            // archiving is.
            Route::get('/users/archived', [ConfigurationController::class, 'archivedAccounts'])
                ->middleware('role:super_admin')
                ->name('users.archived');

            // Activity Logs, and the same trail as a file. The export sits
            // inside this group and nowhere else, so whoever may read the page
            // may export it and nobody else can - and the controller narrows
            // the rows to what the reader may see on top of that. Declared
            // before the table route so the literal segment wins.
            Route::get('/activity-logs/export', [ConfigurationController::class, 'exportActivityLogs'])
                ->name('activity-logs.export');
            Route::get('/activity-logs', [ConfigurationController::class, 'activityLogs'])->name('activity-logs');

            // Inquiries: the messages written in from the public Contact page.
            // Admin and Super Admin both reach these - handling an enquiry is
            // ordinary administrative work - and only the archive is narrowed
            // further, exactly as archived accounts and projects are.
            Route::prefix('inquiries')->name('inquiries.')->group(function () {
                Route::get('/', [InquiryController::class, 'index'])->name('index');

                // Declared before /{inquiry} so the literal segment wins.
                Route::get('/archived', [InquiryController::class, 'archivedIndex'])
                    ->middleware('role:super_admin')
                    ->name('archived');

                Route::get('/{inquiry}', [InquiryController::class, 'show'])
                    ->whereNumber('inquiry')
                    ->name('show');
                Route::put('/{inquiry}/status', [InquiryController::class, 'updateStatus'])
                    ->whereNumber('inquiry')
                    ->name('status');
                Route::post('/{inquiry}/reply', [InquiryController::class, 'reply'])
                    ->whereNumber('inquiry')
                    ->name('reply');
                Route::delete('/{inquiry}', [InquiryController::class, 'archive'])
                    ->whereNumber('inquiry')
                    ->name('archive');
                Route::put('/{inquiry}/restore', [InquiryController::class, 'restore'])
                    ->whereNumber('inquiry')
                    ->middleware('role:super_admin')
                    ->name('restore');
            });

            // System Contents: the public website's text and images. Admin
            // reaches this prefix but not these endpoints - the controller
            // turns anyone who is not a Super Admin away.
            Route::prefix('contents')->name('contents.')->group(function () {
                Route::get('/{section}', [SystemContentController::class, 'show'])->name('show');
                Route::put('/{section}', [SystemContentController::class, 'update'])->name('update');
                Route::post('/images/{key}', [SystemContentController::class, 'storeImage'])->name('images.store');
                Route::delete('/images/{key}', [SystemContentController::class, 'destroyImage'])->name('images.destroy');
            });

            // Project Types: the catalogue of work the company does, which is
            // also the technician specialty list. Super Admin only, turned
            // away by the controller on the same terms as System Contents.
            Route::prefix('project-types')->name('project-types.')->group(function () {
                Route::get('/', [ProjectTypeController::class, 'index'])->name('index');
                Route::post('/', [ProjectTypeController::class, 'store'])->name('store');
                Route::put('/{projectType}', [ProjectTypeController::class, 'update'])->name('update');
                Route::delete('/{projectType}', [ProjectTypeController::class, 'destroy'])->name('destroy');
            });

            // Creation.
            Route::post('/users/employees', [ConfigurationController::class, 'storeEmployee'])->name('users.employees.store');
            Route::post('/users/clients', [ConfigurationController::class, 'storeClient'])->name('users.clients.store');

            // A single account, then the actions available on it. POST rather
            // than PUT for the updates: both carry a multipart profile picture,
            // which PHP does not parse from a PUT body.
            Route::get('/users/{user}', [ConfigurationController::class, 'show'])->name('users.show');

            // The projects a Registered User is connected to, behind the View
            // Projects action on their row. Read-only, and inside the same
            // administrative group as the rest of User Management.
            Route::get('/users/{user}/projects', [ConfigurationController::class, 'userProjects'])
                ->name('users.projects');
            Route::post('/users/{user}/employee', [ConfigurationController::class, 'updateEmployee'])->name('users.employees.update');
            Route::post('/users/{user}/client', [ConfigurationController::class, 'updateClient'])->name('users.clients.update');
            Route::put('/users/{user}/password', [ConfigurationController::class, 'resetPassword'])->name('users.password.reset');
            Route::put('/users/{user}/status', [ConfigurationController::class, 'setStatus'])->name('users.status');

            Route::middleware('role:super_admin')->group(function () {
                Route::delete('/users/{user}', [ConfigurationController::class, 'archive'])->name('users.archive');
                Route::put('/users/{user}/restore', [ConfigurationController::class, 'restoreAccount'])->name('users.restore');
            });
        });
    });

// Technician portal. Both technician roles share every page here: the same
// calendar, the same DataTables, the same task board. What differs is reach,
// and that is decided by ProjectPolicy / TaskPolicy inside the pages rather
// than by giving the two roles separate screens.
//
// A plain technician reads everything and completes their own tasks. A lead
// additionally runs the task board, files reports and closes projects.
Route::prefix('technician')
    ->name('technician.')
    ->middleware(['auth', 'password.changed', 'role:technician,lead_technician'])
    ->group(function () {
        Route::get('/schedule', [TechnicianPortalController::class, 'schedule'])->name('schedule');
        Route::get('/projects', [TechnicianPortalController::class, 'projects'])->name('projects');
        Route::get('/tasks', [TechnicianPortalController::class, 'tasks'])->name('tasks');

        // Reading a project, and closing your own task. Each is gated by a
        // policy: you only ever see a project you are assigned to, and you
        // only complete a task that is yours (or, for a lead, one on a project
        // they run).
        Route::get('/projects/{project}', [TechnicianPortalController::class, 'showProject'])
            ->name('projects.show');
        Route::get('/projects/{project}/details', [TechnicianPortalController::class, 'projectDetails'])
            ->name('projects.details');
        Route::post('/tasks/{task}/complete', [TechnicianPortalController::class, 'completeTask'])
            ->name('tasks.complete');

        // Lead-only. Each one is additionally gated by a policy, so the
        // middleware here is the outer fence rather than the whole of the
        // permission model.
        Route::middleware('role:lead_technician')->group(function () {
            Route::get('/reports', [TechnicianPortalController::class, 'reports'])->name('reports');

            // A lead's own archive. Declared beside the log it belongs to, and
            // narrowed the same way: what a lead filed away themselves.
            Route::get('/reports/archived', [TechnicianPortalController::class, 'archivedReports'])
                ->name('reports.archived');

            Route::post('/projects/{project}/complete', [TechnicianPortalController::class, 'completeProject'])
                ->name('projects.complete');
            Route::get('/projects/{project}/task-form-data', [TechnicianPortalController::class, 'taskFormData'])
                ->name('projects.task-form-data');
            Route::post('/projects/{project}/tasks', [TechnicianPortalController::class, 'storeTask'])
                ->name('tasks.store');
            Route::post('/projects/{project}/reports', [TechnicianPortalController::class, 'storeReport'])
                ->name('reports.store');
            Route::post('/tasks/{task}', [TechnicianPortalController::class, 'updateTask'])
                ->name('tasks.update');
            Route::delete('/tasks/{task}', [TechnicianPortalController::class, 'destroyTask'])
                ->name('tasks.destroy');
        });
    });

// Archiving a technician report, and putting it back.
//
// One pair of endpoints for every screen that offers the action - the Reports
// page and Project Details, in both portals - so a report archived from one
// disappears from all of them and there is a single rule to enforce.
//
// The role list here is the outer fence: a plain technician and a client have
// no business archiving anything. Which reports a lead technician may actually
// touch is TechnicianReportPolicy's decision, made per report inside the
// controller - a lead archives only what they filed themselves, whatever the
// page they came from and whatever id they send.
Route::middleware(['auth', 'password.changed', 'role:super_admin,admin,lead_technician'])
    ->prefix('technician-reports')
    ->name('technician-reports.')
    ->group(function () {
        Route::post('/{report}/archive', [TechnicianReportArchiveController::class, 'archive'])
            ->whereNumber('report')
            ->name('archive');
        Route::put('/{report}/restore', [TechnicianReportArchiveController::class, 'restore'])
            ->whereNumber('report')
            ->name('restore');
    });

// A client has no portal of their own. Signing in lands them on the public
// website, and My Projects there shows the work booked under their email -
// see PublicSiteController.
