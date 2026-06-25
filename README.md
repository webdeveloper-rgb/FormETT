# 📋 FormETT — Módulo de Formulario de Candidatura para Joomla

> Módulo Joomla (`mod_formulario_cv`) para la captación de candidatos ETT: recoge datos personales, CV en PDF, foto de perfil, firma digital de políticas en un documento PDF multipágina y sincroniza todo con una base de datos NocoDB a través de su API REST.

---

## 📑 Tabla de Contenidos

- [Descripción general](#descripción-general)
- [Arquitectura y flujo de datos](#arquitectura-y-flujo-de-datos)
- [Estructura del repositorio](#estructura-del-repositorio)
- [Instalación](#instalación)
- [Configuración — `config.ini`](#configuración--configini)
  - [Conexión API y autenticación](#conexión-api-y-autenticación)
  - [Tablas de base de datos](#tablas-de-base-de-datos)
  - [reCAPTCHA](#recaptcha)
  - [Personalización estética](#personalización-estética)
  - [Coordenadas PDF *(clave del sistema)*](#coordenadas-pdf-clave-del-sistema)
- [Calibrador visual de PDF](#calibrador-visual-de-pdf--pdf-calibratorphp)
- [Referencia de métodos — `helper.php`](#referencia-de-métodos--helperphp)
- [Endpoint AJAX — `submit.php`](#endpoint-ajax--submitphp)
- [Punto de entrada del módulo — `mod_formulario_cv.php`](#punto-de-entrada-del-módulo--mod_formulario_cvphp)
- [Depuración](#depuración)
- [Seguridad](#seguridad)
- [Requisitos](#requisitos)

---

## Descripción general

**FormETT** es un módulo Joomla diseñado para empresas de trabajo temporal (ETT) que necesitan digitalizar su proceso de captación de candidatos. El flujo completo cubre:

1. El candidato rellena el formulario (datos personales, formación, experiencia, idiomas, competencias, carné de conducir, disponibilidad y puestos de interés).
2. Adjunta su CV en PDF y, opcionalmente, una foto de perfil.
3. Lee y firma digitalmente un documento PDF de políticas (LOPD/RGPD) con firma manuscrita sobre lienzo canvas, marcando las casillas de consentimiento en las páginas correspondientes.
4. El servidor valida el reCAPTCHA, sube el PDF al dominio de almacenamiento externo, registra al candidato en NocoDB, vincula los metadatos del archivo en la tabla `Mediapath` y guarda las relaciones N:M candidato-puesto.
5. El candidato recibe un correo de confirmación con el estado de aceptación de cada sección de políticas.

---

## Arquitectura y flujo de datos

```
Navegador (index.html / tmpl/)
        │
        │  POST AJAX (multipart/form-data)
        ▼
   submit.php  ──── valida token Joomla & reCAPTCHA
        │
        ▼
   helper.php  (clase ModFormularioCvHelper)
        ├── authenticate()          → NocoDB /api/v1/auth/user/signin
        ├── uploadFileToDomain()    → dominio_upload /uploadFiles/upload.php
        ├── processSubmission()
        │       ├── resolveNextCodigo()  → GET tabla Candidato (máximo código)
        │       ├── POST tabla Candidato
        │       ├── registerMediaInApi() → POST tabla Mediapath
        │       ├── POST tabla CandidatoPuestos (N:M)
        │       ├── obtenerGeolocalizacion() → Nominatim OSM
        │       └── sendSectionAcceptanceCopy() → mail()
        └── uploadPdfFirmado()      → sube PDF con firma canvas al dominio externo
                └── registerMediaInApi() → registra en Mediapath con descripción 'LOPD firmado'

   config.ini  ←── pdf-calibrator.php (escribe coordenadas en tiempo real)
```

---

## Estructura del repositorio

```
FormETT/
├── config.ini               # Configuración centralizada (API, colores, coords PDF)
├── helper.php               # Clase principal con toda la lógica de negocio
├── mod_formulario_cv.php    # Punto de entrada Joomla: carga CSS/JS y layout
├── mod_formulario_cv.xml    # Manifiesto XML del módulo para Joomla
├── manifest.xml             # Manifiesto alternativo / legado
├── submit.php               # Endpoint AJAX con sistema de diagnóstico incorporado
├── pdf-calibrator.php       # Herramienta visual protegida para calibrar coords PDF
├── index.html               # Página de índice vacía (seguridad: evita listado de dir.)
├── language/                # Ficheros de idioma del módulo
├── media/
│   ├── css/formulario-cv.css
│   ├── js/formulario-cv.js
│   └── documento_base.pdf   # PDF plantilla de políticas a firmar
└── tmpl/
    └── default.php          # Layout principal del formulario
```

---

## Instalación

1. Comprime el contenido como `mod_formulario_cv.zip` (la carpeta raíz debe llamarse `mod_formulario_cv`).
2. En el back-end de Joomla ve a **Extensiones → Instalar** y sube el ZIP.
3. Activa el módulo y asígnalo a la posición deseada.
4. Rellena el `config.ini` con tus credenciales (ver sección siguiente).
5. Asegúrate de que el servidor web tiene **permisos de escritura** sobre `config.ini` para que el calibrador pueda guardar coordenadas.
6. Verifica que la extensión **cURL** de PHP está activa (necesaria para la subida de archivos y llamadas a la API).

---

## Configuración — `config.ini`

El archivo `config.ini` es el **núcleo de configuración** del módulo. Se lee una sola vez en memoria por petición gracias al método `getIniConfig()` con caché estática, evitando accesos redundantes a disco.

### Conexión API y autenticación

```ini
base_url      = "https://api.dominio.com"
auth_url      = "https://api.dominio.com/api/v1/auth/user/signin"
api_email     = "usuario_api"
api_password  = "contraseña_usuario_api"
dominio_upload = "https://nombredominio.dspyme.com"
proyecto      = "NombreProyecto"
upload_token  = "b7f3d9a4-2c6e-437b-a1f9-58d1e4c9e8b2"
```

| Clave | Descripción |
|---|---|
| `base_url` | URL raíz de la instancia NocoDB |
| `auth_url` | Endpoint de login NocoDB. Si se omite se construye automáticamente desde `base_url` |
| `api_email` / `api_password` | Credenciales del usuario técnico en NocoDB |
| `dominio_upload` | Servidor externo donde se almacenan físicamente los archivos PDF y fotos |
| `proyecto` | Nombre exacto del proyecto NocoDB (sensible a mayúsculas) |
| `upload_token` | Token de autorización para el endpoint de subida de archivos físicos |

### Tablas de base de datos

```ini
tabla_candidato = "Candidato"
tabla_puesto    = "Puesto"
tabla_relacion  = "CandidatoPuestos"
```

> ⚠️ **No modificar** estas claves a menos que se hayan renombrado las tablas en NocoDB. El módulo construye endpoints dinámicos probando tanto la versión en mayúscula como en minúscula del nombre de tabla para mayor robustez.

### reCAPTCHA

```ini
recaptcha_site_key   = "Clave Publica Captcha"
recaptcha_secret_key = "Clave Secreta Captcha"
```

El módulo utiliza **Google reCAPTCHA v2** ("No soy un robot"). La clave pública se inyecta en el frontend y la clave secreta se usa en el servidor para verificar el token con `https://www.google.com/recaptcha/api/siteverify`.

### Personalización estética

Todas las variables CSS del formulario se controlan desde el INI, por lo que no es necesario editar CSS para cambiar la identidad visual:

```ini
cv_bg          = "rgba(248, 250, 252, 0.92)"   ; Fondo exterior
cv_panel       = "#ffffff"                       ; Panel principal
cv_text        = "#0b1a30"                       ; Texto principal
cv_muted       = "#4a5a70"                       ; Texto secundario
cv_line        = "#d3dae3"                       ; Bordes
cv_accent      = "#c9931b"                       ; Color de énfasis (botones, foco)
cv_accent_dark = "#a47506"                       ; Hover de énfasis
cv_accent_soft = "rgba(201, 147, 27, 0.08)"     ; Fondo sutil de acento
cv_warning     = "#a95712"                       ; Alertas y advertencias
```

### Coordenadas PDF *(clave del sistema)*

Esta es la sección más importante para el correcto funcionamiento de la firma digital. Define con precisión en qué punto exacto de cada página del PDF se estampará la firma canvas del candidato, las casillas de consentimiento y la fecha.

Las coordenadas siguen el **sistema de coordenadas PDF nativo**: el origen `(0,0)` está en la esquina **inferior izquierda** de la página, y el eje Y crece hacia arriba. Las unidades son **puntos tipográficos** (1 pt ≈ 0,353 mm).

```ini
; ── Asignación de páginas (índice 0 = primera página) ──────────────────────
pdf_coord_firma_page  = 0    ; Página donde se incrusta la firma principal
pdf_coord_check_page  = 0    ; Página donde se marcan las casillas de consentimiento
pdf_coord_firma5_page = 4    ; Página del bloque LOPD (firma secundaria + check + fecha)

; ── Firma principal (Página 1) ──────────────────────────────────────────────
pdf_coord_firma_p1_x  = 77      ; X de la esquina inferior-izquierda del recuadro
pdf_coord_firma_p1_y  = 161.2   ; Y de la esquina inferior-izquierda del recuadro
pdf_coord_firma_p1_w  = 240     ; Anchura del recuadro de firma en puntos
pdf_coord_firma_p1_h  = 65      ; Altura del recuadro de firma en puntos

; ── Casillas de consentimiento (Página de checks) ───────────────────────────
pdf_coord_check1_x    = 49.7    ; X de la casilla nº 1
pdf_coord_check1_y    = 343.9   ; Y de la casilla nº 1
pdf_coord_check2_x    = 49      ; X de la casilla nº 2
pdf_coord_check2_y    = 302.6   ; Y de la casilla nº 2
pdf_coord_check3_x    = 49.7    ; X de la casilla nº 3
pdf_coord_check3_y    = 261.2   ; Y de la casilla nº 3

; ── Bloque LOPD — Firma secundaria (Página 5) ───────────────────────────────
pdf_coord_firma_p5_x  = 75.7    ; X esquina inferior-izquierda
pdf_coord_firma_p5_y  = 273.2   ; Y esquina inferior-izquierda
pdf_coord_firma_p5_w  = 240     ; Anchura del recuadro
pdf_coord_firma_p5_h  = 65      ; Altura del recuadro

; ── Bloque LOPD — Casilla y fecha ───────────────────────────────────────────
pdf_coord_check_p5_x  = 51      ; X de la casilla LOPD
pdf_coord_check_p5_y  = 369.9   ; Y de la casilla LOPD
pdf_coord_fecha_p5_x  = 83.7    ; X donde se escribe la fecha de firma
pdf_coord_fecha_p5_y  = 242.6   ; Y donde se escribe la fecha de firma
```

> 💡 **Nunca edites estas coordenadas a mano.** Usa siempre el calibrador visual `pdf-calibrator.php` para garantizar precisión.

---

## Calibrador visual de PDF — `pdf-calibrator.php`

Herramienta de administración protegida por contraseña que permite colocar visualmente cada marca sobre el PDF plantilla y guardar las coordenadas resultantes directamente en `config.ini`, sin tocar código.

### Acceso

```
https://tudominio.com/modules/mod_formulario_cv/pdf-calibrator.php?admin_pass=TU_CLAVE
```

La contraseña se define en `config.ini` con la clave `calibrator_pass`. Una vez autenticado, la sesión PHP persiste para evitar incluir la contraseña en la URL en cada recarga.

### Funcionamiento interno

1. **Carga del PDF**: Usa `pdf.js` (pdfjs-dist 3.11) para renderizar el documento en un `<canvas>` interactivo con cursor en cruz. Si hay un error CORS al cargar el PDF directamente, la herramienta usa automáticamente un proxy PHP interno (`?proxy_pdf=ruta`) que sirve el archivo con el header `Content-Type: application/pdf`.

2. **Modos de calibración**: Cada modo corresponde a un campo del PDF. El usuario activa el modo deseado (por ejemplo, "Firma principal") y hace clic en el punto exacto del canvas donde debe aparecer ese elemento.

   | Modo | Campo en config.ini | Color indicador |
   |---|---|---|
   | `firma_p1` | `pdf_coord_firma_p1_x/y` | Azul |
   | `check1` | `pdf_coord_check1_x/y` | Verde |
   | `check2` | `pdf_coord_check2_x/y` | Verde |
   | `check3` | `pdf_coord_check3_x/y` | Verde |
   | `firma_p5` | `pdf_coord_firma_p5_x/y` | Violeta |
   | `check_p5` | `pdf_coord_check_p5_x/y` | Ámbar |
   | `fecha_p5` | `pdf_coord_fecha_p5_x/y` | Rojo |

3. **Conversión de coordenadas**: El clic del usuario está en coordenadas de pantalla (canvas, con Y desde arriba). El calibrador convierte esas coordenadas al sistema PDF (Y desde abajo) usando la fórmula:
   ```
   pdfX = canvasX / scale
   pdfY = alturaPageEnPuntos - (canvasY / scale)
   ```

4. **Overlay de marcas**: Tras cada clic, el canvas de superposición (`overlayCanvas`) redibuja todos los elementos posicionados: rectángulos semitransparentes para las firmas y puntos de color para las casillas, siempre respecto a la página actualmente visible.

5. **Previsualización con pdf-lib**: El botón "Previsualizar marcas en PDF" usa `pdf-lib` (1.17.1) para incrustar las marcas directamente en el PDF y abrirlo en una nueva pestaña. Esto permite ver el resultado final antes de guardar.

6. **Guardado en config.ini**: Al pulsar "Guardar en config.ini", se hace un `fetch` POST a la misma URL con `action=save_coords`. El servidor PHP lee el archivo INI línea a línea, sustituye las claves existentes (sin romper comentarios ni otras secciones) y añade al final las que no existían. La operación es atómica a nivel de `file_put_contents`.

> ⚠️ **Elimina o restringe el acceso a este archivo en producción** una vez completada la calibración.

---

## Referencia de métodos — `helper.php`

La clase `ModFormularioCvHelper` concentra toda la lógica de negocio. A continuación se detalla cada método público y protegido.

---

### `getIniConfig(string $key): string`

Lee el archivo `config.ini` desde disco y lo almacena en la propiedad estática `$configData`. Las llamadas posteriores devuelven el valor desde memoria sin acceder de nuevo al disco. Devuelve cadena vacía si la clave no existe.

---

### `authenticate(): array`

Obtiene un token JWT de NocoDB mediante `POST` al endpoint `auth_url` con las credenciales del INI. El resultado se cachea en `$cachedAuth` durante el ciclo de vida de la petición, evitando múltiples llamadas de autenticación.

El método soporta tres estructuras de respuesta distintas (`token`, `accessToken`, `data.token`, `data.accessToken`) para ser compatible con diferentes versiones de NocoDB.

Devuelve un array con las claves `token`, `http_code` y `error`.

---

### `execHttp(string $url, string $method, array $headers, mixed $body = null): array`

Capa de abstracción HTTP con doble implementación:

- **Primaria con cURL**: Usa `curl_init()` con timeouts de 15 s (conexión) y 30 s (total). Admite GET y POST.
- **Fallback con `file_get_contents`**: Se activa automáticamente si cURL no está disponible en el servidor. Parsea el código HTTP desde `$http_response_header`.

Devuelve `['body' => string, 'http_code' => int, 'error' => string]`.

---

### `getDynamicEndpoints(string $iniKeyTabla): array`

Construye las URLs de endpoint de NocoDB combinando `base_url`, `proyecto` y el nombre de tabla leído del INI. Genera **dos variantes** (primera letra en mayúscula y en minúscula) para tolerar inconsistencias en el nombre real de la tabla en NocoDB.

Ejemplo: si `tabla_candidato = "Candidato"`, devuelve:
```
["https://api.dominio.com/api/v1/db/data/1/NombreProyecto/Candidato",
 "https://api.dominio.com/api/v1/db/data/1/NombreProyecto/candidato"]
```

---

### `generarClaveUnica(int $numeroCaracteres): string`

Generador personalizado de identificadores alfanuméricos con estructura determinista. Produce cadenas del tipo `_4A1B2C3D` siguiendo estas reglas:

- Comienza siempre con `_`.
- El segundo carácter es siempre un **dígito** aleatorio.
- El tercer carácter es siempre una **letra mayúscula** aleatoria.
- A partir del cuarto carácter, alterna dígito / letra.
- Usa `random_int()` (criptográficamente seguro) en lugar de `rand()`.

Se usa internamente en `registerMediaInApi()` para generar la clave única de cada registro en la tabla `Mediapath`.

Lanza `InvalidArgumentException` si `$numeroCaracteres` está fuera del rango [1, 12].

---

### `resolveNextCodigo(string $token): int|null`

Determina el próximo código numérico de candidato consultando la tabla `Candidato` en NocoDB y extrayendo el valor máximo del campo `Codigo` mediante el método auxiliar `extractMaxCodigo()`. Itera sobre todos los endpoints dinámicos hasta obtener una respuesta válida.

- Si NocoDB devuelve resultados, devuelve `max(Codigo) + 1`.
- Si no hay candidatos o falla la consulta, devuelve `null` (y `processSubmission()` usa `1` como fallback).

---

### `extractMaxCodigo(mixed $value): int|null`

Método recursivo que navega por cualquier estructura JSON anidada (arrays, objetos `list`, respuestas paginadas) buscando la clave `codigo` (insensible a mayúsculas) con el valor numérico máximo. Garantiza la correcta detección del siguiente código incluso si NocoDB cambia el formato de respuesta.

---

### `uploadFileToDomain(string $fileTmpPath, string $fileName): array|false`

Sube el archivo PDF o foto al servidor de almacenamiento físico externo (`dominio_upload`) mediante un POST multipart/form-data con los campos:

- `token`: el `upload_token` del INI.
- `directorio`: fijo a `"Candidato"`.
- `file`: el archivo como `CURLFile` con su MIME type real.

Implementa un **sistema de reintentos automático**: hasta 3 intentos con 200 ms de espera entre ellos. Registra en `debug.log` el resultado de cada intento fallido.

Requiere cURL. Devuelve el array JSON del servidor externo (que debe incluir la clave `filename`) o `false` en caso de fallo total.

---

### `registerMediaInApi(mixed $idObjeto, string $filenameDom, string $originalName, string $token, string $descripcion): array|false`

Registra los metadatos del archivo subido en la tabla `Mediapath` de NocoDB. El payload enviado incluye:

| Campo NocoDB | Valor |
|---|---|
| `Clave` | Hash de 8 caracteres generado con `generarClaveUnica()` |
| `Idobjeto` | Clave del candidato (ej: `M00000001`) |
| `Tabla` | `"Candidato"` |
| `Ruta` | Nombre físico del archivo en el servidor externo |
| `Nombre` | Nombre original del archivo subido por el usuario |
| `Tipo` | Extensión del archivo (`pdf`, `jpg`, etc.) |
| `Descripcion` | `"CV candidato"` o `"LOPD firmado"` según el caso |
| `Fechacreacion` / `Fechasync` | Timestamp actual en formato `Y-m-d H:i:s` |

---

### `getCandidateDocuments(mixed $idCandidato, string $token): array`

Consulta la tabla `Mediapath` filtrando por `Tabla = 'Candidato'` e `Idobjeto = $idCandidato` usando la sintaxis de filtros de NocoDB (`?where=(campo,eq,valor)~and(campo,eq,valor)`). Devuelve el array `list` de la respuesta o array vacío en caso de error.

---

### `getDownloadUrl(string $filename, string $directorio = 'Candidato'): string`

Construye la URL pública para descargar o visualizar un archivo almacenado en el dominio externo. El formato resultante es:
```
{dominio_upload}/uploadFiles/upload.php?filename=ARCHIVO&directorio=Candidato
```

---

### `getPuestosDisponibles(): array`

Recupera los puestos activos desde la tabla `Puesto` de NocoDB para poblar dinámicamente el selector de puestos de interés en el formulario. Itera sobre los endpoints dinámicos y usa `mapPuestoRows()` para normalizar la respuesta, soportando múltiples nombrados posibles de los campos (`Nombre`, `Puesto`, `Descripcion`, `Titulo`, etc.).

Solo devuelve puestos cuyo campo `Activo` sea igual a `1`.

---

### `mapPuestoRows(array $rows): array`

Normaliza las filas de la tabla de puestos. Prueba en orden una lista de posibles nombres de campo para `id` y `nombre`, lo que permite que el módulo funcione sin configuración adicional aunque las columnas de NocoDB tengan nombres distintos al esperado.

---

### `processSubmission(JInput $input): array`

Orquesta el flujo completo de envío del formulario en siete fases bien diferenciadas:

**A. Validación reCAPTCHA** — Verifica el token con la API de Google. Si falla, aborta con mensaje de error antes de realizar ninguna otra operación.

**B. Validación del archivo CV** — Comprueba que el archivo existe, que no tiene error de subida, que la extensión es `.pdf` y, si `finfo` está disponible, que el MIME type real es `application/pdf` (evita la subida de archivos renombrados).

**C. Subida física del CV** — Llama a `uploadFileToDomain()`. Si falla la subida, el flujo **no se interrumpe**: se usa el nombre original como fallback y se marca `uploadSucceeded = false` para omitir el registro en Mediapath.

**D. Procesamiento de la foto de perfil** — Si se adjuntó una foto, valida su MIME type como imagen (`image/*`), la lee y la convierte a base64 para enviarla como campo `foto_candidato` en el payload del candidato. Rechaza archivos mayores de 10 MB.

**E. Geolocalización automática** — Si el candidato indicó localidad, llama a `obtenerGeolocalizacion()` para enriquecer los datos con `provincia` y `pais` usando la API pública de Nominatim (OpenStreetMap), sin coste adicional.

**F. Inserción del candidato en NocoDB** — Construye el payload completo con todos los datos del formulario y la clave `M########` (8 dígitos con cero a la izquierda) derivada del próximo código. Intenta los endpoints dinámicos en orden hasta obtener una respuesta HTTP 200/201. Aborta con error crítico si todos fallan.

**G. Registro en Mediapath y relaciones N:M** — Solo si la subida física fue exitosa, registra los metadatos en `Mediapath`. Después, inserta una fila en `CandidatoPuestos` por cada puesto marcado por el candidato.

Finalmente, llama a `sendSectionAcceptanceCopy()` para enviar el correo de confirmación y devuelve `['status' => 'success', 'clave' => 'M########']`.

---

### `uploadPdfFirmado(string $clave, string $pdfBase64): array`

Recibe el PDF con la firma canvas incrustada (en base64, con o sin prefijo data URI), lo decodifica, lo escribe en un archivo temporal del sistema (`/tmp/LOPD_firmado_MXXXXXXXX.pdf`), lo sube al dominio externo con `uploadFileToDomain()` y registra el resultado en `Mediapath` con descripción `"LOPD firmado"`.

Se llama desde `submit.php` como una **segunda petición independiente** (sin token de sesión Joomla) identificada por `action=upload_pdf_firmado`. La seguridad se garantiza validando que la clave tenga formato `M########` (M seguida de exactamente 8 dígitos).

---

### `obtenerGeolocalizacion(string $localidad): array|null`

Realiza una consulta a la API REST de Nominatim para resolver una localidad en texto libre a datos estructurados de provincia (`state` / `province` / `county`) y país. Incluye el `User-Agent` requerido por las políticas de uso de Nominatim.

Devuelve `['provincia' => string, 'pais' => string]` o `null` si la consulta no devuelve resultados.

---

### `sendSectionAcceptanceCopy(string $email, string $nombre, array $sectionStatuses, string $policyPdfUrl): void`

Envía un correo de texto plano al candidato confirmando qué secciones de política han sido aceptadas y si se registró firma en cada una. Si se ha proporcionado una URL al PDF de políticas, la incluye en el cuerpo del mensaje.

Usa la función nativa `mail()` de PHP. Si el email no pasa la validación `FILTER_VALIDATE_EMAIL`, la función retorna silenciosamente sin hacer nada.

---

### `parseOptions(string $rawOptions, array $fallbackOptions): array`

Utilidad para leer opciones multilínea desde los parámetros del módulo en el back-end de Joomla. Divide por saltos de línea (`\R`), limpia espacios y devuelve el array resultante. Si no hay opciones válidas, devuelve el array de fallback proporcionado.

---

## Endpoint AJAX — `submit.php`

### Modo ping (diagnóstico rápido)

```
GET https://tudominio.com/modules/mod_formulario_cv/submit.php?ping=1
```

Devuelve `{"status":"success","message":"submit.php alcanzado correctamente."}` sin tocar Joomla ni la API. Útil para confirmar que la ruta de instalación es correcta y que PHP ejecuta el archivo.

### Flujo normal

Solo acepta `POST`. Antes de delegar en `helper.php`, verifica el **token CSRF de Joomla** (`JSession::checkToken('post')`), lo que impide ataques de falsificación de petición.

### Acción `upload_pdf_firmado`

```
POST submit.php
  action=upload_pdf_firmado
  clave=M00000001
  pdf_base64=<datos en base64>
```

Ruta secundaria sin verificación de token de sesión Joomla (el PDF firmado se envía desde el cliente justo después del submit principal, cuando la sesión puede haber expirado o el candidato no está logueado). La autenticidad se valida exclusivamente por el formato de la clave.

### Sistema de depuración incorporado

El archivo captura todos los errores PHP (notices, warnings, fatales) y los escribe en `debug.log` en formato `[timestamp] mensaje`. Los errores fatales se devuelven como JSON en lugar de como HTML, evitando que el frontend reciba respuestas no parseables.

```php
set_error_handler(...)         // warnings y notices → debug.log
set_exception_handler(...)     // excepciones no capturadas → JSON de error
register_shutdown_function(...)// errores fatales → JSON de error
```

---

## Punto de entrada del módulo — `mod_formulario_cv.php`

Archivo mínimo que Joomla carga al renderizar el módulo. Registra la hoja de estilos y el script JavaScript con atributo `defer` (para que el DOM esté disponible antes de que el JS se ejecute) y con `version=auto` (para romper la caché del navegador automáticamente cuando el archivo cambia). Finalmente carga el layout `tmpl/default.php`.

---

## Depuración

El módulo escribe logs en dos archivos:

- **`debug.log`** (en el directorio del módulo): errores PHP, resultados de llamadas HTTP, fallos de autenticación, intentos de subida fallidos, etc.
- Puedes activar el modo ping de `submit.php` para verificar conectividad básica.

Para diagnosticar problemas de coordenadas PDF, usa siempre el calibrador visual con la función de previsualización integrada antes de guardar.

---

## Seguridad

- Las credenciales de la API nunca se exponen al frontend; residen únicamente en `config.ini` en el servidor.
- El token CSRF de Joomla protege el flujo principal de envío.
- La subida de archivos valida tanto la extensión como el MIME type real del archivo.
- El calibrador PDF está protegido por contraseña definida en el INI y sesión PHP.
- El endpoint `upload_pdf_firmado` valida la clave del candidato con una expresión regular estricta (`/^M\d{8}$/`).
- Se recomienda eliminar o denegar el acceso a `pdf-calibrator.php` y `debug.log` en producción una vez completada la configuración.

---

## Requisitos

| Requisito | Versión mínima |
|---|---|
| PHP | 7.4+ |
| Joomla | 3.x / 4.x |
| Extensión cURL | Requerida |
| Extensión finfo | Recomendada (validación MIME) |
| NocoDB | Cualquier versión con API v1 |
| reCAPTCHA | Google reCAPTCHA v2 |
