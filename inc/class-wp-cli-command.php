<?php

namespace Static_Page;
use WP_CLI;

class WP_CLI_Command extends \WP_CLI_Command {

	/**
	 * Get the contents of a URL that would be used for a statis page.
	 *
	 * @synopsis <url> [--replace-from=<from>] [--replace-to=<to>]
	 */
	public function output( $args, $args_assoc ) {
		if ( ! empty( $args_assoc['replace-from'] ) ) {
			add_filter( 'static_page_replace_urls_in_content', function( $contents ) use ( $args_assoc ) {
				return str_replace( $args_assoc['replace-from'], $args_assoc['replace-to'], $contents );
			});
		}
		echo replace_urls( get_url_contents( $args[0] ) );
	}

	/**
	 * Get all the URLs that would be saved to static pages.
	 */
	public function urls() {
		echo implode( "\n", get_site_urls() );
	}

	/**
	 * Save the contents to disk (or the destination directory).
	 *
	 * @synopsis [--url=<url>] [--replace-from=<from>] [--replace-to=<to>]
	 */
	public function save( $args, $args_assoc ) {
		$urls = ! empty( $args_assoc['url'] ) ? [ $args_assoc['url'] ] : get_site_urls();

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
		array_map( function( $content, $url ) use ( $progress ) {
			$progress->tick();
			save_contents_for_url( $content, $url );
		}, $contents, $urls );

		$progress->finish();
	}
}
