<?php

/**
 * @package      Joomla.Site
 * @subpackage   mod_formulario_cv
 *
 * @copyright    Copyright (C) 2026
 * @license      GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

require_once dirname(__DIR__) . '/helper.php';

// Captura de parámetros básicos desde el XML
$privacyUrl    = trim((string) $params->get('privacy_url', ''));
$introText     = trim((string) $params->get('intro_text', ''));
$maxJobOptions = max(1, (int) $params->get('max_job_options', 3));

// PDF plantilla: se obtiene del parámetro del módulo (JCE mediapicker)
$pdfParam   = trim((string) $params->get('pdf_plantilla', ''));
$pdfBaseUrl = '';
if (!empty($pdfParam)) {
    if (strpos($pdfParam, 'http://') === 0 || strpos($pdfParam, 'https://') === 0) {
        $pdfBaseUrl = $pdfParam;
    } else {
        $pdfBaseUrl = JURI::base(true) . '/' . ltrim($pdfParam, '/');
    }
} else {
    $pdfBaseUrl = JURI::base(true) . '/modules/mod_formulario_cv/media/documento_base.pdf';
}

// Prioridad absoluta a la API externa para puestos
$puestosDinamicos = ModFormularioCvHelper::getPuestosDisponibles();
if (!empty($puestosDinamicos)) {
    $jobOptions = $puestosDinamicos;
} else {
    $jobOptions = array(
        1  => 'Administracion',
        2  => 'Atencion al cliente',
        3  => 'Comercial',
        4  => 'Hosteleria',
        5  => 'Limpieza',
        6  => 'Logistica y almacen',
        7  => 'Mantenimiento',
        8  => 'Produccion',
        9  => 'Transporte',
        10 => 'Otro'
    );
}

// Opciones de disponibilidad
$defaultAvailabilityOptions = array('Inmediata', 'En 15 dias', 'En 30 dias', 'A convenir');
$availabilityOptions = ModFormularioCvHelper::parseOptions($params->get('availability_options', ''), $defaultAvailabilityOptions);

// Procesamiento del formulario (respaldo sin AJAX)
$apiMessage = '';
$apiStatus  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!JSession::checkToken('post')) {
        $apiStatus  = 'error';
        $apiMessage = 'Token de seguridad invalido. Por favor, reintente.';
    } else {
        $result     = ModFormularioCvHelper::processSubmission(JFactory::getApplication()->input);
        $apiStatus  = $result['status'];
        $apiMessage = $result['message'];
    }
}

$ajaxEndpoint = JUri::root(true) . '/modules/mod_formulario_cv/submit.php';
?>

<?php
// Variables estéticas del config.ini
$cvBg         = ModFormularioCvHelper::getIniConfig('cv_bg');
$cvPanel      = ModFormularioCvHelper::getIniConfig('cv_panel');
$cvText       = ModFormularioCvHelper::getIniConfig('cv_text');
$cvMuted      = ModFormularioCvHelper::getIniConfig('cv_muted');
$cvLine       = ModFormularioCvHelper::getIniConfig('cv_line');
$cvAccent     = ModFormularioCvHelper::getIniConfig('cv_accent');
$cvAccentDark = ModFormularioCvHelper::getIniConfig('cv_accent_dark');
$cvAccentSoft = ModFormularioCvHelper::getIniConfig('cv_accent_soft');
$cvWarning    = ModFormularioCvHelper::getIniConfig('cv_warning');
?>
<style>
.cv-form-module {
    <?php if (!empty($cvBg)): ?>--cv-bg: <?php echo $cvBg; ?>;<?php endif; ?>
    <?php if (!empty($cvPanel)): ?>--cv-panel: <?php echo $cvPanel; ?>;<?php endif; ?>
    <?php if (!empty($cvText)): ?>--cv-text: <?php echo $cvText; ?>;<?php endif; ?>
    <?php if (!empty($cvMuted)): ?>--cv-muted: <?php echo $cvMuted; ?>;<?php endif; ?>
    <?php if (!empty($cvLine)): ?>--cv-line: <?php echo $cvLine; ?>;<?php endif; ?>
    <?php if (!empty($cvAccent)): ?>--cv-accent: <?php echo $cvAccent; ?>;<?php endif; ?>
    <?php if (!empty($cvAccentDark)): ?>--cv-accent-dark: <?php echo $cvAccentDark; ?>;<?php endif; ?>
    <?php if (!empty($cvAccentSoft)): ?>--cv-accent-soft: <?php echo $cvAccentSoft; ?>;<?php endif; ?>
    <?php if (!empty($cvWarning)): ?>--cv-warning: <?php echo $cvWarning; ?>;<?php endif; ?>
    color: var(--cv-text);
    font-family: inherit;
}
.cv-clausulas-container { font-family: Arial, sans-serif; font-size: 13.5px; line-height: 1.5; color: #333; padding: 5px; }
.cv-clausulas-container h4 { font-size: 14px; font-weight: bold; margin-top: 18px; margin-bottom: 8px; color: #0b1a30; text-transform: uppercase; }
.cv-modal-choice { display: flex; align-items: flex-start; margin-bottom: 10px; cursor: pointer; font-size: 13px; background: #f8f9fa; padding: 10px; border-radius: 6px; border: 1px solid #e9ecef; }
.cv-modal-choice input { margin-top: 3px; margin-right: 10px; flex-shrink: 0; }
</style>

<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<script src="https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>

<?php
// ── Inyección de coordenadas PDF dinámicas desde config.ini ──────────────────
// El calibrador (pdf-calibrator.php) escribe estos valores.
// Si no existen, se usan los fallbacks del JS.
?>
<script>
window.cvPdfTemplateUrl = '<?php echo addslashes($pdfBaseUrl); ?>';
window.cvPdfCoords = <?php echo json_encode([
    'firma_page'  => (int)   ModFormularioCvHelper::getIniConfig('pdf_coord_firma_page'),
    'check_page'  => (int)   ModFormularioCvHelper::getIniConfig('pdf_coord_check_page'),
    'firma5_page' => (int)   ModFormularioCvHelper::getIniConfig('pdf_coord_firma5_page') ?: 4,
    'firma_p1_x'  => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_firma_p1_x') ?: 65,
    'firma_p1_y'  => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_firma_p1_y') ?: 110,
    'firma_p1_w'  => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_firma_p1_w') ?: 240,
    'firma_p1_h'  => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_firma_p1_h') ?: 65,
    'check1_x'    => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_check1_x')   ?: 53,
    'check1_y'    => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_check1_y')   ?: 247,
    'check2_x'    => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_check2_x')   ?: 53,
    'check2_y'    => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_check2_y')   ?: 219,
    'check3_x'    => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_check3_x')   ?: 53,
    'check3_y'    => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_check3_y')   ?: 191,
    'firma_p5_x'  => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_firma_p5_x') ?: 65,
    'firma_p5_y'  => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_firma_p5_y') ?: 195,
    'firma_p5_w'  => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_firma_p5_w') ?: 240,
    'firma_p5_h'  => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_firma_p5_h') ?: 65,
    'check_p5_x'  => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_check_p5_x') ?: 53,
    'check_p5_y'  => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_check_p5_y') ?: 311,
    'fecha_p5_x'  => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_fecha_p5_x') ?: 355,
    'fecha_p5_y'  => (float) ModFormularioCvHelper::getIniConfig('pdf_coord_fecha_p5_y') ?: 164,
], JSON_PRETTY_PRINT); ?>;
</script>

<div class="container cv-form-module">
    <div class="row justify-content-center">
        <div>
            <div class="cv-form-shell" id="formulario">
                <div class="cv-form-header">
                    <p class="cv-form-kicker">Trabaja con nosotros</p>
                    <h2>Formulario de candidatura</h2>
                    <?php if ($introText !== ''): ?>
                        <p class="cv-form-intro"><?php echo htmlspecialchars($introText, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($apiMessage !== ''): ?>
                    <div class="cv-form-message cv-form-message-<?php echo htmlspecialchars($apiStatus, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($apiMessage, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form class="cv-form" data-cv-ajax="1"
                    data-cv-ajax-url="<?php echo htmlspecialchars($ajaxEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
                    action="<?php echo htmlspecialchars(JUri::current(), ENT_QUOTES, 'UTF-8'); ?>" method="post"
                    enctype="multipart/form-data">

                    <fieldset class="cv-form-section">
                        <legend>Datos personales</legend>
                        <div class="cv-form-grid">
                            <div class="cv-field cv-field-wide">
                                <label for="cv_nombre">Nombre y apellidos <span>*</span></label>
                                <input type="text" id="cv_nombre" name="cv_nombre" autocomplete="name" required>
                            </div>
                            <div class="cv-field">
                                <label for="cv_dni">DNI/NIE <span>*</span></label>
                                <input type="text" id="cv_dni" name="cv_dni" required>
                            </div>
                            <div class="cv-field">
                                <label for="cv_telefono">Telefono <span>*</span></label>
                                <input type="tel" id="cv_telefono" name="cv_telefono" autocomplete="tel" required>
                            </div>
                            <div class="cv-field">
                                <label for="cv_email">Email <span>*</span></label>
                                <input type="email" id="cv_email" name="cv_email" autocomplete="email" required>
                            </div>
                            <div class="cv-field">
                                <label for="cv_localidad">Localidad <span>*</span></label>
                                <input type="text" id="cv_localidad" name="cv_localidad" autocomplete="address-level2" required>
                            </div>
                            <div class="cv-field">
                                <label for="cv_foto">Foto opcional</label>
                                <input type="file" id="cv_foto" name="cv_foto" accept="image/png,image/jpeg,image/webp">
                            </div>
                            <div class="cv-field">
                                <label for="cv_disponibilidad">Disponibilidad de incorporacion <span>*</span></label>
                                <select id="cv_disponibilidad" name="cv_disponibilidad" required>
                                    <option value="">Selecciona una opcion</option>
                                    <?php foreach ($availabilityOptions as $availabilityOption): ?>
                                        <option value="<?php echo htmlspecialchars($availabilityOption, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($availabilityOption, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="cv-form-section">
                        <legend>Puesto y documentacion</legend>
                        <div class="cv-field">
                            <label>Puesto de interes <span>*</span></label>
                            <p class="cv-field-help">Puedes seleccionar hasta <?php echo (int) $maxJobOptions; ?> opciones.</p>
                            <div class="cv-checkbox-list" data-max-options="<?php echo (int) $maxJobOptions; ?>">
                                <?php foreach ($jobOptions as $puestoId => $puestoNombre): ?>
                                    <label class="cv-choice" for="cv_puesto_<?php echo htmlspecialchars($puestoId, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="checkbox" id="cv_puesto_<?php echo htmlspecialchars($puestoId, ENT_QUOTES, 'UTF-8'); ?>" name="cv_puesto_interes[]"
                                            value="<?php echo htmlspecialchars($puestoId, ENT_QUOTES, 'UTF-8'); ?>">
                                        <span><?php echo htmlspecialchars($puestoNombre, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="cv-limit-message" aria-live="polite"></p>
                        </div>
                        <div class="cv-form-grid cv-form-grid-files">
                            <div class="cv-field">
                                <label for="cv_cv">Adjuntar CV <span>*</span></label>
                                <input type="file" id="cv_cv" name="cv_cv" accept="application/pdf" required>
                                <p class="cv-field-help">Formatos recomendados: PDF, DOC o DOCX.</p>
                            </div>
                            <div class="cv-field">
                                <label for="cv_carnes">Carnes profesionales</label>
                                <input type="text" id="cv_carnes" name="cv_carnes" placeholder="Carnet de conducir, carretillero...">
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="cv-form-section">
                        <legend>Perfil profesional</legend>
                        <div class="cv-form-grid">
                            <div class="cv-field cv-field-wide">
                                <label for="cv_formacion">Formacion academica</label>
                                <textarea id="cv_formacion" name="cv_formacion" rows="4"></textarea>
                            </div>
                            <div class="cv-field cv-field-wide">
                                <label for="cv_experiencia">Experiencia profesional</label>
                                <textarea id="cv_experiencia" name="cv_experiencia" rows="4"></textarea>
                            </div>
                            <div class="cv-field">
                                <label for="cv_idiomas">Idiomas</label>
                                <textarea id="cv_idiomas" name="cv_idiomas" rows="3"></textarea>
                            </div>
                            <div class="cv-field">
                                <label for="cv_competencias">Competencias y habilidades</label>
                                <textarea id="cv_competencias" name="cv_competencias" rows="3"></textarea>
                            </div>
                            <div class="cv-field cv-field-wide">
                                <label for="cv_observaciones">Observaciones</label>
                                <textarea id="cv_observaciones" name="cv_observaciones" rows="4"></textarea>
                            </div>
                        </div>
                    </fieldset>

                    <!-- ── FIRMA ELECTRÓNICA ──────────────────────────────── -->
                    <fieldset class="cv-form-section cv-signature-section">
                        <legend>Documentación y Firma Electrónica</legend>
                        <p class="cv-field-help">Es necesario que revise y firme las condiciones de protección de datos obligatoriamente.</p>
                        <div style="margin-bottom: 15px;">
                            <button type="button" id="cv_open_pdf_signer" class="cv-submit" style="background:#0b1a30; min-height:44px; padding:10px 20px;">
                                📄 Leer y Firmar Condiciones de Privacidad
                            </button>
                        </div>
                        <p class="cv-docuseal-status" id="cv_firmado_ok_msg" style="font-weight:700; color:var(--cv-warning); margin:0;">
                            ⚠️ Debes abrir y firmar el documento para habilitar el envío.
                        </p>
                        <input type="hidden" id="cv_firma_pdf_base64" name="cv_firma_pdf_base64" value="">
                        <button type="button" id="cv_download_pdf_signed" class="btn btn-primary" style="display:none; margin-top:10px;">
                            📥 Descargar PDF Firmado
                        </button>
                    </fieldset>
                    <!-- ────────────────────────────────────────────────────── -->

                    <div class="cv-privacy">
                        <label for="cv_privacidad">
                            <input type="checkbox" id="cv_privacidad" name="cv_privacidad" value="1" required disabled>
                            <span>
                                Acepto la
                                <?php if ($privacyUrl !== ''): ?>
                                    <a href="<?php echo htmlspecialchars($privacyUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">politica de privacidad</a>
                                <?php else: ?>
                                    politica de privacidad
                                <?php endif; ?>
                                <span class="cv-required">*</span>
                            </span>
                        </label>
                    </div>

                    <div class="cv-captcha-container" style="margin: 20px 0;">
                        <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars(ModFormularioCvHelper::getIniConfig('recaptcha_site_key'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                    </div>

                    <div class="cv-form-actions">
                        <button type="submit" class="cv-submit w-100">Enviar candidatura</button>
                        <p>Los campos marcados con * son obligatorios.</p>
                    </div>

                    <?php echo JHtml::_('form.token'); ?>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ── MODAL DE FIRMA PDF ──────────────────────────────────────────────────── -->
<div id="cv_pdf_modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(11,26,48,0.75); z-index:99999; justify-content:center; align-items:center; padding:20px; box-sizing:border-box;">
    <div style="background:#fff; width:100%; max-width:680px; height:90%; border-radius:12px; display:flex; flex-direction:column; overflow:hidden; position:relative; box-shadow:0 24px 60px rgba(0,0,0,0.3);">

        <div style="padding:15px 20px; background:#f8f9fa; border-bottom:1px solid #e9ecef; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:16px; font-weight:bold; color:#0b1a30;">Revisión y Firma Regulaciones LOPD</h3>
            <button type="button" id="cv_close_pdf_modal" style="background:none; border:none; font-size:24px; cursor:pointer; color:#6c757d; line-height:1;">&times;</button>
        </div>

        <div style="flex:1; overflow-y:auto; background:#fff; padding:25px; box-sizing:border-box;">
            <div class="cv-clausulas-container">
                <div style="text-align:center; font-weight:bold; margin-bottom:15px; font-size:13px; color:#0b1a30;">
                    REGISTRO DE ACTIVIDADES DE TRATAMIENTO - EMPLEO Y FUTURO ETT SLU
                </div>
                <p style="text-align:justify;">Al formalizar su firma, declara conocer y consentir expresamente las normativas de protección de datos personales de nuestra entidad para la gestión del servicio y el tratamiento de su Currículum Vitae en los procesos selectivos activos.</p>

                <h4>1. Consentimiento Comercial y de Servicio (Pág. 1)</h4>
                <p style="font-size:12px; color:#6c757d; margin-bottom:10px;">Marque las casillas para definir sus autorizaciones en la primera página:</p>
                <label class="cv-modal-choice">
                    <input type="checkbox" id="modal_consent_1" checked>
                    <span><strong>Prestación del servicio contratado</strong> (Acepto el tratamiento para la gestión de empleo).</span>
                </label>
                <label class="cv-modal-choice">
                    <input type="checkbox" id="modal_consent_2">
                    <span><strong>Envío del producto adquirido</strong> (Si corresponde).</span>
                </label>
                <label class="cv-modal-choice">
                    <input type="checkbox" id="modal_consent_3">
                    <span><strong>Envío de ofertas de productos y servicios de su interés</strong>.</span>
                </label>

                <h4>2. Cláusula Específica para Currículums (Pág. LOPD)</h4>
                <p style="font-size:12px; color:#6c757d; margin-bottom:10px;">Requerido para poder procesar su candidatura:</p>
                <label class="cv-modal-choice" style="border:1px solid var(--cv-warning, #ffc107);">
                    <input type="checkbox" id="modal_consent_cv" checked required>
                    <span><strong>Aceptación Procesos de Selección:</strong> Consiento de forma expresa que traten mis datos para cubrir puestos de trabajo ofertados por la entidad. <span style="color:red;">*</span></span>
                </label>

                <div style="margin-top:25px; border-top:1px dashed #ccc; padding-top:15px;">
                    <span style="display:block; font-size:13px; color:#0b1a30; font-weight:bold; margin-bottom:8px;">Escriba su firma digital única:</span>
                    <div style="background:#ffffff; border:2px dashed #0b1a30; border-radius:8px; position:relative; height:130px; overflow:hidden;">
                        <canvas id="cv_pdf_canvas_firma" style="display:block; width:100%; height:100%; cursor:crosshair; touch-action:none;"></canvas>
                    </div>
                    <small style="color:#6c757d; display:block; margin-top:5px;">La firma se estampará en las posiciones calibradas del documento.</small>
                </div>
            </div>
        </div>

        <div style="padding:15px 20px; background:#f8f9fa; border-top:1px solid #e9ecef; display:flex; justify-content:space-between; align-items:center;">
            <button type="button" id="cv_clear_pdf_canvas" style="background:#6c757d; color:#fff; border:none; padding:8px 18px; border-radius:6px; cursor:pointer; font-weight:bold;">Limpiar</button>
            <button type="button" id="cv_save_pdf_signed" style="background:#28a745; color:#fff; border:none; padding:10px 25px; border-radius:6px; font-weight:bold; cursor:pointer;">Aceptar y Firmar PDF</button>
        </div>
    </div>
</div>
<!-- ─────────────────────────────────────────────────────────────────────────── -->