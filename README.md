# mod_formulario_cv — Módulo de Candidaturas para Joomla 3.10

Módulo frontend para **Joomla 3.10.x** que permite a los visitantes de una web enviar su candidatura y CV directamente desde cualquier posición del sitio. El formulario valida los datos en el cliente y en el servidor, sube el PDF a un dominio externo, registra al candidato en una base de datos **NocoDB**, y envía al candidato una copia de las políticas aceptadas.

---

## Índice

- [Características](#características)
- [Requisitos](#requisitos)
- [Estructura del repositorio](#estructura-del-repositorio)
- [Instalación](#instalación)
- [Configuración del archivo `config.ini`](#configuración-del-archivo-configini)
- [Parámetros del módulo (back-end Joomla)](#parámetros-del-módulo-back-end-joomla)
- [Flujo de funcionamiento](#flujo-de-funcionamiento)
- [Lógica del cliente (`formulario-cv.js`)](#lógica-del-cliente-formulario-cvjs)
- [Vista y ecosistema de color dinámico (`default.php`)](#vista-y-ecosistema-de-color-dinámico-defaultphp)
- [Endpoint AJAX (`submit.php`)](#endpoint-ajax-submitphp)
- [Diagnóstico y depuración](#diagnóstico-y-depuración)
- [Seguridad](#seguridad)
- [Reglas de mantenimiento operativo](#reglas-de-mantenimiento-operativo)
- [Licencia](#licencia)

---

## Características

- Formulario visual con campos de datos personales, formación, experiencia, idiomas, competencias y disponibilidad.
- **Subida de CV en PDF** con validación de tipo MIME en servidor.
- **Foto de candidato** opcional (imagen → base64 enviada a NocoDB).
- Selección múltiple de **puestos de interés** cargados dinámicamente desde NocoDB.
- **Geolocalización automática** de la localidad introducida (vía Nominatim / OpenStreetMap) para rellenar provincia y país.
- **Firma digital** y aceptación de secciones de políticas, con envío de copia al candidato por email.
- **Subida del PDF de LOPD firmado** como segunda petición independiente.
- Integración completa con **NocoDB** (tablas `Candidato`, `Mediapath`, relación N:M candidato-puesto).
- **Paleta de colores 100% dinámica** gestionada desde `config.ini` e inyectada como variables CSS en el DOM, sin necesidad de tocar código.
- Autenticación segura contra la API de NocoDB mediante credenciales almacenadas en un `config.ini` privado (nunca expuesto al cliente).
- Protección **reCAPTCHA v2** con validación doble: cliente y servidor (Google API).
- Diálogos modales flotantes para errores y confirmaciones, con herencia automática de la paleta CSS.
- Token de sesión **Joomla CSRF** en cada envío.
- Sistema de reintentos (3 intentos) en la subida física de archivos.
- **Log de depuración** en `debug.log` sin exponer errores PHP al navegador.
- Compatible con servidores sin cURL (fallback a `file_get_contents`).

---

## Requisitos

| Requisito | Versión mínima |
|---|---|
| Joomla | 3.10.x |
| PHP | 7.4 o superior |
| Extensión cURL | Recomendada (hay fallback a `file_get_contents`) |
| Extensión `fileinfo` | Recomendada (validación MIME) |
| NocoDB | Cualquier versión con API v1 |
| JCE Editor | Necesario para el selector de PDF de políticas en el back-end |

---

## Estructura del repositorio

```
mod_formulario_cv/
├── mod_formulario_cv.php      # Punto de entrada del módulo
├── mod_formulario_cv.xml      # Manifiesto de instalación (Joomla)
├── helper.php                 # Toda la lógica de negocio (API, subida, geo, email…)
├── submit.php                 # Endpoint AJAX independiente para el envío del formulario
├── pdf-calibrator.php         # Utilidad auxiliar para ajuste/calibración de PDFs
├── config.ini                 # ⚠️ Credenciales privadas (NO subir a producción sin proteger)
├── index.html                 # Archivo de seguridad (impide listado de directorio)
├── language/                  # Archivos de traducción
├── media/                     # CSS, JS y otros assets del módulo
└── tmpl/                      # Plantillas de salida (vistas)
    └── default.php            # Vista principal del formulario
```

---

## Instalación

### Instalación mediante el gestor de extensiones de Joomla

1. Comprime el contenido de este repositorio en un `.zip` (el directorio raíz debe llamarse `mod_formulario_cv`).
2. Accede al back-end de Joomla: **Extensiones → Gestionar → Instalar**.
3. Sube el `.zip` y haz clic en **Subir e Instalar**.
4. Ve a **Extensiones → Módulos**, localiza **Formulario CV** y asígnalo a la posición y páginas deseadas.

### Instalación manual (alternativa)

Copia la carpeta `mod_formulario_cv` en `/modules/` de tu instalación de Joomla y registra el módulo manualmente en la base de datos, o instálalo a través de **Extensiones → Descubrir**.

---

## Configuración del archivo `config.ini`

El archivo `config.ini` (ubicado en la raíz del módulo) actúa como el **único punto de mantenimiento del sistema**: credenciales, tablas de base de datos y toda la paleta visual se gestionan desde aquí. Usa comillas dobles `""` para asegurar la correcta lectura de cadenas con caracteres especiales.

**Nunca debe quedar accesible públicamente.** Se recomienda protegerlo con una regla `.htaccess`:

```apache
<Files "config.ini">
    Order allow,deny
    Deny from all
</Files>
```

### Claves disponibles

```ini
; ── 1. API NocoDB ─────────────────────────────────────────────────
base_url         = "https://api.dominio.es"
auth_url         = "https://api.dominio.es/api/v1/auth/user/signin"
api_email        = "usuario"
api_password     = "contraseña_segura"
proyecto         = "nombre_proyecto"

; ── Tablas NocoDB (NO MODIFICAR los valores) ──────────────────────
tabla_candidato  = "Candidato"
tabla_puesto     = "Puesto"
tabla_relacion   = "CandidatoPuestos"

; ── 2. Almacenamiento físico de archivos ──────────────────────────
dominio_upload   = "https://archivos.tudominio.com"
upload_token     = "token_secreto_del_servidor_de_archivos"

; ── 3. Seguridad reCAPTCHA v2 ─────────────────────────────────────
recaptcha_site_key   = "TU_CLAVE_PUBLICA_AQUI"
recaptcha_secret_key = "TU_CLAVE_PRIVADA_AQUI"

; ── 4. Personalización estética (Variables CSS) ───────────────────
cv_bg          = "rgba(248, 250, 252, 0.92)"  ; Fondo externo gris azulado
cv_panel       = "#ffffff"                     ; Fondo del panel principal
cv_text        = "#0b1a30"                     ; Azul marino principal
cv_muted       = "#4a5a70"                     ; Gris azulado secundario
cv_line        = "#d3dae3"                     ; Color de bordes
cv_accent      = "#c9931b"                     ; Dorado (color de acción)
cv_accent_dark = "#a47506"                     ; Dorado oscuro para hovers
cv_accent_soft = "rgba(201, 147, 27, 0.08)"   ; Fondo dorado translúcido
cv_warning     = "#a95712"                     ; Alertas y advertencias
```

> Si una variable de color se deja en blanco, el sistema no rompe el renderizado: el navegador aplicará los estilos por defecto de la hoja `formulario-cv.css`.

---

## Parámetros del módulo (back-end Joomla)

Estos parámetros se configuran desde **Extensiones → Módulos → Formulario CV → pestaña Opciones**:

| Parámetro | Descripción | Valor por defecto |
|---|---|---|
| `intro_text` | Texto introductorio bajo el título del formulario | *"Completa tus datos y adjunta tu CV…"* |
| `max_job_options` | Número máximo de puestos seleccionables por candidato | `3` |
| `availability_options` | Opciones del desplegable de disponibilidad (una por línea) | Inmediata, En 15 días, En 30 días, A convenir |
| `pdf_plantilla` | PDF de políticas/LOPD que el candidato deberá leer y firmar (selector JCE) | *(vacío)* |

> La URL de la política de privacidad se asigna desde el panel de administración de Joomla.

---

## Flujo de funcionamiento

```
Candidato rellena el formulario
        │
        ▼
[Cliente] Validación JS + reCAPTCHA v2
        │  (si captcha vacío → modal de error, sin petición HTTP)
        ▼
POST → submit.php (AJAX)
        │
        ├─ A. Verificación remota reCAPTCHA (Google API)
        ├─ B. Validación del CV (extensión PDF + MIME)
        ├─ C. Subida física del PDF al dominio externo (3 reintentos)
        ├─ D. Geolocalización de la localidad (Nominatim/OSM)
        ├─ E. Inserción del candidato en NocoDB (tabla Candidato)
        ├─ F. Registro de metadatos del archivo (tabla Mediapath)
        ├─ G. Guardado de relaciones candidato-puesto (tabla N:M)
        └─ H. Email de confirmación con políticas aceptadas
                │
                ▼
        Respuesta JSON → { status, message, clave }
        Modal flotante con resultado (hereda paleta CSS del INI)

Segunda petición (opcional):
POST → submit.php?action=upload_pdf_firmado
        └─ Sube el PDF de LOPD firmado digitalmente y lo registra en Mediapath
```

Cada candidato recibe una **clave única** con formato `M########` (ej. `M00000042`) generada a partir del último código en NocoDB.

---

## Lógica del cliente (`formulario-cv.js`)

El script actúa como controlador del flujo de eventos en el navegador bajo tres pilares:

**Validación previa absoluta.** Al hacer submit, el script evalúa `grecaptcha.getResponse()` de forma inmediata. Si la cadena está vacía, interrumpe la ejecución con un `return`: no se realiza ninguna llamada HTTP, no se procesa el PDF y el botón conserva su texto original.

**Interfaz limpia en formato diálogo.** Los mensajes de error (como omitir el captcha) y las confirmaciones de éxito se muestran mediante ventanas modales flotantes (`showModalDialog`), eliminando barras de texto estáticas del layout.

**Herencia estética automatizada.** Los estilos del modal (fondos, botones, iconos) consumen directamente las variables CSS del contenedor (`var(--cv-accent)`, etc.). Si modificas los colores en el `config.ini`, el modal adoptará la nueva paleta de forma transparente sin ningún cambio adicional.

**Reinicio automatizado.** El script invoca `grecaptcha.reset()` tras envíos exitosos o errores de red controlados para mantener el widget limpio y listo.

---

## Vista y ecosistema de color dinámico (`default.php`)

La vista procesa las directivas del `config.ini` e inyecta un bloque `<style>` que define las variables CSS sobre el contenedor `.cv-form-module`. Esto evita estilos inline en cada etiqueta HTML:

```php
<?php
$cvBg     = ModFormularioCvHelper::getIniConfig('cv_bg');
$cvAccent = ModFormularioCvHelper::getIniConfig('cv_accent');
// ... resto de variables
?>
<style>
.cv-form-module {
    <?php if (!empty($cvBg)): ?>--cv-bg: <?php echo $cvBg; ?>;<?php endif; ?>
    <?php if (!empty($cvAccent)): ?>--cv-accent: <?php echo $cvAccent; ?>;<?php endif; ?>
    /* ... */
}
</style>
```

La clave pública de reCAPTCHA también se inyecta de forma dinámica desde el INI:

```php
<div class="g-recaptcha"
     data-sitekey="<?php echo htmlspecialchars(
         ModFormularioCvHelper::getIniConfig('recaptcha_site_key'),
         ENT_QUOTES, 'UTF-8'
     ); ?>">
</div>
```

> El método `getIniConfig` tiene alcance `public` para poder ser utilizado tanto desde la vista (`default.php`) como internamente en `helper.php`.

---

## Endpoint AJAX (`submit.php`)

El archivo `submit.php` actúa como endpoint independiente, arrancando el framework de Joomla internamente para tener acceso a la sesión y al token CSRF.

### Modo ping (diagnóstico)

Visita la siguiente URL en el navegador para verificar que el archivo es accesible y se ejecuta como PHP:

```
https://tudominio.com/modules/mod_formulario_cv/submit.php?ping=1
```

Respuesta esperada:
```json
{ "status": "success", "message": "submit.php alcanzado correctamente." }
```

### Envío principal

```
POST /modules/mod_formulario_cv/submit.php
Content-Type: multipart/form-data

Campos requeridos:
  cv_nombre               Nombre completo del candidato
  cv_dni                  DNI/NIE/Pasaporte
  cv_email                Correo electrónico
  cv_telefono             Teléfono de contacto
  cv_cv                   Archivo PDF del CV (file upload)
  g-recaptcha-response    Token reCAPTCHA v2

Campos opcionales:
  cv_foto                 Imagen de perfil (file upload)
  cv_localidad            Localidad de residencia
  cv_disponibilidad       Disponibilidad para incorporación
  cv_formacion            Formación académica
  cv_experiencia          Experiencia laboral
  cv_idiomas              Idiomas
  cv_competencias         Otras competencias
  cv_carnes               Carnés de conducir u otros
  cv_puesto_interes[]     IDs de los puestos de interés (array)
  section_N_accepted      Aceptación de sección N de políticas (0/1)
  section_N_signature_data  Datos de firma de sección N
  policy_pdf_url          URL del PDF de políticas mostrado al candidato
```

### Subida del PDF firmado

```
POST /modules/mod_formulario_cv/submit.php

action=upload_pdf_firmado
clave=M00000042
pdf_base64=<contenido base64 del PDF firmado>
```

---

## Diagnóstico y depuración

El módulo escribe un archivo de log en:

```
/modules/mod_formulario_cv/debug.log
```

Incluye marcas de tiempo, errores de PHP capturados, fallos de autenticación, respuestas de la API y errores en la subida de archivos. **Protégelo o elimínalo en producción:**

```apache
<Files "debug.log">
    Order allow,deny
    Deny from all
</Files>
```

---

## Seguridad

- Las credenciales de la API nunca se exponen al navegador; solo se leen en servidor desde `config.ini`.
- El token CSRF de Joomla se valida en cada envío principal (`JSession::checkToken`).
- La clave secreta de reCAPTCHA nunca sale del servidor; la verificación se hace server-side contra Google.
- El endpoint de subida de PDF firmado requiere una clave con formato `M########` válido.
- Los errores PHP se capturan y se devuelven como JSON (nunca como HTML que pueda revelar rutas del servidor).
- La validación MIME del PDF se realiza con `finfo` para evitar archivos maliciosos camuflados.

---

## Reglas de mantenimiento operativo

**Cero modificaciones de código.** Todo rediseño de colores corporativos o sustitución de tokens de seguridad se realiza exclusivamente en el archivo `config.ini`.

**Robustez ante omisiones.** Si un color se borra o deja en blanco en el INI, el sistema no rompe el renderizado; el navegador aplicará los estilos por defecto de `formulario-cv.css`.

**Actualización de caché.** Cualquier ajuste en la paleta de colores del `config.ini` requiere un refresco completo de caché en el navegador (`Ctrl + F5`) para forzar la actualización de los estilos dinámicos del DOM.

---

## Licencia

GNU General Public License versión 2 o posterior.
Consulta el archivo `LICENSE` o visita https://www.gnu.org/licenses/gpl-2.0.html para más información.

---

*Desarrollado por [webdeveloper-rgb](https://github.com/webdeveloper-rgb) · versión 1.0.2*
