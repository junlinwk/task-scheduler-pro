let overlayLockCount = 0;
const CSRF_TOKEN = document.body?.dataset?.csrfToken || '';

function attachCsrfToken(payload) {
    if (!CSRF_TOKEN || !payload) return payload;
    if (payload instanceof FormData) {
        if (!payload.has('csrf_token')) {
            payload.append('csrf_token', CSRF_TOKEN);
        }
        return payload;
    }
    if (payload instanceof URLSearchParams) {
        if (!payload.has('csrf_token')) {
            payload.set('csrf_token', CSRF_TOKEN);
        }
        return payload;
    }
    return payload;
}

function lockBodyScroll() {
    overlayLockCount += 1;
    if (overlayLockCount === 1) {
        document.body.classList.add('overlay-locked');
    }
}

function unlockBodyScroll() {
    overlayLockCount = Math.max(overlayLockCount - 1, 0);
    if (overlayLockCount === 0) {
        document.body.classList.remove('overlay-locked');
    }
    reconcileScrollLock();
}

function reconcileScrollLock() {
    const visibleOverlay = document.querySelector('.stat-overlay.is-visible, .confirm-overlay.is-visible, .task-note-overlay.is-visible, .plan-overlay.is-visible, .settings-overlay.is-visible');
    if (!visibleOverlay) {
        overlayLockCount = 0;
        document.body.classList.remove('overlay-locked');
    }
}

function runAfterTransition(element, callback, timeout = 400) {
    if (!element) {
        callback();
        return;
    }
    let called = false;
    const done = () => {
        if (called) return;
        called = true;
        element.removeEventListener('transitionend', onEnd);
        callback();
    };
    const onEnd = (event) => {
        if (event.target !== element) return;
        done();
    };
    element.addEventListener('transitionend', onEnd);
    window.setTimeout(done, timeout);
}

let currentTheme = 'light';
let themeSwitchUpdater = null;
const SETTINGS_USER_ID = document.body?.dataset?.userId || 'anon';
const AUTO_EMAIL_SETTING_KEY = `pref_auto_email_enabled_${SETTINGS_USER_ID}`;
const AUTO_EMAIL_VALUE_KEY = `pref_auto_email_address_${SETTINGS_USER_ID}`;
const AUTO_EMAIL_TIME_KEY = `pref_auto_email_time_${SETTINGS_USER_ID}`;
const AUTO_EMAIL_TOPIC_KEY = `pref_auto_email_topic_${SETTINGS_USER_ID}`;
const AUTO_EMAIL_LAST_SENT_KEY = `pref_auto_email_last_sent_${SETTINGS_USER_ID}`;

function applyTheme(mode) {
    const normalized = mode === 'dark' ? 'dark' : 'light';
    currentTheme = normalized;
    const isDark = normalized === 'dark';
    document.body.classList.toggle('dark-mode', isDark);
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    if (typeof themeSwitchUpdater === 'function') {
        themeSwitchUpdater(normalized);
    }
}

function toggleTheme() {
    applyTheme(currentTheme === 'dark' ? 'light' : 'dark');
}

function isAutoEmailEnabled() {
    return localStorage.getItem(AUTO_EMAIL_SETTING_KEY) === 'on';
}

function setAutoEmailEnabled(enabled) {
    localStorage.setItem(AUTO_EMAIL_SETTING_KEY, enabled ? 'on' : 'off');
}

function getSavedEmail() {
    return localStorage.getItem(AUTO_EMAIL_VALUE_KEY) || '';
}

function setSavedEmail(email) {
    localStorage.setItem(AUTO_EMAIL_VALUE_KEY, email || '');
}

function getSavedEmailTime() {
    return localStorage.getItem(AUTO_EMAIL_TIME_KEY) || '00:00';
}

function setSavedEmailTime(timeStr) {
    localStorage.setItem(AUTO_EMAIL_TIME_KEY, timeStr || '00:00');
}

function getSavedEmailTopic() {
    return localStorage.getItem(AUTO_EMAIL_TOPIC_KEY) || 'TODO notification';
}

function setSavedEmailTopic(topic) {
    localStorage.setItem(AUTO_EMAIL_TOPIC_KEY, topic || 'TODO notification');
}

function getSavedAutoEmailLastSent() {
    return localStorage.getItem(AUTO_EMAIL_LAST_SENT_KEY) || '';
}

function setSavedAutoEmailLastSent(dateKey) {
    localStorage.setItem(AUTO_EMAIL_LAST_SENT_KEY, dateKey || '');
}

function initGearButton() {
    const gearBtn = document.querySelector('[data-gear-button]');
    if (!gearBtn) return;
    let popTimer = null;
    const playPop = () => {
        gearBtn.classList.remove('is-popping');
        if (popTimer) window.clearTimeout(popTimer);
        // force reflow to restart animation
        void gearBtn.offsetWidth;
        gearBtn.classList.add('is-popping');
        popTimer = window.setTimeout(() => {
            gearBtn.classList.remove('is-popping');
        }, 420);
    };
    gearBtn.addEventListener('click', playPop);
    gearBtn.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            playPop();
        }
    });
}

function initSettingsOverlay() {
    const overlay = document.querySelector('[data-settings-overlay]');
    const gearBtn = document.querySelector('[data-gear-button]');
    const toggle = overlay ? overlay.querySelector('[data-settings-auto-email]') : null;
    const emailRow = overlay ? overlay.querySelector('[data-email-row]') : null;
    const emailInput = overlay ? overlay.querySelector('[data-settings-email]') : null;
    const timeInput = overlay ? overlay.querySelector('[data-settings-email-time]') : null;
    const topicInput = overlay ? overlay.querySelector('[data-settings-email-topic]') : null;
    const sendBtn = overlay ? overlay.querySelector('[data-settings-send-email]') : null;
    const saveBtn = overlay ? overlay.querySelector('[data-settings-save]') : null;
    const loadingIndicator = overlay ? overlay.querySelector('[data-settings-loading]') : null;
    const closeBtns = overlay ? overlay.querySelectorAll('[data-settings-close]') : [];
    if (!overlay || !gearBtn || !toggle || !emailInput || !sendBtn || !timeInput || !topicInput || !saveBtn) return;
    const sendBtnLabel = sendBtn.textContent;
    let autoSendTimer = null;
    let autoSendInFlight = false;
    let lastKnownTime = getSavedEmailTime();
    const setSendBusy = (busy) => {
        sendBtn.disabled = busy;
        sendBtn.textContent = busy ? 'Sending...' : sendBtnLabel;
        if (loadingIndicator) {
            loadingIndicator.hidden = !busy;
        }
    };
    const getTodayKey = () => {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        const d = String(now.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    };
    const updateSavedEmailTimeWithReset = (rawTime) => {
        const normalized = (rawTime && rawTime.includes(':')) ? rawTime : '00:00';
        setSavedEmailTime(normalized);
        lastKnownTime = normalized;
        const [hhStr, mmStr] = normalized.split(':');
        const hh = parseInt(hhStr, 10);
        const mm = parseInt(mmStr, 10);
        if (Number.isNaN(hh) || Number.isNaN(mm)) {
            setSavedAutoEmailLastSent('');
            return;
        }
        const now = new Date();
        const nowMinutes = now.getHours() * 60 + now.getMinutes();
        const targetMinutes = hh * 60 + mm;
        if (nowMinutes >= targetMinutes) {
            // New time already passed today; defer until tomorrow.
            setSavedAutoEmailLastSent(getTodayKey());
        } else {
            // New time is upcoming today; allow send when time is reached.
            setSavedAutoEmailLastSent('');
        }
    };

    const syncView = () => {
        const enabled = isAutoEmailEnabled();
        toggle.checked = enabled;
        overlay.dataset.autoEmail = enabled ? 'on' : 'off';
        if (emailRow) {
            emailRow.classList.toggle('is-collapsed', !enabled);
        }
        emailInput.value = getSavedEmail();
        timeInput.value = getSavedEmailTime();
        lastKnownTime = getSavedEmailTime();
        topicInput.value = getSavedEmailTopic();
    };

    const closeOverlay = () => {
        overlay.classList.remove('is-visible');
        runAfterTransition(overlay, () => {
            overlay.hidden = true;
            unlockBodyScroll();
        });
    };

    const openOverlay = () => {
        syncView();
        overlay.hidden = false;
        lockBodyScroll();
        requestAnimationFrame(() => overlay.classList.add('is-visible'));
    };

    gearBtn.addEventListener('click', (event) => {
        event.preventDefault();
        openOverlay();
    });

    toggle.addEventListener('change', () => {
        const enabled = toggle.checked;
        setAutoEmailEnabled(enabled);
        overlay.dataset.autoEmail = enabled ? 'on' : 'off';
        if (emailRow) emailRow.classList.toggle('is-collapsed', !enabled);
    });

    emailInput.addEventListener('input', () => {
        setSavedEmail(emailInput.value.trim());
    });

    const onTimeInputChanged = () => {
        const nextTime = (timeInput.value || '').trim() || '00:00';
        if (nextTime === lastKnownTime) return;
        updateSavedEmailTimeWithReset(nextTime);
    };
    timeInput.addEventListener('change', onTimeInputChanged);
    timeInput.addEventListener('blur', onTimeInputChanged);

    const sendTasksEmail = async ({ reason = 'manual', silent = false } = {}) => {
        const email = emailInput.value.trim();
        const timeStr = timeInput.value.trim() || '00:00';
        const topic = topicInput.value.trim() || 'TODO notification';
        if (!email) {
            if (!silent) {
                showNoteToast('Please enter an email before sending.', true);
                emailInput.focus();
            }
            return false;
        }
        setSavedEmail(email);
        updateSavedEmailTimeWithReset(timeStr);
        setSavedEmailTopic(topic);
        const busy = reason !== 'auto';
        if (busy) setSendBusy(true);
        autoSendInFlight = reason === 'auto';
        try {
            const formData = new FormData();
            formData.append('email', email);
            formData.append('user_id', SETTINGS_USER_ID);
            formData.append('topic', topic);
            formData.append('ajax', '1');
            attachCsrfToken(formData);
            const response = await fetch('send_tasks_email.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            let payload = null;
            let fallbackText = '';
            try {
                payload = await response.json();
            } catch (err) {
                try {
                    fallbackText = await response.text();
                } catch (readErr) {
                    fallbackText = '';
                }
            }
            const success = response.ok && (!payload || payload.success !== false);
            const message = (payload && payload.message) || (fallbackText && fallbackText.trim());
            if (success) {
                const note = message || (payload && payload.sent === false ? 'No tasks to send today.' : 'Email sent!');
                if (!silent) {
                    showNoteToast(note);
                }
                setSavedAutoEmailLastSent(getTodayKey());
                return true;
            } else {
                if (!silent) {
                    if (message && message.includes('Invalid request token')) {
                        showNoteToast('Session expired. Reloading...', true);
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showNoteToast(message || 'Unable to send email.', true);
                    }
                }
                return false;
            }
        } catch (err) {
            console.error(err);
            if (!silent) {
                showNoteToast('Unable to send email.', true);
            }
            return false;
        } finally {
            if (busy) setSendBusy(false);
            autoSendInFlight = false;
        }
    };

    sendBtn.addEventListener('click', () => {
        sendTasksEmail({ reason: 'manual' });
    });

    saveBtn.addEventListener('click', () => {
        const email = emailInput.value.trim();
        const timeStr = timeInput.value.trim() || '00:00';
        const topic = topicInput.value.trim() || 'TODO notification';
        if (!email) {
            showNoteToast('Please enter an email before saving.', true);
            emailInput.focus();
            return;
        }
        setAutoEmailEnabled(true);
        toggle.checked = true;
        overlay.dataset.autoEmail = 'on';
        if (emailRow) emailRow.classList.remove('is-collapsed');
        setSavedEmail(email);
        updateSavedEmailTimeWithReset(timeStr);
        setSavedEmailTopic(topic);
        showNoteToast('Notification settings saved');
    });

    const shouldAutoSendNow = () => {
        if (!isAutoEmailEnabled()) return false;
        const email = getSavedEmail();
        const timeStr = getSavedEmailTime();
        if (!email || !timeStr) return false;
        const [hh, mm] = timeStr.split(':').map((n) => parseInt(n, 10));
        if (Number.isNaN(hh) || Number.isNaN(mm)) return false;
        const now = new Date();
        const nowMinutes = now.getHours() * 60 + now.getMinutes();
        const targetMinutes = hh * 60 + mm;
        const todayKey = getTodayKey();
        const lastSent = getSavedAutoEmailLastSent();
        if (lastSent === todayKey) return false;
        return nowMinutes >= targetMinutes;
    };

    const startAutoEmailLoop = () => {
        if (autoSendTimer) {
            clearInterval(autoSendTimer);
        }
        autoSendTimer = window.setInterval(async () => {
            if (autoSendInFlight) return;
            if (!shouldAutoSendNow()) return;
            await sendTasksEmail({ reason: 'auto', silent: false });
        }, 30000);
    };

    closeBtns.forEach((btn) => {
        btn.addEventListener('click', closeOverlay);
    });

    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            closeOverlay();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && overlay.classList.contains('is-visible')) {
            closeOverlay();
        }
    });

    startAutoEmailLoop();
}

document.addEventListener('DOMContentLoaded', () => {
    initThemeSwitch();
    initGearButton();
    initSettingsOverlay();
    initPasswordToggles();
    initSelectPlaceholders();
    initTaskFilter();
    initCalendar();
    initStatOverlay();
    initConfirmOverlay();
    initCategoryFilter();
    initCategoryTaskSync();
    initScrollPreserver();
    initTaskCheckboxes();
    initTaskNotesOverlay();
    initColorWheels();
    initScheduleToggle();
    initPlanOverlay();
    initSpotlightOverlay();
    initStaggeredAnimations();
});

function initStaggeredAnimations() {
    const lists = [
        '.today-tasks-list',
        '.todo-schedule-list',
        '.insights-grid',
        '.workspace'
    ];

    lists.forEach(selector => {
        const container = document.querySelector(selector);
        if (!container) return;

        const items = Array.from(container.children);
        items.forEach((item, index) => {
            item.style.animationDelay = `${index * 0.05}s`;
        });
    });
}

function initPasswordToggles() {
    const toggles = document.querySelectorAll('[data-toggle-password]');
    toggles.forEach((button) => {
        button.addEventListener('click', () => {
            const wrapper = button.closest('.input-password') || button.closest('.input-with-action');
            const input = wrapper ? wrapper.querySelector('[data-password-field]') : null;
            if (!input) return;
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            button.textContent = isHidden ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
            input.focus();
        });
    });
}

function initSelectPlaceholders() {
    const selects = document.querySelectorAll('select[data-placeholder-value]');
    if (!selects.length) {
        return;
    }
    selects.forEach((select) => {
        const placeholderValue = select.dataset.placeholderValue ?? '';
        const syncState = () => {
            const isPlaceholder = select.value === placeholderValue;
            select.classList.toggle('select-is-placeholder', isPlaceholder);
        };
        select.addEventListener('change', syncState);
        select.addEventListener('input', syncState);
        syncState();
    });
}

function initTaskFilter() {
    const searchInput = document.querySelector('[data-task-filter]');
    const statusSelect = document.querySelector('[data-status-filter]');
    const categorySelect = document.querySelector('[data-category-filter]');
    const rows = Array.from(document.querySelectorAll('[data-task-row]'));
    const emptyState = document.querySelector('[data-empty-filter]');
    const taskCollection = document.querySelector('[data-task-collection]');
    let animationTimer = null;

    if (rows.length === 0) {
        return;
    }

    const applyFilters = () => {
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const status = statusSelect ? statusSelect.value : 'all';
        const category = categorySelect ? categorySelect.value : 'all';
        let visible = 0;
        rows.forEach((row) => {
            const title = row.dataset.title || '';
            const categoryName = row.dataset.categoryName || '';
            const categoryId = row.dataset.categoryId || '';
            const statusSlug = row.dataset.status || 'active';
            const matchesQuery = !query || title.includes(query) || categoryName.includes(query);
            const matchesStatus = status === 'all' || statusSlug === status;
            const matchesCategory = category === 'all' || categoryId === category;
            const shouldShow = matchesQuery && matchesStatus && matchesCategory;
            row.style.display = shouldShow ? '' : 'None';
            if (shouldShow) visible += 1;
        });
        if (emptyState) {
            emptyState.hidden = visible !== 0;
        }
        if (taskCollection) {
            taskCollection.classList.add('is-filtering');
            if (animationTimer) {
                clearTimeout(animationTimer);
            }
            animationTimer = window.setTimeout(() => {
                taskCollection.classList.remove('is-filtering');
            }, 320);
        }
    };

    const controls = [
        { el: searchInput, event: 'input' },
        { el: statusSelect, event: 'change' },
        { el: categorySelect, event: 'change' },
    ];

    controls.forEach(({ el, event }) => {
        if (!el) return;
        el.addEventListener(event, applyFilters);
        if (el === searchInput) {
            el.addEventListener('keydown', (evt) => {
                if (evt.key === 'Escape') {
                    el.value = '';
                    applyFilters();
                }
            });
        }
    });

    applyFilters();
}

function initCalendar() {
    const calendar = document.querySelector('[data-calendar-root]');
    if (!calendar) {
        return;
    }

    const baseEvents = safeParseJSON(calendar.dataset.events) || {};
    let todoEvents = {};
    let useTodoOnly = false;
    let calendarEvents = baseEvents;
    const labelEl = calendar.querySelector('[data-calendar-label]');
    const gridEl = calendar.querySelector('[data-calendar-grid]');
    const listEl = calendar.querySelector('[data-calendar-list]');
    const emptyEl = calendar.querySelector('[data-calendar-empty]');
    const selectedEl = calendar.querySelector('[data-calendar-selected]');
    const defaultSelectedLabel = selectedEl ? selectedEl.textContent : 'Choose a day';
    const prevBtn = calendar.querySelector('[data-calendar-prev]');
    const nextBtn = calendar.querySelector('[data-calendar-next]');

    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const today = new Date();
    let current = new Date(today.getFullYear(), today.getMonth(), 1);
    let activeDate = null;

    if (listEl) {
        listEl.hidden = true;
    }
    if (emptyEl) {
        emptyEl.hidden = false;
    }

    const buildKey = (date) => {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    };

    const formatSelectedLabel = (key) => {
        const [y, m, d] = key.split('-').map(Number);
        const formatted = new Date(y, m - 1, d);
        return formatted.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
    };

    const syncSelectionHighlight = () => {
        if (!gridEl) return;
        const cells = gridEl.querySelectorAll('.calendar-cell');
        cells.forEach((cell) => {
            if (!activeDate || !cell.dataset.date) {
                cell.classList.remove('is-selected');
                return;
            }
            if (cell.dataset.date === activeDate) {
                cell.classList.add('is-selected');
            } else {
                cell.classList.remove('is-selected');
            }
        });
    };

    const showDetails = (dateKey) => {
        activeDate = dateKey;
        if (selectedEl) {
            selectedEl.textContent = formatSelectedLabel(dateKey);
        }
        if (!listEl || !emptyEl) {
            return;
        }
        listEl.innerHTML = '';
        const items = calendarEvents[dateKey] || [];
        if (items.length === 0) {
            emptyEl.hidden = false;
            listEl.hidden = true;
        } else {
            emptyEl.hidden = true;
            listEl.hidden = false;
            items.forEach((item) => {
                const li = document.createElement('li');
                const dot = document.createElement('span');
                dot.className = `calendar-dot ${item.status || 'active'}`;
                const textWrap = document.createElement('div');
                const title = document.createElement('p');
                title.textContent = item.title;
                const meta = document.createElement('span');
                meta.className = 'meta';
                const category = item.category || 'General';
                meta.textContent = item.deadline ? `${category} - ${item.deadline}` : category;
                textWrap.appendChild(title);
                textWrap.appendChild(meta);
                li.appendChild(dot);
                li.appendChild(textWrap);

                li.dataset.taskNoteTrigger = 'true';
                if (item.task_id) {
                    li.dataset.taskId = String(item.task_id);
                }
                li.dataset.taskTitle = item.title || 'Untitled task';
                li.dataset.taskCategory = category;
                li.dataset.taskDeadline = item.deadline || 'No deadline';
                li.dataset.taskDeadlineRelative = item.deadline_relative || '';
                li.dataset.taskNotes = item.notes || '';
                li.tabIndex = 0;
                li.setAttribute('role', 'button');

                listEl.appendChild(li);
            });
        }
        syncSelectionHighlight();
    };

    const render = () => {
        if (labelEl) {
            labelEl.textContent = current.toLocaleString(undefined, { month: 'long', year: 'numeric' });
        }
        if (!gridEl) return;
        gridEl.innerHTML = '';
        dayNames.forEach((name) => {
            const header = document.createElement('div');
            header.textContent = name;
            header.className = 'day-name';
            gridEl.appendChild(header);
        });

        const firstDay = new Date(current.getFullYear(), current.getMonth(), 1);
        const lastDay = new Date(current.getFullYear(), current.getMonth() + 1, 0);
        const offset = firstDay.getDay();
        const prevMonthDays = new Date(current.getFullYear(), current.getMonth(), 0).getDate();
        const totalCells = 42;
        const cells = [];

        for (let i = offset; i > 0; i--) {
            const day = prevMonthDays - i + 1;
            cells.push({ date: new Date(current.getFullYear(), current.getMonth() - 1, day), muted: true });
        }

        for (let day = 1; day <= lastDay.getDate(); day++) {
            cells.push({ date: new Date(current.getFullYear(), current.getMonth(), day), muted: false });
        }

        let nextDay = 1;
        while (cells.length < totalCells) {
            cells.push({ date: new Date(current.getFullYear(), current.getMonth() + 1, nextDay), muted: true });
            nextDay += 1;
        }

        cells.forEach(({ date, muted }) => {
            const inMonth = !muted && date.getMonth() === current.getMonth();
            const dateKey = buildKey(date);
            const hasEvents = !!calendarEvents[dateKey];
            const cell = document.createElement('div');
            cell.className = 'calendar-cell';
            cell.dataset.date = dateKey;
            if (muted || !inMonth) {
                cell.classList.add('is-muted');
            }
            if (hasEvents) {
                cell.classList.add('has-event');
            }
            if (date.toDateString() === today.toDateString()) {
                cell.classList.add('is-today');
            }
            if (activeDate === dateKey) {
                cell.classList.add('is-selected');
            }

            const number = document.createElement('span');
            number.className = 'calendar-number';
            number.textContent = date.getDate();
            cell.appendChild(number);

            if (hasEvents) {
                const dots = document.createElement('div');
                dots.className = 'calendar-dots';
                const limit = Math.min(3, calendarEvents[dateKey].length);
                for (let i = 0; i < limit; i += 1) {
                    const item = calendarEvents[dateKey][i];
                    const dot = document.createElement('span');
                    dot.className = `calendar-dot ${item.status || 'active'}`;
                    dots.appendChild(dot);
                }
                cell.appendChild(dots);
            }

            if (inMonth) {
                cell.setAttribute('role', 'button');
                cell.tabIndex = 0;
                cell.addEventListener('click', () => showDetails(dateKey));
                cell.addEventListener('keydown', (evt) => {
                    if (evt.key === 'Enter' || evt.key === ' ') {
                        evt.preventDefault();
                        showDetails(dateKey);
                    }
                });
            }

            gridEl.appendChild(cell);
        });
    };

    const applyCalendarDataset = () => {
        calendarEvents = useTodoOnly ? todoEvents : baseEvents;
        render();
        if (activeDate && calendarEvents[activeDate] && calendarEvents[activeDate].length) {
            showDetails(activeDate);
        } else {
            if (listEl) listEl.innerHTML = '';
            if (listEl) listEl.hidden = true;
            if (emptyEl) emptyEl.hidden = false;
            activeDate = null;
            syncSelectionHighlight();
            if (selectedEl) {
                selectedEl.textContent = defaultSelectedLabel;
            }
        }
    };

    window.todoCalendar = {
        setTodoEvents(map) {
            todoEvents = map || {};
            if (useTodoOnly) {
                applyCalendarDataset();
            }
        },
        setMode(todoMode) {
            useTodoOnly = Boolean(todoMode);
            applyCalendarDataset();
        },
    };

    prevBtn?.addEventListener('click', () => {
        current.setMonth(current.getMonth() - 1);
        render();
        activeDate = null;
        syncSelectionHighlight();
        if (listEl) {
            listEl.innerHTML = '';
            listEl.hidden = true;
        }
        if (emptyEl) emptyEl.hidden = false;
        if (selectedEl) selectedEl.textContent = defaultSelectedLabel;
    });

    nextBtn?.addEventListener('click', () => {
        current.setMonth(current.getMonth() + 1);
        render();
        activeDate = null;
        syncSelectionHighlight();
        if (listEl) {
            listEl.innerHTML = '';
            listEl.hidden = true;
        }
        if (emptyEl) emptyEl.hidden = false;
        if (selectedEl) selectedEl.textContent = defaultSelectedLabel;
    });

    applyCalendarDataset();

    document.addEventListener('taskNotesUpdated', (event) => {
        const detail = event.detail || {};
        const taskId = detail.taskId;
        if (!taskId) return;
        const updateMap = (map) => {
            Object.keys(map || {}).forEach((dateKey) => {
                const list = map[dateKey];
                if (!Array.isArray(list)) return;
                list.forEach((task) => {
                    if (String(task.task_id) === String(taskId)) {
                        task.notes = detail.notes || '';
                    }
                });
            });
        };
        updateMap(baseEvents);
        updateMap(todoEvents);
        if (activeDate) {
            showDetails(activeDate);
        }
    });
}

function initStatOverlay() {
    const overlay = document.querySelector('[data-stat-overlay]');
    if (!overlay) return;

    const datasets = safeParseJSON(overlay.dataset.datasets) || {};
    const listEl = overlay.querySelector('[data-overlay-list]');
    const titleEl = overlay.querySelector('[data-overlay-title]');
    const subtitleEl = overlay.querySelector('[data-overlay-subtitle]');
    const emptyEl = overlay.querySelector('[data-overlay-empty]');
    const closeBtn = overlay.querySelector('[data-overlay-close]');
    const triggers = document.querySelectorAll('[data-stat-trigger]');

    const copy = {
        total: {
            title: 'All tasks',
            subtitle: 'Complete timeline from past to future.',
        },
        upcoming: {
            title: 'Upcoming tasks',
            subtitle: 'Focus on what is ahead of you.',
        },
        overdue: {
            title: 'Overdue tasks',
            subtitle: 'Handle these first to regain momentum.',
        },
        categories: {
            title: 'Tasks by category',
            subtitle: 'Every focus area in chronological order.',
        },
    };

    let inlineListEl = null;
    let activeInlineItem = null;

    const getInlineContext = (item) => {
        if (!item) return {};
        const form = item.querySelector('[data-overlay-note-form]');
        const textarea = form?.querySelector('[data-overlay-note-textarea]');
        const taskField = form?.querySelector('input[name="task_id"]');
        const title = form?.dataset.taskTitle || item.querySelector('strong')?.textContent || 'Untitled task';
        return { form, textarea, taskField, title };
    };

    const markInlineClean = (form, notes) => {
        if (!form) return;
        form.dataset.originalNotes = notes ?? '';
        form.dataset.isDirty = 'false';
    };

    const closeInlineItemDom = (item) => {
        if (!item) return;
        item.classList.remove('is-active');
        if (activeInlineItem === item) {
            activeInlineItem = null;
        }
    };

    const openInlineItem = (item) => {
        if (!item) return;
        if (activeInlineItem && activeInlineItem !== item) {
            closeInlineItemDom(activeInlineItem);
        }
        item.classList.add('is-active');
        activeInlineItem = item;
        const { textarea } = getInlineContext(item);
        if (textarea) {
            requestAnimationFrame(() => {
                textarea.focus({ preventScroll: true });
                textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
            });
        }
    };

    const saveInlineForm = async (form, textarea, taskField) => {
        if (!form || !textarea || !taskField || !taskField.value) {
            return true;
        }
        try {
            const payload = await submitTaskNotesForm(form);
            const savedNotes = payload.notes || '';
            const updatedTaskId = String(payload.task_id || taskField.value);
            textarea.value = savedNotes;
            markInlineClean(form, savedNotes);
            syncTaskNoteTriggers(updatedTaskId, savedNotes);
            document.dispatchEvent(new CustomEvent('taskNotesUpdated', {
                detail: {
                    taskId: updatedTaskId,
                    notes: savedNotes,
                },
            }));
            showNoteToast('Notes saved');
            return true;
        } catch (err) {
            console.error(err);
            showNoteToast('Failed to save notes', true);
            return false;
        }
    };

    const ensureInlineSavedBeforeClose = async (item) => {
        if (!item) return true;
        const { form, textarea, taskField, title } = getInlineContext(item);
        if (!form || !textarea) {
            return true;
        }
        const original = form.dataset.originalNotes ?? '';
        if (textarea.value === original) {
            form.dataset.isDirty = 'false';
            return true;
        }
        const shouldSave = window.confirm(`Save Task "${title}" notes?`);
        if (!shouldSave) {
            return false;
        }
        return await saveInlineForm(form, textarea, taskField);
    };

    const setupInlineNotePanels = () => {
        inlineListEl = listEl;
        activeInlineItem = null;
        const items = listEl ? Array.from(listEl.querySelectorAll('.overlay-item')) : [];
        items.forEach((item) => {
            const summary = item.querySelector('.overlay-summary');
            const form = item.querySelector('[data-overlay-note-form]');
            const cancelBtn = item.querySelector('[data-overlay-note-cancel]');
            const textarea = form?.querySelector('[data-overlay-note-textarea]');
            const taskField = form?.querySelector('input[name="task_id"]');
            if (form && textarea) {
                markInlineClean(form, textarea.value || '');
                form.dataset.taskTitle = form.dataset.taskTitle || item.querySelector('strong')?.textContent || 'Untitled task';
                textarea.addEventListener('input', () => {
                    const baseline = form.dataset.originalNotes ?? '';
                    form.dataset.isDirty = textarea.value !== baseline ? 'true' : 'false';
                });
            }
            summary?.addEventListener('click', async (event) => {
                if (event.target.closest('[data-overlay-open-note]')) {
                    return;
                }
                event.preventDefault();
                if (item.classList.contains('is-active')) {
                    const ok = await ensureInlineSavedBeforeClose(item);
                    if (ok) {
                        closeInlineItemDom(item);
                    }
                } else {
                    if (activeInlineItem && activeInlineItem !== item) {
                        const ok = await ensureInlineSavedBeforeClose(activeInlineItem);
                        if (!ok) return;
                        closeInlineItemDom(activeInlineItem);
                    }
                    openInlineItem(item);
                }
            });
            summary?.addEventListener('keydown', async (event) => {
                if (event.target.closest('[data-overlay-open-note]')) {
                    return;
                }
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    if (item.classList.contains('is-active')) {
                        const ok = await ensureInlineSavedBeforeClose(item);
                        if (ok) {
                            closeInlineItemDom(item);
                        }
                    } else {
                        if (activeInlineItem && activeInlineItem !== item) {
                            const ok = await ensureInlineSavedBeforeClose(activeInlineItem);
                            if (!ok) return;
                            closeInlineItemDom(activeInlineItem);
                        }
                        openInlineItem(item);
                    }
                }
            });
            cancelBtn?.addEventListener('click', async (event) => {
                event.preventDefault();
                const ok = await ensureInlineSavedBeforeClose(item);
                if (ok) {
                    closeInlineItemDom(item);
                }
            });
            form?.addEventListener('submit', async (event) => {
                event.preventDefault();
                await saveInlineForm(form, textarea, taskField);
            });
        });
    };

    const handleInlineOutsideClick = async (event) => {
        if (!overlay.classList.contains('is-visible')) return;
        if (activeInlineItem && inlineListEl && !inlineListEl.contains(event.target)) {
            const ok = await ensureInlineSavedBeforeClose(activeInlineItem);
            if (ok) {
                closeInlineItemDom(activeInlineItem);
            }
        }
    };

    document.addEventListener('mousedown', handleInlineOutsideClick);

    const renderItems = (type) => {
        if (!listEl || !emptyEl) return;
        listEl.innerHTML = '';
        let source = datasets[type] || [];

        if (type === 'categories') {
            const flattened = [];
            source.forEach((group) => {
                (group.tasks || []).forEach((task) => {
                    flattened.push({
                        ...task,
                        category: group.category || task.category || 'None',
                    });
                });
            });
            source = flattened;
        }

        if (!Array.isArray(source) || source.length === 0) {
            emptyEl.hidden = false;
            listEl.hidden = true;
            return;
        }

        emptyEl.hidden = true;
        listEl.hidden = false;

        // Separate overdue from other tasks for 'total' view
        let overdueTasks = [];
        let otherTasks = [];
        if (type === 'total') {
            source.forEach((task) => {
                if (task.status === 'overdue') {
                    overdueTasks.push(task);
                } else {
                    otherTasks.push(task);
                }
            });
        } else {
            otherTasks = source;
        }

        const renderOverlayItem = (task) => {
            const item = document.createElement('div');
            const statusSlug = (task.status || '').toLowerCase();
            item.className = 'overlay-item';
            if (statusSlug) {
                item.classList.add(`status-${statusSlug}`);
            }
            const taskId = task.id || task.task_id || '';
            if (taskId) {
                item.dataset.taskId = taskId;
            }

            const summary = document.createElement('div');
            summary.className = 'overlay-summary';
            summary.tabIndex = 0;
            summary.setAttribute('role', 'button');

            const topRow = document.createElement('div');
            topRow.className = 'overlay-summary-row';

            const title = document.createElement('strong');
            title.textContent = task.title || 'Untitled task';

            // Removed quickOpenBtn to prevent global overlay trigger
            // and rely on the inline expansion logic (summary click)

            topRow.appendChild(title);

            const metaTop = document.createElement('div');
            metaTop.className = 'meta';
            const deadlineLabel = task.deadline || 'No deadline';
            metaTop.innerHTML = `<span>${task.category || 'None'}</span><span>${deadlineLabel}</span>`;

            const metaBottom = document.createElement('div');
            metaBottom.className = 'meta';
            const relativeLabel = task.relative || 'Flexible';
            const statusLabel = task.status ? task.status.toUpperCase() : '';
            metaBottom.innerHTML = `<span>${relativeLabel}</span><span>${statusLabel}</span>`;

            summary.appendChild(topRow);
            summary.appendChild(metaTop);
            summary.appendChild(metaBottom);

            const panel = document.createElement('div');
            panel.className = 'overlay-note-panel';

            const form = document.createElement('form');
            form.method = 'post';
            form.dataset.overlayNoteForm = 'true';
            if (taskId) {
                form.dataset.taskId = taskId;
            }
            form.dataset.originalNotes = task.notes || '';
            form.dataset.taskTitle = task.title || 'Untitled task';
            form.dataset.isDirty = 'false';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'update_task_notes';

            const taskInput = document.createElement('input');
            taskInput.type = 'hidden';
            taskInput.name = 'task_id';
            taskInput.value = taskId || '';

            const ajaxInput = document.createElement('input');
            ajaxInput.type = 'hidden';
            ajaxInput.name = 'ajax';
            ajaxInput.value = '1';

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = CSRF_TOKEN;

            const label = document.createElement('label');
            label.className = 'input-group';

            const span = document.createElement('span');
            span.textContent = 'Notes';

            const textarea = document.createElement('textarea');
            textarea.name = 'notes';
            textarea.rows = 6;
            textarea.placeholder = 'Add reminders, checklists, or decisions here...';
            textarea.dataset.overlayNoteTextarea = 'true';
            textarea.value = task.notes || '';

            label.appendChild(span);
            label.appendChild(textarea);

            const actions = document.createElement('div');
            actions.className = 'overlay-note-actions';

            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn-ghost';
            cancelBtn.textContent = 'Close';
            cancelBtn.dataset.overlayNoteCancel = 'true';

            const saveBtn = document.createElement('button');
            saveBtn.type = 'submit';
            saveBtn.className = 'btn-primary';
            saveBtn.textContent = 'Save notes';

            actions.appendChild(cancelBtn);
            actions.appendChild(saveBtn);

            form.appendChild(actionInput);
            form.appendChild(taskInput);
            form.appendChild(ajaxInput);
            form.appendChild(csrfInput);
            form.appendChild(label);
            form.appendChild(actions);

            panel.appendChild(form);

            item.appendChild(summary);
            item.appendChild(panel);
            return item;
        };

        // Render overdue tasks first
        overdueTasks.forEach((task) => {
            listEl.appendChild(renderOverlayItem(task));
        });

        // Add divider line between overdue and other tasks
        if (type === 'total' && overdueTasks.length > 0 && otherTasks.length > 0) {
            const dividerContainer = document.createElement('div');
            dividerContainer.className = 'overlay-divider-container';

            const dividerLeft = document.createElement('div');
            dividerLeft.className = 'overlay-divider';

            const label = document.createElement('span');
            label.className = 'overlay-divider-label';
            label.textContent = 'Today';

            const dividerRight = document.createElement('div');
            dividerRight.className = 'overlay-divider';

            dividerContainer.appendChild(dividerLeft);
            dividerContainer.appendChild(label);
            dividerContainer.appendChild(dividerRight);
            listEl.appendChild(dividerContainer);
        }

        // Render other tasks
        otherTasks.forEach((task) => {
            listEl.appendChild(renderOverlayItem(task));
        });

        setupInlineNotePanels();
    };

    const openOverlay = (type) => {
        const text = copy[type];
        if (!text) return;
        if (titleEl) titleEl.textContent = text.title;
        if (subtitleEl) subtitleEl.textContent = text.subtitle;
        renderItems(type);

        // Set panel background color based on type
        const panel = overlay.querySelector('.overlay-panel');
        if (panel) {
            panel.classList.remove('bg-total', 'bg-upcoming', 'bg-overdue', 'bg-categories');
            panel.classList.add(`bg-${type}`);
        }

        overlay.hidden = false;
        lockBodyScroll();
        requestAnimationFrame(() => overlay.classList.add('is-visible'));
    };

    const closeOverlay = async () => {
        if (activeInlineItem) {
            const ok = await ensureInlineSavedBeforeClose(activeInlineItem);
            if (!ok) {
                return;
            }
            closeInlineItemDom(activeInlineItem);
        }
        overlay.classList.remove('is-visible');
        const handle = () => {
            overlay.hidden = true;
            inlineListEl = null;
            unlockBodyScroll();
        };
        runAfterTransition(overlay, handle);
    };

    triggers.forEach((trigger) => {
        const type = trigger.dataset.statTrigger;
        trigger.addEventListener('click', () => openOverlay(type));
        trigger.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openOverlay(type);
            }
        });
    });

    closeBtn?.addEventListener('click', () => {
        closeOverlay();
    });
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            closeOverlay();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && overlay.classList.contains('is-visible')) {
            closeOverlay();
        }
    });
}

function initConfirmOverlay() {
    const overlay = document.querySelector('[data-confirm-overlay]');
    if (!overlay) {
        return;
    }
    const forms = document.querySelectorAll('form[data-confirm]');
    if (!forms.length) {
        return;
    }

    const messageEl = overlay.querySelector('[data-confirm-message]');
    const cancelBtn = overlay.querySelector('[data-confirm-cancel]');
    const approveBtn = overlay.querySelector('[data-confirm-approve]');
    const altBtn = overlay.querySelector('[data-confirm-alt]');
    const defaultApproveLabel = approveBtn ? approveBtn.textContent : 'Confirm';
    let pendingForm = null;

    const setHiddenValue = (form, name, value) => {
        if (!form || !name) return;
        const input = form.querySelector(`input[name="${name}"]`);
        if (input) {
            input.value = value;
        }
    };

    const closeOverlay = () => {
        overlay.classList.remove('is-visible');
        overlay.hidden = true;
        unlockBodyScroll();
    };

    const openOverlay = (form) => {
        pendingForm = form;
        const message = form.dataset.confirm || 'Are you sure?';
        const approveLabel = form.dataset.confirmPrimary || defaultApproveLabel;
        const primaryTarget = form.dataset.confirmPrimaryTarget || '';
        const primaryValue = form.dataset.confirmPrimaryValue || '';
        const altLabel = form.dataset.confirmAlt || '';
        const altTarget = form.dataset.confirmAltTarget || '';
        const altValue = form.dataset.confirmAltValue || '';
        if (messageEl) {
            messageEl.textContent = message;
        }
        if (approveBtn) {
            approveBtn.textContent = approveLabel || defaultApproveLabel;
        }
        if (altBtn) {
            altBtn.hidden = !(altLabel && altTarget && altValue);
            altBtn.textContent = altLabel || '';
            altBtn.dataset.targetName = altTarget;
            altBtn.dataset.targetValue = altValue;
        }
        if (primaryTarget && primaryValue) {
            setHiddenValue(form, primaryTarget, primaryValue);
        }
        overlay.hidden = false;
        lockBodyScroll();
        requestAnimationFrame(() => overlay.classList.add('is-visible'));
    };

    forms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.skipConfirm === 'true') {
                delete form.dataset.skipConfirm;
                return;
            }
            event.preventDefault();
            openOverlay(form);
        });
    });

    cancelBtn?.addEventListener('click', () => {
        pendingForm = null;
        closeOverlay();
    });

    approveBtn?.addEventListener('click', () => {
        if (pendingForm) {
            pendingForm.dataset.skipConfirm = 'true';
            pendingForm.requestSubmit();
            pendingForm = null;
        }
        closeOverlay();
    });

    altBtn?.addEventListener('click', () => {
        if (pendingForm) {
            const { targetName = '', targetValue = '' } = altBtn.dataset || {};
            if (targetName && targetValue) {
                setHiddenValue(pendingForm, targetName, targetValue);
            }
            pendingForm.dataset.skipConfirm = 'true';
            pendingForm.requestSubmit();
            pendingForm = null;
        }
        closeOverlay();
    });

    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            pendingForm = null;
            closeOverlay();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && overlay.classList.contains('is-visible')) {
            pendingForm = null;
            closeOverlay();
        }
    });
}

function initCategoryFilter() {
    const input = document.querySelector('[data-category-filter-input]');
    const cards = Array.from(document.querySelectorAll('[data-category-card]'));
    const emptyState = document.querySelector('[data-category-filter-empty]');
    if (!input || cards.length === 0) {
        return;
    }

    const applyFilter = () => {
        const query = input.value.trim().toLowerCase();
        let visible = 0;
        cards.forEach((card) => {
            const name = card.dataset.categoryName || '';
            const matches = !query || name.includes(query);
            card.style.display = matches ? '' : 'None';
            if (matches) visible += 1;
        });
        if (emptyState) {
            emptyState.hidden = visible !== 0;
        }
    };

    input.addEventListener('input', applyFilter);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            input.value = '';
            applyFilter();
        }
    });

    applyFilter();
}

function initCategoryTaskSync() {
    const categoryCards = Array.from(document.querySelectorAll('[data-category-card]'));
    const taskCategorySelect = document.querySelector('[data-category-filter]');
    if (!categoryCards.length || !taskCategorySelect) {
        return;
    }

    const highlightActive = (categoryId) => {
        categoryCards.forEach((card) => {
            card.classList.toggle('is-active', !!categoryId && card.dataset.categoryId === categoryId);
        });
    };

    const syncFromSelect = () => {
        const value = taskCategorySelect.value;
        const categoryId = value === 'all' ? null : value;
        highlightActive(categoryId);
    };

    taskCategorySelect.addEventListener('change', () => {
        syncFromSelect();
    });
    syncFromSelect();

    categoryCards.forEach((card) => {
        card.addEventListener('click', (event) => {
            if (event.target.closest('form') || event.target.closest('button')) {
                return;
            }
            const id = card.dataset.categoryId || '';
            const nextValue = taskCategorySelect.value === id ? 'all' : id;
            taskCategorySelect.value = nextValue;
            taskCategorySelect.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
}

function initScrollPreserver() {
    const key = 'todo_scroll_position';
    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
    }
    const targets = document.querySelectorAll('form[data-preserve-scroll], button[data-preserve-scroll]');
    const manualTriggers = document.querySelectorAll('[data-preserve-scroll-trigger]');

    const storePosition = () => {
        sessionStorage.setItem(key, String(window.scrollY || window.pageYOffset));
    };

    targets.forEach((element) => {
        if (element.tagName === 'FORM') {
            element.addEventListener('submit', storePosition);
        } else {
            element.addEventListener('click', storePosition);
        }
    });

    manualTriggers.forEach((element) => {
        element.addEventListener('change', storePosition, { capture: true });
        element.addEventListener('input', storePosition, { capture: true });
    });

    const restorePosition = () => {
        const stored = sessionStorage.getItem(key);
        if (stored) {
            sessionStorage.removeItem(key);
            const top = Number(stored);
            if (!Number.isNaN(top)) {
                requestAnimationFrame(() => {
                    window.scrollTo({ top, behavior: 'auto' });
                });
            }
        }
    };

    if (document.readyState === 'complete') {
        restorePosition();
    } else {
        window.addEventListener('load', restorePosition, { once: true });
    }
}

function initTaskCheckboxes() {
    const checkboxes = document.querySelectorAll('[data-toggle-task-checkbox]');
    checkboxes.forEach((checkbox) => {
        checkbox.addEventListener('change', async (e) => {
            const form = checkbox.closest('form');
            if (!form) return;
            const newCheckedState = checkbox.checked;

            const formData = new FormData(form);
            formData.set('is_done', newCheckedState ? '1' : '0');
            attachCsrfToken(formData);
            try {
                const response = await fetch(form.action || 'todo.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    console.error('Failed to update task');
                    checkbox.checked = !newCheckedState;
                    return;
                }

                // Update checkbox to reflect the new state
                checkbox.checked = newCheckedState;

                // Parse the response and update stats
                const stats = await response.json();
                if (stats) {
                    updateStatsDisplay(stats);
                }
            } catch (err) {
                console.error('Error updating task:', err);
                checkbox.checked = !newCheckedState;
            }
        });
    });

    // Initialize today's task checkboxes
    const todayCheckboxes = document.querySelectorAll('.task-done-checkbox');
    todayCheckboxes.forEach((checkbox) => {
        checkbox.addEventListener('change', async (e) => {
            const taskId = checkbox.dataset.taskId;
            if (!taskId) return;

            const newCheckedState = checkbox.checked;

            try {
                const formData = new FormData();
                formData.append('action', 'toggle_done');
                formData.append('task_id', taskId);
                formData.append('is_done', newCheckedState ? '1' : '0');
                attachCsrfToken(formData);

                const response = await fetch('todo.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    console.error('Failed to update today task');
                    checkbox.checked = !newCheckedState;
                    return;
                }

                checkbox.checked = newCheckedState;

                const stats = await response.json();
                updateStatsDisplay(stats);

                // Fade out completed task
                if (newCheckedState) {
                    const taskItem = checkbox.closest('.today-task-item');
                    if (taskItem) {
                        taskItem.style.opacity = '0.6';
                        setTimeout(() => {
                            taskItem.style.display = 'None';
                        }, 300);
                    }
                }
            } catch (err) {
                console.error('Error updating today task:', err);
                checkbox.checked = !newCheckedState;
            }
        });
    });
}

function initTaskNotesOverlay() {
    const overlay = document.querySelector('[data-task-note-overlay]');
    if (!overlay) {
        return;
    }

    const form = overlay.querySelector('[data-task-note-form]');
    const textarea = overlay.querySelector('[data-task-note-textarea]');
    const titleEl = overlay.querySelector('[data-task-note-title]');
    const metaEl = overlay.querySelector('[data-task-note-meta]');
    const idInput = overlay.querySelector('[data-task-note-id]');
    const closeControls = overlay.querySelectorAll('[data-task-note-close], [data-task-note-cancel]');
    const interactiveSelector = 'button, a, input, textarea, select, label';

    let overlayOriginalValue = '';
    let overlayDirty = false;
    let overlayTaskTitle = 'Untitled task';

    const formatMeta = (category, deadline, relative) => {
        const parts = [];
        if (category) parts.push(category);
        if (relative) {
            parts.push(relative);
        } else if (deadline) {
            parts.push(deadline);
        }
        return parts.join(' · ') || 'Keep important context close.';
    };

    const markOverlayDirty = () => {
        if (!textarea) return;
        overlayDirty = textarea.value !== overlayOriginalValue;
    };

    textarea?.addEventListener('input', markOverlayDirty);

    const populateOverlay = (trigger) => {
        const dataset = trigger.dataset || {};
        const taskId = dataset.taskId;
        if (!taskId) return false;
        if (idInput) {
            idInput.value = taskId;
        }
        overlayTaskTitle = dataset.taskTitle || 'Untitled task';
        overlayOriginalValue = dataset.taskNotes || '';
        overlayDirty = false;
        if (titleEl) {
            titleEl.textContent = overlayTaskTitle;
        }
        if (metaEl) {
            metaEl.textContent = formatMeta(
                dataset.taskCategory || 'General',
                dataset.taskDeadline || '',
                dataset.taskDeadlineRelative || ''
            );
        }
        if (textarea) {
            textarea.value = overlayOriginalValue;
            requestAnimationFrame(() => {
                textarea.focus({ preventScroll: true });
                textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
            });
        }
        return true;
    };

    const showOverlay = (trigger) => {
        const populated = populateOverlay(trigger);
        if (!populated) return;
        overlay.hidden = false;
        lockBodyScroll();
        requestAnimationFrame(() => overlay.classList.add('is-visible'));
    };

    const closeOverlayImmediate = () => {
        overlay.classList.remove('is-visible');
        const handle = () => {
            overlay.hidden = true;
            overlayDirty = false;
            unlockBodyScroll();
        };
        runAfterTransition(overlay, handle);
    };

    const saveOverlayNotes = async () => {
        if (!form || !idInput || !idInput.value) {
            return true;
        }
        try {
            const payload = await submitTaskNotesForm(form);
            const savedNotes = payload.notes || '';
            const updatedTaskId = String(payload.task_id || idInput.value);
            overlayOriginalValue = savedNotes;
            overlayDirty = false;
            if (textarea) {
                textarea.value = savedNotes;
            }
            syncTaskNoteTriggers(updatedTaskId, savedNotes);
            document.dispatchEvent(new CustomEvent('taskNotesUpdated', {
                detail: {
                    taskId: updatedTaskId,
                    notes: savedNotes,
                },
            }));
            showNoteToast('Notes saved');
            return true;
        } catch (err) {
            console.error(err);
            showNoteToast('Failed to save notes', true);
            return false;
        }
    };

    const ensureOverlaySavedBeforeClose = async () => {
        if (!overlayDirty) {
            return true;
        }
        const shouldSave = window.confirm(`Save Task "${overlayTaskTitle}" notes?`);
        if (!shouldSave) {
            return false;
        }
        return await saveOverlayNotes();
    };

    const attemptOverlayClose = async () => {
        const ok = await ensureOverlaySavedBeforeClose();
        if (!ok) {
            return;
        }
        closeOverlayImmediate();
    };

    const shouldIgnore = (target) => {
        if (!target) return false;

        const interactive = target.closest(interactiveSelector);
        if (interactive) {
            const trigger = target.closest('[data-task-note-trigger]');
            // If the interactive element is the trigger itself, don't ignore.
            if (interactive === trigger) return false;

            // Otherwise, ignore clicks on interactive elements (like delete buttons inside the card)
            return true;
        }

        return false;
    };

    const handleOverlayTrigger = async (trigger) => {
        if (!trigger) return;
        if (overlay.classList.contains('is-visible')) {
            const ok = await ensureOverlaySavedBeforeClose();
            if (!ok) return;
        }
        showOverlay(trigger);
    };

    document.addEventListener('click', async (event) => {
        const trigger = event.target.closest('[data-task-note-trigger]');
        if (!trigger) return;
        if (shouldIgnore(event.target)) return;
        await handleOverlayTrigger(trigger);
    });

    document.addEventListener('keydown', async (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const trigger = event.target.closest('[data-task-note-trigger]');
        if (!trigger) return;
        if (shouldIgnore(event.target)) return;
        event.preventDefault();
        await handleOverlayTrigger(trigger);
    });

    closeControls.forEach((btn) => {
        btn.addEventListener('click', async (event) => {
            event.preventDefault();
            await attemptOverlayClose();
        });
    });

    overlay.addEventListener('click', async (event) => {
        if (event.target === overlay) {
            await attemptOverlayClose();
        }
    });

    document.addEventListener('keydown', async (event) => {
        if (event.key === 'Escape' && overlay.classList.contains('is-visible')) {
            await attemptOverlayClose();
        }
    });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        await saveOverlayNotes();
    });
}
async function submitTaskNotesForm(form) {
    const formData = new FormData(form);
    attachCsrfToken(formData);
    const actionAttr = typeof form.getAttribute === 'function' ? form.getAttribute('action') : null;
    const targetUrl = actionAttr && actionAttr.trim() !== '' ? actionAttr : 'todo.php';
    const response = await fetch(targetUrl, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const raw = await response.text();
    if (!response.ok) {
        console.error('Notes save failed with response:', raw);
        throw new Error('Failed to save notes');
    }
    let payload;
    try {
        payload = JSON.parse(raw);
    } catch (err) {
        console.error('Notes save payload parse failed:', raw);
        throw err;
    }
    if (!payload.success) {
        throw new Error(payload.error || 'Unable to save notes');
    }
    return payload;
}

function syncTaskNoteTriggers(taskId, notes) {
    if (!taskId) return;
    const normalizedId = String(taskId);
    const triggers = document.querySelectorAll('[data-task-note-trigger]');
    triggers.forEach((node) => {
        if (String(node.dataset.taskId) === normalizedId) {
            node.dataset.taskNotes = notes || '';
        }
    });
    const inlineForms = document.querySelectorAll('[data-overlay-note-form]');
    inlineForms.forEach((form) => {
        if (String(form.dataset.taskId) === normalizedId) {
            const textarea = form.querySelector('[data-overlay-note-textarea]');
            if (textarea) {
                textarea.value = notes || '';
            }
            form.dataset.originalNotes = notes || '';
            form.dataset.isDirty = 'false';
        }
    });
}

let noteToastTimer = null;
function showNoteToast(message, isError = false) {
    const toast = document.querySelector('[data-note-toast]');
    if (!toast) {
        return;
    }
    toast.textContent = message;
    toast.dataset.variant = isError ? 'error' : 'success';
    toast.classList.add('is-visible');
    if (noteToastTimer) {
        clearTimeout(noteToastTimer);
    }
    noteToastTimer = window.setTimeout(() => {
        toast.classList.remove('is-visible');
        noteToastTimer = null;
    }, 2200);
}

function updateStatsDisplay(stats) {
    // Update completion percent and progress bar
    const progressLabel = document.querySelector('.progress-label strong');
    if (progressLabel) {
        progressLabel.textContent = stats.completion_percent + '%';
    }

    const progressFill = document.querySelector('.progress-fill');
    if (progressFill) {
        progressFill.style.width = stats.completion_percent + '%';
    }

    // Update health text
    const introCopy = document.querySelector('.intro-copy');
    if (introCopy) {
        introCopy.textContent = stats.health_text;
    }

    // Update next deadline info
    const progressNote = document.querySelector('.progress-note');
    if (progressNote) {
        if (stats.next_deadline) {
            progressNote.innerHTML = `Next deadline <strong>${stats.next_deadline}</strong>
                <span>(${stats.next_deadline_relative})</span>`;
        } else {
            progressNote.textContent = 'No planned deadlines yet.';
        }
    }

    // Update stat cards (total, upcoming, overdue)
    const totalCard = document.querySelector('[data-stat-trigger="total"]');
    if (totalCard) {
        const values = totalCard.querySelectorAll('.stat-value, .stat-meta');
        if (values[0]) values[0].textContent = stats.total;
        if (values[1]) values[1].textContent = stats.active + ' active · ' + stats.completed + ' done';
    }

    const upcomingCard = document.querySelector('[data-stat-trigger="upcoming"]');
    if (upcomingCard) {
        const value = upcomingCard.querySelector('.stat-value');
        if (value) value.textContent = stats.upcoming;
    }

    const overdueCard = document.querySelector('[data-stat-trigger="overdue"]');
    if (overdueCard) {
        const value = overdueCard.querySelector('.stat-value');
        if (value) value.textContent = stats.overdue;
    }
}

function safeParseJSON(value) {
    if (!value) return null;
    try {
        return JSON.parse(value);
    } catch (err) {
        console.warn('Unable to parse JSON payload:', err);
        return null;
    }
}

// Theme Switcher
function initThemeSwitch() {
    const switcher = document.querySelector('[data-theme-switch]');
    const indicator = switcher?.querySelector('[data-theme-indicator]');
    const buttons = switcher ? Array.from(switcher.querySelectorAll('[data-theme-option]')) : [];

    if (!switcher) {
        const saved = localStorage.getItem('theme') === 'dark' ? 'dark' : 'light';
        applyTheme(saved);
        return;
    }

    const setActive = (mode) => {
        const translate = mode === 'dark' ? 'translateX(calc(100% + 6px))' : 'translateX(0%)';
        if (indicator) {
            indicator.style.transform = translate;
        }
        buttons.forEach((btn) => {
            const isActive = btn.dataset.themeOption === mode;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-pressed', String(isActive));
        });
    };

    themeSwitchUpdater = setActive;

    if (buttons.length) {
        buttons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const desired = btn.dataset.themeOption === 'dark' ? 'dark' : 'light';
                if (currentTheme === desired) return;
                applyTheme(desired);
            });
        });
    }

    const saved = localStorage.getItem('theme') === 'dark' ? 'dark' : 'light';
    setActive(saved);
    applyTheme(saved);
}

function toggleTheme() {
    applyTheme(currentTheme === 'dark' ? 'light' : 'dark');
}

function initScheduleToggle() {
    const card = document.querySelector('[data-today-card]');
    const switcher = document.querySelector('[data-schedule-switch]');
    const titleEl = document.querySelector('[data-today-card-title]');
    const subtitleEl = document.querySelector('[data-today-card-subtitle]');
    const missionBody = document.querySelector('[data-mission-body]');
    const todoBody = document.querySelector('[data-todo-body]');

    if (!card || !switcher || !titleEl || !subtitleEl) {
        return;
    }

    const indicator = switcher.querySelector('[data-schedule-indicator]');
    const optionButtons = Array.from(switcher.querySelectorAll('[data-schedule-option]'));
    if (optionButtons.length < 2 || !missionBody || !todoBody) {
        return;
    }

    const defaultTitle = titleEl.dataset.defaultText || titleEl.textContent;
    const altTitle = titleEl.dataset.altText || defaultTitle;
    const defaultSubtitle = subtitleEl.dataset.defaultText || subtitleEl.textContent;
    const altSubtitle = subtitleEl.dataset.altText || defaultSubtitle;
    let isTodoMode = false;

    const applyState = () => {
        card.classList.toggle('is-schedule-mode', isTodoMode);
        titleEl.textContent = isTodoMode ? altTitle : defaultTitle;
        subtitleEl.textContent = isTodoMode ? altSubtitle : defaultSubtitle;
        optionButtons.forEach((btn) => {
            const isActive = btn.dataset.scheduleOption === (isTodoMode ? 'todo' : 'mission');
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-pressed', String(isActive));
        });
        if (indicator) {
            indicator.style.transform = isTodoMode ? 'translateX(calc(100% + 7px))' : 'translateX(calc(0% + 1px))';
        }
        missionBody.hidden = isTodoMode;
        todoBody.hidden = !isTodoMode;
        if (window.todoCalendar && typeof window.todoCalendar.setMode === 'function') {
            window.todoCalendar.setMode(isTodoMode);
        }
    };

    optionButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const mode = btn.dataset.scheduleOption === 'todo';
            if (isTodoMode === mode) return;
            isTodoMode = mode;
            applyState();
        });
    });

    applyState();
}

function initPlanOverlay() {
    const trigger = document.querySelector('[data-plan-trigger]');
    const overlay = document.querySelector('[data-plan-overlay]');
    const listEl = overlay ? overlay.querySelector('[data-plan-list]') : null;
    const confirmBtn = overlay ? overlay.querySelector('[data-plan-confirm]') : null;
    const closeButtons = overlay ? overlay.querySelectorAll('[data-plan-close], [data-plan-cancel]') : [];
    const panel = overlay ? overlay.querySelector('[data-plan-panel]') : null;
    const todoResults = document.querySelector('[data-todo-results]');
    const todoResultsList = todoResults ? todoResults.querySelector('[data-todo-result-list]') : null;
    const todoHint = document.querySelector('[data-todo-empty-hint]');

    if (!trigger || !overlay || !listEl || !confirmBtn || !todoResults || !todoResultsList) {
        return;
    }

    const storageKey = trigger.dataset.planStorageKey || 'todo-plan-schedule';
    const baseTasks = safeParseJSON(trigger.dataset.planTasks) || [];
    const taskLookup = new Map(baseTasks.map((task) => [String(task.id), task]));
    let tasksState = [];

    const loadSavedPlan = () => {
        const raw = localStorage.getItem(storageKey);
        if (!raw) return [];
        try {
            const parsed = JSON.parse(raw);
            if (parsed && Array.isArray(parsed.items)) {
                return parsed.items
                    .map((item, index) => ({
                        id: item.id,
                        order: typeof item.order === 'number' ? item.order : index + 1,
                    }))
                    .filter((item) => item.id !== undefined)
                    .sort((a, b) => a.order - b.order);
            }
        } catch (err) {
            console.warn('Unable to parse saved plan data:', err);
        }
        return [];
    };

    const savePlan = (items) => {
        localStorage.setItem(storageKey, JSON.stringify({ items }));
    };

    const hydratePlanItems = (items) => {
        return items
            .map((item) => {
                const data = taskLookup.get(String(item.id));
                if (!data) return null;
                return { ...data, order: item.order };
            })
            .filter(Boolean)
            .sort((a, b) => a.order - b.order);
    };

    const buildTodoEventMap = (planItems) => {
        const map = {};
        planItems.forEach((item) => {
            const data = taskLookup.get(String(item.id));
            if (!data || !data.deadline_key) {
                return;
            }
            const dateKey = data.deadline_key;
            if (!map[dateKey]) {
                map[dateKey] = [];
            }
            map[dateKey].push({
                title: data.title,
                category: data.category || 'General',
                status: 'todo',
                deadline: data.deadline || 'No deadline',
                deadline_relative: data.deadline_relative || '',
                task_id: data.id,
                notes: data.notes || '',
            });
        });
        return map;
    };

    const syncCalendarTodoDots = (planItems) => {
        if (window.todoCalendar && typeof window.todoCalendar.setTodoEvents === 'function') {
            const eventMap = planItems.length ? buildTodoEventMap(planItems) : {};
            window.todoCalendar.setTodoEvents(eventMap);
        }
    };

    const renderScheduleResults = (planItems) => {
        const detailItems = hydratePlanItems(planItems);
        if (!detailItems.length) {
            todoResults.hidden = true;
            if (todoHint) todoHint.hidden = false;
            todoResultsList.innerHTML = '';
            syncCalendarTodoDots([]);
            return;
        }
        todoResults.hidden = false;
        if (todoHint) todoHint.hidden = true;
        todoResultsList.innerHTML = '';
        detailItems.forEach((task) => {
            const item = document.createElement('li');
            item.dataset.taskNoteTrigger = 'true';
            item.dataset.taskTitle = task.title || 'Untitled task';
            item.dataset.taskCategory = task.category || 'General';
            item.dataset.taskDeadline = task.deadline || 'No deadline';
            item.dataset.taskDeadlineRelative = task.deadline_relative || '';
            item.dataset.taskId = task.id;
            item.dataset.taskNotes = task.notes || '';
            item.tabIndex = 0;
            item.setAttribute('role', 'button');

            const badge = document.createElement('span');
            badge.className = 'todo-order-badge';
            badge.textContent = task.order;

            const content = document.createElement('div');
            const titleEl = document.createElement('strong');
            titleEl.textContent = task.title;
            const meta = document.createElement('span');
            const parts = [];
            if (task.category) parts.push(task.category);
            if (task.deadline) parts.push(task.deadline);
            meta.textContent = parts.join(' · ');
            content.appendChild(titleEl);
            content.appendChild(meta);

            item.appendChild(badge);
            item.appendChild(content);
            todoResultsList.appendChild(item);
        });
        syncCalendarTodoDots(planItems);
    };

    const reindexSelections = () => {
        const selected = tasksState
            .filter((task) => task.selected)
            .sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
        selected.forEach((task, index) => {
            task.order = index + 1;
        });
    };

    const getSelected = () => {
        return tasksState.filter((task) => task.selected).sort((a, b) => a.order - b.order);
    };

    const addSelection = (task) => {
        if (task.selected) return;
        const existingCount = getSelected().length;
        task.selected = true;
        task.order = existingCount + 1;
    };

    const removeSelection = (task) => {
        if (!task.selected) return;
        task.selected = false;
        task.order = null;
        reindexSelections();
    };

    const updateOrder = (task, newOrder) => {
        if (!task.selected) return;
        const selected = getSelected();
        const maxOrder = selected.length;
        const target = Math.min(Math.max(newOrder, 1), maxOrder);
        if (target === task.order) return;
        task.order = target;
        reindexSelections();
    };

    const updateOrderControls = () => {
        const selected = getSelected();
        const total = selected.length;
        listEl.querySelectorAll('[data-plan-row]').forEach((row) => {
            const taskId = row.dataset.taskId;
            const task = tasksState.find((t) => String(t.id) === String(taskId));
            if (!task) return;
            const select = row.querySelector('[data-order-select]');
            const badge = row.querySelector('[data-order-badge]');
            row.classList.toggle('is-selected', Boolean(task.selected));
            if (!select || !badge) return;
            if (!task.selected) {
                select.disabled = true;
                select.innerHTML = '<option value="">--</option>';
                badge.textContent = '';
                return;
            }
            select.disabled = false;
            select.innerHTML = '';
            for (let i = 1; i <= total; i += 1) {
                const option = document.createElement('option');
                option.value = i;
                option.textContent = i;
                if (i === task.order) option.selected = true;
                select.appendChild(option);
            }
            badge.textContent = String(task.order);
        });
        confirmBtn.disabled = total === 0;
    };

    const applyNoteDataset = (target, task) => {
        if (!target || !task) return;
        target.dataset.taskNoteTrigger = 'true';
        target.dataset.taskId = task.id;
        target.dataset.taskTitle = task.title || 'Untitled task';
        target.dataset.taskCategory = task.category || 'General';
        target.dataset.taskDeadline = task.deadline || 'No deadline';
        target.dataset.taskDeadlineRelative = task.deadline_relative || '';
        target.dataset.taskNotes = task.notes || '';
        target.tabIndex = 0;
        target.setAttribute('role', 'button');
    };

    const renderRows = () => {
        listEl.innerHTML = '';
        if (!tasksState.length) {
            const empty = document.createElement('p');
            empty.className = 'plan-empty';
            empty.textContent = 'No tasks available to schedule yet.';
            listEl.appendChild(empty);
            confirmBtn.disabled = true;
            return;
        }
        tasksState.forEach((task, index) => {
            const row = document.createElement('div');
            row.className = 'plan-row';
            row.dataset.planRow = 'true';
            row.dataset.taskId = task.id;
            row.style.setProperty('--plan-row-index', String(index));

            const checkboxLabel = document.createElement('label');
            checkboxLabel.className = 'plan-checkbox';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = task.selected;
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) {
                    addSelection(task);
                } else {
                    removeSelection(task);
                }
                updateOrderControls();
            });
            checkboxLabel.appendChild(checkbox);

            const info = document.createElement('div');
            info.className = 'plan-info';
            applyNoteDataset(info, task);
            const title = document.createElement('strong');
            title.textContent = task.title;
            const meta = document.createElement('span');
            meta.className = 'plan-meta';
            const metaParts = [];
            if (task.category) metaParts.push(task.category);
            if (task.deadline) metaParts.push(task.deadline);
            meta.textContent = metaParts.join(' · ');
            info.appendChild(title);
            info.appendChild(meta);

            const orderControls = document.createElement('div');
            orderControls.className = 'plan-order';
            const badge = document.createElement('span');
            badge.className = 'plan-order-badge';
            badge.dataset.orderBadge = 'true';
            badge.textContent = task.selected ? String(task.order) : '';
            const select = document.createElement('select');
            select.dataset.orderSelect = 'true';
            select.disabled = !task.selected;
            select.innerHTML = '<option value="">--</option>';
            select.addEventListener('change', () => {
                const value = parseInt(select.value, 10);
                if (!Number.isNaN(value)) {
                    updateOrder(task, value);
                    updateOrderControls();
                }
            });
            orderControls.appendChild(badge);
            orderControls.appendChild(select);

            row.appendChild(checkboxLabel);
            row.appendChild(info);
            row.appendChild(orderControls);
            listEl.appendChild(row);
        });
        updateOrderControls();
    };

    const openOverlay = () => {
        tasksState = baseTasks.map((task) => ({
            ...task,
            selected: false,
            order: null,
        }));
        const saved = loadSavedPlan();
        if (saved.length) {
            const orderMap = new Map(saved.map((item) => [String(item.id), item.order]));
            tasksState.forEach((task) => {
                const order = orderMap.get(String(task.id));
                if (order !== undefined) {
                    task.selected = true;
                    task.order = order;
                }
            });
            reindexSelections();
        }
        renderRows();
        overlay.hidden = false;
        overlay.classList.remove('is-visible');
        if (overlay) {
            overlay.style.animation = 'none';
            void overlay.offsetWidth; // force reflow to restart animation
            overlay.style.animation = '';
        }
        if (panel) {
            panel.style.animation = 'none';
            void panel.offsetWidth;
            panel.style.animation = '';
        }
        lockBodyScroll();
        requestAnimationFrame(() => overlay.classList.add('is-visible'));
    };

    const closeOverlay = () => {
        overlay.classList.remove('is-visible');
        const finishClose = () => {
            overlay.hidden = true;
            inlineListEl = null;
            unlockBodyScroll();
        };
        runAfterTransition(overlay, finishClose);
    };

    trigger.addEventListener('click', openOverlay);

    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            closeOverlay();
        }
    });

    closeButtons.forEach((btn) => {
        btn.addEventListener('click', closeOverlay);
    });

    confirmBtn.addEventListener('click', () => {
        const selected = getSelected();
        if (!selected.length) return;
        const payload = selected.map((task) => ({ id: task.id, order: task.order }));
        savePlan(payload);
        renderScheduleResults(payload);
        closeOverlay();
    });

    renderScheduleResults(loadSavedPlan());

    document.addEventListener('taskNotesUpdated', (event) => {
        const { taskId, notes } = event.detail || {};
        if (!taskId) return;
        const strId = String(taskId);

        // Update lookup
        const task = taskLookup.get(strId);
        if (task) {
            task.notes = notes || '';
        }

        // Update baseTasks
        const baseTask = baseTasks.find((t) => String(t.id) === strId);
        if (baseTask) {
            baseTask.notes = notes || '';
        }

        // Update tasksState
        const stateTask = tasksState.find((t) => String(t.id) === strId);
        if (stateTask) {
            stateTask.notes = notes || '';
        }
    });
}

function initSpotlightOverlay() {
    const overlay = document.querySelector('[data-spotlight-overlay]');
    const panel = overlay ? overlay.querySelector('[data-spotlight-panel]') : null;
    const input = overlay ? overlay.querySelector('[data-spotlight-input]') : null;
    const form = overlay ? overlay.querySelector('[data-spotlight-form]') : null;
    const submitBtn = overlay ? overlay.querySelector('[data-spotlight-submit]') : null;
    const tabs = overlay ? Array.from(overlay.querySelectorAll('[data-spotlight-tab]')) : [];
    const tabIndicator = overlay ? overlay.querySelector('[data-spotlight-tab-indicator]') : null;
    const typeOptions = overlay ? Array.from(overlay.querySelectorAll('[data-spotlight-type-option]')) : [];
    const resultsWrap = overlay ? overlay.querySelector('[data-spotlight-results]') : null;
    const resultsList = overlay ? overlay.querySelector('[data-spotlight-list]') : null;
    const hintEl = overlay ? overlay.querySelector('[data-spotlight-hint]') : null;

    if (!overlay || !panel || !input || !form || !resultsWrap || !resultsList) {
        return;
    }

    let mode = 'add';
    let searchType = 'task';
    let isSubmitting = false;

    const isTypingField = (el) => {
        if (!el) return false;
        const tag = el.tagName;
        const formTags = ['INPUT', 'TEXTAREA', 'SELECT', 'OPTION', 'BUTTON'];
        return formTags.includes(tag) || el.isContentEditable || !!el.closest('[contenteditable="true"]');
    };

    const getTaskData = () => {
        const rows = Array.from(document.querySelectorAll('[data-task-row]'));
        return rows.map((row) => ({
            title: row.dataset.title || row.querySelector('[data-title]')?.textContent?.trim() || '',
            category: row.dataset.categoryName || 'None',
            status: row.dataset.status || 'active',
            id: row.dataset.id || row.dataset.taskId || row.querySelector('[data-task-id]')?.value || '',
        }));
    };

    const getCategoryData = () => {
        const select = document.querySelector('[data-category-filter]');
        if (!select) return [];
        const options = Array.from(select.options).filter((opt) => opt.value !== 'all');
        const tasks = getTaskData();
        const normalize = (val) => (val || 'None').trim().toLowerCase();
        const grouped = tasks.reduce((map, task) => {
            const key = normalize(task.category);
            if (!map[key]) map[key] = [];
            map[key].push(task);
            return map;
        }, {});
        return options.map((opt) => {
            const label = opt.textContent.trim();
            const key = normalize(label);
            const bucket = grouped[key] || [];
            return {
                name: label,
                count: bucket.length,
                tasks: bucket,
            };
        });
    };

    const setMode = (next) => {
        mode = next === 'search' ? 'search' : 'add';
        overlay.dataset.spotlightMode = mode;
        tabs.forEach((btn) => {
            const active = btn.dataset.spotlightTab === mode;
            btn.classList.toggle('is-active', active);
        });
        if (tabIndicator) {
            const activeIndex = tabs.findIndex((btn) => btn.dataset.spotlightTab === mode);
            tabIndicator.style.transform = activeIndex === 1 ? 'translateX(100%)' : 'translateX(0%)';
        }
        overlay.classList.toggle('is-search-mode', mode === 'search');
        input.placeholder = mode === 'search' ? 'Search tasks or categories...' : 'Add a quick task...';
        if (hintEl) {
            hintEl.textContent = mode === 'search'
                ? 'Type to search. Use ← / → to switch Task or Category.'
                : 'Press Enter to add this task instantly.';
        }
        renderResults();
    };

    const setSearchType = (next) => {
        searchType = next === 'category' ? 'category' : 'task';
        overlay.dataset.spotlightType = searchType;
        typeOptions.forEach((chip) => {
            const active = chip.dataset.spotlightTypeOption === searchType;
            chip.classList.toggle('is-active', active);
        });
        renderResults();
    };

    const renderResults = () => {
        const query = (input.value || '').trim().toLowerCase();
        overlay.classList.toggle('has-query', query.length > 0);
        resultsList.innerHTML = '';

        if (mode !== 'search') {
            resultsWrap.hidden = true;
            overlay.classList.remove('has-results');
            return;
        }

        if (!query) {
            resultsWrap.hidden = true;
            overlay.classList.remove('has-results');
            return;
        }

        const list = searchType === 'category' ? getCategoryData() : getTaskData();
        const filtered = list
            .filter((item) => {
                if (searchType === 'category') {
                    return (item.name || '').toLowerCase().includes(query);
                }
                return (item.title || '').toLowerCase().includes(query) || (item.category || '').toLowerCase().includes(query);
            })
            .slice(0, 10);

        resultsWrap.hidden = false;

        if (!filtered.length) {
            overlay.classList.remove('has-results');
            const empty = document.createElement('li');
            empty.className = 'spotlight-item';
            const text = document.createElement('strong');
            text.textContent = 'No matches yet';
            const meta = document.createElement('span');
            meta.className = 'spotlight-meta';
            meta.textContent = 'Try another keyword.';
            empty.appendChild(text);
            empty.appendChild(meta);
            resultsList.appendChild(empty);
            return;
        }

        overlay.classList.add('has-results');

        filtered.forEach((item) => {
            const li = document.createElement('li');
            li.className = 'spotlight-item';
            const left = document.createElement('div');
            const title = document.createElement('strong');
            const meta = document.createElement('span');
            meta.className = 'spotlight-meta';

            if (searchType === 'category') {
                title.textContent = item.name;
                meta.textContent = `${item.count} task${item.count === 1 ? '' : 's'}`;
            } else {
                title.textContent = item.title || 'Untitled task';
                const statusLabel = item.status ? item.status.replace(/_/g, ' ') : 'active';
                meta.textContent = `${item.category || 'None'} • ${statusLabel}`;
            }

            left.appendChild(title);
            left.appendChild(meta);

            if (searchType === 'category') {
                li.classList.add('is-category');
                li.tabIndex = 0;
                const badge = document.createElement('span');
                badge.className = 'badge';
                badge.textContent = 'Category';
                const headerRow = document.createElement('div');
                headerRow.className = 'spotlight-cat-header';
                headerRow.appendChild(left);
                headerRow.appendChild(badge);
                li.appendChild(headerRow);

                const pillWrap = document.createElement('div');
                pillWrap.className = 'spotlight-pills';
                (item.tasks || []).forEach((task) => {
                    if (!task || !task.id) return;
                    const pill = document.createElement('button');
                    pill.type = 'button';
                    pill.className = 'spotlight-pill';
                    pill.textContent = task.title || 'Untitled';
                    pill.dataset.taskId = task.id;
                    pill.addEventListener('click', (evt) => {
                        evt.stopPropagation();
                        openTaskNotes(task.id);
                    });
                    pill.addEventListener('keydown', (evt) => {
                        if (evt.key === 'Enter' || evt.key === ' ') {
                            evt.preventDefault();
                            pill.click();
                        }
                    });
                    pillWrap.appendChild(pill);
                });
                const pillSection = document.createElement('div');
                pillSection.className = 'spotlight-pills-section';
                pillSection.hidden = true;

                pillSection.appendChild(pillWrap);
                li.appendChild(pillSection);

                const togglePills = () => {
                    const expanded = li.classList.toggle('is-expanded');
                    pillSection.hidden = !expanded;
                };

                li.addEventListener('click', (evt) => {
                    if (evt.target.closest('.spotlight-pill')) return;
                    togglePills();
                });

                li.addEventListener('keydown', (evt) => {
                    if (evt.key === 'Enter' || evt.key === ' ') {
                        evt.preventDefault();
                        togglePills();
                    }
                });
            } else {
                const badge = document.createElement('span');
                badge.className = 'badge';
                badge.textContent = 'Task';
                li.appendChild(left);
                li.appendChild(badge);
                li.dataset.taskId = item.id || '';
                li.tabIndex = 0;
                li.addEventListener('click', () => openTaskNotes(item.id));
                li.addEventListener('keydown', (evt) => {
                    if (evt.key === 'Enter' || evt.key === ' ') {
                        evt.preventDefault();
                        li.click();
                    }
                });
            }
            resultsList.appendChild(li);
        });
    };

    const openTaskNotes = (taskId) => {
        if (!taskId) return;
        const selector = `[data-task-id="${taskId}"]`;
        const trigger = document.querySelector(`[data-task-note-trigger]${selector}`) ||
            document.querySelector(`[data-task-row]${selector}`) ||
            document.querySelector(`[data-task-note-trigger][data-task-id="${Number(taskId)}"]`);
        if (!trigger) return;
        const doOpen = () => trigger.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        if (overlay.classList.contains('is-visible')) {
            closeOverlay(() => doOpen(), 120);
        } else {
            doOpen();
        }
    };

    const openOverlay = (nextMode) => {
        setMode(nextMode);
        overlay.hidden = false;
        lockBodyScroll();
        requestAnimationFrame(() => overlay.classList.add('is-visible'));
        window.setTimeout(() => input.focus({ preventScroll: true }), 60);
    };

    const closeOverlay = (afterClose, transitionMs = 180) => {
        overlay.classList.remove('is-visible');
        const finish = () => {
            overlay.hidden = true;
            overlay.classList.remove('has-results');
            overlay.classList.remove('has-query');
            input.value = '';
            unlockBodyScroll();
            if (typeof afterClose === 'function') {
                afterClose();
            }
        };
        runAfterTransition(overlay, finish, transitionMs);
    };

    const triggerModeTab = (nextMode) => {
        if (overlay.classList.contains('is-visible')) {
            setMode(nextMode);
            input.focus({ preventScroll: true });
            return;
        }
        openOverlay(nextMode);
    };

    document.addEventListener('keydown', (event) => {
        const key = event.key?.toLowerCase();
        if (event.defaultPrevented) return;
        const overlayOpen = overlay.classList.contains('is-visible');
        const target = event.target || document.activeElement;
        if (!overlayOpen && isTypingField(target)) return;

        if (event.shiftKey && key === 't') {
            event.preventDefault();
            triggerModeTab('add');
        }
        if (event.shiftKey && key === 'f') {
            event.preventDefault();
            triggerModeTab('search');
        }
    });

    overlay.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeOverlay();
            return;
        }
        if (mode === 'search') {
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                setSearchType('task');
            }
            if (event.key === 'ArrowRight') {
                event.preventDefault();
                setSearchType('category');
            }
        }
    });

    tabs.forEach((btn) => {
        btn.addEventListener('click', () => {
            setMode(btn.dataset.spotlightTab === 'search' ? 'search' : 'add');
            input.focus({ preventScroll: true });
        });
    });

    typeOptions.forEach((chip) => {
        chip.addEventListener('click', () => {
            setSearchType(chip.dataset.spotlightTypeOption === 'category' ? 'category' : 'task');
            input.focus({ preventScroll: true });
        });
    });

    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            closeOverlay();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const title = (input.value || '').trim();
        if (!title) return;
        if (mode !== 'add') {
            renderResults();
            return;
        }
        if (isSubmitting) return;
        isSubmitting = true;
        if (submitBtn) submitBtn.disabled = true;
        try {
            const payload = new URLSearchParams();
            payload.set('action', 'add_task');
            payload.set('title', title);
            payload.set('deadline', '');
            payload.set('category_id', '0');
            attachCsrfToken(payload);
            const response = await fetch('todo.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString(),
            });
            if (response.ok) {
                window.location.reload();
            } else {
                console.error('Quick add failed');
            }
        } catch (err) {
            console.error('Quick add error', err);
        } finally {
            isSubmitting = false;
            if (submitBtn) submitBtn.disabled = false;
        }
    });

    input.addEventListener('input', renderResults);

    setMode('add');
    setSearchType('task');
}

// Category Color Palette / Wheel
let paletteBackdropEl = null;
const DEFAULT_WHEEL_SAT = 72;
const DEFAULT_WHEEL_LIGHT = 58;
const paletteHostMap = new WeakMap();
const WHEEL_ANGLE_OFFSET = 0; // no offset; visual rotation handled via CSS

function submitFormSafely(form) {
    if (!form) return;
    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
    } else {
        form.submit();
    }
}

function clamp(num, min, max) {
    return Math.min(Math.max(num, min), max);
}

function normalizeHexColor(hex) {
    const cleaned = (hex || '').trim().replace(/^#/, '');
    if (/^[0-9a-fA-F]{6}$/.test(cleaned)) {
        return '#' + cleaned.toUpperCase();
    }
    return '#5C6CFF';
}

function normalizeAngle(angle) {
    return ((angle % 360) + 360) % 360;
}

function angleToHue(angle) {
    return normalizeAngle(angle + WHEEL_ANGLE_OFFSET);
}

function hueToHandleAngle(hue) {
    return normalizeAngle(hue - WHEEL_ANGLE_OFFSET);
}

function hexToHsl(hex) {
    const color = normalizeHexColor(hex).slice(1);
    const num = parseInt(color, 16);
    const r = (num >> 16) & 255;
    const g = (num >> 8) & 255;
    const b = num & 255;
    const rPct = r / 255;
    const gPct = g / 255;
    const bPct = b / 255;
    const max = Math.max(rPct, gPct, bPct);
    const min = Math.min(rPct, gPct, bPct);
    const delta = max - min;
    let h = 0;
    let s = 0;
    const l = (max + min) / 2;
    if (delta !== 0) {
        s = l < 0.5 ? delta / (max + min) : delta / (2 - max - min);
        switch (max) {
            case rPct:
                h = (gPct - bPct) / delta + (gPct < bPct ? 6 : 0);
                break;
            case gPct:
                h = (bPct - rPct) / delta + 2;
                break;
            default:
                h = (rPct - gPct) / delta + 4;
        }
        h *= 60;
    }
    return { h, s: s * 100, l: l * 100 };
}

function hslToHex(h, s, l) {
    const sat = clamp(s, 0, 100) / 100;
    const lig = clamp(l, 0, 100) / 100;
    const c = (1 - Math.abs(2 * lig - 1)) * sat;
    const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
    const m = lig - c / 2;
    let r = 0;
    let g = 0;
    let b = 0;
    if (h >= 0 && h < 60) {
        r = c; g = x; b = 0;
    } else if (h < 120) {
        r = x; g = c; b = 0;
    } else if (h < 180) {
        r = 0; g = c; b = x;
    } else if (h < 240) {
        r = 0; g = x; b = c;
    } else if (h < 300) {
        r = x; g = 0; b = c;
    } else {
        r = c; g = 0; b = x;
    }
    const toHex = (value) => {
        const v = Math.round((value + m) * 255);
        return v.toString(16).padStart(2, '0');
    };
    return '#' + toHex(r) + toHex(g) + toHex(b);
}

function ensurePaletteBackdrop() {
    if (paletteBackdropEl && document.body.contains(paletteBackdropEl)) {
        return paletteBackdropEl;
    }
    const scrim = document.createElement('div');
    scrim.className = 'palette-scrim';
    scrim.hidden = true;
    scrim.addEventListener('click', hideAllPalettes);
    document.body.appendChild(scrim);
    paletteBackdropEl = scrim;
    return scrim;
}

function syncPaletteBackdrop() {
    const scrim = ensurePaletteBackdrop();
    const hasOpen = !!document.querySelector('.color-palette-popup:not([hidden])');
    scrim.hidden = !hasOpen;
    scrim.classList.toggle('is-active', hasOpen);
    document.body.classList.toggle('has-open-palette', hasOpen);
}

function hideAllPalettes() {
    document.querySelectorAll('.color-palette-popup').forEach((p) => {
        p.hidden = true;
        restorePalette(p);
    });
    syncPaletteBackdrop();
}

function positionPaletteNearTrigger(palette, trigger) {
    const rect = trigger?.getBoundingClientRect();
    const paletteWidth = palette.offsetWidth || 220;
    const paletteHeight = palette.offsetHeight || 240;
    const fallbackLeft = window.innerWidth / 2;
    const fallbackTop = Math.min(window.innerHeight - paletteHeight - 12, 120);
    let left = rect ? rect.left + (rect.width / 2) : fallbackLeft;
    let top = rect ? rect.bottom + 10 : fallbackTop;

    left = Math.min(window.innerWidth - 20, Math.max(20, left));
    if (top + paletteHeight + 10 > window.innerHeight && rect) {
        top = rect.top - paletteHeight - 10;
    }
    top = Math.max(12, top);

    palette.style.position = 'fixed';
    palette.style.left = `${left}px`;
    palette.style.top = `${top}px`;
    palette.style.transform = 'translateX(-50%)';
}

function detachPaletteToBody(palette, trigger) {
    if (!palette) return;
    if (!paletteHostMap.has(palette)) {
        const placeholder = document.createComment('palette-slot');
        const parent = palette.parentElement;
        if (parent) {
            parent.insertBefore(placeholder, palette.nextSibling);
            paletteHostMap.set(palette, { placeholder, parent });
        }
    }
    if (palette.parentElement !== document.body) {
        document.body.appendChild(palette);
    }
    positionPaletteNearTrigger(palette, trigger);
}

function restorePalette(palette) {
    const meta = paletteHostMap.get(palette);
    if (!meta) return;
    const { placeholder, parent } = meta;
    if (placeholder?.parentNode && parent) {
        placeholder.parentNode.insertBefore(palette, placeholder);
        placeholder.remove();
    }
    palette.removeAttribute('style');
    paletteHostMap.delete(palette);
}

function syncCategoryDot(catId, color) {
    const dot = document.querySelector(`[data-category-dot="${catId}"]`);
    if (dot) {
        dot.style.backgroundColor = color;
        dot.dataset.currentColor = color;
    }
}

function setCategoryColor(catId, color) {
    const input = document.getElementById('input-color-' + catId);
    const form = document.getElementById('color-form-' + catId);
    if (!input || !form) return;
    const next = normalizeHexColor(color);
    input.value = next;
    syncCategoryDot(catId, next);
    hideAllPalettes();
    submitFormSafely(form);
}

function setNewCategoryColor(color) {
    const next = normalizeHexColor(color);
    const input = document.getElementById('new-category-color');
    const dot = document.getElementById('new-category-dot');
    if (input) {
        input.value = next;
    }
    if (dot) {
        dot.style.backgroundColor = next;
        dot.dataset.currentColor = next;
    }
    hideAllPalettes();
}

function updateWheelPreview(wheel, color, hue) {
    const scope = wheel.closest('.color-palette-popup') || wheel;
    const previewDot = scope.querySelector('[data-wheel-preview]');
    const previewValue = scope.querySelector('[data-wheel-value]');
    const handle = wheel.querySelector('[data-wheel-handle]');
    const rawAngle = typeof hue === 'number' ? hue : hexToHsl(color).h;
    const angle = hueToHandleAngle(rawAngle);
    if (previewDot) previewDot.style.backgroundColor = color;
    if (previewValue) previewValue.textContent = color.toUpperCase();
    if (handle) {
        handle.style.setProperty('--wheel-angle', `${angle}deg`);
        handle.style.backgroundColor = color;
    }
}

function setWheelColorFromHue(wheel, hue) {
    if (!wheel) return '#5C6CFF';
    const baseSat = parseFloat(wheel.dataset.baseSat || '') || DEFAULT_WHEEL_SAT;
    const baseLight = parseFloat(wheel.dataset.baseLight || '') || DEFAULT_WHEEL_LIGHT;
    const color = hslToHex((hue + 360) % 360, baseSat, baseLight);
    wheel.dataset.currentColor = color;
    updateWheelPreview(wheel, color, hue);
    return color;
}

function setWheelFromColor(wheel, color) {
    const hsl = hexToHsl(color);
    wheel.dataset.baseSat = hsl.s.toFixed(2);
    wheel.dataset.baseLight = hsl.l.toFixed(2);
    wheel.dataset.currentColor = normalizeHexColor(color);
    updateWheelPreview(wheel, wheel.dataset.currentColor, hsl.h);
}

function hueFromPointer(event, ring) {
    const rect = ring.getBoundingClientRect();
    const cx = rect.left + rect.width / 2;
    const cy = rect.top + rect.height / 2;
    const dx = event.clientX - cx;
    const dy = event.clientY - cy;
    const rad = Math.atan2(dy, dx);
    const deg = (rad * 180) / Math.PI;
    return (deg + 360) % 360;
}

function attachWheel(wheel) {
    const ring = wheel.querySelector('[data-wheel-ring]');
    const palette = wheel.closest('.color-palette-popup');
    const applyBtn = (palette || wheel).querySelector('[data-wheel-apply]');
    const catId = wheel.dataset.categoryId;
    const isNew = !catId;
    let dragging = false;

    const color = wheel.dataset.currentColor || '#5C6CFF';
    setWheelFromColor(wheel, color);

    const applySelection = () => {
        const chosen = wheel.dataset.currentColor || '#5C6CFF';
        if (isNew) {
            setNewCategoryColor(chosen);
        } else {
            setCategoryColor(catId, chosen);
        }
    };

    const handlePointerMove = (event) => {
        if (!dragging) return;
        const rawAngle = hueFromPointer(event, ring);
        const hue = angleToHue(rawAngle);
        const next = setWheelColorFromHue(wheel, hue);
        if (isNew) {
            const input = document.getElementById('new-category-color');
            if (input) input.value = next;
        }
    };

    ring?.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        dragging = true;
        ring.setPointerCapture(event.pointerId);
        handlePointerMove(event);
    });

    ring?.addEventListener('pointermove', handlePointerMove);

    const stopDragging = (event) => {
        if (!dragging) return;
        dragging = false;
        try {
            ring?.releasePointerCapture(event.pointerId);
        } catch (_) {
            // ignore
        }
    };

    ring?.addEventListener('pointerup', stopDragging);
    ring?.addEventListener('pointercancel', stopDragging);
    ring?.addEventListener('pointerleave', stopDragging);

    applyBtn?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        applySelection();
    });
}

function initColorWheels() {
    document.querySelectorAll('[data-color-wheel]').forEach((wheel) => {
        attachWheel(wheel);
    });
}

function primeWheelInPalette(catId) {
    const paletteId = 'palette-' + catId;
    const palette = document.getElementById(paletteId);
    if (!palette) return;
    const wheel = palette.querySelector('[data-color-wheel]');
    if (!wheel) return;
    let color = wheel.dataset.currentColor || '#5C6CFF';
    if (catId === 'new') {
        const input = document.getElementById('new-category-color');
        if (input?.value) {
            color = input.value;
        }
    } else if (wheel.dataset.categoryId) {
        const dot = document.querySelector(`[data-category-dot="${catId}"]`);
        if (dot?.dataset.currentColor) {
            color = dot.dataset.currentColor;
        }
    }
    setWheelFromColor(wheel, color);
}

function togglePalette(catId, event) {
    // Stop event propagation to prevent immediate closing
    if (event) {
        event.stopPropagation();
    }

    const paletteId = 'palette-' + catId;
    const palette = document.getElementById(paletteId);
    const allPalettes = document.querySelectorAll('.color-palette-popup');
    const trigger = event?.currentTarget || document.querySelector(`[data-category-dot="${catId}"]`);

    // Close others
    allPalettes.forEach((p) => {
        if (p.id !== paletteId) {
            p.hidden = true;
            restorePalette(p);
        }
    });

    if (palette) {
        const willOpen = palette.hidden;
        palette.hidden = !palette.hidden;
        if (willOpen) {
            detachPaletteToBody(palette, trigger);
            primeWheelInPalette(catId);
        } else {
            restorePalette(palette);
        }
    }
    syncPaletteBackdrop();
}

// Close palettes when clicking outside
document.addEventListener('click', function (e) {
    const inWrapper = e.target.closest('.category-color-wrapper');
    const inPalette = e.target.closest('.color-palette-popup');
    if (inWrapper || inPalette) return;
    hideAllPalettes();
});
