<?php
/** Only explicit public command projections enter this component. */
use AISkillsHub\Core\Frontend\Router;
defined( 'ABSPATH' ) || exit;
$installation = $args['installation']; $locale = $args['locale']; $zh = 'zh_CN' === $locale; $t = static fn( string $cn, string $en ): string => $zh ? $cn : $en;
$help = Router::siteUrl( '/' . ( $zh ? 'zh' : 'en' ) . '/help/' );
?>
<section class="aish-installation-experience" aria-labelledby="installation-copy-title" data-installation-experience data-copy-success="<?php echo esc_attr( $t( '完整命令已复制，未执行任何操作。', 'Complete command copied. Nothing was executed.' ) ); ?>" data-copy-failure="<?php echo esc_attr( $t( '未能复制。请选中代码块中的完整文本手动复制。', 'Could not copy. Select the complete text in the code block and copy it manually.' ) ); ?>">
	<h3 id="installation-copy-title" class="font-semibold"><?php echo esc_html( $t( '安装命令与来源', 'Installation commands and evidence' ) ); ?></h3>
	<?php if ( 'verified' === $installation['state'] ) : ?>
		<p class="aish-installation-note"><?php echo esc_html( 'repository' === $installation['kind'] ? $t( '以下命令经人工核对，与当前已审核正文及指定的仓库快照一致。核对不等于官方背书或安全认证。', 'These commands were manually checked against the current approved text and the specified repository snapshot. This is not official endorsement or a safety certification.' ) : $t( '以下命令来自当前已审核的人工编辑说明。不是仓库官方命令，不代表安全保证。', 'These commands come from the current approved editorial instructions. They are not repository-official commands or a safety guarantee.' ) ); ?></p>
		<p class="aish-installation-note"><?php echo esc_html( $t( '先阅读上方安装步骤、环境依赖和风险提示。仅复制文本，不自动运行或安装；请核对每项资源的许可证。', 'Read the installation steps, requirements and risk notes above first. Copying only transfers text: nothing runs or installs automatically. Check the license of each resource.' ) ); ?></p>
		<?php $platforms = array_column( $installation['platforms'], 'name', 'slug' ); foreach ( $installation['commands'] as $index => $row ) : $id = 'aish-installation-command-' . $index; ?>
			<div class="aish-installation-command">
				<div class="aish-installation-command-heading"><h4><?php echo esc_html( $t( '命令 ', 'Command ' ) . ( $index + 1 ) ); ?> · <?php echo esc_html( $platforms[ $row['platform'] ] ?? $t( '平台待核实', 'Platform not verified' ) ); ?></h4><button type="button" class="button button-secondary" data-copy-target="<?php echo esc_attr( $id ); ?>" hidden><?php echo esc_html( $t( '复制完整命令', 'Copy complete command' ) ); ?></button></div>
				<pre tabindex="0" aria-label="<?php echo esc_attr( $t( '可横向滚动的完整安装命令', 'Complete installation command; scroll horizontally' ) ); ?>"><code id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $row['command'] ); ?></code></pre>
			</div>
		<?php endforeach; ?>
		<p class="aish-copy-status" role="status" aria-live="polite" aria-atomic="true"></p>
		<dl class="aish-installation-facts"><div><dt><?php echo esc_html( $t( '核实来源', 'Checked evidence' ) ); ?></dt><dd><a href="<?php echo esc_url( $installation['source_url'] ); ?>"<?php echo 'repository' === $installation['kind'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo esc_html( $t( '查看依据', 'View evidence' ) ); ?></a></dd></div>
			<?php if ( $installation['commit_sha'] ) : ?><div><dt>Commit SHA</dt><dd><code><?php echo esc_html( $installation['commit_sha'] ); ?></code></dd></div><div><dt>File SHA</dt><dd><code><?php echo esc_html( $installation['file_sha'] ); ?></code></dd></div><?php endif; ?>
			<div><dt><?php echo esc_html( $t( '许可证', 'License' ) ); ?></dt><dd><?php echo esc_html( implode( ', ', array_column( $installation['licenses'], 'name' ) ) ); ?></dd></div><div><dt><?php echo esc_html( $t( '核实时间（UTC）', 'Checked at (UTC)' ) ); ?></dt><dd><?php echo esc_html( $installation['reviewed_at'] ); ?></dd></div>
		</dl>
	<?php else : ?>
		<p class="aish-installation-note"><?php echo esc_html( $t( '尚无当前有效的已核实复制命令，或来源、正文、许可等依据已变化。请阅读原说明并核对适用平台与依赖；不提供猜测的通用安装命令。', 'No currently verified copyable commands are available, or the source, text or license evidence has changed. Read the original instructions and check platform requirements and dependencies; no generic command is guessed.' ) ); ?></p>
	<?php endif; ?>
	<a class="aish-installation-help" href="<?php echo esc_url( $help ); ?>"><?php echo esc_html( $t( '安装遇到问题？查看帮助中心', 'Installation trouble? Visit the Help Center' ) ); ?> →</a>
</section>
