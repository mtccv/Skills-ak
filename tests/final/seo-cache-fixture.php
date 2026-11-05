<?php
declare(strict_types=1);

// Disposable-site configuration fixture, not an HTTP/SEO mock. It reproduces an
// administrator/extension explicitly enabling the real plugins' XML caches.
if ( ! defined( 'ABSPATH' ) || ! defined( 'DB_NAME' ) || 'qa_seo' !== DB_NAME ) { return; }
foreach ( array( 'wpseo_enable_xml_sitemap_transient_caching', 'rank_math/sitemap/enable_caching' ) as $hook ) {
	add_filter( $hook, static fn( $enabled ): bool => true, 99 );
}
