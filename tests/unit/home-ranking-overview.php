<?php
/** Render the real template with editorial fields supplied by a small fixture. */
define('ABSPATH',dirname(__DIR__,2).'/');
function add_action(...$args) {}
function get_template_directory(){return ABSPATH.'wp-content/themes/mma-future';}
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function esc_attr($value){return esc_html($value);}
function esc_url($value){return esc_html($value);}
function mmaf_site_field($name){return $GLOBALS['overview_fields'][$name]??null;}
function mmaf_rankings_url(){return 'https://example.test/all-rankings/';}
function mmaf_display_date($value){return $value;}
function mmaf_render_dates($data){}
require get_template_directory().'/inc/presentation.php';
function overview_check($ok,$message){if(!$ok){throw new RuntimeException($message);}echo "PASS: $message\n";}
function overview_render($show,$link=null): array {
    $GLOBALS['overview_fields']=['show_top_movers'=>$show,'primary_link'=>$link,'top_movers_title'=>'Ranking movers','section_title'=>'The leaders'];
    $movers=['status'=>'available','previous_activated_at'=>'2026-10-12','current_activated_at'=>'2026-10-14','items'=>[
        ['display_name'=>'Rising fixture','rank_change'=>4,'previous_rank'=>5,'current_rank'=>1,'permalink'=>'/rising/'],
        ['display_name'=>'Falling fixture','rank_change'=>-4,'previous_rank'=>1,'current_rank'=>5,'permalink'=>'/falling/'],
    ]];
    $data=['items'=>[]];$prefix='fixture';ob_start();
    require get_template_directory().'/template-parts/integration/home-rankings.php';
    $html=ob_get_clean();$doc=new DOMDocument();@$doc->loadHTML($html);
    return [$html,new DOMXPath($doc)];
}
[$html,$xpath]=overview_render(false);
overview_check($xpath->query('//h2[text()="The leaders"]/following-sibling::a[@href="https://example.test/all-rankings/"]')->length===1,'the leaders always have a rankings CTA even when movers are hidden and no editorial link is set');
[$html,$xpath]=overview_render(true);
overview_check($xpath->query('//a[@href="https://example.test/all-rankings/"]')->length===2,'each ranking section has its own CTA');
overview_check(str_contains($html,'+4 places · #5 → #1') && str_contains($html,'-4 places · #1 → #5'),'both movement directions display their signed change and positions');
overview_check($xpath->query('//a[@href="/falling/"]//div[contains(@style,"220, 38, 38")]')->length===1,'a decline uses the loss treatment');
[$html,$xpath]=overview_render(true,['url'=>'https://example.test/custom-rankings/','title'=>'Explore rankings','target'=>'_blank']);
overview_check($xpath->query('//a[@href="https://example.test/custom-rankings/" and @target="_blank" and @rel="noopener noreferrer"]')->length===2,'both CTAs preserve the configured editorial link and safe target');
