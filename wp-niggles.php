<?php
/**
 * Plugin Name: WP Niggles
 * Description: A WordPress user experience plugin for bloggers.
 * License:     GPL-3.0+
 * Plugin URI:      https://github.com/svandragt/wp-niggles/
 * Author:          Sander van Dragt
 * Author URI:      https://vandragt.com
 * Text Domain:     niggles
 * Domain Path:     /languages
 * Version:         0.1.0
 */

namespace Niggles;

require_once __DIR__ .'/features/login-redirects.php';
require_once __DIR__ .'/namespace.php';


bootstrap();
LoginRedirects\bootstrap();
