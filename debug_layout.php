<?php
/**
 * Diagnostic Tool to identify Horizontal Scroll issues
 */
require_once(__DIR__ . '/../../config.php');
require_login();

$PAGE->set_url(new moodle_url('/local/grupomakro_core/debug_gradebook_scroll.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Diagonalizador de Scroll - Gradebook');

echo $OUTPUT->header();
?>
<div class="container mt-5">
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Diagnosticador de Diseño (Layout Debugger)</h5>
        </div>
        <div class="card-body">
            <p>Este script analizará el DOM para encontrar por qué no aparece el scroll lateral en el Libro de Calificaciones.</p>
            <div id="debug-results" class="mt-4">
                <button id="run-diagnostic" class="btn btn-success">Ejecutar Diagnóstico en Dashboard</button>
            </div>
            
            <hr>
            
            <h6>Instrucciones:</h6>
            <ol>
                <li>Abre el <b>Dashboard del Docente</b> en otra pestaña.</li>
                <li>Entra a la pestaña de <b>Calificaciones</b> de una clase que tenga muchas columnas.</li>
                <li>Vuelve aquí y pulsa el botón de arriba. (O mejor aún, copia esto en la consola del Dashboard).</li>
            </ol>
        </div>
    </div>
</div>

<script>
function traceLayout() {
    const target = document.querySelector('.grade-container');
    if (!target) {
        console.error("No se encontró .grade-container. Asegúrate de estar en la pestaña de Calificaciones.");
        alert("No se encontró el Libro de Calificaciones. Por favor, asegúrate de que la pestaña de 'Calificaciones' esté abierta y visible.");
        return;
    }

    console.log("%c--- DIAGNÓSTICO DE LAYOUT INICIADO ---", "color: blue; font-weight: bold; font-size: 16px;");
    
    let results = [];
    let current = target;
    
    while (current && current !== document.body) {
        const style = window.getComputedStyle(current);
        const rect = current.getBoundingClientRect();
        
        results.push({
            tag: current.tagName,
            id: current.id,
            class: current.className,
            width: rect.width,
            scrollWidth: current.scrollWidth,
            overflowX: style.overflowX,
            overflow: style.overflow,
            display: style.display,
            position: style.position,
            maxHeight: style.maxHeight,
            flex: style.flex
        });
        
        if (style.overflowX === 'hidden' || style.overflow === 'hidden') {
            console.log("%c[BLOQUEADO] El elemento " + current.tagName + " (" + current.className + ") tiene overflow: hidden.", "color: red; font-weight: bold;");
        }
        
        current = current.parentElement;
    }

    console.table(results);
    console.log("%c--- FIN DEL DIAGNÓSTICO ---", "color: blue; font-weight: bold;");
    alert("Diagnóstico completado. Por favor, revisa la consola (F12 -> Console) y envíame una captura de la tabla de resultados.");
}

// Provided as a snippet for the user to paste in the console
document.getElementById('run-diagnostic').onclick = function() {
    confirm("Este botón solo funciona si esta página es la misma del Dashboard. Te recomiendo copiar el código de abajo y pegarlo en la consola de tu Dashboard del Docente.");
};
</script>

<div class="container mt-3">
    <h6>Copia este código en la Consola (F12) de tu Dashboard:</h6>
    <pre class="bg-light p-3 border rounded">
(function() {
    const target = document.querySelector('.grade-container');
    if (!target) return console.error('No se encontró el contenedor de notas.');
    
    console.log('%c--- TRACE OVERFLOW ---', 'color: purple; font-weight: bold;');
    let curr = target;
    while (curr && curr !== document.documentElement) {
        const style = window.getComputedStyle(curr);
        const rect = curr.getBoundingClientRect();
        console.log({
            element: curr.tagName + '.' + curr.className.split(' ').join('.'),
            width: rect.width.toFixed(0) + 'px',
            scrollWidth: curr.scrollWidth + 'px',
            overflowX: style.overflowX,
            display: style.display,
            is_clipped: curr.scrollWidth > rect.width && style.overflowX !== 'auto' && style.overflowX !== 'scroll'
        });
        curr = curr.parentElement;
    }
})();
    </pre>
</div>

<?php
echo $OUTPUT->footer();
