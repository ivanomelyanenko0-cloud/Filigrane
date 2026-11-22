<?php
/**
 * Compatibility and delivery:
 *
 * - Whole-page mode: page builders (Elementor, Divi, Bricks, Beaver
 *   Builder...) print images outside the_content, so the normal rewriting
 *   never sees them. With this on, the finished HTML of each front-end
 *   page goes through the same rewriting: <img> src/srcset, lazy-load
 *   data-src/data-srcset, <source> in <picture>, and background-image
 *   url(...) in inline styles.
 * - <picture> sources from WebP/AVIF plugins (WebP Express, EWWW,
 *   ShortPixel, Imagify) point at a converted copy of the original, which
 *   has no watermark. When the <img> in the same <picture> gets a
 *   watermark, those sources are pointed at the watermarked copy of their
 *   original instead, so no visitor gets an unmarked image.
 * - Social previews: Open Graph, Twitter cards and JSON-LD (Yoast, Rank
 *   Math, SEOPress, All in One SEO or the theme) show the watermarked copy
 *   by default; the "original" setting switches those tags back to the
 *   original file, wherever they come from.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The original-file URL of a WebP/AVIF copy another plugin made, when the
 * original is a Media Library file: photo.jpg.webp (WebP Express, EWWW),
 * photo.webp next to photo.jpg (ShortPixel, Imagify), and WebP Express's
 * mirror folder /wp-content/webp-express/webp-images/uploads/...
 *
 * @param string $url URL from a <source> srcset.
 * @return string Original image URL, or '' when it isn't such a copy.
 */
function wmguru_original_of_converted( $url ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( ! preg_match( '/\.(webp|avif)$/i', $path ) ) {
		return '';
	}

	$uploads = wmguru_uploads();
	if ( ! $uploads ) {
		return '';
	}

	// WebP Express mirror folder.
	$url = preg_replace( '#/webp-express/webp-images(?:/doc-root)?/(?:wp-content/)?uploads/#', '/uploads/', $url );

	$candidates = array();
	if ( preg_match( '/^(.+\.(?:jpe?g|png|gif))\.(?:webp|avif)$/i', $url, $m ) ) {
		$candidates[] = $m[1];
	} else {
		$base = preg_replace( '/\.(?:webp|avif)$/i', '', $url );
		foreach ( array( 'jpg', 'jpeg', 'png', 'gif' ) as $ext ) {
			$candidates[] = $base . '.' . $ext;
		}
	}

	foreach ( $candidates as $candidate ) {
		$rel = wmguru_url_to_rel( $candidate );
		if ( false !== $rel && file_exists( $uploads['basedir'] . '/' . $rel ) ) {
			return $candidate;
		}
	}
	return '';
}

/**
 * Rewrites a srcset where each entry may be a converted copy.
 *
 * @param string $srcset srcset value.
 * @return string
 */
function wmguru_rewrite_source_srcset( $srcset ) {
	$entries = explode( ',', $srcset );
	foreach ( $entries as $i => $entry ) {
		$bits = preg_split( '/\s+/', trim( $entry ), 2 );
		if ( ! $bits || '' === $bits[0] ) {
			continue;
		}
		$original = wmguru_original_of_converted( $bits[0] );
		$target   = '' !== $original ? $original : $bits[0];
		$new      = wmguru_rewrite_url( $target, 0 );
		// Only swap when the original really gets a watermark; otherwise keep the faster converted copy.
		if ( $new !== $target ) {
			$bits[0] = $new;
		}
		$entries[ $i ] = implode( ' ', $bits );
	}
	return implode( ', ', $entries );
}

/**
 * Whether a converted <source> would show an unmarked copy: its first URL
 * maps to an original that gets a watermark.
 *
 * @param string $srcset srcset value.
 * @return bool
 */
function wmguru_source_needs_rewrite( $srcset ) {
	return wmguru_rewrite_source_srcset( $srcset ) !== $srcset;
}

/**
 * Rewrites image URLs in a chunk of HTML: <img> (src, srcset, data-src,
 * data-srcset, data-lazy-src), <source> srcset, and url(...) in inline
 * style attributes.
 *
 * @param string $html HTML.
 * @return string
 */
function wmguru_rewrite_html( $html ) {
	if ( ! is_string( $html ) || '' === $html || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$uploads = wmguru_uploads();
	$path    = $uploads ? wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) : '';
	$has_up  = $path && false !== strpos( $html, $path );
	$has_wx  = false !== strpos( $html, '/webp-express/' );
	if ( ! $has_up && ! $has_wx ) {
		return $html;
	}
	if ( ! wmguru_should_rewrite() && false === strpos( $html, '/wmguru-cache/' ) ) {
		return $html;
	}

	$processor = new WP_HTML_Tag_Processor( $html );
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();

		if ( 'IMG' === $tag ) {
			$attachment_id = 0;
			$class         = $processor->get_attribute( 'class' );
			if ( is_string( $class ) && preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class, $m ) ) {
				$attachment_id = (int) $m[1];
			}
			foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-orig-file' ) as $attr ) {
				$value = $processor->get_attribute( $attr );
				if ( is_string( $value ) && '' !== $value ) {
					$new = wmguru_rewrite_url( $value, $attachment_id );
					if ( $new !== $value ) {
						$processor->set_attribute( $attr, $new );
					}
				}
			}
			foreach ( array( 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $attr ) {
				$value = $processor->get_attribute( $attr );
				if ( is_string( $value ) && '' !== $value ) {
					$new = wmguru_rewrite_srcset_string( $value, $attachment_id );
					if ( $new !== $value ) {
						$processor->set_attribute( $attr, $new );
					}
				}
			}
		} elseif ( 'SOURCE' === $tag ) {
			foreach ( array( 'srcset', 'data-srcset' ) as $attr ) {
				$value = $processor->get_attribute( $attr );
				if ( is_string( $value ) && '' !== $value ) {
					$new = wmguru_rewrite_source_srcset( $value );
					if ( $new !== $value ) {
						$processor->set_attribute( $attr, $new );
						// The watermarked copy keeps the original's format, not the converted one.
						$type = $processor->get_attribute( 'type' );
						if ( is_string( $type ) && preg_match( '#image/(webp|avif)#i', $type ) && ! preg_match( '/\.(?:webp|avif)(?:[?\s,]|$)/i', $new ) ) {
							$processor->remove_attribute( 'type' );
						}
					}
				}
			}
		}

		$style = $processor->get_attribute( 'style' );
		if ( is_string( $style ) && false !== stripos( $style, 'url(' ) ) {
			$new = preg_replace_callback(
				'/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
				function ( $m ) {
					$url = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
					return 'url(' . $m[1] . wmguru_rewrite_url( $url, 0 ) . $m[1] . ')';
				},
				$style
			);
			if ( $new !== $style ) {
				$processor->set_attribute( 'style', $new );
			}
		}
	}

	return $processor->get_updated_html();
}

/**
 * <picture> sources inside post content (the_content runs before
 * whole-page mode, and many sites only need this).
 *
 * @param string $content Post content.
 * @return string
 */
function wmguru_filter_content_sources( $content ) {
	if ( ! is_string( $content ) || false === stripos( $content, '<source' ) ) {
		return $content;
	}
	return wmguru_rewrite_html( $content );
}
add_filter( 'the_content', 'wmguru_filter_content_sources', 21 );

/* ---------- whole-page mode ---------- */

/**
 * @return bool
 */
function wmguru_buffer_enabled() {
	$settings = wmguru_get_settings();
	return ! empty( $settings['buffer'] );
}

function wmguru_maybe_start_buffer() {
	if ( ! wmguru_buffer_enabled() || ! wmguru_should_rewrite() || is_feed() || is_robots() || is_trackback() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_customize_preview() ) {
		return;
	}
	ob_start( 'wmguru_buffer_callback' );
}
add_action( 'template_redirect', 'wmguru_maybe_start_buffer', 9999 );

/**
 * @param string $html Whole page.
 * @return string
 */
function wmguru_buffer_callback( $html ) {
	// Only HTML pages: a template can send JSON, XML or a file.
	foreach ( headers_list() as $header ) {
		if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'html' ) ) {
			return $html;
		}
	}
	if ( false === stripos( substr( $html, 0, 2000 ), '<html' ) && false === stripos( substr( $html, 0, 2000 ), '<!doctype' ) ) {
		return $html;
	}
	return wmguru_rewrite_html( $html );
}

/* ---------- social previews ---------- */

/**
 * @return string 'watermarked' or 'original'.
 */
function wmguru_social_mode() {
	$settings = wmguru_get_settings();
	return isset( $settings['social'] ) && 'original' === $settings['social'] ? 'original' : 'watermarked';
}

function wmguru_head_start() {
	if ( 'original' === wmguru_social_mode() && wmguru_should_rewrite() ) {
		ob_start();
	}
}
add_action( 'wp_head', 'wmguru_head_start', -9999 );

function wmguru_head_end() {
	if ( 'original' !== wmguru_social_mode() || ! wmguru_should_rewrite() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the theme's and plugins' own <head> output, with only image URLs changed.
	echo wmguru_social_originals( (string) ob_get_clean() );
}
add_action( 'wp_head', 'wmguru_head_end', PHP_INT_MAX );

/**
 * Puts original URLs back into Open Graph / Twitter meta tags and JSON-LD.
 *
 * @param string $head <head> HTML.
 * @return string
 */
function wmguru_social_originals( $head ) {
	$head = preg_replace_callback(
		'/<meta\b[^>]*(?:property|name)=["\'](?:og:image(?::url|:secure_url)?|twitter:image(?::src)?)["\'][^>]*>/i',
		function ( $m ) {
			return preg_replace_callback(
				'/content=(["\'])(.*?)\1/i',
				function ( $c ) {
					return 'content=' . $c[1] . esc_attr( wmguru_original_url( html_entity_decode( $c[2], ENT_QUOTES, 'UTF-8' ) ) ) . $c[1];
				},
				$m[0]
			);
		},
		$head
	);

	return preg_replace_callback(
		'#(<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>)(.*?)(</script>)#is',
		function ( $m ) {
			$json = json_decode( $m[2], true );
			if ( null === $json ) {
				return $m[0];
			}
			$changed = false;
			array_walk_recursive(
				$json,
				function ( &$value ) use ( &$changed ) {
					if ( is_string( $value ) && ( false !== strpos( $value, '/wmguru-cache/' ) || false !== strpos( $value, 'wmguru_k=' ) ) ) {
						$value   = wmguru_original_url( $value );
						$changed = true;
					}
				}
			);
			if ( ! $changed ) {
				return $m[0];
			}
			return $m[1] . wp_json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . $m[3];
		},
		$head
	);
}

/* ---------- compatibility report ---------- */

/**
 * Image plugins Filigrane knows how it works with, as found on this site.
 *
 * @return array[] Each { name, status: ok|warning, text }.
 */
function wmguru_compatibility_report() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$known = array(
		'webp-express/webp-express.php'                        => array( 'WebP Express', 'picture' ),
		'ewww-image-optimizer/ewww-image-optimizer.php'        => array( 'EWWW Image Optimizer', 'picture' ),
		'shortpixel-image-optimiser/wp-shortpixel.php'         => array( 'ShortPixel', 'picture' ),
		'imagify/imagify.php'                                  => array( 'Imagify', 'picture' ),
		'wp-rocket/wp-rocket.php'                              => array( 'WP Rocket', 'cdn' ),
		'cdn-enabler/cdn-enabler.php'                          => array( 'CDN Enabler', 'cdn' ),
		'w3-total-cache/w3-total-cache.php'                    => array( 'W3 Total Cache', 'cdn' ),
		'litespeed-cache/litespeed-cache.php'                  => array( 'LiteSpeed Cache', 'cdn' ),
		'bunnycdn/bunnycdn.php'                                => array( 'Bunny CDN', 'cdn' ),
		'jetpack/jetpack.php'                                  => array( 'Jetpack', 'photon' ),
		'jetpack-boost/jetpack-boost.php'                      => array( 'Jetpack Boost', 'photon' ),
		'elementor/elementor.php'                              => array( 'Elementor', 'builder' ),
		'bb-plugin/fl-builder.php'                             => array( 'Beaver Builder', 'builder' ),
		'beaver-builder-lite-version/fl-builder.php'           => array( 'Beaver Builder', 'builder' ),
		'oxygen/functions.php'                                 => array( 'Oxygen', 'builder' ),
		'wordpress-seo/wp-seo.php'                             => array( 'Yoast SEO', 'seo' ),
		'seo-by-rank-math/rank-math.php'                       => array( 'Rank Math', 'seo' ),
		'all-in-one-seo-pack/all_in_one_seo_pack.php'          => array( 'All in One SEO', 'seo' ),
		'wp-seopress/seopress.php'                             => array( 'SEOPress', 'seo' ),
	);

	$buffer = wmguru_buffer_enabled();
	$out    = array();
	foreach ( $known as $file => $info ) {
		if ( ! is_plugin_active( $file ) ) {
			continue;
		}
		list( $name, $kind ) = $info;
		switch ( $kind ) {
			case 'picture':
				$out[] = array(
					'name'   => $name,
					'status' => 'ok',
					'text'   => __( 'WebP/AVIF copies in <picture> tags are switched to the watermarked image, so nobody gets an unmarked copy. If the plugin serves WebP by rewriting URLs on the server instead (no <picture> tag), visitors still get the watermarked URL, because Filigrane\'s copies live outside the folders it rewrites.', 'filigrane' ),
				);
				break;
			case 'cdn':
				$out[] = array(
					'name'   => $name,
					'status' => 'ok',
					'text'   => __( 'A pull CDN fetches the watermarked copies from your site like any other file. After changing the watermark, clear the CDN cache too, or it keeps serving the old copies until they expire.', 'filigrane' ),
				);
				break;
			case 'photon':
				$out[] = array(
					'name'   => $name,
					'status' => 'warning',
					'text'   => __( 'Jetpack\'s image CDN (Site Accelerator) fetches images from your Media Library itself and can bypass the watermark. Turn off "Speed up image load times" in Jetpack if images show up without a watermark.', 'filigrane' ),
				);
				break;
			case 'builder':
				$out[] = array(
					'name'   => $name,
					'status' => $buffer ? 'ok' : 'warning',
					'text'   => $buffer
						? __( 'Whole-page mode is on, so images the builder prints outside the post content get the watermark too.', 'filigrane' )
						: __( 'This builder prints many images outside the post content. Turn on whole-page mode below so they get the watermark too.', 'filigrane' ),
				);
				break;
			case 'seo':
				$out[] = array(
					'name'   => $name,
					'status' => 'ok',
					'text'   => 'original' === wmguru_social_mode()
						? __( 'Social previews (Open Graph, Twitter cards, Schema) use the original images, as set below.', 'filigrane' )
						: __( 'Social previews (Open Graph, Twitter cards, Schema) use the watermarked images. You can switch them to the originals below.', 'filigrane' ),
				);
				break;
		}
	}

	return array_values( array_unique( $out, SORT_REGULAR ) );
}
