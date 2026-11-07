<?php
declare(strict_types=1);

namespace AISkillsHub\Core\Commercial;

defined( 'ABSPATH' ) || exit;

/** Existing collection URLs and components retain their own publication and SEO boundaries. */
final class BundleFrontend {
	public function __construct( private Bundles $bundles ) {}
	public function register(): void {
		add_action( 'aish_collection_bundle_directory', array( $this, 'directory' ) );
		add_action( 'aish_collection_bundle_notice', array( $this, 'notice' ), 10, 2 );
	}
	public function directory( string $locale ): void {
		$rows = $this->bundles->publicEntries( $locale ); if ( ! $rows ) { return; }
		echo '<section class="discovery-module aish-resource-bundles"><h2>' . esc_html( 'zh_CN' === $locale ? '免费资源套装' : 'Free resource bundles' ) . '</h2><p class="discovery-filter-note">' . esc_html( 'zh_CN' === $locale ? '按学习与使用场景组织已有资源；每项内容保留自己的来源、作者和许可。' : 'Existing resources organized for learning and use cases. Each item retains its own source, authorship and license.' ) . '</p><div class="discovery-grid">';
		foreach ( $rows as $topic ) { get_template_part( 'templates/discovery/topic', null, array( 'topic' => $topic, 'locale' => $locale, 'route' => 'collections' ) ); }
		echo '</div></section>';
	}
	public function notice( int $collectionId, string $locale ): void {
		if ( ! in_array( $collectionId, array_column( $this->bundles->publicEntries( $locale ), 'id' ), true ) ) { return; }
		echo '<aside class="discovery-method aish-bundle-notice"><h2>' . esc_html( 'zh_CN' === $locale ? '免费资源套装' : 'Free resource bundle' ) . '</h2><p>' . esc_html( 'zh_CN' === $locale ? '查看各 Skill 的来源、许可证与经过核实的作者信息。来源或许可不明确时仍须核实。套装仅组织资源，不授予所有权、商业再分发许可或安全认证。' : 'Check each Skill’s source, license and verified author information. Unclear sources or licenses still require verification. This bundle organizes resources without granting ownership, commercial redistribution rights or safety certification.' ) . '</p></aside>';
	}
}
