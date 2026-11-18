<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use MMAF\DataEngine\Services\Community\FanBoard;
use MMAF\DataEngine\Services\Community\FanLists;
use MMAF\DataEngine\Services\Community\Module;

// Template-scope variables are WordPress globals here. Never use names such as $page, $s or $post: get_header() resets them.
// The list itself is loaded by the script: it changes as the reader works, and a draft may be waiting in the browser.
status_header( 200 );
get_header();
?>
<main id="primary" class="mmafc-page">
	<div class="mmafc-shell">
		<?php Module::account_nav( 'top-10' ); ?>
		<header class="mmafc-head">
			<h1>My Top 10</h1>
			<p>Rank the prospects you rate highest. Your ten picks earn points in the <a class="mmafc-link" href="<?php echo esc_url( FanBoard::url( true ) ); ?>">fan ranking</a>: the higher you place a fighter, the more they get.</p>
		</header>

		<div class="mmafc-card mmafc-editor" data-mmafc-editor data-size="<?php echo (int) FanLists::SIZE; ?>">
			<p class="mmafc-note" data-mmafc-editor-note hidden></p>

			<section class="mmafc-editor__zone" aria-labelledby="mmafc-picks-title">
				<div class="mmafc-editor__head">
					<h2 class="mmafc-editor__title" id="mmafc-picks-title">Your Top 10</h2>
					<p class="mmafc-editor__total" data-mmafc-total></p>
				</div>
				<ol class="mmafc-rows mmafc-rows--picks" data-mmafc-picks aria-describedby="mmafc-picks-help"></ol>
				<p class="mmafc-hint" id="mmafc-picks-help">Drag a fighter by the handle, or use the arrows. With a keyboard, focus a handle and press the up and down arrow keys.</p>
				<button type="button" class="mmafc-button mmafc-button--secondary" data-mmafc-prefill hidden>Start from the official top 10</button>
			</section>

			<section class="mmafc-editor__zone mmafc-editor__zone--field" data-mmafc-field-zone aria-labelledby="mmafc-field-title">
				<div class="mmafc-editor__head">
					<h2 class="mmafc-editor__title" id="mmafc-field-title">The field</h2>
					<label class="mmafc-visually-hidden" for="mmafc-field-search">Search fighters</label>
					<input class="mmafc-input mmafc-input--search" id="mmafc-field-search" type="search" placeholder="Search fighters" autocomplete="off" data-mmafc-field-search>
				</div>
				<p class="mmafc-hint">Every ranked prospect, in official order. Add a fighter or drag them up into your Top 10.</p>
				<ul class="mmafc-rows mmafc-rows--field" data-mmafc-field></ul>
				<p class="mmafc-status" data-mmafc-field-status role="status" aria-live="polite"></p>
				<button type="button" class="mmafc-button mmafc-button--secondary" data-mmafc-field-more hidden>Show more fighters</button>
			</section>

			<p class="mmafc-visually-hidden" data-mmafc-live aria-live="assertive"></p>

			<div class="mmafc-share" data-mmafc-share hidden>
				<p class="mmafc-share__title" data-mmafc-share-title>Your Top 10 is saved.</p>
				<p class="mmafc-share__text" data-mmafc-share-text></p>
				<div class="mmafc-share__actions">
					<a class="mmafc-button mmafc-button--secondary" data-mmafc-share-link href="">View your profile</a>
					<button type="button" class="mmafc-button mmafc-button--quiet" data-mmafc-share-copy hidden>Copy link</button>
				</div>
				<p class="mmafc-status" data-mmafc-share-status role="status" aria-live="polite"></p>
			</div>

			<div class="mmafc-savebar">
				<p class="mmafc-savebar__status" data-mmafc-save-status role="status" aria-live="polite"></p>
				<button type="button" class="mmafc-button mmafc-button--quiet" data-mmafc-reset hidden>Reset</button>
				<button type="button" class="mmafc-button" data-mmafc-save>Save Top 10</button>
			</div>
		</div>
		<noscript><p class="mmafc-note mmafc-note--warning">Building your Top 10 needs JavaScript.</p></noscript>
	</div>
</main>
<?php
get_footer();
