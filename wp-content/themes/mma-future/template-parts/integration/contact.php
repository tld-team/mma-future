<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $id = wp_unique_id( 'mmaf-contact-' ); ?>

<section class="contact-main-section py-10 sm:py-14">
<div class="contact-main-section__container max-w-6xl mx-auto px-2">
<!-- Top header -->
<div class="contact-main-section__top">
<a class="contact-main-section__back inline-flex items-center gap-2 text-sm !text-slate-500 hover:!text-slate-700 transition-colors focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0" href="<?php echo esc_url( home_url( '/blog/' ) ); ?>">
<svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewbox="0 0 24 24">
<path d="M15 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round"></path>
</svg>
                Back to blog
            </a>
<h1 class="contact-main-section__title mt-3 text-4xl sm:text-5xl font-extrabold tracking-tight !text-slate-900"><?php echo esc_html( mmaf_site_field( 'section_title' ) ); ?></h1>
<p class="contact-main-section__subtitle mt-4 max-w-2xl text-base sm:text-lg !text-slate-600 leading-relaxed"><?php echo esc_html( mmaf_site_field( 'section_description' ) ); ?></p>
<p class="contact-main-section__note mt-2 text-sm !text-slate-500"><?php echo esc_html( mmaf_site_field( 'response_note' ) ); ?></p>
</div>
<!-- Main layout -->
<div class="contact-main-section__layout mt-10 grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-8 items-start">
<!-- Left: Form card -->
<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="contact-main-section__form !rounded-2xl !border !border-slate-200 !bg-white p-6 sm:p-8 mmaf-contact-form" data-mmaf-contact="" id="<?php echo esc_attr( $id . 'form' ); ?>" method="post"><?php if ( isset( $_GET['contact_status'] ) ) : ?><p role="status"><?php echo esc_html( 'sent' === sanitize_key( $_GET['contact_status'] ) ? ( mmaf_site_setting( 'contact_success_message' ) ?: 'Your message has been accepted.' ) : 'Unable to send the message. Please check the form and try again.' ); ?></p><?php endif; ?>
<!-- Reason selection -->
<fieldset class="contact-main-section__card">
<legend class="contact-main-section__card-title text-lg font-bold !text-slate-900"><?php echo esc_html( mmaf_site_field( 'form_title' ) ); ?></legend>
<div class="contact-main-section__reasons mt-4 space-y-3"><?php foreach ( (array) mmaf_site_field( 'contact_reasons' ) as $row ) : ?><label class="contact-main-section__reason block cursor-pointer !rounded-xl !border !border-slate-200 !bg-white transition-all hover:!bg-slate-50 hover:!border-slate-300 has-[:checked]:!border-[#0047A8]/50 has-[:checked]:!bg-[rgba(0,71,168,0.06)]">
<span class="flex items-center gap-4 px-4 py-4">
<input class="contact-main-section__reason-radio appearance-none flex-shrink-0 h-[18px] w-[18px] !rounded-full !border-2 !border-slate-300 !bg-white cursor-pointer transition-all checked:!border-[#0047A8] checked:!bg-[#0047A8] checked:!shadow-[inset_0_0_0_3px_white] focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0" name="reason" required="" type="radio" value="<?php echo esc_attr( sanitize_title( $row['reason_title'] ) ); ?>"/>
<span class="contact-main-section__reason-content">
<span class="contact-main-section__reason-title block text-sm font-semibold !text-slate-900"><?php echo esc_html( $row['reason_title'] ); ?></span>
<span class="contact-main-section__reason-text block text-xs !text-slate-500 mt-0.5"><?php echo esc_html( $row['reason_description'] ); ?></span>
</span>
</span>
</label><?php endforeach; ?></div>
<span class="mmaf-field-error" data-error-for="reason"></span></fieldset>
<!-- Your details -->
<div class="mt-6">
<h2 class="contact-main-section__card-title text-lg font-bold !text-slate-900">
                        Your details
                    </h2>
<div class="contact-main-section__fields mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
<div class="contact-main-section__field">
<label class="contact-main-section__label block mb-2 text-sm font-medium !text-slate-700" for="<?php echo esc_attr( $id . 'name' ); ?>"><?php echo esc_html( mmaf_site_field( 'form_name_label' ) ); ?> *</label>
<input class="contact-main-section__input h-11 w-full !rounded-lg !border !border-slate-300/70 !bg-white pl-3 pr-3 text-sm !text-slate-900 placeholder:!text-slate-400 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0" id="<?php echo esc_attr( $id . 'name' ); ?>" maxlength="120" name="name" placeholder="<?php echo esc_attr( mmaf_site_field( 'form_name_placeholder' ) ); ?>" required="" type="text"/>
<span class="mmaf-field-error" data-error-for="name"></span></div>
<div class="contact-main-section__field">
<label class="contact-main-section__label block mb-2 text-sm font-medium !text-slate-700" for="<?php echo esc_attr( $id . 'email' ); ?>"><?php echo esc_html( mmaf_site_field( 'form_email_label' ) ); ?> *</label>
<input class="contact-main-section__input h-11 w-full !rounded-lg !border !border-slate-300/70 !bg-white pl-3 pr-3 text-sm !text-slate-900 placeholder:!text-slate-400 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0" id="<?php echo esc_attr( $id . 'email' ); ?>" maxlength="254" name="email" placeholder="<?php echo esc_attr( mmaf_site_field( 'form_email_placeholder' ) ); ?>" required="" type="email"/>
<span class="mmaf-field-error" data-error-for="email"></span></div>
<div class="contact-main-section__field">
<label class="contact-main-section__label block mb-2 text-sm font-medium !text-slate-700" for="<?php echo esc_attr( $id . 'organization' ); ?>"><?php echo esc_html( mmaf_site_field( 'form_organization_label' ) ); ?></label>
<input class="contact-main-section__input h-11 w-full !rounded-lg !border !border-slate-300/70 !bg-white pl-3 pr-3 text-sm !text-slate-900 placeholder:!text-slate-400 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0" id="<?php echo esc_attr( $id . 'organization' ); ?>" maxlength="160" name="organization" placeholder="<?php echo esc_attr( mmaf_site_field( 'form_organization_placeholder' ) ); ?>" type="text"/>
<span class="mmaf-field-error" data-error-for="organization"></span></div>
<div class="contact-main-section__field">
<label class="contact-main-section__label block mb-2 text-sm font-medium !text-slate-700" for="<?php echo esc_attr( $id . 'website' ); ?>"><?php echo esc_html( mmaf_site_field( 'form_website_label' ) ); ?></label>
<input class="contact-main-section__input h-11 w-full !rounded-lg !border !border-slate-300/70 !bg-white pl-3 pr-3 text-sm !text-slate-900 placeholder:!text-slate-400 focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0" id="<?php echo esc_attr( $id . 'website' ); ?>" maxlength="500" name="website" placeholder="<?php echo esc_attr( mmaf_site_field( 'form_website_placeholder' ) ); ?>" type="url"/>
<span class="mmaf-field-error" data-error-for="website"></span></div>
</div>
</div>
<!-- Message -->
<div class="mt-6">
<label class="contact-main-section__label block mb-2 text-sm font-medium !text-slate-700" for="<?php echo esc_attr( $id . 'message' ); ?>"><?php echo esc_html( mmaf_site_field( 'form_message_label' ) ); ?> *</label>
<textarea class="contact-main-section__textarea w-full !rounded-lg !border !border-slate-300/70 !bg-white pl-3 pr-3 py-3 text-sm !text-slate-900 placeholder:!text-slate-400 min-h-[140px] resize-y focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0" id="<?php echo esc_attr( $id . 'message' ); ?>" maxlength="6000" name="message" placeholder="<?php echo esc_attr( mmaf_site_field( 'form_message_placeholder' ) ); ?>" required=""></textarea>
<span class="mmaf-field-error" data-error-for="message"></span></div>
<!-- Privacy -->
<p class="contact-main-section__privacy mt-3 text-xs !text-slate-500 leading-relaxed"><?php echo esc_html( mmaf_site_field( 'privacy_notice' ) ); ?></p>
<!-- Actions -->
<div class="contact-main-section__actions mt-6 flex flex-wrap items-center gap-4">
<button class="contact-main-section__submit group inline-flex items-center gap-2 h-11 px-5 !rounded-xl !bg-[#0047A8] !text-white text-sm font-semibold hover:!bg-[#003a8a] transition-colors active:translate-y-[1px] focus:outline-none focus-visible:outline-none focus:ring-0 focus-visible:ring-0" type="submit"><?php echo esc_html( mmaf_site_field( 'submit_label' ) ); ?><svg aria-hidden="true" class="w-4 h-4 transition-transform duration-200 group-hover:translate-x-0.5" fill="none" stroke="currentColor" stroke-width="2" viewbox="0 0 24 24">
<path d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" stroke-linecap="round" stroke-linejoin="round"></path>
</svg></button>
<?php if ( mmaf_site_setting( 'contact_email' ) ) : ?><span class="contact-main-section__alt text-sm !text-slate-500">Or email us directly: <a class="underline underline-offset-2" href="<?php echo esc_attr( 'mailto:' . mmaf_site_setting( 'contact_email' ) ); ?>"><?php echo esc_html( mmaf_site_setting( 'contact_email' ) ); ?></a></span><?php endif; ?>
</div>
<input type="hidden" name="action" value="mmaf_contact"><input type="hidden" name="contact_nonce" value="<?php echo esc_attr( wp_create_nonce( 'mmaf_contact' ) ); ?>"><input type="hidden" name="contact_form_id" value="<?php echo esc_attr( $id . 'form' ); ?>"><div class="mmaf-honeypot" aria-hidden="true"><label>Leave this blank<input name="company_fax" tabindex="-1" autocomplete="off"></label></div><p class="mmaf-contact-status" role="status" tabindex="-1"></p></form>
<!-- Right: Sidebar -->
<div class="contact-main-section__sidebar space-y-6">
<!-- Other ways to reach us -->
<?php if ( mmaf_site_setting( 'contact_email' ) || mmaf_site_setting( 'contact_phone' ) || mmaf_site_setting( 'contact_location' ) || mmaf_site_setting( 'social_links' ) ) : ?><div class="contact-main-section__sidebar-card !rounded-2xl !border !border-slate-200/70 !bg-white p-5 transition-all duration-200 hover:shadow-sm hover:!border-slate-300">
<h3 class="contact-main-section__sidebar-title text-sm font-bold !text-slate-900">
                        Other ways to reach us
                    </h3>
<div class="contact-main-section__sidebar-items mt-3 space-y-2"><?php mmaf_render_contact_details( 'contact-main-section__sidebar-link contact-main-section__sidebar-item text-sm !text-slate-600 inline-flex items-center gap-2' ); mmaf_social_links( (array) mmaf_site_setting( 'social_links' ), 'contact-main-section__sidebar-link contact-main-section__sidebar-item text-sm !text-slate-600 inline-flex items-center gap-2' ); ?></div>
</div>
<?php endif; ?><!-- Quick links -->
<?php if ( mmaf_site_field( 'quick_links' ) ) : ?><div class="contact-main-section__sidebar-card !rounded-2xl !border !border-slate-200/70 !bg-white p-5 transition-all duration-200 hover:shadow-sm hover:!border-slate-300">
<h3 class="contact-main-section__sidebar-title text-sm font-bold !text-slate-900">
                        Quick links
                    </h3>
<div class="contact-main-section__sidebar-items mt-3 space-y-2"><?php foreach ( (array) mmaf_site_field( 'quick_links' ) as $row ) { mmaf_presentation_link( $row['link'] ?? null, 'contact-main-section__sidebar-link contact-main-section__sidebar-item text-sm !text-slate-600 inline-flex items-center gap-2' ); } ?></div>
</div><?php endif; ?>
</div>
</div>
</div>
</section>
