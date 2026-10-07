# Shortened labels and hints

243 text changes. Rule: 4–5 words where possible, one short sentence at most; hints that repeat what a field or heading already says were removed.

Not changed: emails, PDF report templates, the Terms text itself, website content managed in Configuration, and server-side validation/flash messages.

## Removed or trimmed obvious hints

### `public/js/super-admin/technicians.js`

| Before | After |
|---|---|
| "{name} is off this project from {date} to {date} and back on it {date}." | Removed — the date fields already show this. Only "Choose the days off." shows while no date is picked. |
| "{name} leads this project. Choose a lead technician to stand in from {date} to {date}." | Removed — the "Choose a Stand-in Lead" heading says it. |
| "{name} leads this project. Choose who takes over from {date} - they must be free for the rest of its schedule from that day." | Must be free for the rest of the schedule. |
| "{name} stays on this project until {date} and comes off it for good on {date}." | Last day: {date}. |
| "{name} comes off this project for good today." | Removed — the Remove button says it. |
| "Assigned to {name} on this project." (task list note) | Removed — the list is under the technician already. |

### `resources/views/components/task-create-modal.blade.php`

| Before | After |
|---|---|
| Phase field hint: "Stage this work belongs to." | Removed — the "Phase" label says it. |

### `resources/views/super-admin/projectDetails.blade.php`

| Before | After |
|---|---|
| Confirmation Date hint: "Day the client confirmed." | Removed — the label says it. |
| Reopen dates hint: "The team (names…) is booked. Busy days are greyed out." | Busy days are greyed out. |
| Quotation amount hint: "Enter or confirm the amount." | Removed — the label says it. |

### `resources/views/super-admin/configuration.blade.php`

| Before | After |
|---|---|
| "This is the Registered User's sign-in address." | Locked: used to sign in. |

### `resources/views/super-admin/createProject.blade.php + public/js/super-admin/createProject.js`

| Before | After |
|---|---|
| Import Team hint: "Copy a team from another project. Once you set the schedule below, anyone who is already booked over those dates is flagged here." | Hidden until dates are set, then: "Booked technicians are flagged." |

## Shortened text

### `resources/views/auth/forgot-password.blade.php`

| Before | After |
|---|---|
| Enter your account email and we will send a 6-digit code. | We'll email you a 6-digit code. |
| Verification codes cannot be sent right now. Ask an administrator to reset your password. | Codes unavailable. Ask an administrator. |

### `resources/views/auth/reset-password.blade.php`

| Before | After |
|---|---|
| Your code was accepted. Pick a password only you know. | Code accepted. Choose a new password. |

### `resources/views/components/account-menu.blade.php`

| Before | After |
|---|---|
| Signed in as {{ $displayName }} - open the account menu | Account menu for {{ $displayName }} |

### `resources/views/layouts/publicSite.blade.php`

| Before | After |
|---|---|
| Signed in as {{ $viewer->fullName() }} - open the account menu | Account menu for {{ $viewer->fullName() }} |

### `resources/views/components/document-history-events.blade.php`

| Before | After |
|---|---|
| No {{ strtolower($label) }} file has been uploaded, replaced or removed yet. | No {{ strtolower($label) }} file changes yet. |

### `resources/views/components/import-team-modal.blade.php`

| Before | After |
|---|---|
| Only technicians are copied. You can add or remove people afterwards. | Copies technicians only. |
| No other project has a technician team to copy. | No teams to copy. |
| The rest of the imported team is added; the lead does not change. | Adds the team, keeps your lead. |
| The imported lead takes over, and the current lead comes off the team. | Imported lead replaces current lead. |

### `resources/views/components/phase-setup-alert.blade.php`

| Before | After |
|---|---|
| This project has not been configured with its project phases. It cannot take tasks until the structure is finalized. | Set up phases before adding tasks. |
| {{ $projects->count() }} of your projects have not been configured with their project phases. They cannot take tasks until their structures are finalized. | {{ $projects->count() }} projects need phase setup. |

### `resources/views/components/phase-setup-required.blade.php`

| Before | After |
|---|---|
| This project has not been configured with its project phases. | Phases not set up yet. |
| Define the complete phase structure before work is booked against it. Until the phases are finalized this project is not monitored by phase and cannot take new tasks. | Finalize phases to start adding tasks. |
| An Admin or the project's Lead Technician has to set the phases up before tasks can be created on this project. | An Admin or Lead Technician must set up phases. |

### `resources/views/components/previous-completion-reports-modal.blade.php`

| Before | After |
|---|---|
| These reports are historical. Each one was superseded when the project was reopened, and none of them is this project's current completion report. | Past reports, replaced when reopened. |

### `resources/views/components/project-active-today-flag.blade.php`

| Before | After |
|---|---|
| This project has a booked schedule range covering today. | Work scheduled today. |

### `resources/views/components/project-activity-log.blade.php`

| Before | After |
|---|---|
| What has been recorded against this project, newest first. | Newest first. |
| Entries by a Super Admin or another Admin are not shown. | Admin entries hidden. |
| There is nothing on this page of the activity log. | No entries on this page. |

### `resources/views/components/project-phase-progress.blade.php`

| Before | After |
|---|---|
| This project has not been configured with its project phases yet. | Phases not set up. |

### `resources/views/components/project-phase-setup-flag.blade.php`

| Before | After |
|---|---|
| This project has not been configured with its project phases, so it cannot take tasks yet. | Set up phases to add tasks. |

### `resources/views/components/project-phases.blade.php`

| Before | After |
|---|---|
| The stages this project is monitored through. The structure was locked when it was finalized. | Locked project stages. |
| This phase structure was changed after it was finalized. | Changed after finalizing. |
| This phase has no tasks yet, so there is nothing to complete. | No tasks to complete yet. |
| This phase cannot be completed because {{ $row['openTasks'] }} {{ \Illuminate\Support\Str::plural('task', $row['openTasks']) }} {{ $row['openTasks'] === 1 ? 'is' : 'are' }} still incomplete. | {{ $row['openTasks'] }} {{ \Illuminate\Support\Str::plural('task', $row['openTasks']) }} still open. |
| Completing {{ $phase->label() }} now leaves {{ $row['openTasks'] }} {{ \Illuminate\Support\Str::plural('task', $row['openTasks']) }} open and moves the project on to the next phase. | Leaves {{ $row['openTasks'] }} {{ \Illuminate\Support\Str::plural('task', $row['openTasks']) }} open and moves to the next phase. |
| Unlocking sends this project back to phase setup and stops it accepting new tasks until you finalize it again, and changing its {{ $summary['total'] }} {{ \Illuminate\Support\Str::plural('phase', $summary['total']) }} changes what every progress figure on it is measured against. | New tasks are blocked until you finalize again. |

### `resources/views/components/quotation-history-modal.blade.php`

| Before | After |
|---|---|
| The quotation amount has not been changed since the project was created. | Amount never changed. |

### `resources/views/components/schedule-conflict-modal.blade.php`

| Before | After |
|---|---|
| This project's schedule conflicts with the current availability of its team. Review the affected schedule ranges before continuing. | Team unavailable for some dates. |

### `resources/views/components/schedule-range-row.blade.php`

| Before | After |
|---|---|
| This date range has already ended. Super Admin access is required to make changes. | Ended. Super Admin only. |
| This schedule is under way. Its start date is fixed; the end date can still be moved. | Under way: only the end date can change. |
| partial-day hours. It was booked before those hours were set and has not been changed - pick times inside them to bring it back in, or leave it as it is. | partial-day hours. |

### `resources/views/components/task-board.blade.php`

| Before | After |
|---|---|
| Finalize this project's phases first before adding new tasks. | Finalize phases to add tasks. |

### `resources/views/components/task-complete-modal.blade.php`

| Before | After |
|---|---|
| You can close it on their behalf; details are optional and the closure is recorded against you. | Closing is recorded under your name. |
| JPG, JPEG or PNG, up to 5 MB each. | JPG or PNG, max 5 MB each. |

### `resources/views/components/task-create-modal.blade.php`

| Before | After |
|---|---|
| Which stage of the project this work belongs to. | Stage this work belongs to. |

### `resources/views/components/task-details-modal.blade.php`

| Before | After |
|---|---|
| {{ $task->technician->name }} was removed from this project. Assign this task to a technician on the team before saving. | {{ $task->technician->name }} left the project. Reassign this task. |
| The assigned technician did not submit completion details, so they are not required here. | No completion details required. |
| None submitted &mdash; the task was closed on the technician's behalf. | None submitted (closed by staff). |
| Not recorded &mdash; this task was completed before the system kept a completion date. | Not recorded. |
| This task was marked as completed without any uploaded completion images. | No completion images. |

### `resources/views/components/task-holder-flag.blade.php`

| Before | After |
|---|---|
| {{ $task->technician->name }} is no longer on this project. Reassign this task. | {{ $task->technician->name }} left. Reassign this task. |
| {{ $task->technician->name }} has a day off on this project within this task's dates. | {{ $task->technician->name }} is off during this task. |

### `resources/views/components/task-phase-cell.blade.php`

| Before | After |
|---|---|
| This task is not filed under a project phase. | No phase assigned. |

### `resources/views/components/terms-agreement-modal.blade.php`

| Before | After |
|---|---|
| We have updated our Terms and Conditions since you last agreed to them. Please read the current version below and accept it to carry on using your account. If you would rather not accept them now, you can log out and we will ask again next time you sign in. | Please accept the updated terms to continue. |
| Please read the Terms and Conditions below and accept them to carry on using your account. If you would rather not accept them now, you can log out and we will ask again next time you sign in. | Please accept the terms to continue. |

### `resources/views/profile/edit.blade.php`

| Before | After |
|---|---|
| Your details, and the only place they can be changed. | Manage your details. |
| JPG, PNG or WEBP, up to 5 MB. Square images work best. | JPG, PNG or WEBP, max 5 MB. |
| Role, status and date of birth can only be changed by an administrator. | Only an administrator can change these. |
| Your sign-in address. Changing it requires a code sent to the new address. | Changing it needs a code. |
| 'A change is already waiting for an administrator to decide.' | 'A request is already pending.' |
| Your current specialties stay active until it is decided. | Current specialties stay active. |
| Select the specialties you should hold. Nothing changes until an administrator approves it. | Needs administrator approval. |

### `resources/views/projects/phaseSetup.blade.php`

| Before | After |
|---|---|
| This project is not monitored and cannot take tasks until its phases are finalized. | Finalize phases to start adding tasks. |
| Changing the count changes what every progress figure on this project is read against. | Changing it affects progress figures. |
| Removing one of these will ask you where its tasks should go. Tasks are never deleted with a phase. | Removed phases keep their tasks. |
| Give each phase a title and one-sentence description, then list the work it needs &mdash; a technician and dates are optional. | Add a title, description and tasks. |
| Once phases are finalized, the phase structure will be locked. Admins and Lead Technicians will no longer be able to add, remove, or reorder phases. Make sure all project phases have been entered correctly before continuing. | Phases will be locked after this. |
| The tasks are moved, never deleted. Only phases you are keeping are listed. | Tasks are moved, not deleted. |

### `resources/views/public/contact.blade.php`

| Before | After |
|---|---|
| A map appears here once its embed link is set. | Map not set yet. |

### `resources/views/public/my-projects.blade.php`

| Before | After |
|---|---|
| Work {{ $content->get('branding.short_name') }} is carrying out for you, newest first. | Your projects, newest first. |
| This page shows a Registered User's own projects. | For Registered Users only. |
| Your projects appear once you sign in with the email address they were booked under. | Sign in with your booking email. |
| You are signed in as {{ auth()->user()->roleLabel() }} - open your portal instead. | Use your {{ auth()->user()->roleLabel() }} portal instead. |

### `resources/views/public/project-details.blade.php`

| Before | After |
|---|---|
| Confirm that the work on this project is complete? This closes the project and cannot be undone. | Confirm completion? This cannot be undone. |
| Something not right? Contact us instead of confirming and we will put it right. @if ($supportPhone) You can also call <strong>{{ $supportPhone }}</strong>. @endif Getting in touch does not pause the {{ \App\Models\Project::completionConfirmationDays() }} day confirmation period. | Something wrong? Contact us first. @if ($supportPhone) Call <strong>{{ $supportPhone }}</strong>. @endif |
| Updates filed from site by the technicians working on your project. | Updates from your technicians. |
| No reports yet. Updates appear here as work progresses. | No reports yet. |

### `resources/views/super-admin/archivedProjects.blade.php`

| Before | After |
|---|---|
| Archived projects are preserved but removed from the active list. | Kept, but hidden from active projects. |
| It returns as <strong>Unscheduled</strong> - this project was archived before archiving kept schedules, so its dates and team must be set again. | It returns as <strong>Unscheduled</strong>; set dates and team again. |

### `resources/views/super-admin/archivedReports.blade.php`

| Before | After |
|---|---|
| Archived technician reports are preserved in full - images and attachments included - and removed from the active reports list. | Kept in full, hidden from active reports. |
| It returns to the active reports list and to its project's report list, with the same submitter, images and attachments it was archived with. | It returns to the active reports. |

### `resources/views/technician/archivedReports.blade.php`

| Before | After |
|---|---|
| Reports you filed away. Nothing was deleted - each one keeps its project, its images and its attachments, and can be restored to your active list. | Archived reports. Nothing was deleted. |
| It returns to your Reports page and to its project's report list, with the same images and attachments it was archived with. | It returns to your Reports page. |

### `resources/views/super-admin/configuration.blade.php`

| Before | After |
|---|---|
| Every employee and Registered User account in the system. | All employee and client accounts. |
| Every recorded action across the system, newest first. | All actions, newest first. |
| Entries by a Super Admin or another Admin are not shown. | Admin entries hidden. |
| No project types yet. Add the first one above. | No project types yet. |
| What each kind of job starts with when a new project is set up. | Default phases per project type. |
| Shared by every project type, in the order jobs run. | Shared stages, in order. |
| Pick a type, tick the stages its work goes through, then list what it starts with. | Pick a type, then its stages and tasks. |
| Add a phase stage in step 1 before writing a template. | Add a stage first. |
| Everything the public website shows. Saved changes go live immediately. | Public website content. Changes go live. |
| A public website account for a company or homeowner | Client website account |
| Sets what this account may do across the system. | Controls account access. |
| A new password is required at first sign-in. @if ($mailEnabled) A copy is emailed to them automatically. @else Email delivery is not configured, so hand this over directly. @endif | @if ($mailEnabled) Emailed; must change at first sign-in. @else Email is off; share it directly. @endif |
| Nothing was deleted. Restoring brings an account back as it was. | Nothing was deleted. |
| Write the reply that will be emailed to them&hellip; | Write your reply&hellip; |
| This inquiry is archived. Restore it to change its status or reply. | Archived. Restore to reply. |
| Nothing was deleted. Restoring puts an inquiry back on the active list. | Nothing was deleted. |

### `resources/views/super-admin/createProject.blade.php`

| Before | After |
|---|---|
| Fill all the necessary details to create a new project. | Fill in the project details. |
| Choose the project scope, attach the files, and add the address. | Scope, files and address. |
| Assign the lead tech, choose the technicians, then set the dates. | Team and dates. |
| This is the initial schedule for the project. More schedule dates can be added later. | Initial schedule only. Add more later. |
| Copy a team from another project. Once you set the schedule below, anyone who is already booked over those dates is flagged here. | Copy a team from another project. |
| Only dates where everyone has a free slot can be picked. | Only shared free dates can be picked. |
| You may select more than one. | Multiple files allowed. |
| Books the whole of every day in the range | Books full days |

### `resources/views/super-admin/projectDetails.blade.php`

| Before | After |
|---|---|
| Its schedule, team, tasks, reports, documents and history stay with it on the Archived Projects page, and its technicians are freed for those dates. | Its technicians are freed. |
| It will go to Awaiting Client Confirmation as usual and complete automatically after {{ \App\Models\Project::completionConfirmationDays() }} days if no confirmation is received. If the client registers before then they can confirm it themselves, and an administrator can record a confirmation given by phone, in person or on paper at any time. | Auto-completes after {{ \App\Models\Project::completionConfirmationDays() }} days. |
| Resume it to add schedules, reports, tasks or technicians. | Resume it to make changes. |
| The dates still ahead of it are kept as its proposed schedule. Its team is free for other work in the meantime, so resuming checks those dates again before putting them back into force. | Future dates are kept for resuming. |
| It will complete automatically unless the client registers, or an administrator records a confirmation given another way. | It will auto-complete. |
| This project was previously completed and has been reopened{{ $reopenedOn ? ' on ' . $reopenedOn : '' }}{{ $reopenedBy ? ' by ' . $reopenedBy : '' }}. | Reopened{{ $reopenedOn ? ' on ' . $reopenedOn : '' }}{{ $reopenedBy ? ' by ' . $reopenedBy : '' }}. |
| Partial Day books only these hours on the one date, between {{ $partialDayWindow['start_label'] }} and {{ $partialDayWindow['end_label'] }} (Project Settings). A technician booked for the whole of a day is still unavailable for part of it. | Hours between {{ $partialDayWindow['start_label'] }} and {{ $partialDayWindow['end_label'] }}. |
| is booked onto these dates. Days any of them are already spoken for are greyed out, and a clash will still refuse the reopen. | is booked. Busy days are greyed out. |
| Recorded in the activity log and shown to the client. At least 10 characters. | Shown to the client. Min 10 characters. |
| . A completed project is a historical record and cannot be reopened. | . Cannot be reopened. |
| Add a new schedule or mark it complete. | Add dates or complete it. |
| This project has been completed and reopened before. It has no current completion report. | No current completion report. |
| can no longer sign in but are still booked. Reassign or remove them. | cannot sign in. Reassign them. |
| This project is on hold. Resume it before adding schedules. | On hold. Resume first. |
| It comes off this project's report list and off the active Reports page. The report, its images and its attachments are kept, and it can be restored from Archived Reports. The project, its schedule and its team are not affected. | It can be restored later. |
| This project's phases have not been set up yet. | Phases not set up. |
| This project is on hold. Resume it before editing tasks. | On hold. Resume first. |
| Its schedule and technicians are released. This cannot be undone. | This cannot be undone. |
| Changing the amount asks whether the quotation file should be replaced as well. | You will be asked about the file. |
| {{ \App\Models\Document::MAX_LABEL }} each. Assessment and contract uploads are added to the files already on record; a quotation upload replaces the current quotation, which is kept in its history. | {{ \App\Models\Document::MAX_LABEL }} each. |
| {{ \App\Models\Document::MAX_LABEL }} each. The current quotation file is kept in the quotation history. | {{ \App\Models\Document::MAX_LABEL }} each. |
| Enter the amount on the new quotation file, or confirm the current one. | Enter or confirm the amount. |
| Cancel goes back to the form. Nothing is saved until you choose. | Nothing is saved yet. |
| Changes take effect today. Anybody removed here comes off this project completely. To take a technician off for some days, or from a later date, use the Technicians page. | Changes take effect today. |
| Choose the account on the public website to link to this project. The project's own client details are not changed by this. | Pick an account to link. |
| There are no accounts to link yet. Open one in Configuration, under User Management, first. | No accounts to link yet. |
| The account keeps its details and its other projects, and this project keeps its client information, team, schedule, tasks and reports. Only the connection ends - the project stops appearing on their My Projects page. | Only the link is removed. |
| Use this when the client has already confirmed that the work is finished, but did so somewhere other than the website. This completes <strong>{{ $project->reference_no }}</strong> straight away rather than waiting out the remaining {{ \App\Models\Project::completionConfirmationDays() }}-day window. | Completes <strong>{{ $project->reference_no }}</strong> now. |
| The day the client confirmed, which may be before today. | Day the client confirmed. |
| e.g. Client confirmed completion by call to Ana Mendoza. | e.g. Confirmed by call. |
| Say who received the confirmation and from whom. This is kept with the project and in the activity log. | Who confirmed, and to whom. |
| Why is this being completed with the above outstanding? | Why complete it now? |
| JPG, JPEG, or PNG. You can select multiple photos. | JPG or PNG. Multiple allowed. |

### `resources/views/super-admin/projectDocumentPreview.blade.php`

| Before | After |
|---|---|
| This file type cannot be previewed in the browser. Use the open original file button instead. | No preview. Open the original file. |

### `resources/views/super-admin/projects.blade.php`

| Before | After |
|---|---|
| ' can no longer sign in. Open the project to reassign the work.' | ' cannot sign in. Reassign the work.' |
| 'This project has no lead technician. Open the project and choose one in Assigned Team.' | 'No lead technician assigned.' |
| No registered client can confirm this project online. It completes automatically when the window ends, unless an administrator records a confirmation given another way. | It will auto-complete. |
| The dates it kept come back into force, so its team has to still be free for them. | Its kept dates are rechecked. |
| It will go to Awaiting Client Confirmation as usual and complete automatically after {{ \App\Models\Project::completionConfirmationDays() }} days if no confirmation is received. | Auto-completes after {{ \App\Models\Project::completionConfirmationDays() }} days. |
| Why is this being completed with the above outstanding? | Why complete it now? |
| JPG, JPEG, or PNG. You can select multiple photos. | JPG or PNG. Multiple allowed. |
| Its schedule, team, tasks, reports, documents and history stay with it on the Archived Projects page, and its technicians are freed for those dates. | Its technicians are freed. |

### `resources/views/super-admin/reports.blade.php`

| Before | After |
|---|---|
| Every technician report in one place, plus system-wide analytics. | All reports and analytics. |
| Completed, cancelled and archived projects can no longer receive reports. | Closed projects take no reports. |
| Optional. JPG, PNG or JPEG, up to 5 MB each. | Optional. JPG or PNG, max 5 MB. |
| Opens a print-ready preview you can print or save as PDF. Archived projects are excluded from every report. | Print or save as PDF. |
| It comes off the active reports list and off its project's report list. The report, its images and its attachments are kept, and it can be restored from View Archived Reports. | It can be restored later. |

### `resources/views/super-admin/schedule.blade.php`

| Before | After |
|---|---|
| Every scheduled project. Click one to edit its dates. | Click a project to edit dates. |
| The dates it kept come back into force, so its team has to still be free for them. | Its kept dates are rechecked. |
| No dates set. Add a schedule to put this project on the calendar. | No dates set yet. |
| Removing every schedule leaves this project Unscheduled. @if ($project->isResidential()) A Partial Day schedule books set hours on one date, leaving the rest of that day free. @endif @if ($mayOverrideLock) Dates that have already passed can be booked here to record work that was done but never scheduled; you will be asked who worked them. @endif | No dates means Unscheduled. |
| These dates are in the past and were not previously scheduled for this project: | Past, unscheduled dates: |
| Type a name to find who worked these dates | Search technician name |
| The record already places these people on another project on these dates. You can continue if what you are recording here is accurate. | Already booked elsewhere on these dates. |
| I have checked these and the work recorded here is accurate. | I confirm this is accurate. |
| This is recorded against your account, with the dates, the range before and after, the names you choose here, and any conflict you confirm. | Recorded under your account. |
| On hold. Only worked days remain - resume the project to schedule it again. | On hold. Resume to reschedule. |
| This project is {{ strtolower($project->statusLabel()) }}. Its schedule is now read-only. | {{ $project->statusLabel() }}. Read-only. |
| Projects with no dates yet, and projects whose dates have all passed while the work is still open. | Projects needing dates. |
| Every project has dates it has not yet run past. Nothing is waiting to be scheduled. | Nothing waiting to be scheduled. |
| Dates where a technician is booked elsewhere cannot be picked. | Booked dates are disabled. |
| Books the whole of every day in the range. | Books full days. |

### `resources/views/super-admin/technicians.blade.php`

| Before | After |
|---|---|
| Select a scheduled project from the calendar to view its details. | Pick a project on the calendar. |
| No tasks assigned to this technician on this project. | No tasks on this project. |
| No lead technician is free for these dates. Free one up or change the schedule first. | No lead technician is free. |
| No project booked on this day can take this technician. | No project can take them. |
| There are no projects this technician can join right now. | No projects to join. |

### `resources/views/super-admin/partials/report-sections.blade.php`

| Before | After |
|---|---|
| No records found for the selected reporting period and filters. | No records found. |

### `resources/views/super-admin/partials/team-schedule-modal.blade.php`

| Before | After |
|---|---|
| Nothing scheduled - no days off, start or end date for {{ $technician->name }}. | Nothing scheduled for {{ $technician->name }}. |

### `resources/views/technician/partials/complete-task-modal.blade.php`

| Before | After |
|---|---|
| JPG, JPEG or PNG, up to 5 MB each. Optional. | Optional. JPG or PNG, max 5 MB. |

### `resources/views/technician/partials/completion-fields.blade.php`

| Before | After |
|---|---|
| JPG, JPEG or PNG, up to 5 MB each. At least one photo is required. | At least one JPG or PNG. |

### `resources/views/technician/partials/report-form-modal.blade.php`

| Before | After |
|---|---|
| None of your projects can receive a report right now. | No projects open for reports. |
| JPG, JPEG or PNG, up to 5 MB each. Optional. | Optional. JPG or PNG, max 5 MB. |

### `resources/views/technician/projectDetails.blade.php`

| Before | After |
|---|---|
| Reports and task edits resume when an administrator lifts the hold. | Changes resume when the hold ends. |
| Close it off, or ask an administrator to add a new schedule. | Complete it or ask for dates. |
| can no longer sign in. Move their tasks and ask an administrator to update the team. | cannot sign in. Move their tasks. |
| You have no days booked on this project yet. | No days booked yet. |
| It comes off this project's report list and off your Reports page. The report, its images and its attachments are kept, and it can be restored from Archived Reports. | It can be restored later. |
| JPG, JPEG or PNG, up to 5 MB each. Optional. | Optional. JPG or PNG, max 5 MB. |
| All tasks are complete. Submitting this sends the project to the client and makes it view only. | Sends it to the client for confirmation. |

### `resources/views/technician/projects.blade.php`

| Before | After |
|---|---|
| ' can no longer sign in. Open the project to move their tasks, and ask an administrator to update the team.' | ' cannot sign in. Move their tasks.' |
| 'This project has no lead technician. Ask an administrator to assign one.' | 'No lead technician assigned.' |
| All tasks are complete. Marking it complete makes the project view only. | This makes the project view only. |

### `resources/views/technician/reports.blade.php`

| Before | After |
|---|---|
| Reports you submitted. To read every report on a project, whoever filed it, open the project. | Reports you submitted. |
| None of your projects can receive a report right now. Reports can only be submitted on a project's scheduled days, and completed, cancelled and archived projects are closed records. | No projects open for reports today. |
| It comes off this list and off its project's report list. The report, its images and its attachments are kept, and it can be restored from View Archived Reports. | It can be restored later. |

### `resources/views/technician/schedule.blade.php`

| Before | After |
|---|---|
| Every project you are booked on. Pick one to see its details and your tasks on it. | Your booked projects. |
| Select a project from the calendar to view its details. | Pick a project on the calendar. |

### `resources/views/technician/tasks.blade.php`

| Before | After |
|---|---|
| The whole task board for every project you are on, grouped by project. | Tasks, grouped by project. |

### `public/js/super-admin/schedule.js`

| Before | After |
|---|---|
| "Books the whole of every day in the range." | "Books full days." |
| "Books set hours on one date, leaving the rest of that day free." | "Books set hours on one date." |
| "Books set hours on the clicked date. Residential projects only." | "Set hours. Residential only." |
| "Everyone named here is recorded as having worked these dates." | "" |
| ". This is recorded against your account." | "." |
| "Their start and due dates will be cleared and they will need new dates. " + "Completed tasks are never changed.", | "Their dates will be cleared.", |
| "Changing them alters the record of completed work, " + "and is logged against your account.", | "This changes the work record.", |

### `public/js/importTeam.js`

| Before | After |
|---|---|
| All technicians are not available for this project's future schedule. | No technicians are free for these dates. |
| Set the schedule first so technicians can be checked. | Set the schedule first. |

### `public/js/phaseSetup.js`

| Before | After |
|---|---|
| "There is no other saved phase to move this work to. Save the new phases first, then remove this one." | "Save another phase first." |

### `public/js/taskCreate.js`

| Before | After |
|---|---|
| This project has no phases to file a task under. Set up its project phases first. | Set up project phases first. |
| Every technician on this project is inactive. Ask an administrator to update the team. | All technicians are inactive. |

### `public/js/super-admin/configuration.js`

| Before | After |
|---|---|
| Email is not configured - hand this over directly. Shown only once. | Shown once. Share it directly. |
| Their current password stops working immediately, and they will have to choose a new one at next sign-in. | Their current password stops working. |
| They can no longer sign in. Nothing is deleted, and the account can be restored. | They can no longer sign in. |
| They can no longer sign in. Nothing is deleted. | They can no longer sign in. |

### `public/js/super-admin/createProject.js`

| Before | After |
|---|---|
| Please choose a time when every selected technician is free. | Pick a time the whole team is free. |
| Please select a continuous date range where all selected technicians are available. | Pick dates the whole team is free. |

### `public/js/super-admin/phaseTemplates.js`

| Before | After |
|---|---|
| "New projects will no longer be offered this phase. " + "Projects already set up keep the phases they have.", | "Existing projects keep it.", |
| ". Untick it there first - removing it would take " + plural(stage.task_count, "default task") + " with it." | ". Untick it there first." |
| "The default phases for " + currentTypeName() + " have been changed and not saved. Switching to another type " + "will lose those changes.", | "Unsaved changes will be lost.", |
| No default tasks yet. Projects will get this phase with no work in it. | No default tasks yet. |

### `public/js/super-admin/projectDetails.js`

| Before | After |
|---|---|
| ' will no longer lead in their place, and the days off they were covering are cancelled.' | ' will no longer stand in.' |
| 'A lead technician is involved, so whoever leads in their place is cancelled too.' | 'Their stand-in lead is cancelled too.' |

### `public/js/super-admin/projectTypes.js`

| Before | After |
|---|---|
| 'It will no longer be offered as a project type or as a ' + 'technician specialty. Projects and technicians that already ' + 'carry it are unaffected.', | 'Existing projects keep it.', |

### `public/js/super-admin/projectWorkspace.js`

| Before | After |
|---|---|
| That record could not be found. It may have been removed. | Record not found. |
| Something went wrong on the server. Reload the page to see what was saved. | Server error. Reload the page. |
| The server answered with something this page cannot show. Reload the page to see what was saved. | Unexpected response. Reload the page. |

### `public/js/super-admin/quotationSync.js`

| Before | After |
|---|---|
| 'Do you also want to replace the uploaded quotation file with a new quotation file ' + 'that reflects this updated amount?', | 'Replace the quotation file too?', |
| 'Do you also want to update the quotation amount to match the new quotation file?' | 'Update the amount too?' |

### `public/js/super-admin/scheduleRecovery.js`

| Before | After |
|---|---|
| : 'Move this range to a period the whole team is free for. ') + 'Past days, days the team is already booked on, and days this project’s other ' + 'ranges hold are greyed out.' | : 'Pick dates the whole team is free.') |
| ? '<p class="conflict-editor-note">Partial Day books only the hours you choose on a ' + 'single date, between ' + escapeHtml(range.partial_day_start_label) + ' and ' + escapeHtml(range.partial_day_end_label) + '. A technician who is booked for the ' + 'whole of a day is still unavailable for part of it.</p>' | ? '<p class="conflict-editor-note">Partial Day: ' + escapeHtml(range.partial_day_start_label) + ' to ' + escapeHtml(range.partial_day_end_label) + '.</p>' |
| ? 'a clash may be resolved with a Partial Day booking of ' | ? 'Partial Day available, ' |
| Partial Day scheduling is for Residential projects only. | Partial Day is Residential only. |
| The calendar changed while this was open. The schedule below is current. | Calendar updated. |

### `public/js/super-admin/technicians.js`

| Before | After |
|---|---|
| ? "This technician was removed from this project on " + data.removed_on + ". These dates are kept as a record of when they were booked." : "This technician is no longer assigned to this project. These dates are kept as a record of when they were booked.", | ? "Removed on " + data.removed_on + ". Kept as a record." : "No longer assigned. Kept as a record.", |
| (affected.length === 1 ? "This task still belongs to them and runs into those days: " : "These tasks still belong to them and run into those days: ") + affected.join(", ") + ". " + (affected.length === 1 ? "It" : "They") + " will be flagged \u201cTechnician Not Assigned for Dates\u201d on the task board for you to reassign." | "Reassign later: " + affected.join(", ") + "." |
| "This project has no scheduled days left to remove. To take " + selectedTechnician.name + " off it, use Assigned Team on the project.", | "No days left. Use Assigned Team instead.", |
| ? "Removed from this project on " + booking.removedOn + ". Kept as a record of when they were booked." : "No longer on this project. Kept as a record of when they were booked.", | ? "Removed on " + booking.removedOn + ". Kept as a record." : "No longer assigned. Kept as a record.", |
| "This day has already passed, so what they were booked on is a record and can no longer be changed." | "Past day. Cannot be changed." |
| This project is on hold. Resume it before changing its assigned technicians. | On hold. Resume first. |
| A project must keep at least one technician. Assign someone else first. | Assign someone else first. |

### `public/js/technician/schedule.js`

| Before | After |
|---|---|
| ? "You were removed from this project on " + props.removedOn + ". These dates stay on your schedule as a record of when you were booked, but the project is no longer assigned to you." : "You are no longer assigned to this project. These dates stay on your schedule as a record of when you were booked.", | ? "Removed on " + props.removedOn + ". Kept as a record." : "No longer assigned. Kept as a record.", |

---

# Round 2: emails, PDFs, server messages, Configuration

Left as-is by choice: the Terms and Conditions text and the public website content (home, about, contact, footer). Also unchanged: activity-log entries (audit records) and developer console commands.

## PDF reports

| File | Before | After |
|---|---|---|
| `resources/views/super-admin/activity-logs-pdf.blade.php` | Entries are shown only where the account exporting them may read them. | Filtered by your access. |
| `resources/views/super-admin/activity-logs-pdf.blade.php` | This document is not the whole match. {matched} entries matched these filters and the {limit} most recent are printed here. Narrow the date range or choose a user to export the rest. | Partial export: latest {limit} of {matched} entries. |

## Shared message

| Files | Before | After |
|---|---|---|
| `ScheduleController.php, ScheduleModeRules.php, ProjectReopen.php` | Partial Day scheduling is for Residential projects only. | Partial Day is Residential only. |

## Emails

### `resources/views/emails/account-status.blade.php`

| Before | After |
|---|---|
| Your {{ $company['name'] }} account has been <strong>temporarily deactivated</strong> by an administrator. You will not be able to sign in until it is reactivated. | Your {{ $company['name'] }} account has been <strong>temporarily deactivated</strong>. |
| Nothing has been deleted. Your projects, documents and history are all intact and will be exactly as you left them when your access is restored. | Nothing has been deleted. |
| If you believe this was a mistake, please contact your administrator. | Think this is a mistake? Contact your administrator. |
| Thank you for confirming your email address. Your {{ $company['name'] }} account is now active and you can sign in at any time. | Your account is now active. |
| Once signed in you can follow the progress of any project booked under this address. | Sign in to follow your projects. |
| Your {{ $company['name'] }} account has been <strong>reactivated</strong>. You may sign in again with the same email address and password you used before. | Your {{ $company['name'] }} account has been <strong>reactivated</strong>. |
| If you have forgotten your password, use "Forgot password?" on the sign-in page. | Forgot your password? Use "Forgot password?" to reset it. |

### `resources/views/emails/client-project-invitation.blade.php`

| Before | After |
|---|---|
| Project {{ $project->reference_no }} is now open. Follow its progress online. | Project {{ $project->reference_no }} is now open. |
| Thank you for choosing {{ $company['name'] }}. Your project has been created and our team is now working on it. You can follow its progress online at any time - the schedule, the assigned technicians, the documents and every status change as it happens. | Your project is created. Follow it online anytime. |
| You already have a {{ $company['name'] }} account under <strong>{{ $contactEmail }}</strong>. Simply sign in and open <em>My Projects</em> to see this project. | Sign in with <strong>{{ $contactEmail }}</strong> and open <em>My Projects</em>. |
| To follow this project, create a free account using <strong>this same email address</strong>: | Create a free account to follow it: |
| Register with <strong>{{ $contactEmail }}</strong> &mdash; a different address will not show this project. | Register with <strong>{{ $contactEmail }}</strong>. |
| If anything about the details above looks wrong, please contact us using the details at the bottom of this email. | Something wrong? Contact us. |

### `resources/views/emails/contact-inquiry.blade.php`

| Before | After |
|---|---|
| {{ $senderName }} wrote in through the Contact page about {{ $inquirySubject }}. | {{ $senderName }}: {{ $inquirySubject }} |
| Somebody has written in through the Contact page. Replying to this email answers them directly. | Reply to this email to answer them. |

### `resources/views/emails/email-changed.blade.php`

| Before | After |
|---|---|
| The email address on your {{ $company['name'] }} account has been changed and confirmed. You will sign in with the new address from now on, and this mailbox will stop receiving notifications about the account. | Sign in with your new address from now on. |
| <strong>If you did not make this change</strong>, contact your administrator immediately - somebody else may have access to your account. | <strong>Not you?</strong> Contact your administrator now. |

### `resources/views/emails/inquiry-reply.blade.php`

| Before | After |
|---|---|
| A reply to the message you sent us about {{ $inquirySubject }}. | Our reply about {{ $inquirySubject }}. |
| Hello {{ $recipientName }}, thank you for getting in touch. Here is our reply to the message you sent us. | Hello {{ $recipientName }}, thanks for writing in. |

### `resources/views/emails/otp-code.blade.php`

| Before | After |
|---|---|
| This code expires in <strong>{{ $minutesValid }} minutes</strong> and can only be used once. | Expires in <strong>{{ $minutesValid }} minutes</strong>. Single use. |
| If you did not request this code, you can safely ignore this email - nothing on your account has changed. Never share this code with anyone, including {{ $company['name'] }} staff. | Not you? Ignore this. Never share this code. |

### `resources/views/emails/specialty-decision.blade.php`

| Before | After |
|---|---|
| An administrator has approved the changes you asked for. Your specialties have been updated, and you will now be matched to work that calls for them. | Your specialties have been updated. |
| An administrator has declined the changes you asked for. <strong>Your current specialties are unchanged</strong>, and you may submit a new request at any time. | <strong>Your specialties are unchanged.</strong> |
| You can review your specialties at any time on your profile page. | View them on your profile. |

### `resources/views/emails/temporary-credentials.blade.php`

| Before | After |
|---|---|
| 'Your account has been created. Here are your sign-in details.' | 'Here are your sign-in details.' |
| An administrator has reset the password on your {{ $company['name'] }} account. Use the temporary password below to sign in. | Sign in with this temporary password. |
| An account has been created for you on the {{ $company['name'] }} {{ $company['tagline'] }}. Use the details below to sign in for the first time. | Your account is ready. Sign in below. |
| You will be asked to choose a new password the first time you sign in. This temporary password stops working at that point, so there is no need to keep it. | You'll choose a new password at first sign-in. |
| If you were not expecting this message, please contact your administrator. | Not expecting this? Contact your administrator. |

### `resources/views/emails/project-update.blade.php`

| Before | After |
|---|---|
| Sign in and open <em>My Projects</em> to see the full history of this project. If you do not have an account yet, register with this email address and the project will appear automatically. | Sign in or register with this email to view it. |

### `resources/views/emails/layout.blade.php`

| Before | After |
|---|---|
| This is an automated message from {{ $company['name'] }}. Please do not reply to it. | Automated message. Please do not reply. |

### `app/Mail/ProjectUpdateMail.php`

| Before | After |
|---|---|
| self::COMPLETED => 'The work on this project has been completed and signed off. Thank you for choosing ' .$company.'. You can review the completion details, including any photographs, on the project page.', | self::COMPLETED => 'Your project is complete. Thank you for choosing '.$company.'.', |
| self::AWAITING_CONFIRMATION => 'Our team has finished the work on this project. Please open the project ' .'page to review what was done, including the completion photographs, and confirm that you are happy ' .'with it. If we do not hear from you within '.$window.' days the project will be marked complete ' .'automatically. If anything needs attention, contact us and we will put it right.', | self::AWAITING_CONFIRMATION => 'Work is done. Please review and confirm within '.$window.' days.', |
| self::CONFIRMATION_REMINDER => 'This project is still waiting for your confirmation. Please review the ' .'completion details on the project page and confirm when you are ready. It will be marked complete ' .'automatically once the '.$window.' day confirmation period ends. If something is not right, ' .'contact us rather than confirming.', | self::CONFIRMATION_REMINDER => 'Please confirm before the '.$window.'-day window ends.', |
| self::CONFIRMED => 'Thank you for confirming that the work is complete. This project is now closed, and ' .'its full record - the schedule, the reports and the completion photographs - stays available to ' .'you online. It has been a pleasure working with you.', | self::CONFIRMED => 'Thanks for confirming. Your project is now closed.', |
| self::AUTO_COMPLETED => 'The '.$window.' day confirmation period for this project has passed, so it has ' .'been marked complete. Its full record remains available to you online. If anything about the work ' .'still needs attention, please get in touch and we will help.', | self::AUTO_COMPLETED => 'Auto-completed after '.$window.' days. Contact us if needed.', |
| self::REOPENED => 'Further work has been scheduled on this project, so it is active again rather than ' .'waiting for your confirmation. The new dates are below, and you will be asked to confirm the ' .'project once that work is finished.', | self::REOPENED => 'More work is scheduled. New dates are below.', |
| self::CANCELLED => 'This project has been cancelled and no further work will be carried out on it. ' .'Its full record remains available to you online.', | self::CANCELLED => 'This project has been cancelled.', |
| self::ON_HOLD => 'Work on this project has been paused. Its schedule has been released, and we will let ' .'you know as soon as it resumes.', | self::ON_HOLD => 'Work is paused. We will tell you when it resumes.', |
| self::RESUMED => 'This project is active again. It will be scheduled shortly, and you will be notified ' .'once the new dates are set.', | self::RESUMED => 'Work has resumed. New dates coming soon.', |
| self::ASSESSMENT_UPLOADED => 'The assessment report for this project has been uploaded and is now ' .'available to view on the project page.', | self::ASSESSMENT_UPLOADED => 'Your assessment report is ready to view.', |
| self::QUOTATION_UPLOADED => 'The quotation for this project has been uploaded and is now available to ' .'view on the project page.', | self::QUOTATION_UPLOADED => 'Your quotation is ready to view.', |
| self::CONTRACT_UPLOADED => 'The contract for this project has been uploaded and is now available to ' .'view on the project page.', | self::CONTRACT_UPLOADED => 'Your contract is ready to view.', |
| self::TARGET_DATE_CHANGED => 'The target completion date for this project has changed.', | self::TARGET_DATE_CHANGED => 'Your target date has changed.', |
| default => 'There has been an update on this project. Open the project page for the full details.', | default => 'Your project has an update.', |
| self::CONFIRMATION_REMINDER => 'Your project is still waiting for confirmation', | self::CONFIRMATION_REMINDER => 'Please confirm your project', |

## Server messages (errors, success toasts, notifications)

### `Http/Controllers/ProfileController.php`

| Before | After |
|---|---|
| 'Profile updated. Enter the code we sent to '.$user->pending_email.' to confirm your new email address.' | 'Profile updated. Enter the code sent to '.$user->pending_email.'.' |

### `Http/Controllers/ProjectController.php`

| Before | After |
|---|---|
| 'Account unlinked. The account and the project were both kept.' | 'Account unlinked.' |
| 'Only a project awaiting client confirmation can be confirmed. This one is %s.' | 'Cannot confirm: project is %s.' |
| 'Unable to update the contact email. Nothing was changed.' | 'Unable to update the contact email.' |
| 'Project contact email changed from %s to %s. The account was not changed.' | 'Contact email changed from %s to %s.' |
| 'Nothing was saved. Someone else changed this project while you were editing it. ' .'Check the details on the page, then save your changes again.' | 'Not saved: someone else just edited this project.' |
| 'Unable to put project on hold. Nothing was changed.' | 'Unable to put project on hold.' |
| 'This project is not ready to be completed. %s Give a reason to complete it anyway.' | 'Not ready to complete. %s Add a reason to override.' |
| 'Completion recorded. %s completes automatically in %d days unless the client replies.' | '%s auto-completes in %d days.' |
| 'Completed projects cannot be reopened - create a new project instead.' | 'Completed projects cannot be reopened.' |
| 'Only a project awaiting client confirmation can be reopened. This one is %s.' | 'Cannot reopen: project is %s.' |
| 'Unable to read that schedule. The project was not reopened.' | 'Invalid schedule. Not reopened.' |
| 'This project is awaiting client confirmation. Reopen it first if the work is not finished.' | 'Awaiting client confirmation. Reopen it first.' |
| 'Unable to change that schedule range. Nothing was changed.' | 'Unable to change that schedule range.' |
| 'This project is on hold. Resume it before changing its assigned technicians.' | 'On hold. Resume it first.' |
| 'This project is '.$project->statusLabel().' and its schedule cannot be changed.' | 'Project is '.$project->statusLabel().'; schedule is locked.' |

### `Http/Controllers/ProjectPhaseController.php`

| Before | After |
|---|---|
| 'This project already has tasks on it, so its phases cannot be reset. Edit the rows instead.' | 'It has tasks. Edit the rows instead.' |
| 'Phase setup saved. The structure is not locked yet.' | 'Phase setup saved.' |
| 'Phases finalized. This project is now monitored across %d %s.' | 'Phases finalized (%d %s).' |
| 'Phase structure unlocked. Make your changes, then finalize it again.' | 'Unlocked. Finalize again when done.' |

### `Http/Controllers/PublicSiteController.php`

| Before | After |
|---|---|
| 'Your message could not be sent just now. Please email or call us instead.' | 'Message not sent. Please email or call us.' |

### `Http/Controllers/ScheduleController.php`

| Before | After |
|---|---|
| '%s is a Commercial project. Partial Day scheduling is for Residential projects only.' | '%s is Commercial. Partial Day is Residential only.' |
| '%s is already under way. Only a Super Admin can remove a schedule that has started.' | '%s has started. Only a Super Admin can remove it.' |
| 'Nothing was saved. This change would clear the dates of %s. Reopen the schedule and confirm to save it.' | 'Not saved: this clears dates on %s. Confirm to save.' |
| 'Nothing was saved. The record already places %s. Reopen the schedule and confirm the correction to save it.' | 'Not saved: the record already places %s.' |
| '%s %s already passed. Recording work already done takes a Super Admin who has confirmed the correction.' | '%s %s already passed. Super Admin confirmation needed.' |
| 'Schedule updated. %s is no longer on this project\'s record.' | 'Schedule updated. %s removed.' |
| 'This project is %s and its schedule can no longer be changed.' | 'Project is %s; schedule is locked.' |
| 'This project is archived and its schedule can no longer be changed.' | 'Archived; schedule is locked.' |
| 'This project is on hold and its schedule can no longer be changed.' | 'On hold; schedule is locked.' |
| 'Today is already under way, so it cannot be removed from the schedule.' | 'Today cannot be removed.' |
| '%s has already passed and cannot be removed. Super Admin access is required to change it.' | '%s has passed. Super Admin only.' |

### `Http/Controllers/TaskController.php`

| Before | After |
|---|---|
| 'This project is on hold. Resume it before adding tasks.' | 'On hold. Resume it first.' |
| 'This project has no schedule yet. Set a schedule before adding tasks.' | 'Set a schedule before adding tasks.' |
| 'This project is on hold. Resume it before editing its tasks.' | 'On hold. Resume it first.' |

### `Http/Controllers/TechnicianController.php`

| Before | After |
|---|---|
| 'This project is %s and its team can no longer be changed.' | 'Project is %s; team is locked.' |
| 'This project is on hold. Resume it before changing its assigned technicians.' | 'On hold. Resume it first.' |
| '%s is not a scheduled day on %s. Choose one of its scheduled days.' | '%s is not a scheduled day on %s.' |
| '%s leads this project. Choose a lead technician to stand in for those days.' | '%s leads this project. Choose a stand-in.' |
| 'A project must keep at least one technician. Assign someone else first.' | 'A project needs at least one technician.' |
| 'That lead technician is no longer free for those days. Choose another.' | 'That lead is no longer free.' |
| ' %s: %s. %s flagged "Technician Not Assigned for Dates" on the task board.' | ' %s: %s. %s flagged on the task board.' |
| 'This task still belongs to them and runs into those days' : 'These tasks still belong to them and run into those days' | 'Task to reassign' : 'Tasks to reassign' |
| ? 'This task of '.$sittingLead->technician?->name.' runs into that day' : 'These tasks of '.$sittingLead->technician?->name.' run into that day', | ? 'Task to reassign' : 'Tasks to reassign', |
| '%s is now led by %s rather than the lead you confirmed. Reopen the list and try again.' | '%s is now led by %s. Reload and try again.' |
| '%s no longer has a lead technician to replace. Reopen the list and try again.' | '%s has no lead to replace. Reload and try again.' |
| 'This day has already passed. A technician can only be added to a day still to come.' | 'This day has passed.' |
| '%s is now led by %s on %s rather than the lead you confirmed. Reopen the day and try again.' | '%s is now led by %s on %s. Reload and try again.' |
| '%s no longer has a lead technician on %s to replace. Reopen the day and try again.' | '%s has no lead on %s to replace.' |

### `Http/Controllers/TechnicianPortalController.php`

| Before | After |
|---|---|
| 'Reports can only be submitted on one of the project\'s scheduled days.' | 'Reports only on scheduled days.' |
| 'These tasks are on projects you cannot edit, so an administrator will need to fill in what is missing.' | 'An administrator must fix these.' |
| 'Completion recorded. Completes automatically in %d days unless the client replies.' | 'Sent. Auto-completes in %d days.' |

### `Http/Controllers/TechnicianReportController.php`

| Before | After |
|---|---|
| '%s is on hold. Resume it before filing a report.' | '%s is on hold.' |

### `Http/Controllers/Auth/AuthController.php`

| Before | After |
|---|---|
| 'Your account has been deactivated. Please contact an administrator for assistance.' | 'Account deactivated. Contact an administrator.' |

### `Http/Controllers/Auth/EmailVerificationController.php`

| Before | After |
|---|---|
| 'That verification session has expired. Sign in to start again.' | 'Session expired. Sign in again.' |

### `Http/Controllers/Auth/PasswordResetController.php`

| Before | After |
|---|---|
| 'Verification codes cannot be sent right now. ' .'Ask an administrator to reset your password from Configuration.' | 'Codes unavailable. Ask an administrator.' |

### `Models/User.php`

| Before | After |
|---|---|
| 'Enter an 11-digit contact number, digits only (e.g. 09171234567).' | 'Enter 11 digits, e.g. 09171234567.' |

### `Policies/ProjectPolicy.php`

| Before | After |
|---|---|
| 'Not scheduled yet. An administrator has to schedule it first.' | 'Not scheduled yet.' |
| 'Not started yet. It can be completed once its first scheduled day arrives.' | 'Not started yet.' |

### `Rules/NotAnEmployeeEmail.php`

| Before | After |
|---|---|
| 'This email address belongs to an employee account. Use the client\'s own email address.' | 'This is an employee email. Use the client\'s.' |

### `Support/UploadStore.php`

| Before | After |
|---|---|
| 'The file could not be stored. Nothing was saved.' | 'File could not be saved.' |

### `Services/CompletionConfirmability.php`

| Before | After |
|---|---|
| 'No registered client can confirm this project online. It completes automatically on %s.' | 'No client can confirm online. Auto-completes %s.' |
| 'No registered client can confirm this project online.' | 'No client can confirm online.' |

### `Services/HistoricalScheduleCorrection.php`

| Before | After |
|---|---|
| '%s %s in the past and %s not scheduled for this project. Say who worked %s before saving.' | '%s %s past and %s unscheduled. Name who worked %s.' |
| '%s was not on this project for %s. Add them through the historical correction to record it.' | '%s was not on this project for %s.' |
| 'Name the Lead Technician who worked %s. A day on the record has one.' | 'Name the Lead Technician who worked %s.' |
| '%s are both Lead Technicians. Only one of them can have led %s.' | '%s are both leads. Only one can lead %s.' |

### `Services/InquiryService.php`

| Before | After |
|---|---|
| 'Unable to send reply. Nothing was changed - try again.' | 'Unable to send reply. Try again.' |

### `Services/InquirySpamGuard.php`

| Before | After |
|---|---|
| 'You have submitted too many inquiries. Please try again later.' | 'Too many inquiries. Try again later.' |

### `Services/NotificationService.php`

| Before | After |
|---|---|
| '%s has been completed. Thank you for your business.' | '%s is complete. Thank you!' |
| '%s marked complete by %s. Completes automatically in %d days.' | '%s marked complete by %s. Auto-completes in %d days.' |
| 'Work on %s is complete. Review and confirm within %d days, or it completes automatically.' | '%s is done. Please confirm within %d days.' |
| '%s was marked complete by %s even though it was not ready. %s Reason given: %s' | '%s completed early by %s. %s Reason: %s' |
| '%s is still waiting for your confirmation. It will be marked complete automatically in %d %s.' | '%s needs your confirmation. Auto-completes in %d %s.' |
| 'Thank you for confirming. %s is now complete, and its full record stays available to you here.' | 'Thanks! %s is now complete.' |
| '%s was marked complete automatically after %d days without a reply from the client.' | '%s auto-completed after %d days.' |
| '%s has been marked complete, as the %d day confirmation period has passed. ' .'Please contact us if anything about the work still needs attention.' | '%s auto-completed after %d days.' |
| 'Further work has been scheduled on %s, so it is active again. ' .'You will be asked to confirm it once the work is finished.' | '%s is active again with new work.' |
| '%s has been put on hold. Work is paused until it resumes.' | '%s is on hold.' |
| 'You no longer stand in as lead on %s %s: those dates were removed from its schedule.' | 'Stand-in on %s %s cancelled (dates removed).' |
| 'Your days off on %s %s were cancelled: those dates were removed from its schedule.' | 'Days off on %s %s cancelled (dates removed).' |
| 'Your specialty request (%s) has been rejected. Your current specialties are unchanged.' | 'Specialty request (%s) rejected.' |
| 'Your specialty update request has been rejected. Your current specialties are unchanged.' | 'Specialty request rejected.' |

### `Services/OtpService.php`

| Before | After |
|---|---|
| 'We could not send a code to that address. Check it is correct and try again.' | 'Could not send a code. Check the address.' |
| 'No verification code is waiting for that address. Ask for a new one.' | 'No code found. Ask for a new one.' |
| 'That code is incorrect, and no attempts remain. Ask for a new code.' | 'Incorrect code. Ask for a new one.' |

### `Services/PhaseSetupTaskRules.php`

| Before | After |
|---|---|
| 'This project has no schedule yet, so its tasks cannot be given dates.' | 'No schedule yet, so tasks cannot have dates.' |

### `Services/PhaseTemplateCatalog.php`

| Before | After |
|---|---|
| 'The stage list is out of date. Reload the page and try again.' | 'Out of date. Reload and try again.' |
| 'That stage is no longer in the vocabulary. Reload the page and try again.' | 'Stage no longer exists. Reload the page.' |

### `Services/ProfileService.php`

| Before | After |
|---|---|
| 'This request asks for no specialties. A technician must keep at least one, so reject it instead.' | 'A technician needs one specialty. Reject it instead.' |

### `Services/ProjectPhaseProgress.php`

| Before | After |
|---|---|
| 'This phase cannot be completed because %d %s still incomplete.' | '%d %s still open.' |
| 'This phase has no tasks yet, so there is nothing to complete.' | 'No tasks to complete yet.' |

### `Services/ProjectPhaseSetup.php`

| Before | After |
|---|---|
| 'This project\'s phase structure has been finalized and can no longer be changed.' | 'Phases are finalized and locked.' |
| 'That phase is no longer part of this project. Reload the page and try again.' | 'That phase was removed. Reload the page.' |
| '%s still has %d %s on it. Move that work to another phase before removing it.' | '%s still has %d %s. Move them first.' |

### `Services/ProjectRegisteredUser.php`

| Before | After |
|---|---|
| 'That account is archived. Restore it before assigning it to a project.' | 'That account is archived. Restore it first.' |

### `Services/ProjectReopen.php`

| Before | After |
|---|---|
| 'Completed projects cannot be reopened - create a new project instead.' | 'Completed projects cannot be reopened.' |
| 'Only a project awaiting client confirmation can be reopened. This one is %s.' | 'Cannot reopen: project is %s.' |
| 'This is a Commercial project. Partial Day scheduling is for Residential projects only.' | 'Partial Day is for Residential projects only.' |

### `Services/ProjectScheduleRecovery.php`

| Before | After |
|---|---|
| 'This schedule range has already ended. It is part of the project\'s history and cannot be changed.' | 'This range has ended and is locked.' |
| 'This project\'s proposed schedule conflicts with the current availability ' .'of its team. Review the affected schedule ranges before resuming the project.', | 'Team unavailable for some dates.', |
| 'This project\'s schedule conflicts with the current availability of its ' .'team. Review the affected schedule ranges before restoring the project.', | 'Team unavailable for some dates.', |
| 'Every current and future schedule range is available. ' .'This project can be resumed.', | 'All dates are available.', |
| 'Every current and future schedule range is available. ' .'This project can be restored.', | 'All dates are available.', |
| 'No conflicts remain - this project can be resumed.' | 'No conflicts. Ready to resume.' |
| 'No conflicts remain - this project can be restored.' | 'No conflicts. Ready to restore.' |
| 'This schedule conflicts with the current availability of one or more team members.' | 'Some team members are unavailable.' |
| 'Unable to resume - the days this project still holds are now booked elsewhere. ', ' Reschedule that work or remove them from this team.', | 'Unable to resume: dates booked elsewhere. ', ' Reschedule or change the team.', |
| 'Unable to restore - the dates this project still holds are now booked elsewhere. ', ' Reschedule that work or remove them from this team, then restore it again.', | 'Unable to restore: dates booked elsewhere. ', ' Reschedule or change the team.', |

### `Services/ProjectTeam.php`

| Before | After |
|---|---|
| 'These dates overlap a period this technician already has on the project. Record them in smaller parts.' | 'Overlaps their existing dates. Split it up.' |

### `Services/ProjectTeamChange.php`

| Before | After |
|---|---|
| 'A project must keep at least one technician. Assign someone else first.' | 'A project needs at least one technician.' |
| '%s stops leading this project on %s. Choose a lead technician who takes over that day.' | '%s stops leading on %s. Choose a new lead.' |
| 'This project would have no lead technician from %s. Choose one who takes over that day.' | 'No lead from %s. Choose one.' |
| '%s would both lead this project from %s. A project has one lead technician%s.' | '%s would both lead from %s. Only one lead allowed%s.' |
| '%s already holds %s dated to their time on this project (%s). Reassign %s before cancelling.' | '%s has %s in this period (%s). Reassign %s first.' |

### `Services/ProjectTeamRules.php`

| Before | After |
|---|---|
| '%s is a Lead Technician - choose them in the Lead Technician field, or pick someone else here.' | '%s is a Lead Technician. Use the Lead field.' |

### `Services/QuotationChange.php`

| Before | After |
|---|---|
| 'The quotation amount was changed without confirming whether the quotation file ' .'should be replaced too. Nothing was saved.', | 'Confirm whether to replace the quotation file.', |
| 'A new quotation file was chosen without confirming whether the quotation amount ' .'should change too. Nothing was saved.', | 'Confirm whether to change the amount.', |
| 'The replacement quotation file did not arrive. Nothing was saved - choose the ' .'file and save again.', | 'File did not upload. Try again.', |
| 'Project updated. The quotation amount is now %s (was %s) and the quotation file was replaced.' | 'Saved. Quotation is now %s (was %s), file replaced.' |
| 'Project updated. The quotation amount is now %s (was %s). The uploaded quotation file was not replaced.' | 'Saved. Quotation is now %s (was %s).' |
| 'Project updated. The quotation file was replaced. The quotation amount stayed at %s.' | 'Saved. File replaced; amount stays %s.' |

### `Services/ScheduleModeRules.php`

| Before | After |
|---|---|
| 'This date range has already ended. Super Admin access is required to make changes.' | 'Ended. Super Admin only.' |
| 'This schedule has started. Super Admin access is required to move its start date.' | 'Started. Only a Super Admin can move the start.' |
| 'The schedule for %s covers more than one day. Split it into single days first.' | '%s spans several days. Split it first.' |

### `Services/TaskAssignmentRules.php`

| Before | After |
|---|---|
| '%s is not assigned to this project with no end date, so this task needs dates first.' | '%s leaves this project, so add task dates.' |
| '%s is off this project from %s to %s, which this task runs across.' | '%s is off from %s to %s.' |
| '%s is off this project from %s to %s, and this task is due %s.' | '%s is off from %s to %s; task due %s.' |
| '%s is off this project from %s to %s, and this task starts %s.' | '%s is off from %s to %s; task starts %s.' |
| '%s is assigned to this project until %s, and this task is due %s.' | '%s leaves after %s; task due %s.' |
| '%s is assigned to this project until %s, and this task starts %s.' | '%s leaves after %s; task starts %s.' |
| '%s joins this project on %s, and this task starts %s.' | '%s joins on %s; task starts %s.' |
| '%s is not assigned to this project between %s and %s.' | '%s is not on the team from %s to %s.' |
| '%s was removed from this project. Assign this task to a technician on the team.' | '%s left the project. Reassign this task.' |

### `Services/TaskPhaseRules.php`

| Before | After |
|---|---|
| 'This project has not been configured with its project phases yet. Set up the project phases before adding tasks.' | 'Set up project phases before adding tasks.' |
| 'This project has no phases to file a task under. Ask a Super Admin to review its phase structure.' | 'No phases. Ask a Super Admin to review.' |
| 'Every phase of this project has been completed, so there is no open phase to add a task to.' | 'All phases are complete.' |
| 'Pick a phase of this project that has not been completed yet.' | 'Pick an open phase.' |

### `Services/TaskScheduleRules.php`

| Before | After |
|---|---|
| 'This project has no scheduled dates, so a task cannot be given any.' | 'No scheduled dates to choose from.' |

### `Services/UserAccountService.php`

| Before | After |
|---|---|
| 'An Admin or Super Admin account can only be activated or deactivated by a Super Admin.' | 'Only a Super Admin can change admin accounts.' |
| 'An Admin or Super Admin account can only be archived by a Super Admin.' | 'Only a Super Admin can archive admin accounts.' |
| 'You cannot reset your own password here. Use the password change page instead.' | 'Use Change Password for your own account.' |

## Configuration editor help and validation

### `app/Models/SystemContent.php (Configuration editor)`

| Before | After |
|---|---|
| 'Shown beside the logo in the header, where there is less room.' | 'Shown next to the logo.' |
| 'The small yellow pill above the headline. Leave empty to hide it.' | 'Yellow pill above the headline. Optional.' |
| 'The framed photograph beneath the hero text. A wide shot works best.' | 'Photo under the hero text. Wide works best.' |
| 'The small blue line above the services heading.' | 'Blue line above the heading.' |
| 'Optional. Sits under the heading; leave empty to hide it.' | 'Optional. Shown under the heading.' |
| 'Add, edit, remove, and order the services shown on the website. Each service can have its own image.' | 'Services shown on the website.' |
| 'One person per line, as "Name \| Role". Their photographs are the four fields below, in the same order. Leave empty to hide the section.' | 'One per line: "Name \| Role".' |
| 'Add each owner with their name, contact details, and optional profile image. Owners appear in this order on the About page.' | 'Owners shown on the About page, in order.' |
| 'Shown under the Send button - for example, how soon somebody replies. Leave it empty to show nothing.' | 'Shown under the Send button. Optional.' |
| 'The src URL from the Google Maps "Embed a map" share option.' | 'Google Maps "Embed a map" src URL.' |
| 'One link per line, as "Label \| /path". A link to /my-projects is hidden from visitors who are not signed in.' | 'One per line: "Label \| /path".' |
| 'Automatically complete a project after it remains awaiting client confirmation for this many days. The client is reminded shortly before the deadline.' | 'Days before a project auto-completes.' |
| 'Enter the number of days before a project completes automatically.' | 'Enter the number of days.' |
| 'The number of days must be a whole number.' | 'Use a whole number.' |
| 'The number of days cannot be more than 365.' | 'Maximum is 365 days.' |
| 'The earliest a partial-day schedule may start. Whole hours only - it feeds the time pickers, the availability checks and the validation behind them.' | 'Earliest partial-day start. Whole hours.' |
| 'Enter the hour a partial day may start at.' | 'Enter a start hour.' |
| 'Choose a start time on the hour, such as 08:00.' | 'Use a whole hour, e.g. 08:00.' |
| 'The partial day end hour must be later than the start hour.' | 'End must be after start.' |
| 'The latest a partial-day schedule may end. Whole hours only, and later than the start hour.' | 'Latest partial-day end. Whole hours.' |
| 'Enter the hour a partial day may end at.' | 'Enter an end hour.' |
| 'Choose an end time on the hour, such as 17:00.' | 'Use a whole hour, e.g. 17:00.' |
| 'How long a visitor must wait before sending another message from the public Contact form. The form never mentions it - somebody who writes in too soon simply sees a notice asking them to try again later.' | 'Minutes between Contact form messages.' |
| 'Enter the number of minutes between inquiry submissions.' | 'Enter the minutes.' |
| 'The limit must be a whole number of minutes.' | 'Use a whole number.' |
| 'The limit cannot be more than 1440 minutes (24 hours).' | 'Maximum is 1440 minutes.' |
| 'Shown wherever the system asks somebody to accept the terms, exactly as written here.' | 'Shown exactly as written.' |
