<?php
/**
 * WP-CLI: `wp filigrane cache stats|purge|warm`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage Filigrane's cache of watermarked copies.
 */
class WMGURU_CLI_Cache {

	/**
	 * Show how many watermarked copies are cached and their size.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function stats( $args, $assoc_args ) {
		$stats = wmguru_cache_stats();
		$row   = array(
			'files' => $stats['files'] . ( $stats['capped'] ? '+' : '' ),
			'bytes' => $stats['bytes'],
			'size'  => size_format( $stats['bytes'] ),
			'dir'   => wmguru_cache_dir(),
		);
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', array( $row ), array_keys( $row ) );
	}

	/**
	 * Delete cached copies. Always safe: they are regenerated on demand.
	 *
	 * ## OPTIONS
	 *
	 * [<attachment-id>...]
	 * : Only these attachments. Without IDs the whole cache is cleared.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function purge( $args, $assoc_args ) {
		if ( ! $args ) {
			wmguru_purge_all();
			WP_CLI::success( __( 'Cache cleared.', 'filigrane' ) );
			return;
		}
		foreach ( array_map( 'absint', $args ) as $id ) {
			wmguru_purge_attachment( $id );
		}
		/* translators: %d: number of attachments. */
		WP_CLI::success( sprintf( _n( 'Cleared the cached copies of %d image.', 'Cleared the cached copies of %d images.', count( $args ), 'filigrane' ), count( $args ) ) );
	}

	/**
	 * Generate watermarked copies ahead of the first visitor, newest images first.
	 *
	 * ## OPTIONS
	 *
	 * [<attachment-id>...]
	 * : Only these attachments.
	 *
	 * [--limit=<number>]
	 * : How many images, newest first. Ignored with IDs or --all.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--all]
	 * : Every image in the Media Library.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function warm( $args, $assoc_args ) {
		if ( ! wmguru_is_enabled() ) {
			WP_CLI::error( __( 'Watermarking is turned off in Settings > Filigrane, so there is nothing to warm.', 'filigrane' ) );
		}

		$all   = ! empty( $assoc_args['all'] );
		$limit = $all ? 200 : max( 1, (int) ( $assoc_args['limit'] ?? 100 ) );
		$ids   = array_map( 'absint', $args );

		$totals = array(
			'images'    => 0,
			'generated' => 0,
			'cached'    => 0,
			'skipped'   => 0,
			'failed'    => 0,
		);
		$offset = 0;
		do {
			$batch = wmguru_warm_candidates( $ids ? count( $ids ) : $limit, $offset, $ids );
			foreach ( $batch as $id ) {
				foreach ( wmguru_warm_attachment( $id ) as $k => $v ) {
					$totals[ $k ] += $v;
				}
				++$totals['images'];
			}
			$offset += count( $batch );
			// Free memory between pages on a large library.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( $all && ! $ids && $batch );

		WP_CLI::success(
			sprintf(
				/* translators: 1: images, 2: files generated, 3: already cached, 4: sizes that get no watermark, 5: failures. */
				_n( '%1$d image: %2$d copies generated, %3$d already cached, %4$d sizes without a watermark, %5$d failed.', '%1$d images: %2$d copies generated, %3$d already cached, %4$d sizes without a watermark, %5$d failed.', $totals['images'], 'filigrane' ),
				$totals['images'],
				$totals['generated'],
				$totals['cached'],
				$totals['skipped'],
				$totals['failed']
			)
		);
	}
}

WP_CLI::add_command( 'filigrane cache', 'WMGURU_CLI_Cache' );
