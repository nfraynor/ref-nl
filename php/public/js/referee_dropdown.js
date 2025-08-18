// referee_dropdown.js — Tom Select companion (filters + select2 kill-switch)

(function () {
    // Kill Select2 if anything tries to call it (no-ops)
    if (window.jQuery && !jQuery.fn.select2) {
        jQuery.fn.select2 = function () {
            console.warn('[referee_dropdown.js] select2() called but disabled; ignoring.');
            return this;
        };
    }

    // Filter state
    const currentFilters = {
        grades: new Set(['A','B','C','D']),
        availability: 'available' // '', 'available', 'unavailable'
    };

    function setGradesFromInputs(scope) {
        const boxes = scope.querySelectorAll('.grade-filter');
        currentFilters.grades.clear();
        boxes.forEach(cb => { if (cb.checked) currentFilters.grades.add(cb.value); });
    }
    function setAvailabilityFromInputs(scope) {
        const picked = scope.querySelector('.availability-filter:checked');
        currentFilters.availability = picked ? picked.value : '';
    }

    // Apply filters by overriding score
    function applyFilterToInstance(ts) {
        const scoreFn = ts.getScoreFunction(ts.lastQuery || '');
        ts.settings.score = function (search) {
            const base = ts.getScoreFunction(search);
            return function (item) {
                const opt = item.$option;
                const grade = opt ? opt.getAttribute('data-grade') : null;
                const avail = opt ? opt.getAttribute('data-availability') : null;

                const gradeOk = currentFilters.grades.size === 0 || (grade && currentFilters.grades.has(grade));
                const availOk = !currentFilters.availability || (avail === currentFilters.availability);
                if (!gradeOk || !availOk) return 0;

                return base(item);
            };
        };
        ts.refreshOptions(false);
    }

    function buildFilterUI(ts) {
        const el = document.createElement('div');
        el.className = 'dropdown-filters p-2 border-bottom';
        el.innerHTML = `
      <div class="mb-1"><strong>Grade:</strong></div>
      <div class="flex flex-wrap items-center gap-2 mb-2">
        ${['A','B','C','D'].map(g => `
          <label class="me-2">
            <input type="checkbox" class="grade-filter" value="${g}" ${currentFilters.grades.has(g) ? 'checked' : ''}> ${g}
          </label>
        `).join('')}
      </div>
      <div class="mb-1"><strong>Availability:</strong></div>
      <div class="flex items-center gap-3">
        <label><input type="radio" name="availability_${ts.inputId}" class="availability-filter" value="" ${!currentFilters.availability ? 'checked' : ''}> All</label>
        <label><input type="radio" name="availability_${ts.inputId}" class="availability-filter" value="available" ${currentFilters.availability==='available'?'checked':''}> Available</label>
        <label><input type="radio" name="availability_${ts.inputId}" class="availability-filter" value="unavailable" ${currentFilters.availability==='unavailable'?'checked':''}> Unavailable</label>
      </div>
    `;
        el.addEventListener('change', (e) => {
            if (e.target.classList.contains('grade-filter')) {
                setGradesFromInputs(el);
                applyFilterToInstance(ts);
            } else if (e.target.classList.contains('availability-filter')) {
                setAvailabilityFromInputs(el);
                applyFilterToInstance(ts);
            }
        });
        return el;
    }

    const HOOKED = new WeakSet();
    function hookInstance(ts) {
        if (!ts || HOOKED.has(ts)) return;
        HOOKED.add(ts);

        ts.on('dropdown_open', () => {
            if (!ts.dropdown) return;
            if (!ts.dropdown.querySelector('.dropdown-filters')) {
                const ui = buildFilterUI(ts);
                ts.dropdown.insertBefore(ui, ts.dropdown.firstChild);
            }
            applyFilterToInstance(ts);
        });

        ts.on('type', () => applyFilterToInstance(ts));
    }

    function hookAllExisting() {
        document.querySelectorAll('select.referee-select').forEach(sel => {
            if (sel.tomselect) hookInstance(sel.tomselect);
        });
    }

    function onReady(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    onReady(() => {
        hookAllExisting();
        const mo = new MutationObserver(hookAllExisting);
        mo.observe(document.body, { childList: true, subtree: true });
    });

    window.hookRefereeFilterUI = function (selectElOrTS) {
        const ts = selectElOrTS && selectElOrTS.tomselect ? selectElOrTS.tomselect : selectElOrTS;
        if (ts) hookInstance(ts);
    };
})();
