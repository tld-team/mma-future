<?php
/**
 * Fighter notification email. Rendered by EmailComposer with a prepared $m view model.
 *
 * Email clients ignore stylesheets and most layout CSS, so this is nested tables with inline styles,
 * square pre-cropped photos (Outlook ignores object-fit) and buttons built from a coloured table cell.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$font   = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
$corner = static function( array $fighter ) use ( $font ): string {
	$name  = $fighter['url'] ? '<a href="' . esc_url( $fighter['url'] ) . '" style="color:#0f172a;text-decoration:none;">' . esc_html( $fighter['name'] ) . '</a>' : esc_html( $fighter['name'] );
	$photo = $fighter['photo']
		? '<img src="' . esc_url( $fighter['photo'] ) . '" width="72" height="72" alt="' . esc_attr( $fighter['name'] ) . '" style="display:block;width:72px;height:72px;border-radius:36px;border:0;">'
		: '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="72" height="72" align="center" valign="middle" bgcolor="#e2e8f0" style="width:72px;height:72px;border-radius:36px;' . $font . 'font-size:22px;font-weight:700;color:#475569;">' . esc_html( $fighter['initials'] ) . '</td></tr></table>';
	$html  = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td align="center">' . $photo . '</td></tr></table>';
	$html .= '<div style="' . $font . 'font-size:16px;line-height:22px;font-weight:700;color:#0f172a;padding-top:10px;">' . $name . '</div>';
	if ( $fighter['followed'] ) { $html .= '<div style="' . $font . 'font-size:12px;line-height:18px;color:#0047a8;font-weight:600;">You follow</div>'; }
	return $html;
};
$button = static function( string $url, string $label, bool $primary ) use ( $font ): string {
	$cell = $primary ? 'bgcolor="#0047a8" style="border-radius:8px;"' : 'bgcolor="#ffffff" style="border-radius:8px;border:1px solid #0047a8;"';
	$link = $primary ? 'color:#ffffff;' : 'color:#0047a8;';
	return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td align="center" ' . $cell . '><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:13px 26px;' . $font . 'font-size:15px;line-height:20px;font-weight:700;text-decoration:none;' . $link . '">' . esc_html( $label ) . '</a></td></tr></table>';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title><?php echo esc_html( $m['subject'] ); ?></title>
<style>@media only screen and (max-width:480px){.mmaf-pad{padding-left:18px!important;padding-right:18px!important}.mmaf-h1{font-size:22px!important;line-height:28px!important}}</style>
</head>
<body style="margin:0;padding:0;background:#eef2f7;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#eef2f7;font-size:1px;line-height:1px;"><?php echo esc_html( $m['preheader'] ); ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef2f7" style="background:#eef2f7;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">

<tr><td align="center" bgcolor="#0b1220" style="background:#0b1220;border-radius:16px 16px 0 0;padding:22px 24px;">
	<a href="<?php echo esc_url( $m['home_url'] ); ?>" style="text-decoration:none;">
	<?php if ( $m['logo_url'] ) : ?>
		<img src="<?php echo esc_url( $m['logo_url'] ); ?>" width="92" height="64" alt="MMA Future" style="display:block;width:92px;height:64px;border:0;<?php echo $font; ?>font-size:20px;font-weight:800;color:#ffffff;">
	<?php else : ?>
		<span style="<?php echo $font; ?>font-size:22px;font-weight:800;letter-spacing:1px;color:#ffffff;">MMA FUTURE</span>
	<?php endif; ?>
	</a>
</td></tr>

<tr><td class="mmaf-pad" bgcolor="#ffffff" style="background:#ffffff;padding:32px 32px 20px;">
	<div style="<?php echo $font; ?>font-size:12px;line-height:16px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#0047a8;"><?php echo esc_html( $m['eyebrow'] ); ?></div>
	<h1 class="mmaf-h1" style="margin:8px 0 10px;<?php echo $font; ?>font-size:26px;line-height:32px;font-weight:800;color:#0f172a;"><?php echo esc_html( $m['headline'] ); ?></h1>
	<p style="margin:0;<?php echo $font; ?>font-size:16px;line-height:24px;color:#475569;"><?php echo esc_html( $m['intro'] ); ?></p>
</td></tr>

<?php foreach ( $m['events'] as $event ) : ?>
<tr><td class="mmaf-pad" bgcolor="#ffffff" style="background:#ffffff;padding:0 32px 24px;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e2e8f0;border-radius:12px;border-collapse:separate;">
	<tr><td bgcolor="#f8fafc" style="background:#f8fafc;border-radius:12px 12px 0 0;padding:16px 20px;">
		<?php if ( $event['promotion'] ) : ?><div style="<?php echo $font; ?>font-size:12px;line-height:16px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#64748b;"><?php echo esc_html( $event['promotion'] ); ?></div><?php endif; ?>
		<div style="<?php echo $font; ?>font-size:18px;line-height:24px;font-weight:700;color:#0f172a;padding-top:2px;"><?php echo esc_html( $event['title'] ); ?></div>
		<div style="<?php echo $font; ?>font-size:14px;line-height:20px;color:#0f172a;padding-top:6px;"><?php echo esc_html( $event['when'] ); ?></div>
		<?php if ( $event['where'] ) : ?><div style="<?php echo $font; ?>font-size:14px;line-height:20px;color:#64748b;"><?php echo esc_html( $event['where'] ); ?></div><?php endif; ?>
	</td></tr>
	<?php foreach ( $event['bouts'] as $bout ) : ?>
	<tr><td style="border-top:1px solid #e2e8f0;padding:20px 16px 22px;">
		<div style="text-align:center;padding-bottom:14px;">
			<?php foreach ( $bout['labels'] as $label ) : ?><span style="display:inline-block;<?php echo $font; ?>font-size:11px;line-height:16px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;padding:3px 10px;border-radius:10px;margin:0 2px;<?php echo $bout['alert'] ? 'background:#fee2e2;color:#991b1b;' : 'background:#e8f0fb;color:#0047a8;'; ?>"><?php echo esc_html( $label ); ?></span><?php endforeach; ?>
		</div>
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
		<tr>
			<td width="43%" align="center" valign="top" style="text-align:center;"><?php echo $corner( $bout['a'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			<td width="14%" align="center" valign="top" style="padding-top:26px;<?php echo $font; ?>font-size:13px;line-height:20px;font-weight:700;letter-spacing:1px;color:#94a3b8;">VS</td>
			<td width="43%" align="center" valign="top" style="text-align:center;"><?php echo $corner( $bout['b'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
		</tr>
		</table>
		<?php if ( $bout['weight_class'] ) : ?><div style="text-align:center;<?php echo $font; ?>font-size:13px;line-height:18px;color:#64748b;padding-top:12px;"><?php echo esc_html( $bout['weight_class'] ); ?></div><?php endif; ?>
		<?php if ( $bout['note'] ) : ?><div style="text-align:center;<?php echo $font; ?>font-size:14px;line-height:20px;color:#475569;padding-top:10px;"><?php echo esc_html( $bout['note'] ); ?></div><?php endif; ?>
		<?php if ( $bout['remind_url'] ) : ?>
		<div style="padding-top:18px;"><?php echo $button( $bout['remind_url'], $bout['remind_label'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<div style="text-align:center;<?php echo $font; ?>font-size:12px;line-height:18px;color:#94a3b8;padding-top:8px;">One extra email before the fight. Nothing else.</div>
		<?php endif; ?>
	</td></tr>
	<?php endforeach; ?>
	</table>
</td></tr>
<?php endforeach; ?>

<tr><td class="mmaf-pad" bgcolor="#ffffff" align="center" style="background:#ffffff;padding:4px 32px 32px;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-top:1px solid #e2e8f0;padding-top:24px;text-align:center;">
		<p style="margin:0 0 16px;<?php echo $font; ?>font-size:15px;line-height:22px;color:#475569;"><?php echo esc_html( $m['site_text'] ); ?></p>
		<?php echo $button( $m['site_url'], $m['site_label'], false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</td></tr></table>
</td></tr>

<tr><td class="mmaf-pad" bgcolor="#f8fafc" align="center" style="background:#f8fafc;border-radius:0 0 16px 16px;padding:20px 32px 24px;">
	<p style="margin:0 0 8px;<?php echo $font; ?>font-size:13px;line-height:20px;color:#64748b;"><?php echo esc_html( $m['reason'] ); ?></p>
	<p style="margin:0;<?php echo $font; ?>font-size:13px;line-height:20px;color:#64748b;">
		<a href="<?php echo esc_url( $m['account_url'] ); ?>" style="color:#0047a8;text-decoration:underline;">Manage notifications</a>
		&nbsp;&middot;&nbsp;
		<a href="<?php echo esc_url( $m['unsubscribe_url'] ); ?>" style="color:#0047a8;text-decoration:underline;">Unsubscribe</a>
	</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
