<?php

/**
* Plugin Name: Static Page
* Version: 1.0
* Author: Joe Hoyle | Human Made
*/

namespace Static_Page;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RecursiveRegexIterator;
use RegexIterator;
use WP_Error;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/inc/class-wp-cli-command.php';
	\WP_CLI::add_command( 'static-page', __NAMESPACE__ . '\\WP_CLI_Command' );
}

add_action( 'save_post', __NAMESPACE__ . '\\queue_export' );
add_action( 'static_page_save', __NAMESPACE__ . '\\save_site' );
add_action( 'admin_notices', __NAMESPACE__ . '\\show_save_admin_notice' );

function queue_export( $config = null ) {
	if ( ! wp_next_scheduled( 'static_page_save' ) ) {
		wp_schedule_single_event( time() + 5, 'static_page_save', array( $config ) );
	}
}

function save_site( $config = null ) {
	$urls = get_site_urls();
	$contents = array_map( __NAMESPACE__ . '\\get_url_contents', $urls, array_fill( 0, count( $urls ), $config ) );

	// Remove any URLs that errored.
	$contents = array_filter( $contents, function ( $content ) {
		return ! is_wp_error( $content );
	});

	$contents = array_map( __NAMESPACE__ . '\\replace_urls', $contents, array_fill( 0, count( $urls ), $config ) );
	array_map( __NAMESPACE__ . '\\save_contents_for_url', $contents, $urls, array_fill( 0, count( $urls ), $config ) );
}

function show_save_admin_notice() {
	if ( ! wp_next_scheduled( 'static_page_save' ) ) {
		return;
	}
	?>
	<div class="notice notice-success">
		<p><?php _e( 'Saving site to NetStorage is currently in progress.', 'static-page' ); ?></p>
	</div>
	<?php
}

/**
 * Get all the URLs on the site.
 *
 * This is all the pages that will have static pages generated for them.
 *
 * @return string[]
 */
function get_site_urls( $config = null ) {
	$urls = [
		site_url( '/' ),
	];

	// Get URLs for all published posts
	$posts = get_posts( [ 'posts_per_page' => -1, 'post_type' => 'any' ] );
	$urls = array_merge( $urls, array_map( 'get_permalink', $posts ) );

	// Get URLs for all public terms
	$taxonomies = apply_filters( 'static_page_taxonomies', get_taxonomies( [ 'public' => true ], 'names' ) );
	$taxonomies = array_map( 'get_terms', $taxonomies );
	$terms = array_reduce( $taxonomies, function( $all_terms, $terms ) {
		return array_merge( $all_terms, $terms );
	}, [] );

	$urls = array_merge( $urls, array_map( 'get_term_link', $terms ) );

	return apply_filters( 'static_page_site_urls', $urls, $config );
}

/**
 * Get the page contents of a URL.
 *
 * @param  string $url
 * @param  mixed  $config Option config object that will be passed to filters etc.
 * @return string|WP_Error URL contents on success, error object otherwise.
 */
function get_url_contents( $url, $config = null ) {
	// for now we just do a loop back
	$url = apply_filters( 'static_page_get_url_contents_request_url', $url, $config );
	$args = apply_filters( 'static_page_get_url_contents_request_args', array(), $config );
	$response = wp_remote_get( $url, $args );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code !== 200 ) {
		return new WP_Error(
			'static-page.get_url_contents.non_200',
			sprintf( __( 'Non-200 response (%1$d) returned from %2$s', 'static-page' ), $code ),
			[ 'response' => $response ]
		);
	}

	return wp_remote_retrieve_body( $response );
}

function replace_urls( $content, $config = null ) {
	$content = apply_filters( 'static_page_replace_urls_in_content', $content, $config );
	return $content;
}

/**
 * @param  mixed  $config Option config object that will be passed to filters etc.
 */
function get_destination_directory( $config = null ) {
	$dir = wp_upload_dir()['basedir'] . '/static-page';
	return untrailingslashit( apply_filters( 'static_page_destination_directory', $dir, $config ) );
}

/**
 * Save the contents of a url to the static page destination.
 *
 * @param  string $contents
 * @param  string $url
 * @param  mixed  $config Option config object that will be passed to filters etc.
 */
function save_contents_for_url( $contents, $url, $config = null ) {
	$dir = get_destination_directory( $config );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}

	// Force HTTPS
	$base_url = home_url( '', 'https' );
	$url = set_url_scheme( $url, 'https' );
	$path = $dir . str_replace( $base_url, '', $url );

	add_filter( 's3_uploads_putObject_params', $func = function( $params ) {
		$params['ContentType'] = 'text/html';
		return $params;
	});

	// if the url looks to be a direcotry, create it and then call the file index.html
	if ( substr( $path, -1 ) === '/' ) {
		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0755, true );
		}
		$path = $path . 'index.html';
	} else {
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0755, true );
		}
	}

	if ( empty( $contents ) ) {
		trigger_error( sprintf( 'Writing to %s with empty content', $url ), E_USER_WARNING );
	}

	file_put_contents( $path, $contents );

	remove_filter( 's3_uploads_putObject_params', $func );

	// Handy if we want to do a cache expiry.
	do_action( 'static_page_saved_contents_for_url', $path, $config );
}

function remove_url( $url, $config = null ) {
	$dir = get_destination_directory( $config );
	$path = $dir . str_replace( site_url(), '', $url );

	// if the url looks to be a direcotry, create it and then call the file index.html
	if ( substr( $path, -1 ) === '/' ) {
		$path = $path . 'index.html';
	}

	unlink( $path );

	// Handy if we want to do a cache expiry.
	do_action( 'static_page_removed_url', $path, $config );
}

/**
 * Copy an asset to the static page destination.
 *
 * @param  string $path
 * @param  mixed  $config Option config object that will be passed to filters etc.
 */
function copy_asset( $path, $config = null ) {
	$dir = get_destination_directory( $config );

	if ( strpos( WP_CONTENT_DIR, ABSPATH ) === false ) {
		$destination = str_replace( dirname( ABSPATH ), $dir, $path );
	} else {
		$destination = str_replace( ABSPATH, $dir . '/', $path );
	}

	$destination = apply_filters( 'static_page_copy_asset_destination', $destination, $path, $config );
	if ( ! is_dir( dirname( $destination ) ) ) {
		mkdir( dirname( $destination ), 0755, true );
	}
	copy( $path, $destination );
}

function delete_asset( $url ) {
	$dir = get_destination_directory();

	$path = $dir . parse_url( $url, PHP_URL_PATH );

	if ( strpos( WP_CONTENT_DIR, ABSPATH ) === false ) {
		$destination = str_replace( dirname( ABSPATH ), $dir, $path );
	} else {
		$destination = str_replace( ABSPATH, $dir . '/', $path );
	}

	$destination = apply_filters( 'static_page_copy_asset_destination', $destination, $path );

	unlink( $destination );
}

/**
 * Get all the static assets on the site that should be copied.
 *
 * @return string[]
 */
function get_assets() {
	$assets = array();
	$asset_regex = '/^.+(\.jpe?g|\.png|\.gif|\.css|\.ico|\.js|\.woff|\.ttf|\.svg|\.json)$/i';
	$dirs = [ ABSPATH . WPINC ];
	foreach ( wp_get_themes() as $theme ) {
	    $dirs[] = $theme->get_stylesheet_directory();
	}
	$dirs = apply_filters( 'static_page_assets_dirs', $dirs );

	foreach ( $dirs as $dir ) {
		$directory = new RecursiveDirectoryIterator( $dir );
		$iterator = new RecursiveIteratorIterator( $directory );
		$regex = new RegexIterator( $iterator, $asset_regex, RecursiveRegexIterator::GET_MATCH );

		foreach ( $regex as $filename => $r ) {
			$assets[] = $filename;
		}
	}

	return apply_filters( 'static_page_assets', $assets );
}
