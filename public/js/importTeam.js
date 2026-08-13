/**
 * The Import Team dialog, shared by the project wizard and the assigned-team
 * editor.
 *
 * It only ever reads. Choosing a team hands the caller a list of technicians
 * and, when there is one, a lead; putting them into the picker is the caller's
 * job, and saving is still the ordinary save. That is what keeps an imported
 * team editable exactly like a hand-picked one - nothing here is locked, and
 * nothing is written.
 *
 * Availability comes from the server, which asks TechnicianAvailabilityService
 * the same question every other scheduling screen asks it.
 */
(function (global) {
    'use strict';

    function escapeHtml(value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);

        return span.innerHTML;
    }

    /**
     * @param {Object} options
     * @param {HTMLElement} options.modal          the dialog element
     * @param {Function} options.params            () => query parameters, or null when not ready
     * @param {Function} [options.currentLeadId]   () => the destination's lead, or null
     * @param {Function} options.onImport          ({lead, technicians, keepCurrentLead}) => void
     * @param {boolean} [options.confirmLeadChange] ask before replacing an existing lead
     */
    function init(options) {
        const modal = options.modal;

        if (!modal) {
            return null;
        }

        const browser = modal.querySelector('[data-import-browser]');
        const searchInput = modal.querySelector('[data-import-search]');
        const loadingEl = modal.querySelector('[data-import-loading]');
        const errorEl = modal.querySelector('[data-import-error]');
        const sectionsEl = modal.querySelector('[data-import-sections]');
        const emptyEl = modal.querySelector('[data-import-empty]');
        const noMatchesEl = modal.querySelector('[data-import-no-matches]');

        const leadChoice = modal.querySelector('[data-import-lead-choice]');
        const leadSummary = modal.querySelector('[data-import-lead-summary]');
        const currentLeadName = modal.querySelector('[data-import-current-lead]');
        const importedLeadName = modal.querySelector('[data-import-imported-lead]');
        const keepLeadButton = modal.querySelector('[data-import-keep-lead]');
        const useLeadButton = modal.querySelector('[data-import-use-lead]');
        const leadCancelButton = modal.querySelector('[data-import-lead-cancel]');

        let projects = [];
        let pending = null;
        // Which status groups are folded open, kept out here so re-rendering
        // after a search does not collapse what somebody just opened.
        const openBlocked = { active: false, closed: false };
        let closedOpen = false;
        let token = 0;

        function showError(message) {
            errorEl.textContent = message || '';
            errorEl.classList.toggle('d-none', !message);
        }

        function matches(project, term) {
            if (!term) {
                return true;
            }

            const haystack = [
                project.name,
                project.client,
                project.reference_no,
                String(project.project_id),
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            return haystack.indexOf(term) !== -1;
        }

        function importableOf(project) {
            return (project.technicians || []).filter(function (technician) {
                return technician.available;
            });
        }

        function memberChip(technician) {
            return (
                '<span class="import-team-chip' +
                (technician.available ? '' : ' is-unavailable') +
                '">' +
                (technician.avatar_url
                    ? '<img class="import-team-chip-avatar" src="' +
                      escapeHtml(technician.avatar_url) +
                      '" alt="" loading="lazy">'
                    : '') +
                escapeHtml(technician.name) +
                (technician.is_lead
                    ? '<span class="import-team-chip-lead">Lead</span>'
                    : '') +
                '</span>'
            );
        }

        function reasonsMarkup(project) {
            if (!project.unavailable || !project.unavailable.length) {
                return '';
            }

            return (
                '<div class="import-team-reasons">' +
                project.unavailable
                    .map(function (technician) {
                        return (
                            '<div><strong>' +
                            escapeHtml(technician.name) +
                            '</strong> — ' +
                            escapeHtml(technician.reason) +
                            '</div>'
                        );
                    })
                    .join('') +
                '</div>'
            );
        }

        function actionMarkup(project) {
            const importable = importableOf(project);

            if (!importable.length) {
                return (
                    '<div class="import-team-actions">' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" disabled>' +
                    'Nobody on this team is free' +
                    '</button></div>'
                );
            }

            const partial = importable.length < (project.technicians || []).length;

            return (
                '<div class="import-team-actions">' +
                '<button type="button" class="btn btn-sm ' +
                (partial ? 'btn-outline-primary' : 'btn-primary') +
                '" data-import-pick="' +
                project.project_id +
                '">' +
                '<i class="bi bi-people me-1" aria-hidden="true"></i>' +
                (partial
                    ? 'Import the ' + importable.length + ' who are free'
                    : 'Import this team') +
                '</button></div>'
            );
        }

        function cardMarkup(project) {
            const meta = [];

            if (project.reference_no) {
                meta.push(escapeHtml(project.reference_no));
            }

            if (project.client) {
                meta.push(escapeHtml(project.client));
            }

            const importable = importableOf(project);
            const state = project.available
                ? ''
                : importable.length
                  ? ' is-partial'
                  : ' is-blocked';

            return (
                '<div class="import-team-card' + state + '">' +
                '<div class="import-team-card-top">' +
                '<div>' +
                '<div class="import-team-card-name">' +
                escapeHtml(project.name) +
                '</div>' +
                '<div class="import-team-card-meta">' +
                meta.join(' &middot; ') +
                '</div>' +
                '</div>' +
                '<span class="badge bg-light text-secondary border">' +
                escapeHtml(project.status_label) +
                '</span>' +
                '</div>' +
                '<span class="import-team-card-schedule">' +
                '<i class="bi bi-calendar3" aria-hidden="true"></i>' +
                escapeHtml(project.schedule_label) +
                '</span>' +
                '<div class="import-team-members">' +
                (project.technicians || []).map(memberChip).join('') +
                '</div>' +
                reasonsMarkup(project) +
                actionMarkup(project) +
                '</div>'
            );
        }

        /**
         * One status group: the teams that are free, then - folded away, but
         * never hidden - the ones that are not.
         */
        function sectionMarkup(key, label, groupProjects, collapsed) {
            if (!groupProjects.length) {
                return '';
            }

            const available = groupProjects.filter(function (project) {
                return project.available;
            });

            const blocked = groupProjects.filter(function (project) {
                return !project.available;
            });

            const body =
                '<div class="import-team-list">' +
                (available.length
                    ? available.map(cardMarkup).join('')
                    : '<div class="import-team-empty">Every team here has somebody who is not free.</div>') +
                '</div>' +
                (blocked.length
                    ? '<div class="import-blocked-wrap">' +
                      '<button type="button" class="import-blocked-toggle' +
                      (openBlocked[key] ? ' is-open' : '') +
                      '" data-import-blocked-toggle="' +
                      key +
                      '">' +
                      '<i class="bi bi-chevron-right" aria-hidden="true"></i>' +
                      '<span>' +
                      (openBlocked[key] ? 'Hide' : 'Show') +
                      ' unavailable projects (' +
                      blocked.length +
                      ')</span></button>' +
                      '<div class="import-blocked-list' +
                      (openBlocked[key] ? '' : ' d-none') +
                      '">' +
                      blocked.map(cardMarkup).join('') +
                      '</div></div>'
                    : '');

            return (
                '<div class="import-team-section">' +
                '<div class="import-team-section-heading">' +
                (key === 'closed'
                    ? '<button type="button" class="import-blocked-toggle' +
                      (closedOpen ? ' is-open' : '') +
                      '" data-import-closed-toggle>' +
                      '<i class="bi bi-chevron-right" aria-hidden="true"></i>' +
                      '<span>' +
                      escapeHtml(label) +
                      '</span></button>'
                    : '<span>' + escapeHtml(label) + '</span>') +
                '<span class="import-team-count">' +
                groupProjects.length +
                '</span>' +
                '</div>' +
                '<div class="' + (key === 'closed' && !closedOpen ? 'd-none' : '') + '">' +
                body +
                '</div>' +
                '</div>'
            );
        }

        function render() {
            const term = (searchInput.value || '').trim().toLowerCase();
            const visible = projects.filter(function (project) {
                return matches(project, term);
            });

            emptyEl.classList.toggle('d-none', projects.length > 0);
            noMatchesEl.classList.toggle(
                'd-none',
                projects.length === 0 || visible.length > 0,
            );

            sectionsEl.innerHTML = [
                sectionMarkup(
                    'active',
                    'Active projects',
                    visible.filter(function (project) {
                        return project.group === 'active';
                    }),
                ),
                sectionMarkup(
                    'closed',
                    'Completed & cancelled projects',
                    visible.filter(function (project) {
                        return project.group === 'closed';
                    }),
                ),
            ].join('');
        }

        function load() {
            const params = options.params();

            showError('');
            leadChoice.classList.add('d-none');
            browser.classList.remove('d-none');
            sectionsEl.innerHTML = '';
            emptyEl.classList.add('d-none');
            noMatchesEl.classList.add('d-none');
            searchInput.value = '';
            projects = [];

            if (!params) {
                loadingEl.classList.add('d-none');
                showError(
                    'Choose the project schedule first, so the technicians can be checked against it.',
                );

                return;
            }

            const current = ++token;
            loadingEl.classList.remove('d-none');

            fetch(
                '/super-admin/projects/importable-teams?' +
                    new URLSearchParams(params).toString(),
                { headers: { Accept: 'application/json' } },
            )
                .then(function (response) {
                    return response.json().then(function (body) {
                        return { ok: response.ok, body: body };
                    });
                })
                .then(function (result) {
                    if (current !== token) {
                        return;
                    }

                    loadingEl.classList.add('d-none');

                    if (!result.ok) {
                        showError(result.body.error || 'Could not load projects.');

                        return;
                    }

                    projects = result.body.projects || [];
                    render();
                })
                .catch(function () {
                    if (current !== token) {
                        return;
                    }

                    loadingEl.classList.add('d-none');
                    showError('Could not load projects.');
                });
        }

        function finish(keepCurrentLead) {
            const project = pending;

            if (!project) {
                return;
            }

            const importable = importableOf(project);
            const lead = importable.find(function (technician) {
                return technician.is_lead;
            }) || null;

            pending = null;

            options.onImport({
                project: project,
                lead: lead,
                keepCurrentLead: Boolean(keepCurrentLead),
                technicians: importable.filter(function (technician) {
                    return !technician.is_lead;
                }),
            });

            leadChoice.classList.add('d-none');
            browser.classList.remove('d-none');

            if (global.bootstrap) {
                global.bootstrap.Modal.getOrCreateInstance(modal).hide();
            }
        }

        function pick(projectId) {
            const project = projects.find(function (item) {
                return String(item.project_id) === String(projectId);
            });

            if (!project) {
                return;
            }

            pending = project;

            const importedLead = importableOf(project).find(function (technician) {
                return technician.is_lead;
            });

            const currentLead = options.currentLeadId
                ? options.currentLeadId()
                : null;

            // Replacing a lead is never automatic: the person has to say so.
            const clashes =
                options.confirmLeadChange &&
                importedLead &&
                currentLead &&
                String(currentLead.id) !== String(importedLead.id);

            if (!clashes) {
                finish(false);

                return;
            }

            leadSummary.textContent =
                project.name + ' is led by ' + importedLead.name + '.';
            currentLeadName.textContent = currentLead.name;
            importedLeadName.textContent = importedLead.name;

            browser.classList.add('d-none');
            leadChoice.classList.remove('d-none');
        }

        sectionsEl.addEventListener('click', function (event) {
            const picked = event.target.closest('[data-import-pick]');

            if (picked) {
                pick(picked.dataset.importPick);

                return;
            }

            const blockedToggle = event.target.closest('[data-import-blocked-toggle]');

            if (blockedToggle) {
                const key = blockedToggle.dataset.importBlockedToggle;
                openBlocked[key] = !openBlocked[key];
                render();

                return;
            }

            if (event.target.closest('[data-import-closed-toggle]')) {
                closedOpen = !closedOpen;
                render();
            }
        });

        searchInput.addEventListener('input', render);

        keepLeadButton.addEventListener('click', function () {
            finish(true);
        });

        useLeadButton.addEventListener('click', function () {
            finish(false);
        });

        leadCancelButton.addEventListener('click', function () {
            pending = null;
            leadChoice.classList.add('d-none');
            browser.classList.remove('d-none');
        });

        modal.addEventListener('show.bs.modal', load);

        return { reload: load };
    }

    global.importTeam = { init: init };
})(window);
