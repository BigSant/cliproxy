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
                    cell(record.account),
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
