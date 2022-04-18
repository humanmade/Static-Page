<?php

namespace Static_Page;
use WP_CLI;

class WP_CLI_Command extends \WP_CLI_Command {

	/**
	 * Get the contents of a URL that would be used for a statis page.
	 *
	 * @synopsis [--config=<config>] [<url>] [--replace-from=<from>] [--replace-to=<to>]
	 */
	public function output( $args, $args_assoc ) {
		if ( ! empty( $args_assoc['replace-from'] ) ) {
			add_filter( 'static_page_replace_urls_in_content', function( $contents ) use ( $args_assoc ) {
				return str_replace( $args_assoc['replace-from'], $args_assoc['replace-to'], $contents );
			});
		}

		$urls = ! empty( $args[0] ) ? [ $args[0] ] : get_site_urls( $args_assoc['config'] );
		$urls = array_unique( $urls );

		$contents = array_map( __NAMESPACE__ . '\\get_url_contents', $urls );

		$contents = array_map( __NAMESPACE__ . '\\replace_urls', $contents );

		array_map( function( $content, $url ) use ( $args_assoc ) {
			save_contents_for_url( $content, $url, $args_assoc['config'] );
		}, $contents, $urls );

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
	 * @synopsis [--config=<config>] [<path>] [--page-url=<url>] [--replace-from=<from>] [--replace-to=<to>] [--verbose]
	 */
	public function save( $args, $args_assoc ) {
		$urls = ! empty( $args_assoc['page-url'] ) ? [ $args_assoc['page-url'] ] : get_site_urls( $args_assoc['config'] );
		$urls = array_unique( $urls );

		$progress = WP_CLI\Utils\make_progress_bar( 'Fetching & Saving pages', count( $urls ) );

		$config = $args_assoc['config'] ?? null;
		$replace_form = $args_assoc['replace-from'] ?? '';
		$replace_to = $args_assoc['replace-to'] ?? '';
		$output_dir = $args[0] ?? '';

		$status = null;
		foreach( $urls as $url ) {
			$progress->tick();

			$pid = \pcntl_fork();

			if ( $pid == - 1 ) {
				die( 'could not fork' );
			} else if ( $pid ) {
				// we are the parent
				\pcntl_wait( $status ); //Protect against Zombie children
			} else {
				// Now we are in child process.

				$content = get_url_contents( $url, $config );
				// $content = get_url_contents( $url, $args_assoc['config'] );
				if ( is_wp_error( $content ) ) {
					WP_CLI::warning( $content->get_error_message() );
					$content = '';
				}

				if ( ! empty( $replace_form ) ) {
					add_filter( 'static_page_replace_urls_in_content', function( $contents ) use ( $replace_form, $replace_to ) {
						return str_replace( $replace_form, $replace_to, $contents );
					});
				}

				$content = replace_urls( $content, $config );

				if ( ! empty( $output_dir ) ) {
					add_filter( 'static_page_destination_directory', function( $dir ) use ( $output_dir ) {
						return $output_dir;
					});
				}

				$options = [
					'user_id' => get_current_user_id(),
					'context' => '',
					'action'  => '',
				];

				$post_id = url_to_postid( $url );
				if ( ! empty( $post_id ) ) {
					$post = get_post( $post_id );
					if ( $post instanceof  \WP_Post && $post->post_type === 'page' ) {
						$options['context'] = 'page';
						$options['action']  = 'wp_cli_netstorage_publish';
					}
				} else {
					$parsed_url = wp_parse_url( $url );
					if ( ! empty( $parsed_url ) ) {
						$args = array(
							'meta_key'   => 'netstorage_path',
							'meta_value' => substr( $parsed_url['path'], 1 ),
							'post_type'  => 'netstorage-file',
						);

						$query = new \WP_Query( $args );
						if ( ! empty( $query->post ) && $query->post->ID === $post_id ) { // Get the first post assuming no posts will have the same path.
							$options['context'] = 'netstorage-file';
							$options['action']  = 'wp_cli_netstorage_publish';
						}
					}
				}

				/**
				 * Action hook to pass data about netstorage export.
				 *
				 * @param int   $post_id  Post ID.
				 * @param array $options  Netstorage update data.
				 */
				do_action( 'ns_wp_cli_export_page', $post_id, $options );

				save_contents_for_url( $content, $url, $config );

				exit();
			}

		}

		while ( \pcntl_waitpid( 0, $status ) != - 1 ) {
			// Waiting till the pid finished.
		}

		$progress->finish();
	}

	/**
	 * @subcommand save-assets
	 * @synopsis [--config=<config>] [<path>] [--include=<whitelist-regex>] [--verbose]
	 */
	public function save_assets( $args, $args_assoc ) {
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
