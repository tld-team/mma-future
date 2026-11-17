<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use MMAF\DataEngine\Services\Community\Config;
use MMAF\DataEngine\Services\Community\Handles;
use MMAF\DataEngine\Services\Community\Module;
use MMAF\DataEngine\Services\Community\Profiles;
use MMAF\DataEngine\Services\Community\Socials;

// Template-scope variables are WordPress globals here. Never use names such as $page, $s or $post: get_header() resets them.
$mmafc_form   = ( new Profiles() )->editable( get_current_user_id() );
$mmafc_handle = '' !== $mmafc_form['handle'] ? $mmafc_form['handle'] : $mmafc_form['handle_suggestion'];
$mmafc_locked = (string) ( $mmafc_form['handle_locked_until'] ?? '' );
status_header( 200 );
get_header();
?>
<main id="primary" class="mmafc-page">
	<div class="mmafc-shell">
		<?php Module::account_nav( 'profile' ); ?>
		<header class="mmafc-head">
			<h1>Your profile</h1>
			<p>How other fans see you on MMA Future. Your email address is never shown.</p>
		</header>

		<?php if ( 'hidden' === $mmafc_form['status'] ) : ?>
			<p class="mmafc-note mmafc-note--warning" role="status">Your public profile is hidden by a moderator. You can still edit it.</p>
		<?php endif; ?>

		<form class="mmafc-card mmafc-form" data-mmafc-profile data-has-profile="<?php echo $mmafc_form['has_profile'] ? '1' : '0'; ?>" novalidate>
			<div class="mmafc-photo">
				<img class="mmafc-avatar mmafc-avatar--lg" data-mmafc-avatar src="<?php echo esc_url( $mmafc_form['avatar_url'] ); ?>" alt="" width="96" height="96">
				<div class="mmafc-photo__body">
					<p class="mmafc-label" id="mmafc-photo-label">Photo</p>
					<div class="mmafc-photo__actions">
						<input class="mmafc-visually-hidden" id="mmafc-avatar-input" type="file" accept="image/jpeg,image/png,image/webp" data-mmafc-avatar-input aria-describedby="mmafc-photo-hint mmafc-photo-error">
						<label class="mmafc-button mmafc-button--secondary" for="mmafc-avatar-input">Upload photo</label>
						<button type="button" class="mmafc-button mmafc-button--quiet" data-mmafc-avatar-remove<?php echo $mmafc_form['has_avatar'] ? '' : ' hidden'; ?>>Remove</button>
					</div>
					<p class="mmafc-hint" id="mmafc-photo-hint">JPG, PNG or WebP, up to <?php echo esc_html( size_format( Config::avatar_max_bytes() ) ); ?>. It’s cropped to a square.</p>
					<p class="mmafc-error" id="mmafc-photo-error" data-mmafc-error="avatar" role="alert" hidden></p>
				</div>
			</div>

			<div class="mmafc-field">
				<label class="mmafc-label" for="mmafc-display-name">Display name</label>
				<input class="mmafc-input" id="mmafc-display-name" name="display_name" type="text" maxlength="<?php echo (int) Profiles::NAME_MAX; ?>" autocomplete="nickname" value="<?php echo esc_attr( $mmafc_form['display_name'] ); ?>" placeholder="Nikola the Grappler" aria-describedby="mmafc-display-name-error">
				<p class="mmafc-error" id="mmafc-display-name-error" data-mmafc-error="display_name" role="alert" hidden></p>
			</div>

			<div class="mmafc-field">
				<label class="mmafc-label" for="mmafc-handle">Handle</label>
				<div class="mmafc-affix">
					<span class="mmafc-affix__text" aria-hidden="true">@</span>
					<input class="mmafc-input" id="mmafc-handle" name="handle" type="text" autocapitalize="none" autocomplete="off" spellcheck="false" minlength="<?php echo (int) Handles::MIN; ?>" maxlength="<?php echo (int) Handles::MAX; ?>" pattern="[A-Za-z0-9_]+" value="<?php echo esc_attr( $mmafc_handle ); ?>" data-mmafc-handle data-saved="<?php echo esc_attr( $mmafc_form['handle'] ); ?>"<?php echo '' !== $mmafc_locked ? ' readonly' : ''; ?> aria-describedby="mmafc-handle-hint mmafc-handle-status mmafc-handle-error" required>
				</div>
				<p class="mmafc-hint" id="mmafc-handle-hint">
					<?php if ( '' !== $mmafc_locked ) : ?>
						You can change your handle again on <?php echo esc_html( $mmafc_locked ); ?>.
					<?php else : ?>
						Your profile address: <?php echo esc_html( preg_replace( '#^https?://#', '', home_url( '/fan/' ) ) ); ?><strong data-mmafc-handle-preview><?php echo esc_html( $mmafc_handle ); ?></strong>. Letters, numbers and underscores.
					<?php endif; ?>
				</p>
				<p class="mmafc-status" id="mmafc-handle-status" data-mmafc-handle-status aria-live="polite"></p>
				<p class="mmafc-error" id="mmafc-handle-error" data-mmafc-error="handle" role="alert" hidden></p>
			</div>

			<div class="mmafc-field">
				<div class="mmafc-field__top">
					<label class="mmafc-label" for="mmafc-bio">Bio</label>
					<span class="mmafc-counter" data-mmafc-bio-count aria-hidden="true"></span>
				</div>
				<textarea class="mmafc-input mmafc-input--area" id="mmafc-bio" name="bio" rows="4" maxlength="<?php echo (int) Profiles::BIO_MAX; ?>" data-mmafc-bio placeholder="Regional scene nerd. Calling prospects before they sign." aria-describedby="mmafc-bio-error"><?php echo esc_textarea( $mmafc_form['bio'] ); ?></textarea>
				<p class="mmafc-error" id="mmafc-bio-error" data-mmafc-error="bio" role="alert" hidden></p>
			</div>

			<fieldset class="mmafc-fieldset">
				<legend class="mmafc-label">Social links</legend>
				<p class="mmafc-hint">Enter a username or paste your profile link. Leave blank to skip.</p>
				<div class="mmafc-socials">
					<?php foreach ( Socials::PLATFORMS as $mmafc_platform => $mmafc_rule ) : ?>
						<div class="mmafc-field mmafc-field--inline">
							<label class="mmafc-sublabel" for="mmafc-social-<?php echo esc_attr( $mmafc_platform ); ?>"><?php echo esc_html( $mmafc_rule['label'] ); ?></label>
							<input class="mmafc-input" id="mmafc-social-<?php echo esc_attr( $mmafc_platform ); ?>" name="socials[<?php echo esc_attr( $mmafc_platform ); ?>]" data-mmafc-social="<?php echo esc_attr( $mmafc_platform ); ?>" type="text" autocapitalize="none" autocomplete="off" spellcheck="false" maxlength="120" value="<?php echo esc_attr( $mmafc_form['socials'][ $mmafc_platform ] ?? '' ); ?>" placeholder="username" aria-describedby="mmafc-social-<?php echo esc_attr( $mmafc_platform ); ?>-error">
							<p class="mmafc-error" id="mmafc-social-<?php echo esc_attr( $mmafc_platform ); ?>-error" data-mmafc-error="socials.<?php echo esc_attr( $mmafc_platform ); ?>" role="alert" hidden></p>
						</div>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<div class="mmafc-savebar">
				<p class="mmafc-savebar__status" data-mmafc-save-status role="status" aria-live="polite"></p>
				<a class="mmafc-button mmafc-button--quiet" data-mmafc-public-link href="<?php echo esc_url( $mmafc_form['url'] ); ?>"<?php echo $mmafc_form['has_profile'] ? '' : ' hidden'; ?>>View public profile</a>
				<button type="submit" class="mmafc-button" data-mmafc-save>Save profile</button>
			</div>
		</form>
		<noscript><p class="mmafc-note mmafc-note--warning">Editing your profile needs JavaScript.</p></noscript>
	</div>
</main>
<?php
get_footer();
