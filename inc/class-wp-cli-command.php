<?php

namespace Static_Page;
use WP_CLI;

class WP_CLI_Command extends \WP_CLI_Command {

	/**
	 * Get the contents of a URL that would be used for a statis page.
	 *
	 * @synopsis [<url>] [--replace-from=<from>] [--replace-to=<to>]
	 */
	public function output( $args, $args_assoc ) {
		if ( ! empty( $args_assoc['replace-from'] ) ) {
			add_filter( 'static_page_replace_urls_in_content', function( $contents ) use ( $args_assoc ) {
				return str_replace( $args_assoc['replace-from'], $args_assoc['replace-to'], $contents );
			});
		}

		$urls = ! empty( $args[0] ) ? [ $args[0] ] : get_site_urls();

		$contents = array_map( __NAMESPACE__ . '\\get_url_contents', $urls );
		$contents = array_map( __NAMESPACE__ . '\\replace_urls', $contents );

		print_r( $contents );
	}

	/**
	 * Get all the URLs that would be saved to static pages.
	 */
	public function urls() {
		echo implode( "\n", get_site_urls() );
	}

	/**
	 * Get all the assets that would be saved to static pages.
	 */
	public function assets() {
		echo implode( "\n", get_assets() );
	}

	/**
	 * Save the contents to disk (or the destination directory).
	 *
	 * @synopsis [<path>] [--page-url=<url>] [--replace-from=<from>] [--replace-to=<to>]
	 */
	public function save( $args, $args_assoc ) {
		$urls = ! empty( $args_assoc['page-url'] ) ? [ $args_assoc['page-url'] ] : get_site_urls();

		$progress = WP_CLI\Utils\make_progress_bar( 'Fetching pages', count( $urls ) );
		$contents = array_map( function( $url ) use ( $progress ) {
			$contents = get_url_contents( $url );
			$progress->tick();
			return $contents;
		}, $urls );
		$progress->finish();

		if ( ! empty( $args_assoc['replace-from'] ) ) {
			add_filter( 'static_page_replace_urls_in_content', function( $contents ) use ( $args_assoc ) {
				return str_replace( $args_assoc['replace-from'], $args_assoc['replace-to'], $contents );
			});
		}

		$contents = array_map( __NAMESPACE__ . '\\replace_urls', $contents );

		$progress = WP_CLI\Utils\make_progress_bar( 'Saving pages', count( $urls ) );

		if ( ! empty( $args[0] ) ) {
			add_filter( 'static_page_destination_directory', function( $dir ) use ( $args ) {
				return $args[0];
			});
		}
		array_map( function( $content, $url ) use ( $progress ) {
			$progress->tick();
			save_contents_for_url( $content, $url );
		}, $contents, $urls );

		$progress->finish();
	}

	/**
	 * @subcommand save-assets [<path>]
	 */
	public function save_assets( $args, $args_assoc ) {

		$assets = get_assets();
		$progress = WP_CLI\Utils\make_progress_bar( 'Saving assets', count( $assets ) );

		if ( ! empty( $args[0] ) ) {
			add_filter( 'static_page_destination_directory', function( $dir ) use ( $args ) {
				return realpath( $args[0] );
			});
		}

		array_map( function( $path ) use ( $progress ) {
			$progress->tick();
			copy_asset( $path );
		}, $assets );
	}
}
