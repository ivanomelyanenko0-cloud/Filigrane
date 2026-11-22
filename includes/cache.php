<?php
/**
 * On-disk cache of watermarked copies: {uploads}/wmguru-cache/{key}/{relative path}.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return string Absolute cache directory without trailing slash, '' when uploads are unusable.
 */
function wmguru_cache_dir() {
	$uploads = wmguru_uploads();

	return $uploads ? $uploads['basedir'] . '/wmguru-cache' : '';
}

/**
 * Create the cache directory (with an index.php) if it is missing.
 *
 * @return bool True when the directory exists and is writable.
 */
function wmguru_ensure_cache_dir() {
	$dir = wmguru_cache_dir();
	if ( '' === $dir ) {
		return false;
	}

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( is_dir( $dir ) && ! file_exists( $dir . '/index.php' ) ) {
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	return is_dir( $dir ) && wp_is_writable( $dir );
}

/**
 * @param string $key Cache key.
 * @param string $rel Path relative to the uploads directory.
 * @return string
 */
function wmguru_cache_file_path( $key, $rel ) {
	return wmguru_cache_dir() . '/' . $key . '/' . $rel;
}

/**
 * Recursively delete a directory that lives inside the cache.
 *
 * @param string $dir Directory to remove.
 */
function wmguru_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) || 0 !== strpos( realpath( $dir ), realpath( wmguru_cache_dir() ) ) ) {
		return;
	}

	$items = scandir( $dir );
	foreach ( false === $items ? array() : $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			wmguru_rrmdir( $path );
		} else {
			wp_delete_file( $path );
		}
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/**
 * Empty the whole cache (all keys).
 */
function wmguru_purge_all() {
	$dir = wmguru_cache_dir();
	foreach ( wmguru_cache_key_dirs() as $key_dir ) {
		wmguru_rrmdir( $key_dir );
	}
	do_action( 'wmguru_cache_purged', $dir );
}

/**
 * @return string[] Absolute paths of the per-key directories.
 */
function wmguru_cache_key_dirs() {
	$dir  = wmguru_cache_dir();
	$dirs = array();
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return $dirs;
	}

	foreach ( (array) scandir( $dir ) as $item ) {
		if ( '.' !== $item && '..' !== $item && is_dir( $dir . '/' . $item ) ) {
			$dirs[] = $dir . '/' . $item;
		}
	}

	return $dirs;
}

/**
 * Remove every cached copy of one attachment (main file and all sub-sizes).
 *
 * @param int        $attachment_id Attachment id.
 * @param array|null $metadata      Metadata to read file names from; defaults to the stored one.
 */
function wmguru_purge_attachment( $attachment_id, $metadata = null ) {
	$key_dirs = wmguru_cache_key_dirs();
	if ( ! $key_dirs ) {
		return;
	}

	foreach ( wmguru_attachment_rels( $attachment_id, $metadata ) as $rel ) {
		foreach ( $key_dirs as $key_dir ) {
			wp_delete_file( $key_dir . '/' . $rel );
		}
	}
}

/**
 * Every file of an attachment (main file, original and sub-sizes), as
 * paths relative to uploads.
 *
 * @param int        $attachment_id Attachment id.
 * @param array|null $metadata      Extra metadata to read file names from.
 * @return string[]
 */
function wmguru_attachment_rels( $attachment_id, $metadata = null ) {
	$rels = array();
	$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
	if ( $file ) {
		$rels[] = $file;
	}

	$metas = array( wp_get_attachment_metadata( $attachment_id ) );
	if ( is_array( $metadata ) ) {
		$metas[] = $metadata;
	}
	foreach ( $metas as $meta ) {
		if ( ! is_array( $meta ) ) {
			continue;
		}
		$folder = ! empty( $meta['file'] ) ? dirname( $meta['file'] ) : dirname( (string) $file );
		$folder = '.' === $folder ? '' : $folder . '/';
		if ( ! empty( $meta['file'] ) ) {
			$rels[] = $meta['file'];
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$rels[] = $folder . $meta['original_image'];
		}
		foreach ( isset( $meta['sizes'] ) ? (array) $meta['sizes'] : array() as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$rels[] = $folder . $size['file'];
			}
		}
	}

	return array_values( array_unique( $rels ) );
}

/**
 * Generates the watermarked copies of one attachment ahead of the first
 * visitor, for every size that gets a watermark.
 *
 * @param int $attachment_id Attachment id.
 * @return array{generated:int,cached:int,skipped:int,failed:int}
 */
function wmguru_warm_attachment( $attachment_id ) {
	$counts  = array(
		'generated' => 0,
		'cached'    => 0,
		'skipped'   => 0,
		'failed'    => 0,
	);
	$uploads = wmguru_uploads();
	if ( ! $uploads || ! wmguru_is_enabled() ) {
		return $counts;
	}
	$mime = get_post_mime_type( $attachment_id );

	foreach ( wmguru_attachment_rels( $attachment_id ) as $rel ) {
		$src     = $uploads['basedir'] . '/' . $rel;
		$profile = is_file( $src ) ? wmguru_watermark_profile_for( $attachment_id, $rel ) : null;
		if ( ! $profile ) {
			++$counts['skipped'];
			continue;
		}
		$dest = wmguru_cache_file_path( wmguru_profile_key( $profile ), $rel );
		if ( file_exists( $dest ) && filemtime( $dest ) >= filemtime( $src ) ) {
			++$counts['cached'];
			continue;
		}
		$result = wmguru_generate( $src, $dest, $profile, $mime, $attachment_id, $rel );
		if ( is_wp_error( $result ) ) {
			wmguru_log_error( $rel . ': ' . $result->get_error_message() );
			++$counts['failed'];
		} else {
			++$counts['generated'];
		}
	}

	return $counts;
}

/**
 * Images to warm, newest first.
 *
 * @param int   $limit  How many.
 * @param int   $offset Skip this many.
 * @param int[] $ids    Only these attachments, when given.
 * @return int[]
 */
function wmguru_warm_candidates( $limit, $offset = 0, $ids = array() ) {
	$args = array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => array_keys( wmguru_get_file_handlers() ),
		'posts_per_page' => $limit,
		'offset'         => $offset,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	);
	if ( $ids ) {
		$args['post__in'] = array_map( 'absint', $ids );
		$args['orderby']  = 'post__in';
	}
	return array_map( 'intval', get_posts( $args ) );
}

/**
 * Warms images newest first until the time budget runs out.
 *
 * @param int $limit   Most images to look at.
 * @param int $seconds Time budget.
 * @return array{images:int,generated:int,cached:int,skipped:int,failed:int,done:bool}
 */
function wmguru_warm_recent( $limit = 100, $seconds = 20 ) {
	$start  = microtime( true );
	$totals = array(
		'images'    => 0,
		'generated' => 0,
		'cached'    => 0,
		'skipped'   => 0,
		'failed'    => 0,
		'done'      => true,
	);
	foreach ( wmguru_warm_candidates( $limit ) as $id ) {
		if ( microtime( true ) - $start > $seconds ) {
			$totals['done'] = false;
			break;
		}
		foreach ( wmguru_warm_attachment( $id ) as $k => $v ) {
			$totals[ $k ] += $v;
		}
		++$totals['images'];
	}
	return $totals;
}

/**
 * Number of cached files and their total size. Capped so a huge cache never
 * stalls the settings page.
 *
 * @return array array( 'files' => int, 'bytes' => int, 'capped' => bool )
 */
function wmguru_cache_stats() {
	$stats = array(
		'files'  => 0,
		'bytes'  => 0,
		'capped' => false,
	);
	$dir   = wmguru_cache_dir();
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return $stats;
	}

	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'index.php' === $file->getFilename() ) {
			continue;
		}
		++$stats['files'];
		$stats['bytes'] += $file->getSize();
		if ( $stats['files'] >= 20000 ) {
			$stats['capped'] = true;
			break;
		}
	}

	return $stats;
}

/**
 * Remove key directories that no profile uses any more (older than a day, so
 * page-cached HTML that still points at the previous key keeps working).
 */
function wmguru_cleanup_stale() {
	$live = array();
	foreach ( wmguru_get_profiles() as $profile ) {
		$live[] = wmguru_profile_key( $profile );
	}

	foreach ( wmguru_cache_key_dirs() as $key_dir ) {
		if ( in_array( basename( $key_dir ), $live, true ) ) {
			continue;
		}
		if ( filemtime( $key_dir ) < time() - DAY_IN_SECONDS ) {
			wmguru_rrmdir( $key_dir );
		}
	}
}
add_action( 'wmguru_daily_cleanup', 'wmguru_cleanup_stale' );
