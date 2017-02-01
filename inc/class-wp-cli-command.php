<?php

namespace Static_Page;
use WP_CLI;

class WP_CLI_Command extends \WP_CLI_Command {

	/**
	 * Get the contents of a URL that would be used for a statis page.
	 *
	 * @synopsis [<url>] [--replace-from=<from>] [--replace-to=<to>] [--config=<config>]
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
	 *
	 * @synopsis [--config=<config>]
	 */
	public function urls( $args, $args_assoc ) {
		$args_assoc = wp_parse_args( $args_assoc, array(
			'config' => null,
		));
		echo implode( "\n", get_site_urls( $args_assoc['config'] ) );
	}

	/**
	 * Get all the assets that would be saved to static pages.
	 *
	 * @synopsis [--config=<config>]
	 */
	public function assets( $args, $args_assoc ) {
		$args_assoc = wp_parse_args( $args_assoc, array(
			'config' => null,
		));
		echo implode( "\n", get_assets( $args_assoc['config'] ) );
	}

	/**
	 * Save the contents to disk (or the destination directory).
	 *
	 * @synopsis [<path>] [--page-url=<url>] [--replace-from=<from>] [--replace-to=<to>] [--verbose] [--config=<config>]
	 */
	public function save( $args, $args_assoc ) {
		$args_assoc = wp_parse_args( $args_assoc, array(
			'config' => null,
		));

		$urls = ! empty( $args_assoc['page-url'] ) ? [ $args_assoc['page-url'] ] : get_site_urls();

		$progress = WP_CLI\Utils\make_progress_bar( 'Fetching pages', count( $urls ) );
		$contents = array_map( function( $url ) use ( $progress ) {
			$contents = get_url_contents( $url, $args_assoc['config'] );
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
		array_map( function( $content, $url ) use ( $progress, $args_assoc ) {
			$progress->tick();
			if ( ! empty( $args_assoc['verbose'] ) ) {
				WP_CLI::line( 'Saving ' . $url );
			}
			save_contents_for_url( $content, $url, $args_assoc['config'] );
		}, $contents, $urls );

		$progress->finish();
	}

	/**
	 * @subcommand save-assets [<path>] [--include=<whitelist-regex>] [--verbose] [--config=<config>]
	 */
	public function save_assets( $args, $args_assoc ) {
		$args_assoc = wp_parse_args( $args_assoc, array(
			'config' => null,
		));

		if ( ! empty( $args_assoc['include'] ) ) {
			add_filter( 'static_page_assets', function( $assets ) use ( $args_assoc ) {
				return array_filter( $assets, function( $asset ) use ( $args_assoc ) {
					return preg_match( '#' . $args_assoc['include'] . '#', $asset );
				});
			});
		}

		$assets = get_assets( $args_assoc['config'] );
		$progress = WP_CLI\Utils\make_progress_bar( 'Saving assets', count( $assets ) );

		if ( ! empty( $args[0] ) ) {
			add_filter( 'static_page_destination_directory', function( $dir ) use ( $args ) {
				return realpath( $args[0] );
			});
		}

		array_map( function( $path ) use ( $progress, $args_assoc ) {
			$progress->tick();
			if ( ! empty( $args_assoc['verbose'] ) ) {
				WP_CLI::line( 'Copying ' . $path );
			}
			copy_asset( $path, $args_assoc['config'] );
		}, $assets );
	}
}
