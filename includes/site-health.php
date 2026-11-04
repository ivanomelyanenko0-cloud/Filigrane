<?php
/**
 * Site Health integration: image library, cache directory and delivery checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array $tests Site Health tests.
 * @return array
 */
function wmguru_register_site_health_tests( $tests ) {
	$tests['direct']['wmguru_image_library'] = array(
		'label' => __( 'Filigrane image library', 'filigrane' ),
		'test'  => 'wmguru_test_image_library',
	);
	$tests['direct']['wmguru_delivery']      = array(
		'label' => __( 'Filigrane delivery', 'filigrane' ),
		'test'  => 'wmguru_test_delivery',
	);

	return $tests;
}
add_filter( 'site_status_tests', 'wmguru_register_site_health_tests' );

/**
 * @param string $label       Label.
 * @param string $status      good|recommended|critical.
 * @param string $description Description (plain text).
 * @param string $test        Test id.
 * @return array
 */
function wmguru_health_result( $label, $status, $description, $test ) {
	return array(
		'label'       => $label,
		'status'      => $status,
		'badge'       => array(
			'label' => __( 'Filigrane', 'filigrane' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html( $description ) . '</p>',
		'actions'     => '',
		'test'        => $test,
	);
}

/**
 * @return array
 */
function wmguru_test_image_library() {
	$support = wmguru_image_support();
	$missing = array();

	foreach ( array( 'image/jpeg', 'image/png', 'image/webp' ) as $mime ) {
		if ( empty( $support['gd'][ $mime ] ) && empty( $support['imagick'][ $mime ] ) ) {
			$missing[] = $mime;
		}
	}

	if ( $missing ) {
		return wmguru_health_result(
			__( 'No image library can watermark some common formats', 'filigrane' ),
			'critical',
			/* translators: %s: comma separated mime types */
			sprintf( __( 'Neither GD nor Imagick can process: %s. Ask your host to enable GD with FreeType, or Imagick.', 'filigrane' ), implode( ', ', $missing ) ),
			'wmguru_image_library'
		);
	}

	return wmguru_health_result(
		__( 'Filigrane has an image library', 'filigrane' ),
		'good',
		__( 'JPEG, PNG and WebP can be watermarked.', 'filigrane' ),
		'wmguru_image_library'
	);
}

/**
 * @return array
 */
function wmguru_test_delivery() {
	if ( ! wmguru_ensure_cache_dir() ) {
		return wmguru_health_result(
			__( 'The Filigrane cache directory is not writable', 'filigrane' ),
			'critical',
			__( 'Watermarked copies are stored in wp-content/uploads/wmguru-cache. Make the uploads directory writable by the web server.', 'filigrane' ),
			'wmguru_delivery'
		);
	}

	$status = get_option( 'wmguru_delivery_status', array() );
	if ( is_array( $status ) && isset( $status['pretty'] ) && false === $status['pretty'] ) {
		return wmguru_health_result(
			__( 'Watermarked images use the fallback delivery mode', 'filigrane' ),
			'recommended',
			__( 'Your server does not pass missing image URLs to WordPress, so Filigrane uses signed query-string URLs instead. Everything works; the URLs are just less pretty.', 'filigrane' ),
			'wmguru_delivery'
		);
	}

	return wmguru_health_result(
		__( 'Watermarked image delivery works', 'filigrane' ),
		'good',
		__( 'The cache directory is writable and image URLs are routed correctly.', 'filigrane' ),
		'wmguru_delivery'
	);
}
