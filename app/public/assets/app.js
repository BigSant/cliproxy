import './styles/app.css';

// Copy buttons: data-copy holds the text.
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) {
        return;
    }
    try {
        await navigator.clipboard.writeText(button.dataset.copy);
        const label = button.textContent;
        button.textContent = 'Nukopijuota';
        setTimeout(() => { button.textContent = label; }, 1500);
    } catch {
        window.prompt('Nukopijuok:', button.dataset.copy);
    }
});

// "Rodyti" next to a masked key shows it in full.
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-reveal]');
    const secret = button?.closest('tr')?.querySelector('[data-secret]');
    if (secret) {
        secret.textContent = secret.dataset.secret;
        button.remove();
    }
});

// Forms with data-confirm ask first.
document.addEventListener('submit', (event) => {
    const message = event.target.dataset.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

// Apžvalga's live list: new requests every few seconds while the tab is visible.
const live = document.querySelector('[data-live-url]');
if (live) {
    const body = live.querySelector('tbody');
    const number = (value) => {
        for (const [size, suffix] of [[1e9, ' mlrd.'], [1e6, ' M'], [1e3, ' k']]) {
            if (Math.abs(value) >= size) {
                return (value / size).toLocaleString('lt-LT', { maximumFractionDigits: value / size >= 100 ? 0 : 1 }) + suffix;
            }
        }
        return value.toLocaleString('lt-LT');
    };
    const cell = (text, className) => {
        const td = document.createElement('td');
        if (className) {
            td.className = className;
        }
        td.textContent = text;
        return td;
    };
    const accountCell = (text) => {
        const td = cell('');
        const span = document.createElement('span');
        span.className = 'email';
        span.textContent = text;
        td.append(span);
        return td;
    };
    let newest = body.querySelector('tr:not(.placeholder)') ? null : '';
    const refresh = async () => {
        if (document.hidden) {
            return;
        }
        try {
            const response = await fetch(live.dataset.liveUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const records = await response.json();
            if (records.length === 0) {
                return;
            }
            const first = records[0].at + records[0].account + records[0].model;
            if (newest === first) {
                return;
            }
            const fresh = newest !== null;
            newest = first;
            body.replaceChildren(...records.map((record) => {
                const row = document.createElement('tr');
                if (record.failed) {
                    row.className = 'is-failed';
                }
                const model = document.createElement('span');
                model.className = `provider provider-${record.provider}`;
                model.textContent = record.model;
                const modelCell = cell('');
                modelCell.append(model);
                row.append(
                    cell(new Date(record.at).toLocaleTimeString('lt-LT')),
                    accountCell(record.account),
                    modelCell,
                    cell(number(record.input), 'num'),
                    cell(number(record.output), 'num'),
                    cell(number(record.cacheRead), 'num'),
                    cell(number(record.cacheCreation), 'num'),
                    cell((record.latencyMs / 1000).toLocaleString('lt-LT', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' s', 'num'),
                );
                return row;
            }));
            if (fresh) {
                body.firstElementChild?.classList.add('fresh');
            }
        } catch {
            // The next tick tries again.
        }
    };
    setInterval(refresh, 5000);
}

// Theme (dark by default) and hidden e-mails, remembered in this browser.
const remember = (key, value) => { try { localStorage.setItem(key, value); } catch { /* private window */ } };
document.querySelector('[data-toggle-theme]')?.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = next;
    remember('theme', next);
});
document.querySelector('[data-toggle-emails]')?.addEventListener('click', () => {
    remember('showEmails', document.documentElement.classList.toggle('show-emails') ? '1' : '0');
});
document.querySelector('[data-reload]')?.addEventListener('click', () => window.location.reload());
document.querySelector('[data-toggle-sidebar]')?.addEventListener('click', () => document.getElementById('sidebar')?.classList.toggle('open'));

// Whole table rows open their page (the first link stays the keyboard way).
document.addEventListener('click', (event) => {
    const row = event.target.closest('tr[data-href]');
    if (row && !event.target.closest('a, button, form, input, select')) {
        window.location.href = row.dataset.href;
    }
});

// Time charts: the value nearest to the pointer, per series.
for (const box of document.querySelectorAll('[data-time-chart]')) {
    const svg = box.querySelector('svg');
    const tip = box.querySelector('.chart-tip');
    const hover = svg.querySelector('.hover');
    const line = svg.querySelector('.hover-line');
    const points = JSON.parse(box.dataset.points || '[]');
    const from = Number(box.dataset.from);
    const to = Number(box.dataset.to);
    const left = Number(box.dataset.left);
    const right = Number(box.dataset.right);
    const width = svg.viewBox.baseVal.width;
    const unit = box.dataset.unit;
    const format = new Intl.DateTimeFormat('lt-LT', { weekday: 'short', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    svg.addEventListener('mousemove', (event) => {
        const rect = svg.getBoundingClientRect();
        const x = (event.clientX - rect.left) / rect.width * width;
        if (x < left || x > right || points.length === 0) {
            hover.setAttribute('visibility', 'hidden');
            tip.hidden = true;
            return;
        }
        const t = from + (x - left) / (right - left) * (to - from);
        const nearest = new Map();
        for (const point of points) {
            const best = nearest.get(point.s);
            if (!best || Math.abs(point.t - t) < Math.abs(best.t - t)) {
                nearest.set(point.s, point);
            }
        }
        const shown = [...nearest.values()].filter((point) => Math.abs(point.t - t) < (to - from) / 60);
        if (shown.length === 0) {
            hover.setAttribute('visibility', 'hidden');
            tip.hidden = true;
            return;
        }
        line.setAttribute('x1', x);
        line.setAttribute('x2', x);
        hover.setAttribute('visibility', 'visible');
        tip.hidden = false;
        tip.style.left = `${(x / width) * 100}%`;
        tip.textContent = `${format.format(new Date(shown[0].t * 1000))} · ` + shown.map((point) => `${shown.length > 1 ? point.s + ': ' : ''}${point.v.toLocaleString('lt-LT')}${unit}`).join(' · ');
    });
    svg.addEventListener('mouseleave', () => {
        hover.setAttribute('visibility', 'hidden');
        tip.hidden = true;
    });
}
