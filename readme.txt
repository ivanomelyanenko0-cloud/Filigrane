=== Filigrane – Text & Logo Image Watermark, Originals Untouched ===
Contributors: lukystile
Tags: watermark, image watermark, photo watermark, protect images, copyright
Requires at least: 6.3
Tested up to: 7.1
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add a text or logo watermark to your images without changing the originals. Watermarked copies are made on the fly. JPEG, PNG, WebP, AVIF, GIF.

== Description ==

**Filigrane** is an image watermark plugin for WordPress that adds your text or logo watermark to photos. Your original files are **never touched**. Watermarked copies are created the first time an image is requested, stored in a cache, and swapped into your pages at output time.

With this design:

* **No "restore" needed.** Originals stay exactly as uploaded, so there is nothing to undo.
* **No quality loss on your originals.** Only the copy is re-encoded, at a quality you choose.
* **Deactivate = instantly clean.** Turn the plugin off and visitors get the untouched images straight away.
* **Works whatever way the image got in.** Because it acts on the URL, it does not matter whether images arrive through the Media Library, an importer, a gallery block or a regenerate-thumbnails run.
* **Modern formats.** JPEG, PNG, WebP, AVIF and (static) GIF.
* **Any language.** Bundled fonts cover Latin, Cyrillic and Greek.

= Features =

* Text watermark and/or logo watermark (up to two layers).
* Live preview rendered by the same engine that creates the real copies.
* Nine-position placement, margin, opacity, rotation.
* Size relative to each image, with minimum and maximum limits.
* Choose which image sizes get a watermark and skip small images.
* "Do not watermark this image" switch on every attachment.
* Text tokens: `{site}`, `{year}`, `{url}`.
* Works with GD or Imagick (chosen automatically); EXIF orientation is respected.
* Site Health checks for the image library, cache folder and delivery.
* Automatic fallback for hosts that do not pass missing image URLs to WordPress.
* Whole-page mode for page builders (Elementor, Divi, Bricks, Beaver Builder), sliders and themes: images anywhere on the page, including lazy-loaded and CSS background images.
* WebP/AVIF plugins (WebP Express, EWWW, ShortPixel, Imagify): their `<picture>` copies are switched to the watermarked image, so nobody gets an unmarked copy.
* Social previews: choose whether Open Graph, Twitter cards and Schema (Yoast, Rank Math, SEOPress, All in One SEO) show the watermarked or the original image.
* Cache warm-up from the settings screen, plus WP-CLI: `wp filigrane cache stats`, `wp filigrane cache purge`, `wp filigrane cache warm --all`.
* A compatibility list on the settings screen for the image, CDN, builder and SEO plugins found on your site.

Need several watermark profiles, unlimited layers, or rules that pick a watermark by post type, category or file type? They are in **[Filigrane Pro](https://cognitolab.net/products/filigrane)**, a separate, optional add-on. Everything described above works fully in the free plugin, with no restrictions or nag screens.

== External services ==

This plugin does not connect to any external service. No data leaves your site. Watermarked copies are stored in `wp-content/uploads/wmguru-cache`.

== Installation ==

1. Upload the `filigrane` folder to `/wp-content/plugins/`, or install it from the WordPress plugin directory.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Media → Filigrane**.
4. Set up your text and/or logo, check the live preview, then tick **Show watermarked images on the front end** and save.

== Frequently Asked Questions ==

= How do I add a watermark to images in WordPress? =

Install Filigrane, go to **Media → Filigrane**, add your text and/or logo, check the live preview, then tick **Show watermarked images on the front end** and save. Existing and new images get the watermark on your pages; the files in your Media Library stay as uploaded.

= Does it change my original files? =

No. Originals are never modified or re-encoded. Watermarked copies live in `wp-content/uploads/wmguru-cache` and can be deleted at any time; they are regenerated on demand.

= What happens if I deactivate or uninstall the plugin? =

Deactivating stops the URL swapping at once, so visitors see the originals. Uninstalling also deletes the cache folder. Your settings are kept unless you ticked "Also delete these settings".

= Can someone still get the original image? =

Yes, if they know the original file URL. Filigrane replaces the URLs your site outputs; it does not block direct requests to files in `wp-content/uploads`.

= Are animated GIFs and WebP watermarked? =

Not yet. They are served unchanged.

= It says direct file URLs do not work on my host. =

Some hosts answer 404 for missing image files without asking WordPress. Filigrane detects this and switches to signed query-string URLs automatically. Everything still works.

= Will my page-builder images be watermarked? =

Images output through WordPress image functions and post content always are. For builders that print image URLs from their own data, turn on **Whole-page mode** under Delivery & cache: every image on the page is then watermarked, including lazy-loaded images and CSS background images in inline styles.

= Does it work with WebP Express, EWWW, ShortPixel or Imagify? =

Yes. When those plugins wrap images in a `<picture>` tag with a WebP or AVIF copy, Filigrane points that copy at the watermarked image, so visitors whose browser picks the WebP never get an unmarked file. The settings screen lists the plugins it found and how it works with each.

= Does it work with a CDN? =

A pull CDN (WP Rocket, CDN Enabler, W3 Total Cache, LiteSpeed, Bunny) fetches the watermarked copies from your site like any other file. After changing the watermark, clear the CDN cache too. Jetpack's Site Accelerator fetches images from your Media Library itself and can bypass the watermark; turn off its image option if you see unmarked images.

= Which image do Facebook and other social sites show? =

The watermarked one, by default. Under **Social previews** you can switch Open Graph, Twitter card and Schema images back to the original file, whichever SEO plugin or theme prints them.

= Can I create the watermarked copies in advance? =

Yes. **Warm the cache** on the settings screen prepares the 100 newest images; on the command line `wp filigrane cache warm --all` does the whole library, and `wp filigrane cache warm 12 34` only the given attachments. `wp filigrane cache stats` and `wp filigrane cache purge` are there too.

= Which image library do I need? =

GD (with FreeType) or Imagick. Imagick keeps colour profiles and metadata of the source; GD drops them. AVIF needs Imagick with AVIF support, or PHP 8.1+ with GD AVIF support.

== Screenshots ==

1. Settings with the live preview. The preview is rendered by the same engine that creates the real copies.
2. Watermarked images on the front end: a logo and a text layer. The originals in the Media Library stay untouched.
3. Text layer: tokens, bundled fonts, size relative to each image with min/max limits, nine positions.
4. The "Do not watermark this image" switch on every attachment.

== Third-party resources ==

Bundled fonts: Noto Sans and Noto Serif, © The Noto Project Authors, licensed under the SIL Open Font License 1.1 (see `fonts/OFL.txt`).

== Changelog ==

= 1.1.0 =
* New: Whole-page mode for page builders, sliders and themes, covering lazy-loaded (data-src) and CSS background images.
* New: WebP/AVIF `<picture>` sources from WebP Express, EWWW, ShortPixel and Imagify are switched to the watermarked image.
* New: Social previews setting: watermarked or original image in Open Graph, Twitter cards and Schema.
* New: Warm the cache from the settings screen; WP-CLI commands `wp filigrane cache stats|purge|warm`.
* New: Compatibility list for the image, CDN, builder and SEO plugins active on your site.
* Fix: With query-string URLs (the fallback for hosts that don't pass missing images to WordPress), sub-size image URLs from `wp_get_attachment_image_src()` (featured images, WooCommerce) pointed at the site root and failed to load.
* Fix: Query-string watermark URLs saved in content are turned back into the original when watermarking does not apply.

= 1.0.0 =
* First public release.
