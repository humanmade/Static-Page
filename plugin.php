<?php

/**
* Plugin Name: Static Page
* Version: 1.0
* Author: Joe Hoyle | Human Made
*/

namespace Static_Page;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/inc/class-wp-cli-command.php';
	\WP_CLI::add_command( 'static-page', __NAMESPACE__ . '\\WP_CLI_Command' );
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

/**
 * Save the contents of a url to the static page destination.
 *
 * @param  string $contents
 * @param  string $url
 */
function save_contents_for_url( $contents, $url ) {
	$dir = wp_upload_dir()['basedir'] . '/static-page';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir );
	}
	$path =  $dir . parse_url( $url, PHP_URL_PATH );

	add_filter( 's3_uploads_putObject_params', $func = function( $params ) {
		$params['ContentType'] = 'text/html';
		return $params;
	});
	file_put_contents( $path, $contents );
	remove_filter( 's3_uploads_putObject_params', $func );
}
