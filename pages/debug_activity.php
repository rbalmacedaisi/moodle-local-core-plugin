<?php
/**
 * Debug page for activity creation issues.
 * Access: /local/grupomakro_core/pages/debug_activity.php
 */

require_once(__DIR__ . '/../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Debug Activity Creation</title>
<style>
body { font-family: monospace; font-size: 13px; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
h2 { color: #569cd6; }
h3 { color: #9cdcfe; margin-top: 20px; }
pre { background: #252526; border: 1px solid #3e3e3e; padding: 12px; border-radius: 4px; overflow-x: auto; white-space: pre-wrap; word-break: break-all; }
.ok { color: #4ec9b0; }
.err { color: #f44747; }
.warn { color: #dcdcaa; }
button { background: #0e639c; color: white; border: none; padding: 10px 20px; cursor: pointer; margin: 5px; border-radius: 4px; font-size: 13px; }
button:hover { background: #1177bb; }
input, select { background: #3c3c3c; color: #d4d4d4; border: 1px solid #555; padding: 6px; margin: 4px; border-radius: 3px; width: 300px; }
#log { margin-top: 10px; }
.entry { border-bottom: 1px solid #333; padding: 8px 0; }
.entry .label { color: #ce9178; }
</style>
</head>
<body>

<h2>🔧 Debug: Activity Creation</h2>

<div>
    <strong class="warn">wsUrl detectado:</strong> <span id="wsUrlDisplay">cargando...</span>
</div>
<div>
    <strong class="warn">sesskey:</strong> <span id="sesskeyDisplay">cargando...</span>
</div>

<h3>Test 1: JSON simple (sin archivos)</h3>
<div>
    classid: <input type="number" id="classid1" value="1" style="width:80px">
    type: <input type="text" id="type1" value="assignment" style="width:100px">
    name: <input type="text" id="name1" value="Test Debug Tarea">
</div>
<button onclick="testJSON()">Enviar como JSON</button>

<h3>Test 2: FormData (simula supportsFiles=true, sin archivo)</h3>
<div>
    classid: <input type="number" id="classid2" value="1" style="width:80px">
    type: <input type="text" id="type2" value="assignment" style="width:100px">
    name: <input type="text" id="name2" value="Test Debug FormData">
</div>
<button onclick="testFormData()">Enviar como FormData</button>

<h3>Test 3: FormData con archivo</h3>
<div>
    classid: <input type="number" id="classid3" value="1" style="width:80px">
    type: <input type="text" id="type3" value="resource" style="width:100px">
    name: <input type="text" id="name3" value="Test Debug Resource">
    archivo: <input type="file" id="file3">
</div>
<button onclick="testFormDataFile()">Enviar como FormData + archivo</button>

<h3>Test 4: Verificar ajax.php directamente (action=ping)</h3>
<button onclick="testPing()">Ping ajax.php</button>

<h3>Log de resultados:</h3>
<div id="log"></div>

<script>
// Detectar wsUrl y sesskey igual que el dashboard
window.wsUrl = window.location.origin + '/local/grupomakro_core/ajax.php';
window.wsStaticParams = { sesskey: '<?php echo sesskey(); ?>' };

document.getElementById('wsUrlDisplay').textContent = window.wsUrl;
document.getElementById('sesskeyDisplay').textContent = window.wsStaticParams.sesskey;

function log(title, data, isError) {
    const div = document.createElement('div');
    div.className = 'entry';
    const color = isError ? '#f44747' : '#4ec9b0';
    div.innerHTML = '<span class="label" style="color:' + color + '">[' + title + ']</span><pre>' + JSON.stringify(data, null, 2) + '</pre>';
    document.getElementById('log').prepend(div);
}

async function testJSON() {
    log('JSON Request', {
        action: 'local_grupomakro_create_express_activity',
        args: {
            classid: parseInt(document.getElementById('classid1').value),
            type: document.getElementById('type1').value,
            name: document.getElementById('name1').value,
            intro: '',
            duedate: 0,
            save_as_template: false,
            gradecat: 0,
            tags: '',
            guest: false
        },
        sesskey: window.wsStaticParams.sesskey
    });
    try {
        const resp = await fetch(window.wsUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'local_grupomakro_create_express_activity',
                sesskey: window.wsStaticParams.sesskey,
                args: {
                    classid: parseInt(document.getElementById('classid1').value),
                    type: document.getElementById('type1').value,
                    name: document.getElementById('name1').value,
                    intro: '',
                    duedate: 0,
                    save_as_template: false,
                    gradecat: 0,
                    tags: '',
                    guest: false
                }
            })
        });
        const data = await resp.json();
        log('JSON Response', data, data.status !== 'success');
    } catch(e) {
        log('JSON Error', { error: e.message }, true);
    }
}

async function testFormData() {
    const fd = new FormData();
    fd.append('action', 'local_grupomakro_create_express_activity');
    fd.append('sesskey', window.wsStaticParams.sesskey);
    fd.append('classid', document.getElementById('classid2').value);
    fd.append('type', document.getElementById('type2').value);
    fd.append('name', document.getElementById('name2').value);
    fd.append('intro', '');
    fd.append('tags', '');
    fd.append('save_as_template', '0');

    log('FormData Request (no archivo)', {
        action: 'local_grupomakro_create_express_activity',
        classid: document.getElementById('classid2').value,
        type: document.getElementById('type2').value,
        name: document.getElementById('name2').value,
        note: 'Sin Content-Type forzado — Axios/fetch lo gestiona'
    });

    try {
        // Sin especificar Content-Type (igual que el fix)
        const resp = await fetch(window.wsUrl, { method: 'POST', body: fd });
        const data = await resp.json();
        log('FormData Response (no archivo)', data, data.status !== 'success');
    } catch(e) {
        log('FormData Error', { error: e.message }, true);
    }
}

async function testFormDataFile() {
    const fd = new FormData();
    fd.append('action', 'local_grupomakro_create_express_activity');
    fd.append('sesskey', window.wsStaticParams.sesskey);
    fd.append('classid', document.getElementById('classid3').value);
    fd.append('type', document.getElementById('type3').value);
    fd.append('name', document.getElementById('name3').value);
    fd.append('intro', '');
    fd.append('tags', '');
    fd.append('save_as_template', '0');

    const fileInput = document.getElementById('file3');
    if (fileInput.files[0]) {
        fd.append('resource_file_0', fileInput.files[0], fileInput.files[0].name);
        log('FormData Request (con archivo)', {
            action: 'local_grupomakro_create_express_activity',
            classid: document.getElementById('classid3').value,
            type: document.getElementById('type3').value,
            file: fileInput.files[0].name,
            size: fileInput.files[0].size + ' bytes (' + (fileInput.files[0].size / 1024 / 1024).toFixed(2) + ' MB)'
        });
    } else {
        log('FormData Request (con archivo)', { note: 'Sin archivo seleccionado' }, true);
    }

    try {
        const resp = await fetch(window.wsUrl, { method: 'POST', body: fd });
        const text = await resp.text();
        let data;
        try { data = JSON.parse(text); } catch(e) { data = { raw: text.substring(0, 500) }; }
        log('FormData+Archivo Response', data, !data.status || data.status !== 'success');
    } catch(e) {
        log('FormData+Archivo Error', { error: e.message }, true);
    }
}

async function testPing() {
    // Test that ajax.php responds at all with a known-bad action
    try {
        const resp = await fetch(window.wsUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'debug_ping', sesskey: window.wsStaticParams.sesskey })
        });
        const data = await resp.json();
        log('Ping Response (espera Action not found: debug_ping)', data, false);
    } catch(e) {
        log('Ping Error', { error: e.message, note: 'ajax.php no responde o hay error de red' }, true);
    }
}
</script>

</body>
</html>
