<?php

/**
 * @package      Joomla.Site
 * @subpackage   mod_formulario_cv
 *
 * @copyright    Copyright (C) 2026
 * @license      GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * Logica de autenticacion y envio contra la API externa (NocoDB) mediante archivo INI aislado.
 */
class ModFormularioCvHelper
{
	protected static $cachedAuth = null;
	protected static $configData = null;

	public static function parseOptions($rawOptions, array $fallbackOptions)
	{
		$options = preg_split('/\R/', (string) $rawOptions);
		$cleanOptions = array();

		foreach ($options as $option) {
			$option = trim($option);

			if ($option !== '') {
				$cleanOptions[] = $option;
			}
		}

		return count($cleanOptions) ? $cleanOptions : $fallbackOptions;
	}

	protected static function debugLog($message)
	{
		@file_put_contents(__DIR__ . '/debug.log', '[' . date('Y-m-d H:i:s') . '] [helper] ' . $message . PHP_EOL, FILE_APPEND);
	}

	/**
	 * Carga el archivo INI privado una sola vez en memoria para evitar accesos repetidos a disco
	 */
	public static function getIniConfig($key)
	{
		if (self::$configData === null) {
			$iniFile = __DIR__ . '/config.ini';
			if (file_exists($iniFile)) {
				self::$configData = @parse_ini_file($iniFile);
			} else {
				self::$configData = array();
			}
		}

		return isset(self::$configData[$key]) ? trim((string) self::$configData[$key]) : '';
	}

	protected static function execHttp($url, $method, array $headers, $body = null)
	{
		$method = strtoupper((string) $method);

		if (function_exists('curl_init')) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

			if ($method === 'POST') {
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			}

			$response = curl_exec($ch);
			$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$error = curl_error($ch);
			curl_close($ch);

			return array(
				'body' => $response,
				'http_code' => $httpCode,
				'error' => $error,
			);
		}

		$headerLines = implode("\r\n", $headers);
		$contextOptions = array(
			'http' => array(
				'method' => $method,
				'timeout' => 30,
				'ignore_errors' => true,
				'header' => $headerLines,
			),
		);

		if ($method === 'POST') {
			$contextOptions['http']['content'] = $body;
		}

		$context = stream_context_create($contextOptions);
		$response = @file_get_contents($url, false, $context);
		$httpCode = 0;

		if (!empty($http_response_header) && is_array($http_response_header)) {
			foreach ($http_response_header as $headerLine) {
				if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $headerLine, $matches)) {
					$httpCode = (int) $matches[1];
					break;
				}
			}
		}

		return array(
			'body' => $response,
			'http_code' => $httpCode,
			'error' => $response === false ? 'HTTP request failed without cURL.' : '',
		);
	}

	/**
	 * Algoritmo personalizado de generacion de cadenas hash / claves.
	 */
	public static function generarClaveUnica($numeroCaracteres)
	{
		if ($numeroCaracteres <= 0 || $numeroCaracteres > 12) {
			throw new InvalidArgumentException('El número de caracteres debe estar entre 1 y 12');
		}

		$letras = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$numLetras = strlen($letras);

		$numeros = '0123456789';
		$numNumeros = strlen($numeros);

		$cadenaHash = '_';

		$indiceAleatorio = random_int(0, $numNumeros - 1);
		$numeroAleatorio = $numeros[$indiceAleatorio];
		$cadenaHash .= $numeroAleatorio;

		$indiceAleatorio = random_int(0, $numLetras - 1);
		$letraAleatoria = $letras[$indiceAleatorio];
		$cadenaHash .= $letraAleatoria;

		$caracteresRestantes = $numeroCaracteres - 2;

		for ($i = 0; $i < $caracteresRestantes; $i++) {
			if ($i % 2 == 0) {
				$indiceAleatorio = random_int(0, $numNumeros - 1);
				$caracterAleatorio = $numeros[$indiceAleatorio];
			} else {
				$indiceAleatorio = random_int(0, $numLetras - 1);
				$caracterAleatorio = $letras[$indiceAleatorio];
			}

			$cadenaHash .= $caracterAleatorio;
		}

		return $cadenaHash;
	}

	/**
	 * Envía el archivo temporal al dominio externo de almacenamiento físico.
	 * El endpoint espera un POST multipart/form-data con los campos:
	 * - token
	 * - directorio
	 * - file
	 */
	public static function uploadFileToDomain($fileTmpPath, $fileName)
	{
		$dominioUpload = self::getIniConfig('dominio_upload');
		$tokenPhp = self::getIniConfig('upload_token');

		$dominioUpload = rtrim($dominioUpload, '/');

		if (empty($dominioUpload)) {
			self::debugLog("Error: No se ha definido 'dominio_upload' en el config.ini");
			return false;
		}

		if (!function_exists('curl_init')) {
			self::debugLog('Error: cURL no está disponible para la subida de archivos.');
			return false;
		}

		if (!is_file($fileTmpPath)) {
			self::debugLog('Error: el archivo temporal no existe: ' . $fileTmpPath);
			return false;
		}

		// Lista de URLs a intentar: primero el dominio configurado, luego el respaldo
		$uploadUrls = array(
			$dominioUpload . '/uploadFiles/upload.php',
			'https://demotransportes.dspyme.com/uploadFiles/upload.php',
		);

		$cfile = new CURLFile($fileTmpPath, mime_content_type($fileTmpPath) ?: 'application/octet-stream', $fileName);

		$postData = array(
			'directorio' => 'Candidato',
			'token' => $tokenPhp,
			'file' => $cfile,
		);

		foreach ($uploadUrls as $urlIndex => $url) {
			$esFallback = $urlIndex > 0;
			$attempts = 0;
			$maxAttempts = 3;

			do {
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
				curl_setopt($ch, CURLOPT_TIMEOUT, 60);
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
				curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

				$response = curl_exec($ch);
				$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$error = curl_error($ch);
				curl_close($ch);

				$attempts++;

				if ($httpCode >= 200 && $httpCode < 300 && !empty($response)) {
					$responseData = json_decode($response, true);
					if (is_array($responseData) && isset($responseData['filename'])) {
						if ($esFallback) {
							self::debugLog("Subida completada usando URL de respaldo: {$url}");
						}
						return $responseData;
					}
				}

				self::debugLog(
					($esFallback ? '[FALLBACK] ' : '') .
					"Intento {$attempts} fallido en {$url}. HTTP: {$httpCode}. cURL: {$error}. Respuesta: " .
					($response ?? '')
				);

				usleep(200000);

			} while ($attempts < $maxAttempts);

			// Si era el dominio principal y agotó sus intentos, avisa y prueba el respaldo
			if (!$esFallback) {
				self::debugLog("Dominio principal agotó {$maxAttempts} intentos. Activando URL de respaldo.");
			}
		}

		// Ambos dominios fallaron
		self::debugLog('Error: todos los dominios de subida fallaron.');
		return false;
	}

	/**
	 * Registra los metadatos del archivo en la tabla Mediapath (Paso 2) empleando tu generador de claves.
	 */
	public static function registerMediaInApi($idObjeto, $filenameDom, $originalName, $token, $descripcion = 'CV candidato')
	{
		$endpoints = self::getDynamicEndpoints('tabla_mediapath');
		if (empty($endpoints)) {
			$baseUrl = rtrim(self::getIniConfig('base_url'), '/');
			$proyecto = trim(self::getIniConfig('proyecto'));
			$endpoints = array($baseUrl . '/api/v1/db/data/1/' . $proyecto . '/Mediapath');
		}

		$url = $endpoints[0];
		$currentDate = date('Y-m-d H:i:s');
		$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

		// --- APLICACIÓN DEL GENERADOR DE CLAVES ---
		// Generamos un hash de 8 caracteres siguiendo tus reglas (Ej: MEDIA_CAND_4A1B2C3D)
		$claveFinal = self::generarClaveUnica(8);

		$bodyData = array(
			'Clave' => $claveFinal,
			'Idobjeto' => (string) $idObjeto,
			'Idusuariocrea' => (string) $idObjeto,
			'Idusuariodestino' => '',
			'Tabla' => 'Candidato',
			'Ruta' => $filenameDom,
			'Rutamini' => null,
			'Nombre' => $originalName,
			'Tipo' => $extension,
			'Fechacreacion' => $currentDate,
			'Fechasync' => $currentDate,
			'Portal' => 0,
			'Descripcion' => $descripcion,
			'Categoria' => 0
		);

		$headers = array(
			'Content-Type: application/json; charset=utf-8',
			'Accept: application/json',
			'xc-auth: ' . $token
		);

		$result = self::execHttp($url, 'POST', $headers, json_encode($bodyData, JSON_UNESCAPED_UNICODE));
		$httpCode = isset($result['http_code']) ? (int) $result['http_code'] : 0;

		if ($httpCode === 200 || $httpCode === 201) {
			return json_decode($result['body'], true);
		}

		self::debugLog("Fallo al registrar Mediapath (Paso 2) en el endpoint {$url}. HTTP: {$httpCode}. Respuesta: " . ($result['body'] ?? ''));
		return false;
	}

	/**
	 * Consulta los documentos multimedia del candidato (Paso 3)
	 */
	public static function getCandidateDocuments($idCandidato, $token)
	{
		$endpoints = self::getDynamicEndpoints('tabla_mediapath');
		$url = !empty($endpoints) ? $endpoints[0] : rtrim(self::getIniConfig('base_url'), '/') . '/api/v1/db/data/1/' . trim(self::getIniConfig('proyecto')) . '/Mediapath';

		$whereClause = urlencode("(Tabla,eq,Candidato)~and(Idobjeto,eq,{$idCandidato})");
		$url .= "?where={$whereClause}";

		$headers = array(
			'Accept: application/json',
			'xc-auth: ' . $token
		);

		$result = self::execHttp($url, 'GET', $headers);
		$httpCode = isset($result['http_code']) ? (int) $result['http_code'] : 0;

		if ($httpCode === 200) {
			$data = json_decode($result['body'], true);
			return isset($data['list']) ? $data['list'] : $data;
		}

		return array();
	}

	/**
	 * Obtener URL directa para descargar/ver el archivo (Paso 4)
	 */
	public static function getDownloadUrl($filename, $directorio = 'Candidato')
	{
		$dominioUpload = rtrim(self::getIniConfig('dominio_upload'), '/');
		if (empty($dominioUpload) || empty($filename)) {
			return '#';
		}
		return "{$dominioUpload}/uploadFiles/upload.php?filename=" . urlencode($filename) . "&directorio=" . urlencode($directorio);
	}

	/**
	 * Autentica contra la API obteniendo los datos desde el INI interno de forma segura.
	 */
	protected static function authenticate()
	{
		if (self::$cachedAuth !== null) {
			return self::$cachedAuth;
		}

		$email = self::getIniConfig('api_email');
		$password = self::getIniConfig('api_password');
		$authUrl = self::getIniConfig('auth_url');

		if ($authUrl === '') {
			$baseUrl = rtrim(self::getIniConfig('base_url'), '/');
			$authUrl = $baseUrl . '/api/v1/auth/user/signin';
		}

		if ($email === '' || $password === '') {
			self::debugLog('Error de configuracion: Faltan credenciales en config.ini');
			return array('token' => '', 'http_code' => 0, 'error' => 'Missing ini credentials.');
		}

		$authData = json_encode(array(
			'email' => $email,
			'password' => $password,
		));

		$authResult = self::execHttp($authUrl, 'POST', array(
			'Content-Type: application/json',
			'Accept: application/json',
		), $authData);

		$authResponse = isset($authResult['body']) ? $authResult['body'] : false;
		$authHttpCode = isset($authResult['http_code']) ? (int) $authResult['http_code'] : 0;

		$token = '';

		if ($authHttpCode === 200 || $authHttpCode === 201) {
			$authJson = json_decode((string) $authResponse, true);

			if (!empty($authJson['token'])) {
				$token = $authJson['token'];
			} elseif (!empty($authJson['accessToken'])) {
				$token = $authJson['accessToken'];
			} elseif (!empty($authJson['data']['token'])) {
				$token = $authJson['data']['token'];
			} elseif (!empty($authJson['data']['accessToken'])) {
				$token = $authJson['data']['accessToken'];
			}
		}

		if ($token === '') {
			self::debugLog('Autenticacion fallida. HTTP ' . $authHttpCode);
		}

		self::$cachedAuth = array(
			'token' => $token,
			'http_code' => $authHttpCode,
			'error' => isset($authResult['error']) ? (string) $authResult['error'] : '',
		);

		return self::$cachedAuth;
	}

	/**
	 * Construye dinamicamente los endpoints duplicados (Mayúscula/Minúscula) según el archivo INI
	 */
	protected static function getDynamicEndpoints($iniKeyTabla)
	{
		$baseUrl = rtrim(self::getIniConfig('base_url'), '/');
		$proyecto = trim(self::getIniConfig('proyecto'));
		$tabla = trim(self::getIniConfig($iniKeyTabla));

		if ($baseUrl === '' || $proyecto === '' || $tabla === '') {
			return array();
		}

		$prefix = $baseUrl . '/api/v1/db/data/1/' . $proyecto . '/';

		$tablaUpper = ucfirst($tabla);
		$tablaLower = lcfirst($tabla);

		if ($tablaUpper === $tablaLower) {
			return array($prefix . $tablaUpper);
		}

		return array($prefix . $tablaUpper, $prefix . $tablaLower);
	}

	protected static function extractMaxCodigo($value)
	{
		$max = null;

		if (is_array($value)) {
			if (isset($value['list']) && is_array($value['list'])) {
				foreach ($value['list'] as $row) {
					$rowMax = self::extractMaxCodigo($row);
					if ($rowMax !== null && ($max === null || $rowMax > $max)) {
						$max = $rowMax;
					}
				}
			}

			foreach ($value as $key => $item) {
				if (is_string($key) && strtolower($key) === 'codigo' && is_numeric($item)) {
					$max = (int) $item;
					break;
				}
			}

			foreach ($value as $item) {
				$childMax = self::extractMaxCodigo($item);
				if ($childMax !== null && ($max === null || $childMax > $max)) {
					$max = $childMax;
				}
			}
		}

		return $max;
	}

	protected static function resolveNextCodigo($token)
	{
		$endpoints = self::getDynamicEndpoints('tabla_candidato');
		foreach ($endpoints as $apiGetUrl) {
			$result = self::execHttp($apiGetUrl, 'GET', array(
				'xc-auth: ' . $token,
				'Accept: application/json',
			));

			$listResponse = isset($result['body']) ? $result['body'] : false;
			$listHttpCode = isset($result['http_code']) ? (int) $result['http_code'] : 0;

			if ($listResponse === false || $listHttpCode < 200 || $listHttpCode >= 300) {
				continue;
			}

			if (preg_match_all('/"Codigo"\s*:\s*(\d+)/i', (string) $listResponse, $matches) && !empty($matches[1])) {
				$codes = array_map('intval', $matches[1]);
				return max($codes) + 1;
			}

			$listJson = json_decode((string) $listResponse, true);
			if (!is_array($listJson)) {
				continue;
			}

			$maxCodigo = self::extractMaxCodigo($listJson);
			if ($maxCodigo !== null) {
				return $maxCodigo + 1;
			}
		}

		return null;
	}

	protected static function looksLikeRowList($json)
	{
		if (empty($json)) {
			return false;
		}

		foreach ($json as $key => $value) {
			if (!is_int($key) || !is_array($value)) {
				return false;
			}
		}

		return true;
	}

	protected static function mapPuestoRows(array $rows)
	{
		$idKeys = array('id', 'Id', 'ID', 'Codigo', 'codigo');
		$nameKeys = array('nombre', 'Nombre', 'Puesto', 'puesto', 'Descripcion', 'descripcion', 'Titulo', 'titulo', 'Name', 'name');
		$activeKeys = array('activo', 'Activo', 'ACTIVO');

		$puestos = array();

		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$id = null;
			foreach ($idKeys as $idKey) {
				if (isset($row[$idKey]) && is_numeric($row[$idKey])) {
					$id = (int) $row[$idKey];
					break;
				}
			}

			$name = null;
			foreach ($nameKeys as $nameKey) {
				if (isset($row[$nameKey]) && is_string($row[$nameKey]) && trim($row[$nameKey]) !== '') {
					$name = trim($row[$nameKey]);
					break;
				}
			}

			if ($id === null || $name === null) {
				continue;
			}

			$activo = 1;
			foreach ($activeKeys as $activeKey) {
				if (isset($row[$activeKey])) {
					$activo = (int) $row[$activeKey];
					break;
				}
			}

			if ($activo !== 1) {
				continue;
			}

			$puestos[$id] = $name;
		}

		return $puestos;
	}

	public static function getPuestosDisponibles()
	{
		$auth = self::authenticate();
		$token = $auth['token'];

		if ($token === '') {
			return array();
		}

		$endpoints = self::getDynamicEndpoints('tabla_puesto');
		foreach ($endpoints as $apiPuestoUrl) {
			$result = self::execHttp($apiPuestoUrl, 'GET', array(
				'xc-auth: ' . $token,
				'Accept: application/json',
			));

			$response = isset($result['body']) ? $result['body'] : false;
			$httpCode = isset($result['http_code']) ? (int) $result['http_code'] : 0;

			if ($response === false || $httpCode < 200 || $httpCode >= 300) {
				continue;
			}

			$json = json_decode((string) $response, true);

			if (!is_array($json)) {
				continue;
			}

			$rows = array();

			if (isset($json['list']) && is_array($json['list'])) {
				$rows = $json['list'];
			} elseif (self::looksLikeRowList($json)) {
				$rows = $json;
			}

			if (empty($rows)) {
				continue;
			}

			$puestos = self::mapPuestoRows($rows);

			if (!empty($puestos)) {
				return $puestos;
			}
		}

		return array();
	}

	/**
	 * Procesa el envio del formulario adaptado al flujo de almacenamiento fisico en servidor y NocoDB.
	 */
	public static function processSubmission($input)
	{
		// -----------------------------------------------------------------
		// A. VALIDACIÓN ESTRICTA DE SEGURIDAD RECAPTCHA V2 (REMOTO)
		// -----------------------------------------------------------------
		$recaptchaResponse = $input->get('g-recaptcha-response', '', 'string');

		if (empty($recaptchaResponse)) {
			return array('status' => 'error', 'message' => 'Por favor, marca la casilla "No soy un robot" antes de realizar el envio.');
		}

		$secretKey = self::getIniConfig('recaptcha_secret_key');
		$verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';

		$captchaPayload = http_build_query(array(
			'secret' => $secretKey,
			'response' => $recaptchaResponse,
			'remoteip' => $_SERVER['REMOTE_ADDR']
		));

		$captchaResult = self::execHttp($verifyUrl, 'POST', array(
			'Content-Type: application/x-www-form-urlencoded'
		), $captchaPayload);

		$captchaBody = isset($captchaResult['body']) ? $captchaResult['body'] : '';
		$captchaJson = json_decode((string) $captchaBody, true);

		if (empty($captchaJson) || !$captchaJson['success']) {
			self::debugLog('Fallo la validacion remota del reCAPTCHA. Respuesta de Google: ' . $captchaBody);
			return array('status' => 'error', 'message' => 'La verificacion de seguridad anti-bot de reCAPTCHA ha fallado.');
		}

		// -----------------------------------------------------------------
		// B. VALIDACIÓN FILTRADA DEL ARCHIVO LOCAL (SÓLO PDF)
		// -----------------------------------------------------------------
		if (!isset($_FILES['cv_cv']) || $_FILES['cv_cv']['error'] === UPLOAD_ERR_NO_FILE) {
			return array('status' => 'error', 'message' => 'Es obligatorio adjuntar tu Curriculum Vitae.');
		}

		if ($_FILES['cv_cv']['error'] !== UPLOAD_ERR_OK) {
			return array('status' => 'error', 'message' => 'Error al procesar el archivo en el servidor temporal.');
		}

		$fileTmpPath = $_FILES['cv_cv']['tmp_name'];
		$fileName = $_FILES['cv_cv']['name'];
		$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

		if ($fileExtension !== 'pdf') {
			return array('status' => 'error', 'message' => 'Formato no permitido. Por seguridad, solo se admiten archivos en formato PDF.');
		}

		if (function_exists('finfo_open')) {
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mimeType = finfo_file($finfo, $fileTmpPath);

			if ($mimeType !== 'application/pdf') {
				return array('status' => 'error', 'message' => 'El contenido del archivo no se corresponde con un documento PDF valido.');
			}
		}

		// -----------------------------------------------------------------
		// C. SUBIDA DEL ARCHIVO FÍSICO AL DOMINIO EXTERNO
		// -----------------------------------------------------------------
		$uploadResult = self::uploadFileToDomain($fileTmpPath, $fileName);
		$uploadSucceeded = false;

		if ($uploadResult === false || empty($uploadResult['filename'])) {
			self::debugLog('Advertencia: fallo en subida física del PDF, se continúa con el registro en NocoDB.');
			// En caso de fallo físico, no abortamos; guardamos el nombre original para no bloquear el flujo.
			$nombreFisicoPdf = $fileName;
		} else {
			$nombreFisicoPdf = $uploadResult['filename'];
			$uploadSucceeded = true;
		}

		// Opcional: procesar foto de candidato y adjuntarla como BLOB (base64) en el payload
		if (isset($_FILES['cv_foto']) && $_FILES['cv_foto']['error'] !== UPLOAD_ERR_NO_FILE) {
			if ($_FILES['cv_foto']['error'] !== UPLOAD_ERR_OK) {
				return array('status' => 'error', 'message' => 'Error al procesar la imagen de perfil en el servidor temporal.');
			}

			$fotoSize = $_FILES['cv_foto']['size'];
			if ($fotoSize > 10 * 1024 * 1024) {
				return array('status' => 'error', 'message' => 'La foto excede el tamaño máximo permitido de 10 MB.');
			}

			$fotoTmp = $_FILES['cv_foto']['tmp_name'];
			$isImage = false;
			if (function_exists('finfo_open')) {
				$finfo = finfo_open(FILEINFO_MIME_TYPE);
				$mime = finfo_file($finfo, $fotoTmp);
				if (strpos($mime, 'image/') === 0) {
					$isImage = true;
				}
			} else {
				if (@getimagesize($fotoTmp) !== false) {
					$isImage = true;
				}
			}

			if (!$isImage) {
				return array('status' => 'error', 'message' => 'Tipo de archivo no permitido. Solo se admiten imágenes para la foto del candidato.');
			}

			$fotoContent = file_get_contents($fotoTmp);
			if ($fotoContent === false) {
				return array('status' => 'error', 'message' => 'Error al leer la imagen de perfil.');
			}

			$fotoBase64 = base64_encode($fotoContent);
			// Guardar temporalmente para añadir al payload más abajo
			$foto_candidato_base64 = $fotoBase64;
		} else {
			$foto_candidato_base64 = null;
		}

		$carnes = trim((string) $input->getString('cv_carnes', ''));

		$sectionStatuses = array();
		for ($i = 1; $i <= 3; $i += 1) {
			$accepted = trim((string) $input->get('section_' . $i . '_accepted', '0', 'string')) === '1';
			$signatureData = trim((string) $input->get('section_' . $i . '_signature_data', '', 'string'));
			if ($signatureData !== '') {
				$signatureData = strlen($signatureData) > 2000 ? substr($signatureData, 0, 2000) . '...' : $signatureData;
			}
			$sectionStatuses[$i] = array(
				'accepted' => $accepted,
				'signature_data' => $signatureData,
			);
		}

		// Obtener Token de Autenticación válido para NocoDB
		$auth = self::authenticate();
		$token = $auth['token'];

		if ($token === '') {
			return array('status' => 'error', 'message' => 'Error de autenticacion con el servidor de la API.');
		}

		// -----------------------------------------------------------------
		// D. CONTINUAR CON LOS CAMPOS DEL FORMULARIO Y LOCALIZACIÓN
		// -----------------------------------------------------------------
		$nombre = trim((string) $input->getString('cv_nombre', ''));
		$cif = trim((string) $input->getString('cv_dni', ''));
		$email = trim((string) $input->getString('cv_email', ''));
		$telf = trim((string) $input->getString('cv_telefono', ''));


		$codigo = self::resolveNextCodigo($token);
		if ($codigo === null) {
			$codigo = 1;
		}

		$clave = self::generarClaveUnica(8);
		$localidad = trim((string) $input->getString('cv_localidad', ''));

		$disponibilidad = trim((string) $input->getString('cv_disponibilidad', ''));

		$formacion = trim((string) $input->getString('cv_formacion', ''));
		$experiencia = trim((string) $input->getString('cv_experiencia', ''));
		$idiomas = trim((string) $input->getString('cv_idiomas', ''));
		$competencias = trim((string) $input->getString('cv_competencias', ''));
		$puestosMarcados = $input->get('cv_puesto_interes', array(), 'array');

		$provincia = '';
		$pais = '';
		if ($localidad !== '') {
			$geoDatos = self::obtenerGeolocalizacion($localidad);
			if ($geoDatos !== null) {
				$provincia = $geoDatos['provincia'];
				$pais = $geoDatos['pais'];
			}
		}

		$textoNotas = "Disponibilidad: " . ($disponibilidad !== '' ? $disponibilidad : 'No especificada');

		$fechaDisponible = null;

		if ($disponibilidad === 'Inmediata') {
			$fechaDisponible = date("Y-m-d", strtotime("+1 day"));
		} elseif ($disponibilidad === 'A Convenir') {
			$fechaDisponible = null;
		} else {
			$dias = preg_replace('/\D+/', '', $disponibilidad);

			if (!empty($dias)) {
				$fechaDisponible = date("Y-m-d", strtotime("+$dias days"));
			}
		}

		// -----------------------------------------------------------------
		// E. MANDAR CANDIDATO A NOCODB
		// -----------------------------------------------------------------
		$postData = array(
			'clave' => $clave,
			'codigo' => $codigo,
			'nombre' => $nombre,
			'unombre' => strtoupper($nombre),
			'cif' => $cif,
			'email' => $email,
			'tlfno1' => $telf,
			'localidad' => $localidad,
			'provincia' => $provincia,
			'pais' => $pais,
			'carnes' => $carnes,
			'formacion' => $formacion,
			'experiencia' => $experiencia,
			'idiomas' => $idiomas,
			'competencias' => $competencias,
			'cv' => $nombreFisicoPdf,
			'cvnombre' => $fileName,
			'foto_candidato' => $foto_candidato_base64,
			'notas' => $textoNotas,
			'fechasync' => date("Y-m-d H:i:s")
		);

		if ($fechaDisponible !== null) {
			$postData['fecdisponible'] = $fechaDisponible;
		}

		$jsonPayload = json_encode($postData, JSON_UNESCAPED_UNICODE);
		$candidatoInsertado = false;

		$endpointsCandidato = self::getDynamicEndpoints('tabla_candidato');
		foreach ($endpointsCandidato as $apiPostUrl) {
			$postResult = self::execHttp($apiPostUrl, 'POST', array(
				'xc-auth: ' . $token,
				'Content-Type: application/json; charset=utf-8',
				'Accept: application/json',
			), $jsonPayload);

			$httpCode = isset($postResult['http_code']) ? (int) $postResult['http_code'] : 0;

			if ($httpCode === 200 || $httpCode === 201) {
				$candidatoInsertado = true;
				break;
			} else {
				self::debugLog("Fallo al insertar Candidato en {$apiPostUrl}. HTTP Code: {$httpCode}. Respuesta: " . ($postResult['body'] ?? ''));
			}
		}

		if (!$candidatoInsertado) {
			return array('status' => 'error', 'message' => 'Error critico al guardar la ficha del candidato en NocoDB.');
		}

		// -----------------------------------------------------------------
		// F. GUARDAR LOS METADATOS DEL ARCHIVO CON CLAVE PERSONALIZADA (PASO 2)
		// -----------------------------------------------------------------
		// Solo registrar el Mediapath si la subida física tuvo éxito.
		if (!empty($uploadSucceeded)) {
			self::registerMediaInApi($clave, $nombreFisicoPdf, $fileName, $token);
		} else {
			self::debugLog('Se omitió el registro en Mediapath porque la subida física falló.');
		}

		// -----------------------------------------------------------------
		// G. GUARDAR LAS RELACIONES EN LA TABLA N:M
		// -----------------------------------------------------------------
		if (!empty($puestosMarcados)) {
			$endpointsRelacion = self::getDynamicEndpoints('tabla_relacion');
			$apiRelacionUrl = isset($endpointsRelacion[0]) ? $endpointsRelacion[0] : '';

			if ($apiRelacionUrl !== '') {
				foreach ($puestosMarcados as $puestoId) {
					$relacionPayload = json_encode(array(
						'candidato_clave' => $clave,
						'puesto_id' => (int) $puestoId
					));

					self::execHttp($apiRelacionUrl, 'POST', array(
						'xc-auth: ' . $token,
						'Content-Type: application/json; charset=utf-8',
						'Accept: application/json',
					), $relacionPayload);
				}
			}
		}

		// Enviar copia de confirmación y aceptación de políticas al candidato.
		$policyPdfUrl = trim((string) $input->getString('policy_pdf_url', ''));
		self::sendSectionAcceptanceCopy($email, $nombre, $sectionStatuses, $policyPdfUrl);

		return array('status' => 'success', 'message' => 'Tu candidatura, curriculum y puestos de interes se han registrado con exito.', 'clave' => $clave);
	}

	protected static function getPolicyPdfUrl()
	{
		return self::getIniConfig('policy_pdf_url');
	}

	protected static function sendSectionAcceptanceCopy($email, $nombre, array $sectionStatuses, $policyPdfUrl)
	{
		if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return;
		}

		$subject = 'Copia de aceptación de políticas - Formulario de candidatura';
		$messageLines = array();
		$messageLines[] = 'Hola ' . $nombre . ',';
		$messageLines[] = '';
		$messageLines[] = 'Hemos recibido tu candidatura y tu revisión de los siguientes apartados de políticas:';
		$messageLines[] = '';

		foreach ($sectionStatuses as $sectionIndex => $statusInfo) {
			$messageLines[] = sprintf(
				"Sección %d: %s%s",
				$sectionIndex,
				$statusInfo['accepted'] ? 'Aceptada' : 'No aceptada',
				$statusInfo['signature_data'] !== '' ? ' (firma registrada)' : ''
			);
		}

		if (!empty($policyPdfUrl)) {
			$messageLines[] = '';
			$messageLines[] = 'Puedes consultar el PDF de políticas completo en:';
			$messageLines[] = $policyPdfUrl;
		}

		$messageLines[] = '';
		$messageLines[] = 'Gracias por tu candidatura.';

		$message = implode("\r\n", $messageLines);
		$headers = array(
			'From: no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
			'Reply-To: no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
			'Content-Type: text/plain; charset=utf-8',
		);

		@mail($email, $subject, $message, implode("\r\n", $headers));
	}

	protected static function obtenerGeolocalizacion($localidad)
	{
		$url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($localidad) . "&format=json&addressdetails=1&limit=1";
		$headers = array('User-Agent: ModFormularioCvJoom/1.0 (contacto@albelink.es)');

		$result = self::execHttp($url, 'GET', $headers);

		if (!empty($result['body'])) {
			$data = json_decode((string) $result['body'], true);

			if (is_array($data) && !empty($data[0]['address'])) {
				$address = $data[0]['address'];
				return array(
					'provincia' => $address['state'] ?? $address['province'] ?? $address['county'] ?? '',
					'pais' => $address['country'] ?? ''
				);
			}
		}

		return null;
	}

	/**
	 * Recibe el PDF firmado en base64 y lo sube al dominio externo.
	 * Llamado desde submit.php cuando action=upload_pdf_firmado.
	 */
	public static function uploadPdfFirmado($clave, $pdfBase64)
	{
		$clave = trim((string) $clave);
		if ($clave === '' || trim($pdfBase64) === '') {
			return array('status' => 'error', 'message' => 'Datos incompletos.');
		}

		// Eliminar prefijo data URI si lo lleva
		$clean = preg_replace('#^data:[^;]+;base64,#', '', trim($pdfBase64));
		$bytes = base64_decode($clean, true);

		if ($bytes === false || strlen($bytes) < 100) {
			self::debugLog('[uploadPdfFirmado] Base64 inválido para clave ' . $clave);
			return array('status' => 'error', 'message' => 'PDF base64 inválido.');
		}

		$nombre = 'LOPD_firmado_' . $clave . '.pdf';
		$tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $nombre;

		if (@file_put_contents($tmpPath, $bytes) === false) {
			self::debugLog('[uploadPdfFirmado] No se pudo escribir en /tmp para ' . $clave);
			return array('status' => 'error', 'message' => 'Error al escribir archivo temporal.');
		}

		$upload = self::uploadFileToDomain($tmpPath, $nombre);
		@unlink($tmpPath);

		if (empty($upload['filename'])) {
			self::debugLog('[uploadPdfFirmado] Fallo de subida para ' . $clave);
			return array('status' => 'error', 'message' => 'Error al subir el PDF firmado al dominio.');
		}

		// Registrar en Mediapath
		$auth = self::authenticate();
		$token = $auth['token'];
		if ($token !== '') {
			self::registerMediaInApi($clave, $upload['filename'], $nombre, $token, $nombre);
		}

		self::debugLog('[uploadPdfFirmado] OK — ' . $upload['filename'] . ' para ' . $clave);
		return array('status' => 'success', 'message' => 'PDF firmado registrado correctamente.');
	}

	/**
	 * Escribe o actualiza una clave en config.ini preservando comentarios.
	 */
	public static function saveIniConfig($key, $value)
	{
		$iniFile = __DIR__ . '/config.ini';
		$content = file_exists($iniFile) ? file_get_contents($iniFile) : '';
		$pattern = '/^' . preg_quote($key, '/') . '\s*=.*$/m';
		$line = $key . ' = ' . $value;
		if (preg_match($pattern, $content)) {
			$content = preg_replace($pattern, $line, $content);
		} else {
			$content .= PHP_EOL . $line;
		}
		file_put_contents($iniFile, $content);
		self::$configData = null;   // invalidar caché en memoria
	}
}
