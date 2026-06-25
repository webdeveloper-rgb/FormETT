<?php

/**
 * @package      Joomla.Site
 * @subpackage   mod_formulario_cv
 *
 * Endpoint dedicado para el envio AJAX del formulario, con herramientas
 * de depuracion incorporadas.
 */

// -----------------------------------------------------------------
// MODO PING: visita esta URL directamente en el navegador para
// comprobar que el archivo se ejecuta como PHP y es alcanzable,
// SIN tocar Joomla ni la API externa.
//
//   https://tudominio.com/modules/mod_formulario_cv/submit.php?ping=1
//
// Si ves el JSON de exito, el archivo esta bien colocado y el
// servidor lo ejecuta. Si ves un 404, una pantalla en blanco, o el
// codigo PHP "tal cual" como texto, el problema es de ruta/despliegue,
// no del codigo de mas abajo.
// -----------------------------------------------------------------
if (isset($_GET['ping'])) {
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array(
		'status'  => 'success',
		'message' => 'submit.php alcanzado correctamente.',
	));
	exit;
}

// A partir de aqui, cualquier error de PHP se captura y se devuelve
// como JSON (en vez de como HTML, que es lo que rompia el envio).
ini_set('display_errors', '0');
error_reporting(E_ALL);

$debugLogFile = __DIR__ . '/debug.log';

function cv_debug_log($message)
{
	global $debugLogFile;
	@file_put_contents($debugLogFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

function cv_send_json_error($message, array $extra = array())
{
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
	}

	cv_debug_log('ERROR: ' . $message);
	echo json_encode(array_merge(array('status' => 'error', 'message' => $message), $extra), JSON_UNESCAPED_UNICODE);
	exit;
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
	cv_debug_log('PHP warning/notice: ' . $errstr . ' en ' . $errfile . ':' . $errline);
	return true;
});

set_exception_handler(function ($exception) {
	cv_send_json_error('Excepcion no controlada: ' . $exception->getMessage());
});

register_shutdown_function(function () {
	$error = error_get_last();

	if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
		cv_debug_log('FATAL: ' . $error['message'] . ' en ' . $error['file'] . ':' . $error['line']);

		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
		}

		echo json_encode(array(
			'status'  => 'error',
			'message' => 'Error fatal en el servidor. Revisa modules/mod_formulario_cv/debug.log',
		));
	}
});

try {
	define('_JEXEC', 1);
	define('JPATH_BASE', dirname(dirname(dirname(__FILE__))));

	cv_debug_log('Peticion recibida. Metodo: ' . $_SERVER['REQUEST_METHOD'] . '. JPATH_BASE calculada: ' . JPATH_BASE);

	if (!file_exists(JPATH_BASE . '/includes/defines.php')) {
		cv_send_json_error('No se encontro Joomla en la ruta esperada: ' . JPATH_BASE . '. Revisa en que carpeta esta realmente instalado este modulo.');
	}

	require_once JPATH_BASE . '/includes/defines.php';
	require_once JPATH_BASE . '/includes/framework.php';

	$app = JFactory::getApplication('site');
	$app->initialise();

	// Carga del helper que autogestiona el archivo .ini
	require_once __DIR__ . '/helper.php';

	header('Content-Type: application/json; charset=utf-8');

	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		cv_send_json_error('Metodo no permitido.');
	}

	// ── upload_pdf_firmado: petición secundaria sin token Joomla ────────────
	$action = isset($_POST['action']) ? trim($_POST['action']) : '';

	if ($action === 'upload_pdf_firmado') {
		// Esta petición llega después del submit principal, sin token de sesión.
		// Solo se permite con clave válida (formato M########).
		$clave  = isset($_POST['clave'])      ? trim($_POST['clave'])      : '';
		$pdfB64 = isset($_POST['pdf_base64']) ? trim($_POST['pdf_base64']) : '';

		if (!preg_match('/^M\d{8}$/', $clave)) {
			cv_send_json_error('Clave de candidato inválida.');
		}

		$result = ModFormularioCvHelper::uploadPdfFirmado($clave, $pdfB64);
		cv_debug_log('uploadPdfFirmado: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
		echo json_encode($result, JSON_UNESCAPED_UNICODE);
		$app->close();
	}

	// ── Flujo normal ─────────────────────────────────────────────────────────
	if (!JSession::checkToken('post')) {
		cv_send_json_error('Token de seguridad invalido. Por favor, reintente.');
	}

	$result = ModFormularioCvHelper::processSubmission($app->input);
	cv_debug_log('Resultado de processSubmission: ' . json_encode($result, JSON_UNESCAPED_UNICODE));

	echo json_encode($result, JSON_UNESCAPED_UNICODE);
	$app->close();
} catch (Throwable $e) {
	cv_send_json_error('Excepcion: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
}
