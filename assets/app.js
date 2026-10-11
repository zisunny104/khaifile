const settings = JSON.parse(document.querySelector('#app-data').textContent);
const $ = (id) => document.getElementById(id);
const items = [];
let processing = false;
let downloading = false;
const allowed = new Set(['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'pdf']);
const size = (bytes) => bytes < 1024 ? `${bytes} B` : bytes < 1048576 ? `${(bytes / 1024).toFixed(1)} KB` : `${(bytes / 1048576).toFixed(1)} MB`;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function announce(text) { $('live').textContent = text; }
function error(text = '') { $('message').hidden = !text; $('message').textContent = text; }
function node(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
}
function button(text, handler, className = 'ts-button is-small is-outlined') {
    const element = node('button', className, text);
    element.type = 'button';
    element.addEventListener('click', () => Promise.resolve().then(handler).catch((e) => error(e.message)));
    return element;
}

async function api(action, data = {}, attempt = 0) {
    const form = new FormData();
    form.append('csrf', settings.csrf);
    for (const [key, value] of Object.entries(data)) {
        if (Array.isArray(value)) value.forEach((entry) => form.append(`${key}[]`, entry));
        else form.append(key, value);
    }
    const url = new URL(location.href);
    url.search = '';
    url.searchParams.set('api', action);
    const response = await fetch(url, { method: 'POST', body: form, credentials: 'same-origin' });
    if (response.status === 429 && ['process', 'archive'].includes(action) && attempt < 3) {
        await sleep(2000);
        return api(action, data, attempt + 1);
    }
    let result;
    try { result = await response.json(); }
    catch { throw new Error('伺服器沒有回傳處理結果，請確認上傳限制或稍後重試。'); }
    if ([429, 503].includes(response.status) && ['process', 'archive'].includes(action) && attempt < 3) {
        const delay = Math.min(10, Math.max(2, Number(response.headers.get('Retry-After')) || 2));
        await sleep(delay * 1000);
        return api(action, data, attempt + 1);
    }
    if (!response.ok) throw new Error(result.error || '操作失敗，請重試。');
    return result;
}

function updateControls() {
    const done = items.filter((item) => item.status === 'done').length;
    const failed = items.filter((item) => item.status === 'error').length;
    const waiting = items.filter((item) => item.status === 'waiting').length;
    $('count').textContent = items.length;
    $('empty').hidden = items.length > 0;
    $('summary').textContent = items.length ? `${done} 組完成 · ${waiting} 組等待${failed ? ` · ${failed} 組需重試` : ''}${processing ? ' · 正在依序處理' : ''}` : '加入檔案後，即可開始處理。';
    $('start').disabled = processing || waiting === 0;
    $('start').textContent = processing ? '處理中…' : '開始處理';
    $('clear').disabled = processing || downloading || items.length === 0;
    $('compress').disabled = processing;
    $('download-panel').hidden = done === 0;
    const ready = items.filter((item) => item.status === 'done');
    $('select-all').textContent = ready.length && ready.every((item) => item.selected) ? '取消全選' : '全選已完成';
    for (const id of ['download-files', 'download-groups', 'download-all', 'select-all']) $(id).disabled = downloading || processing;
}

function render(item) {
    let card = item.card;
    if (!card) { card = node('article', 'ts-box'); item.card = card; $('queue').append(card); }
    card.replaceChildren();
    const content = node('div', 'ts-content');
    const heading = node('div', 'file-heading');
    const identity = node('div');
    const selection = node('label', 'ts-checkbox');
    const check = document.createElement('input');
    check.type = 'checkbox'; check.checked = item.selected; check.disabled = item.status !== 'done';
    check.setAttribute('aria-label', `選取 ${item.file.name}`);
    check.addEventListener('change', () => { item.selected = check.checked; updateControls(); });
    selection.append(check, node('span', 'file-name', item.file.name));
    identity.append(selection, node('div', 'ts-text is-description', `${size(item.file.size)} · ${item.extension.toUpperCase()}`));
    const actions = node('div', 'file-actions');
    const states = { waiting: '等待處理', processing: '處理中…', done: '已完成', error: '處理失敗' };
    actions.append(node('span', `ts-badge ${item.status === 'done' ? 'is-positive' : item.status === 'error' ? 'is-negative' : ''}`, states[item.status]));
    if (item.status === 'error') actions.append(button('重試', async () => { item.status = 'waiting'; item.error = ''; render(item); await processQueue(); }));
    const remove = button('移除', async () => {
        if (processing || downloading) return;
        if (item.job) await api('delete', { id: item.job.id });
        items.splice(items.indexOf(item), 1); card.remove(); updateControls();
    });
    remove.disabled = processing || downloading; actions.append(remove);
    heading.append(identity, actions); content.append(heading);
    const nameField = node('div', 'name-field');
    const label = node('label', 'ts-text is-label', '下載名稱');
    const inputId = `name-${item.key}`; label.htmlFor = inputId;
    const wrap = node('div', 'ts-input');
    const input = document.createElement('input'); input.type = 'text'; input.id = inputId; input.value = item.name; input.maxLength = 160;
    input.setAttribute('aria-describedby', `${inputId}-hint`);
    input.addEventListener('input', () => { item.name = input.value; });
    input.addEventListener('change', () => { if (item.job) syncName(item).catch((e) => error(e.message)); });
    wrap.append(input); nameField.append(label, wrap);
    nameField.append(node('span', 'ts-text is-description', '各格式保留自己的副檔名'));
    nameField.lastChild.id = `${inputId}-hint`; content.append(nameField);
    if (item.job) {
        const outputs = node('div', 'outputs');
        for (const file of item.job.outputs) {
            const tile = node('div', 'output');
            tile.append(node('div', 'ts-text is-label', file.label), node('div', 'ts-text file-name', file.name), node('div', 'ts-text is-description', size(file.size)));
            tile.append(button(`下載 ${file.name.split('.').pop().toUpperCase()}`, async () => { await syncName(item); triggerDownload({ id: item.job.id, file: file.id }); }));
            outputs.append(tile);
        }
        content.append(outputs);
        const zip = button('下載這組 ZIP', async () => { await syncName(item); const archive = await api('archive', { ids: [item.job.id] }); triggerDownload({ bundle: archive.bundle }); });
        zip.classList.add('has-top-spaced'); content.append(zip);
        for (const note of item.job.notes) content.append(node('div', 'ts-text is-description notes', note));
    }
    if (item.error) content.append(node('div', 'error-text notes', item.error));
    card.append(content); updateControls();
}

function addFiles(files) {
    error();
    const rejected = [];
    let total = items.reduce((sum, item) => sum + item.file.size, 0);
    for (const file of files) {
        const extension = file.name.split('.').pop().toLowerCase();
        if (!allowed.has(extension)) { rejected.push(`${file.name}：不支援的格式`); continue; }
        if (!file.size || file.size > settings.maxFileBytes) { rejected.push(`${file.name}：空檔案或超過 50 MB`); continue; }
        if (items.length >= settings.maxJobs || total + file.size > settings.maxSessionBytes) { rejected.push(`${file.name}：已超過組數或總量上限`); continue; }
        const item = { key: crypto.randomUUID(), file, extension, name: file.name.slice(0, -(extension.length + 1)), status: 'waiting', selected: true, job: null, error: '' };
        items.push(item); total += file.size; render(item);
    }
    if (rejected.length) error(rejected.join('；'));
    announce(`已加入 ${items.length} 組檔案。`);
    $('file-input').value = ''; updateControls();
}

async function syncName(item) {
    if (!item.job) return;
    if (item.job.expires * 1000 < Date.now()) throw new Error('檔案已到期，請重新上傳。');
    if (item.renaming) await item.renaming;
    if (item.name === item.job.name) return;
    const intended = item.name.trim();
    if (!intended) throw new Error('下載名稱不能留白。');
    item.renaming = api('rename', { id: item.job.id, name: intended });
    try {
        const result = await item.renaming;
        item.job = result.job;
        // Preserve edits made while this request was running.
        if (item.name.trim() === intended) item.name = result.job.name;
    } finally { item.renaming = null; }
    if (item.name !== item.job.name) return syncName(item);
    render(item);
}

async function processQueue() {
    if (processing) return;
    processing = true; error(); updateControls();
    items.forEach(render);
    try {
        for (const item of items) {
            if (item.status !== 'waiting') continue;
            item.status = 'processing'; render(item); announce(`正在處理 ${item.file.name}`);
            try {
                const result = await api('process', { file: item.file, name: item.name, compress: $('compress').checked ? '1' : '0' });
                item.job = result.job;
                item.status = 'done';
                try { await syncName(item); }
                catch (e) { error(`檔案已完成，但名稱未更新：${e.message}`); }
                announce(`${item.file.name} 處理完成。`);
            } catch (e) { item.status = 'error'; item.error = e.message; }
            render(item);
        }
    } finally { processing = false; items.forEach(render); updateControls(); announce('佇列處理完成。'); }
}

function triggerDownload(params) {
    const url = new URL(location.href); url.search = '';
    url.searchParams.set('api', 'download');
    for (const [key, value] of Object.entries(params)) url.searchParams.set(key, value);
    const anchor = document.createElement('a'); anchor.href = url; anchor.download = ''; anchor.hidden = true;
    document.body.append(anchor); anchor.click(); anchor.remove();
}

async function batch(mode) {
    if (downloading) return;
    const selected = items.filter((item) => item.selected && item.status === 'done');
    if (!selected.length) { error('請勾選至少一組已完成的檔案。'); return; }
    downloading = true; error(); updateControls();
    try {
        for (const item of selected) await syncName(item);
        if (mode === 'all') {
            $('download-status').textContent = '正在打包 ZIP…';
            const archive = await api('archive', { ids: selected.map((item) => item.job.id) });
            triggerDownload({ bundle: archive.bundle });
            $('download-status').textContent = `已送出 ${selected.length} 組檔案的 ZIP 下載。`;
        } else {
            let count = 0;
            for (const item of selected) {
                if (mode === 'groups') {
                    const archive = await api('archive', { ids: [item.job.id] });
                    triggerDownload({ bundle: archive.bundle }); count++; await sleep(700);
                } else {
                    for (const file of item.job.outputs) { triggerDownload({ id: item.job.id, file: file.id }); count++; await sleep(700); }
                }
                $('download-status').textContent = `已依序送出 ${count} 個下載。`;
            }
            $('download-status').textContent += ' 若未收到所有檔案，請允許瀏覽器的多檔下載或改用 ZIP。';
        }
    } catch (e) { error(e.message); }
    finally { downloading = false; items.forEach(render); updateControls(); }
}

$('file-input').addEventListener('change', (event) => addFiles(event.target.files));
$('drop-zone').addEventListener('click', () => $('file-input').click());
$('drop-zone').addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); $('file-input').click(); } });
for (const eventName of ['dragenter', 'dragover']) $('drop-zone').addEventListener(eventName, (event) => { event.preventDefault(); $('drop-zone').classList.add('drag-over'); });
for (const eventName of ['dragleave', 'drop']) $('drop-zone').addEventListener(eventName, (event) => { event.preventDefault(); $('drop-zone').classList.remove('drag-over'); });
$('drop-zone').addEventListener('drop', (event) => addFiles(event.dataTransfer.files));
document.addEventListener('dragover', (event) => event.preventDefault());
document.addEventListener('drop', (event) => event.preventDefault());
$('start').addEventListener('click', processQueue);
$('clear').addEventListener('click', async () => {
    try { await api('clear'); items.splice(0); $('queue').replaceChildren(); error(); $('download-status').textContent = ''; updateControls(); announce('暫存檔案已清除。'); }
    catch (e) { error(e.message); }
});
$('select-all').addEventListener('click', () => {
    const ready = items.filter((item) => item.status === 'done');
    const selected = !ready.every((item) => item.selected);
    ready.forEach((item) => { item.selected = selected; render(item); });
});
$('download-files').addEventListener('click', () => batch('files'));
$('download-groups').addEventListener('click', () => batch('groups'));
$('download-all').addEventListener('click', () => batch('all'));

function theme(value) {
    document.body.className = `is-rounded${value === 'system' ? '' : ` is-${value}`}`;
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `preferred-theme=${value}; path=/; max-age=31536000; SameSite=Lax${secure}`;
}
const preferred = document.cookie.split('; ').find((cookie) => cookie.startsWith('preferred-theme='))?.split('=')[1] || 'system';
if (['light', 'dark', 'system'].includes(preferred)) { document.querySelector(`input[name=theme][value=${preferred}]`).checked = true; theme(preferred); }
document.querySelectorAll('input[name=theme]').forEach((input) => input.addEventListener('change', () => theme(input.value)));
$('license-button').addEventListener('click', async () => {
    const dialog = $('license-dialog'); dialog.showModal();
    try { const result = await fetch(`${settings.assetBase}/LICENSE`); $('license-text').textContent = result.ok ? await result.text() : '授權檔暫時無法載入。'; }
    catch { $('license-text').textContent = '授權檔暫時無法載入。'; }
});
updateControls();
