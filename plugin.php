<?php

/**
* Plugin Name: Static Page
* Version: 1.0
* Author: Joe Hoyle | Human Made
*/

namespace Static_Page;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RegexIterator;
use RecursiveRegexIterator;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/inc/class-wp-cli-command.php';
	\WP_CLI::add_command( 'static-page', __NAMESPACE__ . '\\WP_CLI_Command' );
}

add_action( 'save_post', __NAMESPACE__ . '\\queue_export' );
add_action( 'static_page_export', __NAMESPACE__ . '\\export_site' );

function queue_export() {
	if ( ! wp_next_scheduled( 'static_page_export' ) ) {
		wp_schedule_single_event( time() + 5, 'static_page_export' );
	}
}

function export_site() {
	$urls = get_site_urls();
	$contents = array_map( __NAMESPACE__ . '\\get_url_contents', $urls );
	$contents = array_map( __NAMESPACE__ . '\\replace_urls', $contents );
	array_map( __NAMESPACE__ . '\\save_contents_for_url', $contents, $urls );
}

/**
 * Get all the URLs on the site.
 *
 * This is all the pages that will have static pages generated for them.
 *
 * @return string[]
 */
function get_site_urls() {
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
	}, []) ;

	$urls = array_merge( $urls, array_map( 'get_term_link', $terms ) );

	return apply_filters( 'static_page_site_urls', $urls );
}

/**
 * Get the page contents of a URL.
 *
 * @param  string $url
 * @return string
 */
function get_url_contents( $url ) {
	// for now we just do a loop back
	$response = wp_remote_get( $url );
	return wp_remote_retrieve_body( $response );
}

function replace_urls( $content ) {
	$content = apply_filters( 'static_page_replace_urls_in_content', $content );
	return $content;
}

function get_destination_directory() {
	$dir = wp_upload_dir()['basedir'] . '/static-page';
	return untrailingslashit( apply_filters( 'static_page_destination_directory', $dir ) );
}

/**
 * Save the contents of a url to the static page destination.
 *
 * @param  string $contents
 * @param  string $url
 */
function save_contents_for_url( $contents, $url ) {
	$dir = get_destination_directory();
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir );
	}
	$path =  $dir . parse_url( $url, PHP_URL_PATH );

	add_filter( 's3_uploads_putObject_params', $func = function( $params ) {
		$params['ContentType'] = 'text/html';
		return $params;
	});

	// if the url looks to be a direcotry, create it and then call the file index.html
	if ( substr( $path, -1 ) === '/' ) {
		wp_mkdir_p( $path );
		$path = $path . 'index.html';
	} else {
		wp_mkdir_p( dirname( $path ) );
	}

	file_put_contents( $path, $contents );
	remove_filter( 's3_uploads_putObject_params', $func );
}

function copy_asset( $path ) {
	$dir = get_destination_directory();
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir );
	}


	if ( strpos( WP_CONTENT_DIR, ABSPATH ) === false ) {
		$destination = str_replace( dirname( ABSPATH ), $dir, $path );
	} else {
		$destination = str_replace( ABSPATH, $dir . '/', $path );
	}
	wp_mkdir_p( dirname( $destination ) );
	copy( $path, $destination );
}

/**
 * Get all the static assets on the site that should be copied.
 *
 * @return string[]
 */
function get_assets() {
	$assets = array();
	$asset_regex = '/^.+(\.jpe?g|\.png|\.gif|\.css|\.ico|\.js)$/i';
	$dirs = [ get_stylesheet_directory(), ABSPATH . WPINC ];
	$dirs = apply_filters( 'static_page_assets_dirs', $dirs );

	foreach( $dirs as $dir ) {
		$directory = new RecursiveDirectoryIterator( $dir );
		$iterator = new RecursiveIteratorIterator( $directory );
		$regex = new RegexIterator( $iterator, $asset_regex, RecursiveRegexIterator::GET_MATCH );

		foreach ( $regex as $filename => $r ) {
			$assets[] = $filename;
		}
	}

	return apply_filters( 'static_page_assets', $assets );
}
