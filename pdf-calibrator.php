<?php
/**
 * pdf-calibrator.php — Herramienta de calibración visual de coordenadas PDF
 * Úsalo una vez por cada PDF que subas. Guarda las coords en config.ini.
 *
 * Acceso: /modules/mod_formulario_cv/pdf-calibrator.php?admin_pass=TU_CLAVE
 * Protégelo o bórralo después de calibrar.
 */

// ── SEGURIDAD ──────────────────────────────────────────────────────────────
// session_start debe ir antes de cualquier output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$configFile = __DIR__ . '/config.ini';

// INI_SCANNER_RAW evita que las comillas del archivo queden en los valores
$iniData = file_exists($configFile) ? parse_ini_file($configFile, false, INI_SCANNER_RAW) : [];

// Limpia comillas dobles/simples residuales
function cleanIniVal($v) {
    return trim(trim((string)$v), '"\'');
}

$adminPass = isset($iniData['calibrator_pass']) ? cleanIniVal($iniData['calibrator_pass']) : '';
$passOk    = false;

if ($adminPass !== '' && isset($_GET['admin_pass']) && $_GET['admin_pass'] === $adminPass) {
    $passOk = true;
    $_SESSION['cv_calibrator_auth'] = true;
}

// Acepta sesión activa (evita repetir la pass en la URL en recargas)
if (!empty($_SESSION['cv_calibrator_auth'])) {
    $passOk = true;
}

if (!$passOk) {
    http_response_code(403);
    echo '<p style="font-family:sans-serif;padding:40px;color:#c00">Acceso denegado. Añade <code>?admin_pass=TU_CLAVE</code> a la URL.</p>';
    exit;
}

// ── GUARDAR COORDENADAS (POST AJAX) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_coords') {
    header('Content-Type: application/json');

    $allowed = [
        'pdf_coord_firma_p1_x', 'pdf_coord_firma_p1_y',
        'pdf_coord_firma_p1_w', 'pdf_coord_firma_p1_h',
        'pdf_coord_check1_x',   'pdf_coord_check1_y',
        'pdf_coord_check2_x',   'pdf_coord_check2_y',
        'pdf_coord_check3_x',   'pdf_coord_check3_y',
        'pdf_coord_firma_p5_x', 'pdf_coord_firma_p5_y',
        'pdf_coord_firma_p5_w', 'pdf_coord_firma_p5_h',
        'pdf_coord_check_p5_x', 'pdf_coord_check_p5_y',
        'pdf_coord_fecha_p5_x', 'pdf_coord_fecha_p5_y',
        'pdf_coord_firma_page',  // página donde va la firma principal (0-index)
        'pdf_coord_check_page',  // página donde van los checks (0-index)
        'pdf_coord_firma5_page', // página donde va la firma secundaria (0-index)
    ];

    // Leer línea a línea para preservar comentarios y formato original
    $rawLines  = file_exists($configFile) ? file($configFile, FILE_IGNORE_NEW_LINES) : [];
    $overrides = [];

    foreach ($allowed as $key) {
        if (isset($_POST[$key]) && is_numeric($_POST[$key])) {
            $overrides[$key] = (float) $_POST[$key];
        }
    }

    // Sustituir líneas existentes que coincidan con las claves a actualizar
    $updatedKeys = [];
    $newLines    = [];
    foreach ($rawLines as $line) {
        $matched = false;
        foreach ($overrides as $key => $val) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $line)) {
                $newLines[]        = $key . ' = ' . $val;
                $updatedKeys[$key] = true;
                $matched           = true;
                break;
            }
        }
        if (!$matched) {
            $newLines[] = $line;
        }
    }

    // Añadir al final las claves que no existían aún
    foreach ($overrides as $key => $val) {
        if (empty($updatedKeys[$key])) {
            $newLines[] = $key . ' = ' . $val;
        }
    }

    $written = file_put_contents($configFile, implode("\n", $newLines) . "\n");

    echo json_encode(['ok' => $written !== false]);
    exit;
}

// ── LEER PDF para pasarlo al navegador (evita CORS) ────────────────────────
if (isset($_GET['proxy_pdf'])) {
    $pdfPath = realpath(__DIR__ . '/' . basename($_GET['proxy_pdf']));
    // También acepta rutas dentro del árbol de Joomla
    if (!$pdfPath) {
        $pdfPath = realpath(dirname(__DIR__, 3) . '/' . ltrim($_GET['proxy_pdf'], '/'));
    }
    if ($pdfPath && file_exists($pdfPath) && pathinfo($pdfPath, PATHINFO_EXTENSION) === 'pdf') {
        header('Content-Type: application/pdf');
        readfile($pdfPath);
        exit;
    }
    http_response_code(404);
    exit;
}

// ── DETECTAR PDF configurado ───────────────────────────────────────────────
$pdfParam   = isset($iniData['pdf_plantilla']) ? cleanIniVal($iniData['pdf_plantilla']) : '';
// Si la ruta es relativa, convertirla a URL absoluta basada en el servidor
if ($pdfParam !== '' && strpos($pdfParam, 'http') !== 0) {
    $baseHref = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST'];
    $pdfParam = $baseHref . '/' . ltrim($pdfParam, '/');
}
$pdfUrlJs = $pdfParam ?: ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST'] . '/modules/mod_formulario_cv/media/documento_base.pdf');

// Coordenadas actuales (para mostrar en UI)
$coords = [
    'firma_p1_x'  => isset($iniData['pdf_coord_firma_p1_x'])  ? (float)$iniData['pdf_coord_firma_p1_x']  : 65,
    'firma_p1_y'  => isset($iniData['pdf_coord_firma_p1_y'])  ? (float)$iniData['pdf_coord_firma_p1_y']  : 110,
    'firma_p1_w'  => isset($iniData['pdf_coord_firma_p1_w'])  ? (float)$iniData['pdf_coord_firma_p1_w']  : 240,
    'firma_p1_h'  => isset($iniData['pdf_coord_firma_p1_h'])  ? (float)$iniData['pdf_coord_firma_p1_h']  : 65,
    'check1_x'    => isset($iniData['pdf_coord_check1_x'])    ? (float)$iniData['pdf_coord_check1_x']    : 53,
    'check1_y'    => isset($iniData['pdf_coord_check1_y'])    ? (float)$iniData['pdf_coord_check1_y']    : 247,
    'check2_x'    => isset($iniData['pdf_coord_check2_x'])    ? (float)$iniData['pdf_coord_check2_x']    : 53,
    'check2_y'    => isset($iniData['pdf_coord_check2_y'])    ? (float)$iniData['pdf_coord_check2_y']    : 219,
    'check3_x'    => isset($iniData['pdf_coord_check3_x'])    ? (float)$iniData['pdf_coord_check3_x']    : 53,
    'check3_y'    => isset($iniData['pdf_coord_check3_y'])    ? (float)$iniData['pdf_coord_check3_y']    : 191,
    'firma_p5_x'  => isset($iniData['pdf_coord_firma_p5_x'])  ? (float)$iniData['pdf_coord_firma_p5_x']  : 65,
    'firma_p5_y'  => isset($iniData['pdf_coord_firma_p5_y'])  ? (float)$iniData['pdf_coord_firma_p5_y']  : 195,
    'firma_p5_w'  => isset($iniData['pdf_coord_firma_p5_w'])  ? (float)$iniData['pdf_coord_firma_p5_w']  : 240,
    'firma_p5_h'  => isset($iniData['pdf_coord_firma_p5_h'])  ? (float)$iniData['pdf_coord_firma_p5_h']  : 65,
    'check_p5_x'  => isset($iniData['pdf_coord_check_p5_x'])  ? (float)$iniData['pdf_coord_check_p5_x']  : 53,
    'check_p5_y'  => isset($iniData['pdf_coord_check_p5_y'])  ? (float)$iniData['pdf_coord_check_p5_y']  : 311,
    'fecha_p5_x'  => isset($iniData['pdf_coord_fecha_p5_x'])  ? (float)$iniData['pdf_coord_fecha_p5_x']  : 355,
    'fecha_p5_y'  => isset($iniData['pdf_coord_fecha_p5_y'])  ? (float)$iniData['pdf_coord_fecha_p5_y']  : 164,
    'firma_page'  => isset($iniData['pdf_coord_firma_page'])  ? (int)$iniData['pdf_coord_firma_page']    : 0,
    'check_page'  => isset($iniData['pdf_coord_check_page'])  ? (int)$iniData['pdf_coord_check_page']    : 0,
    'firma5_page' => isset($iniData['pdf_coord_firma5_page']) ? (int)$iniData['pdf_coord_firma5_page']   : 4,
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Calibrador PDF — Módulo CV</title>
<script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
<script src="https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}
header{background:#1e293b;padding:16px 24px;display:flex;align-items:center;gap:16px;border-bottom:1px solid #334155}
header h1{font-size:18px;font-weight:700;color:#f8fafc}
header small{color:#94a3b8;font-size:13px}
.layout{display:grid;grid-template-columns:1fr 320px;height:calc(100vh - 57px)}
.canvas-wrap{position:relative;overflow:auto;background:#374151;display:flex;justify-content:center;align-items:flex-start;padding:20px}
canvas#pdfCanvas{display:block;cursor:crosshair}
.overlay-layer{position:absolute;top:0;left:0;pointer-events:none}
.sidebar{background:#1e293b;overflow-y:auto;border-left:1px solid #334155;padding:16px;display:flex;flex-direction:column;gap:14px}
.sidebar h2{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b}
.nav-pages{display:flex;gap:6px;flex-wrap:wrap}
.nav-pages button{padding:6px 12px;border:1px solid #475569;background:#0f172a;color:#cbd5e1;border-radius:6px;cursor:pointer;font-size:12px}
.nav-pages button.active{background:#3b82f6;border-color:#3b82f6;color:#fff}
.mode-btns{display:flex;flex-direction:column;gap:6px}
.mode-btn{padding:8px 12px;border:2px solid transparent;border-radius:8px;cursor:pointer;font-size:12px;font-weight:600;text-align:left;background:#0f172a;color:#cbd5e1;transition:.15s}
.mode-btn:hover{border-color:#3b82f6;color:#93c5fd}
.mode-btn.active{border-color:#f59e0b;background:#451a03;color:#fcd34d}
label.field-row{display:flex;flex-direction:column;gap:3px;font-size:12px;color:#94a3b8}
label.field-row span{font-size:11px;color:#64748b}
input.coord-input{background:#0f172a;border:1px solid #334155;color:#e2e8f0;padding:5px 8px;border-radius:5px;font-size:12px;width:100%}
.btn-save{background:#16a34a;color:#fff;border:none;border-radius:8px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;width:100%}
.btn-save:hover{background:#15803d}
.btn-preview{background:#7c3aed;color:#fff;border:none;border-radius:8px;padding:10px;font-size:13px;font-weight:600;cursor:pointer;width:100%}
.btn-preview:hover{background:#6d28d9}
.toast{position:fixed;bottom:20px;right:20px;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:600;z-index:9999;display:none}
.toast.ok{background:#16a34a;color:#fff}
.toast.err{background:#dc2626;color:#fff}
.crosshair{position:absolute;pointer-events:none;z-index:10}
.crosshair-h,.crosshair-v{position:absolute;background:rgba(251,191,36,.7)}
.crosshair-h{height:1px;left:0;right:0}
.crosshair-v{width:1px;top:0;bottom:0}
.point-mark{position:absolute;width:12px;height:12px;border-radius:50%;transform:translate(-50%,-50%);border:2px solid #fff;z-index:20}
.hint{font-size:11px;color:#94a3b8;line-height:1.5}
.separator{border:none;border-top:1px solid #334155}
.page-label{font-size:11px;background:#0f172a;border:1px solid #334155;border-radius:5px;padding:4px 8px;color:#94a3b8}
select.coord-input{cursor:pointer}
</style>
</head>
<body>
<header>
    <h1>🎯 Calibrador de campos PDF</h1>
    <small>Módulo Formulario CV — Haz clic en el PDF para marcar la posición de cada campo</small>
</header>

<div class="layout">
    <!-- PDF CANVAS -->
    <div class="canvas-wrap" id="canvasWrap">
        <div style="position:relative;display:inline-block">
            <canvas id="pdfCanvas"></canvas>
            <canvas id="overlayCanvas" style="position:absolute;top:0;left:0;pointer-events:none"></canvas>
        </div>
    </div>

    <!-- SIDEBAR -->
    <div class="sidebar">
        <!-- Navegación de páginas -->
        <div>
            <h2>Páginas</h2>
            <div class="nav-pages" id="navPages"></div>
        </div>

        <hr class="separator">

        <!-- Selector de modo -->
        <div>
            <h2>Modo activo <span id="modeName" style="color:#fcd34d"></span></h2>
            <div class="mode-btns">
                <button class="mode-btn active" data-mode="firma_p1" data-color="#3b82f6">
                    🖊️ Firma principal (Pág. firma)<br>
                    <small style="color:#64748b;font-size:10px">Clic = esquina inferior-izquierda</small>
                </button>
                <button class="mode-btn" data-mode="check1" data-color="#10b981">✅ Casilla 1 (Pág. checks)</button>
                <button class="mode-btn" data-mode="check2" data-color="#10b981">✅ Casilla 2</button>
                <button class="mode-btn" data-mode="check3" data-color="#10b981">✅ Casilla 3</button>
                <button class="mode-btn" data-mode="firma_p5" data-color="#8b5cf6">
                    🖊️ Firma secundaria (Pág. LOPD)<br>
                    <small style="color:#64748b;font-size:10px">Clic = esquina inferior-izquierda</small>
                </button>
                <button class="mode-btn" data-mode="check_p5" data-color="#f59e0b">✅ Casilla LOPD</button>
                <button class="mode-btn" data-mode="fecha_p5" data-color="#ef4444">📅 Fecha LOPD</button>
            </div>
        </div>

        <hr class="separator">

        <!-- Tamaños de firma -->
        <div>
            <h2>Tamaño de firma</h2>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                <label class="field-row"><span>Ancho firma principal</span>
                    <input class="coord-input" id="f_firma_p1_w" type="number" value="<?= $coords['firma_p1_w'] ?>">
                </label>
                <label class="field-row"><span>Alto firma principal</span>
                    <input class="coord-input" id="f_firma_p1_h" type="number" value="<?= $coords['firma_p1_h'] ?>">
                </label>
                <label class="field-row"><span>Ancho firma LOPD</span>
                    <input class="coord-input" id="f_firma_p5_w" type="number" value="<?= $coords['firma_p5_w'] ?>">
                </label>
                <label class="field-row"><span>Alto firma LOPD</span>
                    <input class="coord-input" id="f_firma_p5_h" type="number" value="<?= $coords['firma_p5_h'] ?>">
                </label>
            </div>
        </div>

        <hr class="separator">

        <!-- Asignación de páginas -->
        <div>
            <h2>Asignación de páginas</h2>
            <div style="display:flex;flex-direction:column;gap:8px">
                <label class="field-row"><span>Página de firma principal (0 = primera)</span>
                    <input class="coord-input" id="f_firma_page" type="number" min="0" value="<?= $coords['firma_page'] ?>">
                </label>
                <label class="field-row"><span>Página de casillas (0 = primera)</span>
                    <input class="coord-input" id="f_check_page" type="number" min="0" value="<?= $coords['check_page'] ?>">
                </label>
                <label class="field-row"><span>Página de firma+casilla LOPD</span>
                    <input class="coord-input" id="f_firma5_page" type="number" min="0" value="<?= $coords['firma5_page'] ?>">
                </label>
            </div>
        </div>

        <hr class="separator">

        <!-- Coordenadas (solo lectura visual, se rellenan con clics) -->
        <div>
            <h2>Coordenadas PDF actuales</h2>
            <p class="hint">Haz clic en el PDF para actualizar. Las coordenadas son en puntos PDF (sistema Y invertido).</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px">
                <?php
                $fields = [
                    ['firma_p1_x','Firma P1 X'],['firma_p1_y','Firma P1 Y'],
                    ['check1_x','Check1 X'],['check1_y','Check1 Y'],
                    ['check2_x','Check2 X'],['check2_y','Check2 Y'],
                    ['check3_x','Check3 X'],['check3_y','Check3 Y'],
                    ['firma_p5_x','Firma P5 X'],['firma_p5_y','Firma P5 Y'],
                    ['check_p5_x','Check P5 X'],['check_p5_y','Check P5 Y'],
                    ['fecha_p5_x','Fecha X'],['fecha_p5_y','Fecha Y'],
                ];
                foreach ($fields as [$key, $label]):
                ?>
                <label class="field-row">
                    <span><?= $label ?></span>
                    <input class="coord-input" id="f_<?= $key ?>" type="number" step="0.5" value="<?= $coords[$key] ?>">
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <hr class="separator">

        <button class="btn-preview" id="btnPreview">👁️ Previsualizar marcas en PDF</button>
        <button class="btn-save" id="btnSave">💾 Guardar en config.ini</button>
        <p class="hint" style="text-align:center">Los cambios se aplican inmediatamente al formulario sin tocar código.</p>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
// ─── ESTADO ────────────────────────────────────────────────────────────────
var pdfDoc      = null;
var currentPage = 1;
var totalPages  = 0;
var scale       = 1.5;
var activeMode  = 'firma_p1';
var pageHeights = {}; // altura en puntos PDF por página (para invertir Y)

var modeColors = {
    firma_p1: '#3b82f6', check1: '#10b981', check2: '#10b981', check3: '#10b981',
    firma_p5: '#8b5cf6', check_p5: '#f59e0b', fecha_p5: '#ef4444'
};

// Coordenadas actuales desde PHP
var coords = <?= json_encode([
    'firma_p1_x'  => $coords['firma_p1_x'],
    'firma_p1_y'  => $coords['firma_p1_y'],
    'firma_p1_w'  => $coords['firma_p1_w'],
    'firma_p1_h'  => $coords['firma_p1_h'],
    'check1_x'    => $coords['check1_x'],
    'check1_y'    => $coords['check1_y'],
    'check2_x'    => $coords['check2_x'],
    'check2_y'    => $coords['check2_y'],
    'check3_x'    => $coords['check3_x'],
    'check3_y'    => $coords['check3_y'],
    'firma_p5_x'  => $coords['firma_p5_x'],
    'firma_p5_y'  => $coords['firma_p5_y'],
    'firma_p5_w'  => $coords['firma_p5_w'],
    'firma_p5_h'  => $coords['firma_p5_h'],
    'check_p5_x'  => $coords['check_p5_x'],
    'check_p5_y'  => $coords['check_p5_y'],
    'fecha_p5_x'  => $coords['fecha_p5_x'],
    'fecha_p5_y'  => $coords['fecha_p5_y'],
    'firma_page'  => $coords['firma_page'],
    'check_page'  => $coords['check_page'],
    'firma5_page' => $coords['firma5_page'],
]) ?>;

var pdfUrl = '<?= addslashes($pdfUrlJs) ?>';

// ─── CARGA DE PDF con pdf.js ───────────────────────────────────────────────
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.worker.min.js';

async function loadPdf() {
    try {
        pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
        totalPages = pdfDoc.numPages;

        // Pre-calcular alturas
        for (var p = 1; p <= totalPages; p++) {
            var pg = await pdfDoc.getPage(p);
            pageHeights[p] = pg.getViewport({scale: 1}).height;
        }

        buildPageNav();
        await renderPage(1);
    } catch (e) {
        // Intentar via proxy PHP si hay error CORS
        var proxied = location.pathname + '?admin_pass=<?= isset($_GET['admin_pass']) ? htmlspecialchars($_GET['admin_pass']) : '' ?>&proxy_pdf=' + encodeURIComponent(pdfUrl.replace(/^\//, ''));
        try {
            pdfDoc = await pdfjsLib.getDocument(proxied).promise;
            totalPages = pdfDoc.numPages;
            for (var p2 = 1; p2 <= totalPages; p2++) {
                var pg2 = await pdfDoc.getPage(p2);
                pageHeights[p2] = pg2.getViewport({scale: 1}).height;
            }
            buildPageNav();
            await renderPage(1);
        } catch (e2) {
            alert('No se pudo cargar el PDF: ' + e2.message + '\n\nVerifica que la ruta en config.ini sea correcta.');
        }
    }
}

function buildPageNav() {
    var nav = document.getElementById('navPages');
    nav.innerHTML = '';
    for (var i = 1; i <= totalPages; i++) {
        (function(n){
            var btn = document.createElement('button');
            btn.textContent = 'Pág ' + n;
            btn.className = n === 1 ? 'active' : '';
            btn.addEventListener('click', function() {
                document.querySelectorAll('.nav-pages button').forEach(function(b){ b.classList.remove('active'); });
                btn.classList.add('active');
                renderPage(n);
            });
            nav.appendChild(btn);
        })(i);
    }
}

async function renderPage(n) {
    currentPage = n;
    var page    = await pdfDoc.getPage(n);
    var vp      = page.getViewport({ scale: scale });

    var canvas  = document.getElementById('pdfCanvas');
    var overlay = document.getElementById('overlayCanvas');
    canvas.width  = overlay.width  = vp.width;
    canvas.height = overlay.height = vp.height;

    await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
    drawOverlayMarks();
}

// ─── OVERLAY — dibujar marcas guardadas ────────────────────────────────────
function drawOverlayMarks() {
    var overlay = document.getElementById('overlayCanvas');
    var ctx     = overlay.getContext('2d');
    ctx.clearRect(0, 0, overlay.width, overlay.height);

    var pH = pageHeights[currentPage] || 841; // A4 en puntos

    // Definición de qué dibujar en qué página
    var marks = [
        { page: coords.firma_page + 1,  x: coords.firma_p1_x, y: coords.firma_p1_y, w: coords.firma_p1_w, h: coords.firma_p1_h, color: '#3b82f6', label: 'Firma P1', type: 'rect' },
        { page: coords.check_page + 1,  x: coords.check1_x,   y: coords.check1_y,   color: '#10b981', label: '✓1', type: 'dot' },
        { page: coords.check_page + 1,  x: coords.check2_x,   y: coords.check2_y,   color: '#10b981', label: '✓2', type: 'dot' },
        { page: coords.check_page + 1,  x: coords.check3_x,   y: coords.check3_y,   color: '#10b981', label: '✓3', type: 'dot' },
        { page: coords.firma5_page + 1, x: coords.firma_p5_x, y: coords.firma_p5_y, w: coords.firma_p5_w, h: coords.firma_p5_h, color: '#8b5cf6', label: 'Firma LOPD', type: 'rect' },
        { page: coords.firma5_page + 1, x: coords.check_p5_x, y: coords.check_p5_y, color: '#f59e0b', label: '✓LOPD', type: 'dot' },
        { page: coords.firma5_page + 1, x: coords.fecha_p5_x, y: coords.fecha_p5_y, color: '#ef4444', label: 'Fecha', type: 'dot' },
    ];

    marks.forEach(function(m) {
        if (m.page !== currentPage) return;
        // Convertir coords PDF (Y desde abajo) a canvas (Y desde arriba)
        var cx = m.x * scale;
        var cy = (pH - m.y) * scale;

        ctx.strokeStyle = m.color;
        ctx.fillStyle   = m.color;
        ctx.lineWidth   = 2;
        ctx.font        = 'bold 11px system-ui';

        if (m.type === 'rect') {
            ctx.globalAlpha = 0.35;
            ctx.fillRect(cx, cy - m.h * scale, m.w * scale, m.h * scale);
            ctx.globalAlpha = 1;
            ctx.strokeRect(cx, cy - m.h * scale, m.w * scale, m.h * scale);
        } else {
            ctx.beginPath();
            ctx.arc(cx, cy, 6, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.fillStyle = m.color;
        ctx.fillText(m.label, cx + 8, cy - 4);
    });
}

// ─── CLIC EN CANVAS ────────────────────────────────────────────────────────
document.getElementById('pdfCanvas').addEventListener('click', function(e) {
    var rect   = this.getBoundingClientRect();
    var cx     = (e.clientX - rect.left) * (this.width / rect.width);
    var cy     = (e.clientY - rect.top)  * (this.height / rect.height);
    var pH     = pageHeights[currentPage] || 841;

    // Convertir canvas coords → PDF coords (Y desde abajo)
    var pdfX = cx / scale;
    var pdfY = pH - (cy / scale);

    // Guardar en coords y en los inputs
    var map = {
        firma_p1:  ['firma_p1_x',  'firma_p1_y'],
        check1:    ['check1_x',    'check1_y'],
        check2:    ['check2_x',    'check2_y'],
        check3:    ['check3_x',    'check3_y'],
        firma_p5:  ['firma_p5_x',  'firma_p5_y'],
        check_p5:  ['check_p5_x',  'check_p5_y'],
        fecha_p5:  ['fecha_p5_x',  'fecha_p5_y'],
    };

    if (map[activeMode]) {
        var kx = map[activeMode][0];
        var ky = map[activeMode][1];
        coords[kx] = Math.round(pdfX * 10) / 10;
        coords[ky] = Math.round(pdfY * 10) / 10;
        document.getElementById('f_' + kx).value = coords[kx];
        document.getElementById('f_' + ky).value = coords[ky];
        drawOverlayMarks();
        toast('Marcado ' + activeMode + ' en (' + coords[kx] + ', ' + coords[ky] + ')', 'ok');
    }
});

// ─── INPUTS MANUALES ───────────────────────────────────────────────────────
document.querySelectorAll('.coord-input').forEach(function(inp) {
    inp.addEventListener('change', function() {
        var id  = this.id.replace('f_', '');
        var val = parseFloat(this.value);
        if (!isNaN(val)) {
            coords[id] = val;
            // Sync también el objeto para páginas
            if (id === 'firma_page')  coords.firma_page  = parseInt(val);
            if (id === 'check_page')  coords.check_page  = parseInt(val);
            if (id === 'firma5_page') coords.firma5_page = parseInt(val);
            drawOverlayMarks();
        }
    });
});

// ─── SELECTOR DE MODO ──────────────────────────────────────────────────────
document.querySelectorAll('.mode-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.mode-btn').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
        activeMode = btn.dataset.mode;
        document.getElementById('modeName').textContent = '→ ' + btn.textContent.trim().split('\n')[0];
    });
});
document.getElementById('modeName').textContent = '→ Firma principal';

// ─── GUARDAR ───────────────────────────────────────────────────────────────
document.getElementById('btnSave').addEventListener('click', function() {
    // Leer todos los inputs por si el usuario los editó manualmente
    document.querySelectorAll('.coord-input').forEach(function(inp) {
        var id  = inp.id.replace('f_', '');
        var val = parseFloat(inp.value);
        if (!isNaN(val)) coords[id] = val;
    });

    var body = new URLSearchParams();
    body.set('action', 'save_coords');
    body.set('pdf_coord_firma_p1_x',  coords.firma_p1_x);
    body.set('pdf_coord_firma_p1_y',  coords.firma_p1_y);
    body.set('pdf_coord_firma_p1_w',  coords.firma_p1_w);
    body.set('pdf_coord_firma_p1_h',  coords.firma_p1_h);
    body.set('pdf_coord_check1_x',    coords.check1_x);
    body.set('pdf_coord_check1_y',    coords.check1_y);
    body.set('pdf_coord_check2_x',    coords.check2_x);
    body.set('pdf_coord_check2_y',    coords.check2_y);
    body.set('pdf_coord_check3_x',    coords.check3_x);
    body.set('pdf_coord_check3_y',    coords.check3_y);
    body.set('pdf_coord_firma_p5_x',  coords.firma_p5_x);
    body.set('pdf_coord_firma_p5_y',  coords.firma_p5_y);
    body.set('pdf_coord_firma_p5_w',  coords.firma_p5_w);
    body.set('pdf_coord_firma_p5_h',  coords.firma_p5_h);
    body.set('pdf_coord_check_p5_x',  coords.check_p5_x);
    body.set('pdf_coord_check_p5_y',  coords.check_p5_y);
    body.set('pdf_coord_fecha_p5_x',  coords.fecha_p5_x);
    body.set('pdf_coord_fecha_p5_y',  coords.fecha_p5_y);
    body.set('pdf_coord_firma_page',  coords.firma_page);
    body.set('pdf_coord_check_page',  coords.check_page);
    body.set('pdf_coord_firma5_page', coords.firma5_page);

    fetch(location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.ok) toast('✅ Guardado en config.ini — el formulario ya usa las nuevas coordenadas', 'ok');
        else      toast('❌ Error al guardar. Verifica permisos de escritura en config.ini', 'err');
    })
    .catch(function(){ toast('❌ Error de red', 'err'); });
});

// ─── PREVISUALIZAR con pdf-lib ─────────────────────────────────────────────
document.getElementById('btnPreview').addEventListener('click', async function() {
    toast('Generando previsualización...', 'ok');
    try {
        var resp     = await fetch(pdfUrl);
        var bytes    = await resp.arrayBuffer();
        var pdfLibDoc = await PDFLib.PDFDocument.load(bytes);
        var pages    = pdfLibDoc.getPages();

        var fp  = coords.firma_page;
        var cp  = coords.check_page;
        var f5p = coords.firma5_page;

        // Firma principal
        if (pages[fp]) {
            pages[fp].drawRectangle({ x: coords.firma_p1_x, y: coords.firma_p1_y, width: coords.firma_p1_w, height: coords.firma_p1_h, borderColor: PDFLib.rgb(0.23,0.51,1), borderWidth: 2 });
        }
        // Checks página principal
        ['check1','check2','check3'].forEach(function(k) {
            if (pages[cp]) {
                pages[cp].drawText('X', { x: coords[k+'_x'], y: coords[k+'_y'], size: 12 });
            }
        });
        // Firma y check página 5
        if (pages[f5p]) {
            pages[f5p].drawRectangle({ x: coords.firma_p5_x, y: coords.firma_p5_y, width: coords.firma_p5_w, height: coords.firma_p5_h, borderColor: PDFLib.rgb(0.54,0.36,0.96), borderWidth: 2 });
            pages[f5p].drawText('X', { x: coords.check_p5_x, y: coords.check_p5_y, size: 12 });
            pages[f5p].drawText('HOY', { x: coords.fecha_p5_x, y: coords.fecha_p5_y, size: 11 });
        }

        var outBytes = await pdfLibDoc.save();
        var blob     = new Blob([outBytes], { type: 'application/pdf' });
        var url      = URL.createObjectURL(blob);
        window.open(url, '_blank');
    } catch(e) {
        toast('Error en previsualización: ' + e.message, 'err');
    }
});

// ─── TOAST ─────────────────────────────────────────────────────────────────
function toast(msg, type) {
    var el = document.getElementById('toast');
    el.textContent = msg;
    el.className   = 'toast ' + type;
    el.style.display = 'block';
    clearTimeout(el._t);
    el._t = setTimeout(function(){ el.style.display = 'none'; }, 3500);
}

// ─── INIT ──────────────────────────────────────────────────────────────────
loadPdf();
</script>
</body>
</html>