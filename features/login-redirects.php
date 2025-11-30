<?php
/**
 * Login Redirects Feature
 *
 * Handles role-based redirection after user login.
 *
 * @package Niggles
 * @subpackage LoginRedirects
 * @version 1.0.0
 * @license GPL-3.0-or-later
 */

namespace Niggles\LoginRedirects;

use WP_User;

/**
 * Bootstrap the login redirects functionality.
 * Hooks into the WordPress login action to handle role-based redirects.
 */
function bootstrap() {
	add_action( 'wp_login', __NAMESPACE__ . '\\redirect_by_role', 10, 2 );
}

/**
 * Redirects users to specific admin pages based on their role after login.
 *
 * @param string  $user_login The user's login name
 * @param WP_User $user The user object
 */
function redirect_by_role( string $user_login, WP_User $user ) : void {
	if ( $user->has_cap( 'customize' ) ) {
		// Admins to dashboard
		return;
	}
	if ( $user->has_cap( 'delete_others_pages' ) ) {
		// Editors to page list
		wp_safe_redirect( admin_url( 'edit.php?post_type=page' ) );
		die();
	}
	if ( $user->has_cap( 'publish_posts' ) ) {
		// Authors to new page
		wp_safe_redirect( admin_url( 'post-new.php?post_type=post' ) );
		die();
	}
	if ( $user->has_cap( 'delete_posts' ) ) {
		// Contributors to new post
		wp_safe_redirect( admin_url( 'post-new.php?post_type=post' ) );
		die();
	}
}
