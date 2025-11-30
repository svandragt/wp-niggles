<?php
/**
 * WP-CLI commands to temporarily grant Super Admin role to a user, which is auto-revoked.
 */

namespace Niggles\SuperAdminBump;

use WP_CLI;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OPTION_KEY = 'niggles_super_admin_bumps'; // network option.
const CRON_HOOK  = 'niggles_super_admin_bump_revoke';

function bootstrap() : void {
	if ( ! is_multisite() ) {
		// Multisite only.
		return;
	}
	add_action( 'init', __NAMESPACE__ . '\sweep_expired_on_init', 20 );
	add_action( CRON_HOOK, __NAMESPACE__ . '\revoke_callback', 10, 1 );

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		register_cli();
	}
}

/**
 * Register WP-CLI commands.
 */
function register_cli() : void {
	WP_CLI::add_command( 'super-admin bump add', __NAMESPACE__ . '\cli_bump_add', [
		'shortdesc' => 'Temporarily grant Super Admin privileges to a user for 1-60 minutes.',
		'synopsis'  => [
			[
				'type'        => 'positional',
				'name'        => 'user',
				'description' => 'User ID, login, or email address.',
				'optional'    => false,
			],
			[
				'type'        => 'assoc',
				'name'        => 'minutes',
				'description' => 'Duration in minutes (default: 30)',
				'optional'    => true,
			],
		],
	] );
	WP_CLI::add_command( 'super-admin bump list', __NAMESPACE__ . '\cli_bump_list', [
		'shortdesc' => 'List all active temporary Super Admin grants.',
	] );
	WP_CLI::add_command( 'super-admin bump clear-expired', __NAMESPACE__ . '\cli_clear_expired', [
		'shortdesc' => 'Manually clear expired temporary Super Admin grants.',
	] );
}

/**
 * CLI: wp super-admin bump add <user> [--minutes=<1-60>]
 */
function cli_bump_add( $args, $assoc_args ) : void {
	assert_super_admin_actor();

	[ $user_identifier ] = $args;

	$user = resolve_user( $user_identifier );
	if ( ! $user ) {
		WP_CLI::error( "User not found: {$user_identifier}" );
	}

	$minutes = isset( $assoc_args['minutes'] ) ? (int) $assoc_args['minutes'] : 30;
	if ( $minutes < 5 || $minutes > 60 ) {
		WP_CLI::error( "Minutes must be between 5 and 60. Given: {$minutes}" );
	}

	// Basic sanity: only bump users who are at least admins somewhere.
	if ( ! user_can( $user, 'manage_options' ) && ! is_super_admin( $user->ID ) ) {
		WP_CLI::warning( "User {$user->user_login} is not an admin. Proceeding anyway." );
	}

	$now     = time();
	$expires = $now + ( $minutes * MINUTE_IN_SECONDS );

	$bumps = get_bumps();

	$actor_id = get_current_user_id() ?: 0;

	// If already bumped, refresh/extend from now.
	$bumps[ $user->ID ] = [
		'user_id'     => $user->ID,
		'elevated_by' => $actor_id,
		'start'       => $now,
		'expires'     => $expires,
	];

	set_bumps( $bumps );

	// Grant SA.
	if ( ! is_super_admin( $user->ID ) ) {
		grant_super_admin( $user->ID );
	}

	// Schedule single revocation.
	schedule_revoke( $user->ID, $expires );

	WP_CLI::success( sprintf( 'Bumped %s (ID %d) to Super Admin for %d minutes. Expires at %s.', $user->user_login, $user->ID, $minutes, gmdate( 'Y-m-d H:i:s \U\T\C', $expires ) ) );
}

/**
 * CLI: wp super-admin bump list
 */
function cli_bump_list() : void {
	assert_super_admin_actor();

	$bumps = get_bumps();
	if ( empty( $bumps ) ) {
		WP_CLI::log( 'No active bumps.' );

		return;
	}

	$rows = [];
	$now  = time();

	foreach ( $bumps as $user_id => $bump ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			continue;
		}

		$remaining = max( 0, (int) ceil( ( $bump['expires'] - $now ) / MINUTE_IN_SECONDS ) );

		$rows[] = [
			'user'        => $user->user_login,
			'user_id'     => $user_id,
			'elevated_by' => $bump['elevated_by'] ?? 0,
			'start_utc'   => gmdate( 'Y-m-d H:i:s', (int) $bump['start'] ),
			'expires_utc' => gmdate( 'Y-m-d H:i:s', (int) $bump['expires'] ),
			'remainingm'  => $remaining,
		];
	}

	WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
}

/**
 * CLI: wp super-admin bump clear-expired
 * Manual sweep for good measure.
 */
function cli_clear_expired() : void {
	assert_super_admin_actor();

	$expired = sweep_expired();
	WP_CLI::success( "Cleared {$expired} expired bump(s)." );
}

/**
 * Cron callback: revoke SA for a user id.
 */
function revoke_callback( int $user_id ) : void {
	$bumps = get_bumps();

	// If no longer bumped, nothing to do.
	if ( empty( $bumps[ $user_id ] ) ) {
		return;
	}

	$bump = $bumps[ $user_id ];
	$now  = time();

	// Only revoke if actually expired.
	if ( (int) $bump['expires'] > $now ) {
		// Reschedule to exact expiry if fired early for any reason.
		schedule_revoke( $user_id, (int) $bump['expires'] );

		return;
	}

	if ( is_super_admin( $user_id ) ) {
		revoke_super_admin( $user_id );
	}

	unset( $bumps[ $user_id ] );
	set_bumps( $bumps );
}

/**
 * Sweep on init to catch missed cron.
 */
function sweep_expired_on_init() : void {
	sweep_expired();
}

/**
 * Returns number of expired bumps cleared.
 */
function sweep_expired() : int {
	$bumps   = get_bumps();
	$now     = time();
	$cleared = 0;

	foreach ( $bumps as $user_id => $bump ) {
		if ( (int) ( $bump['expires'] ?? 0 ) <= $now ) {
			if ( is_super_admin( $user_id ) ) {
				revoke_super_admin( $user_id );
			}
			unset( $bumps[ $user_id ] );
			$cleared ++;
		}
	}

	if ( $cleared > 0 ) {
		set_bumps( $bumps );
	}

	return $cleared;
}

/**
 * Ensure revocation is scheduled at expiry.
 */
function schedule_revoke( int $user_id, int $expires ) : void {
	// Clear old scheduled events for this user.
	$timestamp = wp_next_scheduled( CRON_HOOK, [ $user_id ] );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, CRON_HOOK, [ $user_id ] );
		$timestamp = wp_next_scheduled( CRON_HOOK, [ $user_id ] );
	}

	wp_schedule_single_event( $expires, CRON_HOOK, [ $user_id ] );
}

/**
 * Network option helpers.
 */
function get_bumps() : array {
	$bumps = get_site_option( OPTION_KEY, [] );

	return is_array( $bumps ) ? $bumps : [];
}

function set_bumps( array $bumps ) : void {
	update_site_option( OPTION_KEY, $bumps );
}

/**
 * Resolve user from ID/login/email (standard WP-CLI behavior).
 */
function resolve_user( string $identifier ) : ?WP_User {
	if ( is_numeric( $identifier ) ) {
		$user = get_user_by( 'id', (int) $identifier );
		if ( $user instanceof WP_User ) {
			return $user;
		}
	}

	$user = get_user_by( 'login', $identifier );
	if ( $user instanceof WP_User ) {
		return $user;
	}

	$user = get_user_by( 'email', $identifier );
	if ( $user instanceof WP_User ) {
		return $user;
	}

	return null;
}

/**
 * Best-effort check that the CLI actor is a super admin.
 * In WP-CLI, get_current_user_id() is non-zero only if --user is supplied.
 * If no actor, allow but warn.
 */
function assert_super_admin_actor() : void {
	$actor_id = get_current_user_id();
	if ( $actor_id && ! is_super_admin( $actor_id ) ) {
		WP_CLI::error( 'This command must be run as a Super Admin.' );
	}
	if ( ! $actor_id ) {
		WP_CLI::warning( 'No CLI actor detected (run with --user=<sa>). Proceeding.' );
	}
}
