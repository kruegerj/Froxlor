<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2003-2009 the SysCP Team (see authors).
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  (c) the authors
 * @author     Florian Lippert <flo@syscp.org> (2003-2009)
 * @author     Froxlor team <team@froxlor.org> (2010-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package    System
 *
 */

// define default theme for configurehint, etc.
$_deftheme = 'Froxlor';

function view($template, $attributes)
{
	$view = file_get_contents(dirname(__DIR__) . '/templates/' . $template);

	return str_replace(array_keys($attributes), array_values($attributes), $view);
}

// validate correct php version
if (version_compare("7.4.0", PHP_VERSION, ">=")) {
	die(view($_deftheme . '/misc/phprequirementfailed.html.twig', [
		'{{ basehref }}' => '',
		'{{ froxlor_min_version }}' => '7.4.0',
		'{{ current_version }}' => PHP_VERSION,
		'{{ current_year }}' => date('Y', time()),
	]));
}

// validate vendor autoloader
if (!file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
	die(view($_deftheme . '/misc/vendormissinghint.html.twig', [
		'{{ basehref }}' => '',
		'{{ froxlor_install_dir }}' => dirname(__DIR__),
		'{{ current_year }}' => date('Y', time()),
	]));
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Froxlor\Database\Database;
use Froxlor\Settings;
use Froxlor\UI\Panel\UI;
use Froxlor\UI\Request;
use Froxlor\CurrentUser;

// include MySQL-tabledefinitions
require \Froxlor\Froxlor::getInstallDir() . '/lib/tables.inc.php';

UI::sendHeaders();
UI::initTwig();

/**
 * Register Globals Security Fix
 */
Request::cleanAll();

unset($_);
unset($key);

$filename = htmlentities(basename($_SERVER['SCRIPT_NAME']));

// check whether the userdata file exists
if (!file_exists(\Froxlor\Froxlor::getInstallDir() . '/lib/userdata.inc.php')) {
	UI::twig()->addGlobal('install_mode', '1');
	echo UI::twig()->render($_deftheme . '/misc/configurehint.html.twig');
	die();
}

// check whether we can read the userdata file
if (!is_readable(\Froxlor\Froxlor::getInstallDir() . '/lib/userdata.inc.php')) {
	// get possible owner
	$posixusername = posix_getpwuid(posix_getuid());
	$posixgroup = posix_getgrgid(posix_getgid());
	UI::twig()->addGlobal('install_mode', '1');
	echo UI::twig()->render($_deftheme . '/misc/ownershiphint.html.twig', [
		'user' => $posixusername['name'],
		'group' => $posixgroup['name'],
		'installdir' => \Froxlor\Froxlor::getInstallDir()
	]);
	die();
}

// include MySQL-Username/Passwort etc.
require \Froxlor\Froxlor::getInstallDir() . '/lib/userdata.inc.php';
if (!isset($sql) || !is_array($sql)) {
	UI::twig()->addGlobal('install_mode', '1');
	echo UI::twig()->render($_deftheme . '/misc/configurehint.html.twig');
	die();
}

// set error-handler
@set_error_handler([
	'\\Froxlor\\PhpHelper',
	'phpErrHandler'
]);
@set_exception_handler([
	'\\Froxlor\\PhpHelper',
	'phpExceptionHandler'
]);

// send ssl-related headers (later than the others because we need a working database-connection and installation)
UI::sendSslHeaders();

// create a new idna converter
$idna_convert = new \Froxlor\Idna\IdnaWrapper();

// re-read user data if logged in
if (CurrentUser::hasSession()) {
	CurrentUser::reReadUserData();
}

// Language Management
$langs = array();
$languages = array();
$iso = array();

// query the whole table
$result_stmt = Database::query("SELECT * FROM `" . TABLE_PANEL_LANGUAGE . "`");

// presort languages
while ($row = $result_stmt->fetch(PDO::FETCH_ASSOC)) {
	$langs[$row['language']][] = $row;
	// check for row[iso] cause older froxlor
	// versions didn't have that and it will
	// lead to a lot of undefined variables
	// before the admin can even update
	if (isset($row['iso'])) {
		$iso[$row['iso']] = $row['language'];
	}
}

// buildup $languages for the login screen
foreach ($langs as $key => $value) {
	$languages[$key] = $key;
}

// set default language before anything else to
// ensure that we can display messages
$language = Settings::Get('panel.standardlanguage');

if (CurrentUser::hasSession() && !empty(CurrentUser::getField('language')) && isset($languages[CurrentUser::getField('language')])) {
	// default: use language from session, #277
	$language = CurrentUser::getField('language');
} else {
	if (!CurrentUser::hasSession()) {
		if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
			$accept_langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
			for ($i = 0; $i < count($accept_langs); $i++) {
				// this only works for most common languages. some (uncommon) languages have a 3 letter iso-code.
				// to be able to use these also, we would have to depend on the intl extension for php (using Locale::lookup or similar)
				// as long as froxlor does not support any of these languages, we can leave it like that.
				if (isset($iso[substr($accept_langs[$i], 0, 2)])) {
					$language = $iso[substr($accept_langs[$i], 0, 2)];
					break;
				}
			}
			unset($iso);

			// if HTTP_ACCEPT_LANGUAGES has no valid langs, use default (very unlikely)
			if (!strlen($language) > 0) {
				$language = Settings::Get('panel.standardlanguage');
			}
		}
	} else {
		$language = CurrentUser::getField('def_language');
	}
}

// include every english language file we can get
foreach ($langs['English'] as $key => $value) {
	include_once \Froxlor\FileDir::makeSecurePath($value['file']);
}

// now include the selected language if its not english
if ($language != 'English') {
	foreach ($langs[$language] as $key => $value) {
		include_once \Froxlor\FileDir::makeSecurePath($value['file']);
	}
}

// last but not least include language references file
include_once \Froxlor\FileDir::makeSecurePath('lng/lng_references.php');

UI::setLng($lng);

// Initialize our link - class
$linker = new \Froxlor\UI\Linker('index.php');
UI::setLinker($linker);

/**
 * global Theme-variable
 */
$theme = (Settings::Get('panel.default_theme') !== null) ? Settings::Get('panel.default_theme') : $_deftheme;

/**
 * overwrite with customer/admin theme if defined
 */
if (CurrentUser::hasSession() && CurrentUser::getField('theme') != $theme) {
	$theme = CurrentUser::getField('theme');
}

// Check if a different variant of the theme is used
$themevariant = "default";
if (preg_match("/([a-z0-9\.\-]+)_([a-z0-9\.\-]+)/i", $theme, $matches)) {
	$theme = $matches[1];
	$themevariant = $matches[2];
}

// check for existence of the theme
if (@file_exists('templates/' . $theme . '/config.json')) {
	$_themeoptions = json_decode(file_get_contents('templates/' . $theme . '/config.json'), true);
} else {
	$_themeoptions = null;
}

// check for existence of variant in theme
if (is_array($_themeoptions) && (!array_key_exists('variants', $_themeoptions) || !array_key_exists($themevariant, $_themeoptions['variants']))) {
	$themevariant = "default";
}

// check for custom header-graphic
$hl_path = 'templates/' . $theme . '/assets/img';

// default is theme-image
$header_logo = $hl_path . '/logo_white.png';
$header_logo_login = $hl_path . '/logo.png';

if (Settings::Get('panel.logo_overridetheme') == 1 || Settings::Get('panel.logo_overridecustom') == 1) {
	// logo settings shall overwrite theme logo and possible custom logo
	$header_logo = Settings::Get('panel.logo_image_header') ?: $header_logo;
	$header_logo_login = Settings::Get('panel.logo_image_login') ?: $header_logo_login;
}
if (Settings::Get('panel.logo_overridecustom') == 0 && file_exists($hl_path . '/logo_custom.png')) {
	// custom theme image (logo_custom.png) is not being overwritten by logo_image_* setting
	$header_logo = $hl_path . '/logo_custom.png';
	$header_logo_login = $hl_path . '/logo_custom.png';
	if (file_exists($hl_path . '/logo_custom_login.png')) {
		$header_logo_login = $hl_path . '/logo_custom_login.png';
	}
}

UI::twig()->addGlobal('header_logo_login', $header_logo_login);
UI::twig()->addGlobal('header_logo', $header_logo);

/**
 * Redirects to index.php (login page) if no session exists
 */
if (!CurrentUser::hasSession() && AREA != 'login') {
	unset($_SESSION['userinfo']);
	CurrentUser::setData();
	session_destroy();
	$params = array(
		"script" => basename($_SERVER["SCRIPT_NAME"]),
		"qrystr" => $_SERVER["QUERY_STRING"]
	);
	\Froxlor\UI\Response::redirectTo('index.php', $params);
	exit();
}

$userinfo = CurrentUser::getData();
UI::twig()->addGlobal('userinfo', ($userinfo ?? []));
UI::setCurrentUser($userinfo);
// Initialize logger
if (CurrentUser::hasSession()) {
	// Initialize logging
	$log = \Froxlor\FroxlorLogger::getInstanceOf($userinfo);
	if ((CurrentUser::isAdmin() && AREA != 'admin') || (!CurrentUser::isAdmin() && AREA != 'customer')) {
		// user tries to access an area not meant for him -> redirect to corresponding index
		\Froxlor\UI\Response::redirectTo((CurrentUser::isAdmin() ? 'admin' : 'customer') . '_index.php', $params);
		exit();
	}
}

/**
 * Fills variables for navigation, header and footer
 */
$navigation = [];
if (AREA == 'admin' || AREA == 'customer') {
	if (\Froxlor\Froxlor::hasUpdates() || \Froxlor\Froxlor::hasDbUpdates()) {
		/*
		 * if froxlor-files have been updated
		 * but not yet configured by the admin
		 * we only show logout and the update-page
		 */
		$navigation_data = array(
			'admin' => array(
				'server' => array(
					'label' => $lng['admin']['server'],
					'required_resources' => 'change_serversettings',
					'elements' => array(
						array(
							'url' => 'admin_updates.php?page=overview',
							'label' => $lng['update']['update'],
							'required_resources' => 'change_serversettings'
						)
					)
				)
			)
		);
		$navigation = \Froxlor\UI\HTML::buildNavigation($navigation_data['admin'], CurrentUser::getData());
	} else {
		$navigation_data = \Froxlor\PhpHelper::loadConfigArrayDir('lib/navigation/');
		$navigation = \Froxlor\UI\HTML::buildNavigation($navigation_data[AREA], CurrentUser::getData());
	}
}
UI::twig()->addGlobal('nav_entries', $navigation);

$js = "";
$css = "";
if (is_array($_themeoptions) && array_key_exists('js', $_themeoptions['variants'][$themevariant])) {
	if (is_array($_themeoptions['variants'][$themevariant]['js'])) {
		foreach ($_themeoptions['variants'][$themevariant]['js'] as $jsfile) {
			if (file_exists('templates/' . $theme . '/assets/js/' . $jsfile)) {
				$js .= '<script type="text/javascript" src="templates/' . $theme . '/assets/js/' . $jsfile . '"></script>' . "\n";
			}
		}
	}
	if (is_array($_themeoptions['variants'][$themevariant]['css'])) {
		foreach ($_themeoptions['variants'][$themevariant]['css'] as $cssfile) {
			if (file_exists('templates/' . $theme . '/assets/css/' . $cssfile)) {
				$css .= '<link href="templates/' . $theme . '/assets/css/' . $cssfile . '" rel="stylesheet" type="text/css" />' . "\n";
			}
		}
	}
}

UI::twig()->addGlobal('theme_js', $js);
UI::twig()->addGlobal('theme_css', $css);
unset($js);
unset($css);

$action = Request::get('action');
$page = Request::get('page', 'overview');

// clear request data
if (!$action && isset($_SESSION)) {
	unset($_SESSION['requestData']);
}

UI::twig()->addGlobal('action', $action);
UI::twig()->addGlobal('page', $page);

/**
 * Initialize the mailingsystem
 */
$mail = new \Froxlor\System\Mailer(true);
