<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_formulario_cv
 *
 * @copyright   Copyright (C) 2026
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

$document = JFactory::getDocument();
$modulePath = JUri::base(true) . '/modules/mod_formulario_cv';

$document->addStyleSheet($modulePath . '/media/css/formulario-cv.css');

// --- CAMBIO AQUÍ: Añadimos 'defer' => true en las opciones del script ---
$document->addScript(
    $modulePath . '/media/js/formulario-cv.js',
    array('version' => 'auto'), // Esto ayuda a romper la caché cuando actualizas el JS
    array('defer' => true)      // <--- Esto obliga al JS a esperar al HTML
);

require JModuleHelper::getLayoutPath('mod_formulario_cv', $params->get('layout', 'default'));