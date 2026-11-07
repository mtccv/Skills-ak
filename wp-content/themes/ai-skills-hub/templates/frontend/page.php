<?php
use AISkillsHub\Core\Frontend\Router;
use AISkillsHub\Core\Frontend\View;

defined( 'ABSPATH' ) || exit;
$context = Router::context();
$data = $context['data'] ?? array();
$kind = in_array( $context['route'] ?? '', array( 'about', 'contact', 'submit' ), true ) ? $context['route'] : 'about';
$page = $data['page'] ?? null;
$form_status = (string) ( $data['form_status'] ?? '' );
$form_messages = array( 'sent', 'invalid', 'expired', 'limited', 'error' );
?>
<div class="aish-container py-8 sm:py-12">
	<?php echo View::breadcrumbs(); ?>
	<div class="grid items-start gap-10 py-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:gap-20 lg:py-16">
		<div>
			<p class="eyebrow mb-5"><?php echo esc_html( View::t( 'page_' . $kind . '_eyebrow' ) ); ?></p>
			<h1 class="max-w-2xl break-words text-4xl font-semibold leading-tight tracking-tight sm:text-5xl"><?php echo esc_html( ! empty( $page['title'] ) ? $page['title'] : View::t( 'page_' . $kind . '_title' ) ); ?></h1>
			<p class="mt-6 max-w-xl text-lg leading-8 muted"><?php echo esc_html( ! empty( $page['summary'] ) ? $page['summary'] : View::t( 'page_' . $kind . '_intro' ) ); ?></p>
			<?php if ( ! empty( $page['content'] ) ) : ?>
				<div class="prose-aish mt-9"><?php echo View::rich( (string) $page['content'] ); ?></div>
			<?php endif; ?>
			<?php if ( 'contact' === $kind ) { do_action( 'aish_contact_enterprise', (string) $context['locale'] ); } ?>
			<?php if ( 'about' !== $kind ) : ?>
				<div class="mt-9 rounded-2xl border border-slate-200 bg-white p-6">
					<h2 class="mb-3 text-base font-semibold"><?php echo esc_html( View::t( 'page_before_sending' ) ); ?></h2>
					<p class="text-sm leading-7 muted"><?php echo esc_html( View::t( 'page_' . $kind . '_guidance' ) ); ?></p>
				</div>
			<?php else : ?>
				<a class="button button-primary mt-8" href="<?php echo esc_url( View::url( 'skills' ) ); ?>"><?php echo esc_html( View::t( 'detail_browse_skills' ) ); ?><?php echo View::icon( 'arrow-right' ); ?></a>
			<?php endif; ?>
		</div>
		<?php if ( 'about' === $kind ) : ?>
			<div class="space-y-5">
				<?php foreach ( array( 'discover', 'understand', 'contribute' ) as $index => $principle ) : ?>
					<section class="surface surface-pad">
						<span class="mb-5 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 text-sm font-semibold text-violet-700" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $index + 1 ) ); ?></span>
						<h2 class="mb-3 text-xl font-semibold"><?php echo esc_html( View::t( 'page_about_' . $principle . '_title' ) ); ?></h2>
						<p class="text-sm leading-7 muted"><?php echo esc_html( View::t( 'page_about_' . $principle . '_copy' ) ); ?></p>
					</section>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<div class="surface surface-pad">
				<?php if ( in_array( $form_status, $form_messages, true ) ) : ?>
					<div class="mb-7 rounded-xl border p-4 text-sm leading-7 <?php echo 'sent' === $form_status ? 'border-emerald-200 bg-emerald-50 text-emerald-950' : 'border-amber-200 bg-amber-50 text-amber-950'; ?>" role="status">
						<?php echo esc_html( View::t( 'form_status_' . $form_status ) ); ?>
					</div>
				<?php endif; ?>
				<h2 class="mb-7 text-xl font-semibold"><?php echo esc_html( View::t( 'page_' . $kind . '_form_title' ) ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="space-y-6" x-data="{ submitting: false }" x-on:submit="submitting = true" x-bind:aria-busy="submitting">
					<input type="hidden" name="action" value="aish_public_message">
					<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>">
					<input type="hidden" name="locale" value="<?php echo esc_attr( $context['locale'] ); ?>">
					<?php wp_nonce_field( 'aish_public_message' ); ?>
					<div class="sr-only" aria-hidden="true"><label for="aish-website">Website</label><input id="aish-website" type="text" name="website" value="" tabindex="-1" autocomplete="off"></div>
					<div class="form-field">
						<label class="field-label" for="aish-name"><?php echo esc_html( View::t( 'form_name' ) ); ?> <span aria-hidden="true">*</span></label>
						<input class="field-input" id="aish-name" type="text" name="name" maxlength="120" autocomplete="name" required>
					</div>
					<div class="form-field">
						<label class="field-label" for="aish-email"><?php echo esc_html( View::t( 'form_email' ) ); ?> <span aria-hidden="true">*</span></label>
						<input class="field-input" id="aish-email" type="email" name="email" maxlength="254" autocomplete="email" required>
					</div>
					<?php if ( 'submit' === $kind ) : ?>
						<div class="form-field">
							<label class="field-label" for="aish-github"><?php echo esc_html( View::t( 'form_github_url' ) ); ?></label>
							<input class="field-input" id="aish-github" type="url" name="github_url" maxlength="2048" placeholder="https://github.com/owner/repository" aria-describedby="aish-github-help">
							<p id="aish-github-help" class="field-help"><?php echo esc_html( View::t( 'form_github_help' ) ); ?></p>
						</div>
					<?php endif; ?>
					<div class="form-field">
						<label class="field-label" for="aish-message"><?php echo esc_html( View::t( 'form_' . $kind . '_message' ) ); ?> <span aria-hidden="true">*</span></label>
						<textarea class="field-input min-h-44" id="aish-message" name="message" rows="7" maxlength="3000" required aria-describedby="aish-message-help"></textarea>
						<p id="aish-message-help" class="field-help"><?php echo esc_html( View::t( 'form_message_help' ) ); ?></p>
					</div>
					<p class="text-xs leading-6 muted"><?php echo esc_html( View::t( 'form_privacy' ) ); ?></p>
					<button class="button button-primary w-full disabled:cursor-wait disabled:opacity-60" type="submit" x-bind:disabled="submitting"><span x-show="!submitting"><?php echo esc_html( View::t( 'form_' . $kind . '_send' ) ); ?></span><span x-cloak x-show="submitting"><?php echo esc_html( View::t( 'form_sending' ) ); ?></span><?php echo View::icon( 'arrow-right' ); ?></button>
				</form>
			</div>
		<?php endif; ?>
	</div>
</div>
