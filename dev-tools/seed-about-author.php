<?php
/** Private, repeatable content delivery; wp eval-file seed-author.php preview|apply PHOTO. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required.'); }
$environment=wp_get_environment_type();
if (!in_array($environment,['local','staging'],true) || ($environment==='staging' && wp_parse_url(home_url(),PHP_URL_HOST)!=='mma-future-dev.tldteam.com')) { throw new RuntimeException('Unexpected target environment.'); }
$apply=($args[0]??'preview')==='apply';
$source=$args[1]??(__DIR__.'/Nikola-Spasojevic.jpeg');
if (!is_file($source)) { throw new RuntimeException('Author photograph is missing.'); }
$page=get_page_by_path('about');
if (!$page || $page->post_status!=='publish') { throw new RuntimeException('Published About page missing.'); }
$backup_file=__DIR__.'/author-before-'.$environment.'.json';
$receipt_file=__DIR__.'/author-receipt-'.$environment.'.json';
$current_hash=hash('sha256',$page->post_content);
if (is_file($receipt_file)) {
 $receipt=json_decode(file_get_contents($receipt_file),true,512,JSON_THROW_ON_ERROR);
 if ($current_hash===$receipt['content_sha256'] && get_post($receipt['attachment_id'])) { WP_CLI::line(wp_json_encode(['status'=>'already_applied']+$receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); return; }
 throw new RuntimeException('Content changed after this author seed; preserve editor changes and review before applying again.');
}
if (!is_file($backup_file)) {
 file_put_contents($backup_file,wp_json_encode(['page_id'=>$page->ID,'url'=>home_url(),'content_sha256'=>$current_hash,'post_content'=>$page->post_content],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));chmod($backup_file,0600);
}
$backup=json_decode(file_get_contents($backup_file),true,512,JSON_THROW_ON_ERROR);
if ($backup['content_sha256']!==$current_hash || $backup['page_id']!==$page->ID) { throw new RuntimeException('About page changed since preview.'); }
$blocks=parse_blocks($page->post_content);$founder=null;$found=0;$remaining=[];
foreach($blocks as $block){if(($block['blockName']??'')==='acf/about-founder-bio-section'){$founder=$block;$found++;}else{$remaining[]=$block;}}
if($found>1){throw new RuntimeException('Multiple author instances require editorial review.');}
$position=null;foreach($remaining as $i=>$block){if(($block['blockName']??'')==='acf/about-faq-section'){$position=$i;break;}}
if($position===null){throw new RuntimeException('FAQ insertion anchor missing.');}
$hash=hash_file('sha256',$source);
$existing=get_posts(['post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>1,'fields'=>'ids','meta_key'=>'_mmaf_author_source_sha256','meta_value'=>$hash]);
$photo=(int)($existing[0]??0);
if(!$apply){WP_CLI::line(wp_json_encode(['mode'=>'preview','page_id'=>$page->ID,'environment'=>$environment,'existing_block'=>(bool)$found,'existing_photo'=>$photo,'insert_before'=>'acf/about-faq-section','name'=>'Nikola Spasojević','social_links'=>4,'backup'=>$backup_file],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));return;}
if(!$photo){
 require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';
 $temp=wp_tempnam('nikola-spasojevic.jpeg');if(!copy($source,$temp)){throw new RuntimeException('Photo staging failed.');}
 $photo=media_handle_sideload(['name'=>'nikola-spasojevic.jpeg','tmp_name'=>$temp],$page->ID,'Nikola Spasojević');
 if(is_wp_error($photo)){throw new RuntimeException($photo->get_error_message());}
 update_post_meta($photo,'_mmaf_author_source_sha256',$hash);
 update_post_meta($photo,'_wp_attachment_image_alt','Nikola Spasojević');
 wp_update_post(['ID'=>$photo,'post_title'=>'Nikola Spasojević']);
}
$values=[
 'section_title'=>'The person behind MMA Future',
 'section_description'=>'',
 'founder_name'=>'Nikola Spasojević',
 'founder_role'=>'Creator of MMA Future',
 'founder_photo'=>$photo,
 'founder_biography'=>"<p>I created MMA Future to make emerging fighters easier to discover and their results easier to understand. My aim is to bring records, context and a transparent ranking approach into one place.</p><p>I enjoy looking beyond the headline names and following the stories taking shape across the sport. If you share that curiosity, connect with me below.</p>",
 'founder_links'=>[
  ['link'=>['url'=>'https://www.linkedin.com/in/nikola-spasojevi%C4%87-09ab49245/','title'=>'LinkedIn','target'=>'_blank']],
  ['link'=>['url'=>'https://www.instagram.com/spalekralj/','title'=>'Instagram','target'=>'_blank']],
  ['link'=>['url'=>'https://www.facebook.com/#nikolaspasojevic','title'=>'Facebook','target'=>'_blank']],
  ['link'=>['url'=>'https://www.youtube.com/@BlueHammerTV-Official','title'=>'YouTube','target'=>'_blank']],
 ],
];
$fields=array_column(acf_get_fields('group_mmaf_about_founder_bio_section'),null,'name');
$data=$founder['attrs']['data']??[];
foreach($values as $name=>$value){
 $field=$fields[$name]??null;if(!$field){throw new RuntimeException('ACF field missing: '.$name);}
 $data['_'.$name]=$field['key'];
 if($field['type']==='repeater'){
  foreach(array_keys($data) as $key){if(preg_match('/^_?'.preg_quote($name,'/').'_[0-9]+_/',$key)){unset($data[$key]);}}
  $data[$name]=count($value);$sub=array_column($field['sub_fields'],null,'name');
  foreach($value as $i=>$row){foreach($row as $key=>$item){$flat=$name.'_'.$i.'_'.$key;$data[$flat]=$item;$data['_'.$flat]=$sub[$key]['key'];}}
 }else{$data[$name]=$value;}
}
$founder=$founder??['blockName'=>'acf/about-founder-bio-section','attrs'=>['name'=>'acf/about-founder-bio-section','id'=>'block_mmaf_author_nikola','mode'=>'preview'],'innerBlocks'=>[],'innerHTML'=>'','innerContent'=>[]];
$founder['attrs']['data']=$data;
array_splice($remaining,$position,0,[$founder]);
$new=serialize_blocks($remaining);
$result=wp_update_post(wp_slash(['ID'=>$page->ID,'post_content'=>$new]),true);
if(is_wp_error($result)){throw new RuntimeException($result->get_error_message());}
clean_post_cache($page->ID);
if(get_post($page->ID)->post_content!==$new){throw new RuntimeException('Saved content differs.');}
$receipt=['page_id'=>$page->ID,'url'=>get_permalink($page->ID),'attachment_id'=>$photo,'content_sha256'=>hash('sha256',$new),'source_sha256'=>$hash,'saved_at'=>gmdate('c'),'biography'=>'Draft copy for replacement by the author'];
file_put_contents($receipt_file,wp_json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));chmod($receipt_file,0600);
do_action('mmaf_public_data_changed','author_content');
WP_CLI::line(wp_json_encode(['status'=>'applied']+$receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
