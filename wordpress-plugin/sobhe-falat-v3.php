<?php
/**
 * Plugin Name: Sobhe Falat V3 — Intelligent News Engine
 * Description: Automated Persian news ingestion, deduplication, AI rewriting, image extraction, queue, logs and admin dashboard for Sobhe Falat.
 * Version: 3.0.0
 * Author: Sobhe Falat
 */

if (!defined('ABSPATH')) exit;

final class Sobhe_Falat_V3 {
    const OPT = 'sfv3_options';
    const TABLE = 'sfv3_items';
    const CRON = 'sfv3_cron';
    const NONCE = 'sfv3_nonce';

    public function __construct() {
        add_action('admin_menu', [$this,'menu']);
        add_action('admin_init', [$this,'settings']);
        add_filter('cron_schedules', [$this,'schedule']);
        add_action(self::CRON, [$this,'run']);
        add_action('admin_post_sfv3_run_now', [$this,'run_now']);
        add_action('admin_post_sfv3_save_source', [$this,'save_source']);
        add_action('admin_post_sfv3_toggle_source', [$this,'toggle_source']);
        add_action('admin_post_sfv3_retry', [$this,'retry']);
        add_action('rest_api_init', [$this,'rest']);
    }

    public static function activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $table (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            source varchar(190) NOT NULL,
            url text NOT NULL,
            guid_hash char(64) NOT NULL,
            title_hash char(64) DEFAULT '' NOT NULL,
            content_hash char(64) DEFAULT '' NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            post_id bigint unsigned DEFAULT 0,
            error text,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY guid_hash (guid_hash),
            KEY title_hash (title_hash),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;");
        if (!get_option(self::OPT)) update_option(self::OPT, self::defaults());
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time()+60, 'sfv3_10min', self::CRON);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON);
    }

    private static function defaults() {
        return [
            'api_key'=>'','model'=>'gpt-5.6-luna','max_items'=>10,'publish'=>'1',
            'telegram_token'=>'','telegram_chat_id'=>'',
            'sources'=>[
                ['name'=>'فارس','url'=>'https://www.farsnews.ir/feeds','enabled'=>1,'category'=>'اخبار'],
                ['name'=>'ایسنا','url'=>'https://www.isna.ir/rss','enabled'=>1,'category'=>'اخبار'],
                ['name'=>'ایرنا','url'=>'https://www.irna.ir/rss','enabled'=>1,'category'=>'اخبار'],
                ['name'=>'مهر','url'=>'https://www.mehrnews.com/rss','enabled'=>1,'category'=>'اخبار'],
                ['name'=>'تسنیم','url'=>'https://www.tasnimnews.com/fa/rss/feed/0/8/0/%D8%A2%D8%AE%D8%B1%DB%8C%D9%86-%D8%A7%D8%AE%D8%A8%D8%A7%D8%B1','enabled'=>1,'category'=>'اخبار'],
            ]
        ];
    }

    private function opts() {
        $o = get_option(self::OPT, []);
        return wp_parse_args($o, self::defaults());
    }

    public function schedule($s) {
        $s['sfv3_10min'] = ['interval'=>600,'display'=>'Sobhe Falat — every 10 minutes'];
        return $s;
    }

    public function settings() {
        register_setting('sfv3_group', self::OPT, ['sanitize_callback'=>[$this,'sanitize']]);
    }

    public function sanitize($v) {
        $o = $this->opts();
        $o['api_key'] = sanitize_text_field($v['api_key'] ?? '');
        $o['model'] = sanitize_text_field($v['model'] ?? 'gpt-5.6-luna');
        $o['max_items'] = max(1,min(50,intval($v['max_items'] ?? 10)));
        $o['publish'] = !empty($v['publish']) ? '1':'0';
        $o['telegram_token'] = sanitize_text_field($v['telegram_token'] ?? '');
        $o['telegram_chat_id'] = sanitize_text_field($v['telegram_chat_id'] ?? '');
        return $o;
    }

    public function menu() {
        add_menu_page('صبح فلات','صبح فلات','manage_options','sfv3',[$this,'dashboard'],'dashicons-rss',3);
        add_submenu_page('sfv3','داشبورد','داشبورد','manage_options','sfv3',[$this,'dashboard']);
        add_submenu_page('sfv3','منابع','منابع','manage_options','sfv3-sources',[$this,'sources']);
        add_submenu_page('sfv3','تنظیمات','تنظیمات','manage_options','sfv3-settings',[$this,'settings_page']);
        add_submenu_page('sfv3','گزارش‌ها','گزارش‌ها','manage_options','sfv3-logs',[$this,'logs']);
    }

    private function nonce() { return wp_nonce_field(self::NONCE,self::NONCE,true,false); }

    public function dashboard() {
        global $wpdb; $t=$wpdb->prefix.self::TABLE;
        $today=$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE created_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)");
        $published=$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='published' AND created_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)");
        $errors=$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='error' AND created_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)");
        $dupes=$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='duplicate' AND created_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)");
        echo '<div class="wrap"><h1>صبح فلات — موتور هوشمند V3</h1>';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
        foreach ([['کل ورودی ۲۴ساعت',$today],['منتشرشده',$published],['تکراری',$dupes],['خطا',$errors]] as $x)
            echo '<div style="background:#fff;border:1px solid #ddd;padding:18px;min-width:150px"><b>'.esc_html($x[0]).'</b><div style="font-size:28px;margin-top:8px">'.intval($x[1]).'</div></div>';
        echo '</div><p>اجرای زمان‌بندی‌شده: هر ۱۰ دقیقه.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.$this->nonce().'<input type="hidden" name="action" value="sfv3_run_now"><button class="button button-primary">اجرای موتور همین حالا</button></form>';
        echo '<h2>وضعیت</h2><p>API: '.(!empty($this->opts()['api_key'])?'✓ تنظیم شده':'⚠ تنظیم نشده').' | Cron: '.(wp_next_scheduled(self::CRON)?'✓ فعال':'⚠ فعال نیست').'</p></div>';
    }

    public function sources() {
        $o=$this->opts();
        echo '<div class="wrap"><h1>منابع خبر</h1><table class="widefat"><thead><tr><th>منبع</th><th>Feed</th><th>دسته</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
        foreach ($o['sources'] as $i=>$s) {
            echo '<tr><td>'.esc_html($s['name']).'</td><td style="max-width:420px;word-break:break-all">'.esc_html($s['url']).'</td><td>'.esc_html($s['category']).'</td><td>'.(!empty($s['enabled'])?'فعال':'غیرفعال').'</td><td>';
            echo '<form style="display:inline" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.$this->nonce().'<input type="hidden" name="action" value="sfv3_toggle_source"><input type="hidden" name="i" value="'.$i.'"><button class="button">تغییر وضعیت</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table><h2>افزودن منبع</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.$this->nonce().'<input type="hidden" name="action" value="sfv3_save_source"><p><input name="name" required placeholder="نام منبع"> <input name="url" required size="60" placeholder="RSS/Atom URL"> <input name="category" value="اخبار" placeholder="دسته"> <button class="button button-primary">افزودن</button></p></form></div>';
    }

    public function settings_page() {
        $o=$this->opts(); echo '<div class="wrap"><h1>تنظیمات موتور</h1><form method="post" action="options.php">';
        settings_fields('sfv3_group');
        echo '<table class="form-table"><tr><th>OpenAI API Key</th><td><input type="password" name="'.self::OPT.'[api_key]" value="'.esc_attr($o['api_key']).'" size="55"></td></tr>';
        echo '<tr><th>Model</th><td><input name="'.self::OPT.'[model]" value="'.esc_attr($o['model']).'" size="30"><p class="description">مدل را مطابق مدل فعال حساب API خود تنظیم کنید.</p></td></tr>';
        echo '<tr><th>حداکثر خبر در هر اجرا</th><td><input type="number" min="1" max="50" name="'.self::OPT.'[max_items]" value="'.intval($o['max_items']).'"></td></tr>';
        echo '<tr><th>انتشار مستقیم</th><td><label><input type="checkbox" name="'.self::OPT.'[publish]" value="1" '.checked($o['publish'],'1',false).'> انتشار خودکار</label></td></tr>';
        echo '<tr><th>Telegram Bot Token</th><td><input type="password" name="'.self::OPT.'[telegram_token]" value="'.esc_attr($o['telegram_token']).'" size="55"></td></tr>';
        echo '<tr><th>Telegram Chat ID</th><td><input name="'.self::OPT.'[telegram_chat_id]" value="'.esc_attr($o['telegram_chat_id']).'"></td></tr></table>';
        submit_button('ذخیره تنظیمات'); echo '</form></div>';
    }

    public function logs() {
        global $wpdb; $t=$wpdb->prefix.self::TABLE;
        $rows=$wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 100");
        echo '<div class="wrap"><h1>گزارش پردازش</h1><table class="widefat"><thead><tr><th>زمان</th><th>منبع</th><th>وضعیت</th><th>عنوان/URL</th><th>خطا</th></tr></thead><tbody>';
        foreach($rows as $r) echo '<tr><td>'.esc_html($r->created_at).'</td><td>'.esc_html($r->source).'</td><td>'.esc_html($r->status).'</td><td style="max-width:400px;word-break:break-all">'.esc_html($r->url).'</td><td>'.esc_html($r->error).'</td></tr>';
        echo '</tbody></table></div>';
    }

    public function run_now() {
        if (!current_user_can('manage_options') || !check_admin_referer(self::NONCE,self::NONCE)) wp_die('Unauthorized');
        $this->run(); wp_safe_redirect(admin_url('admin.php?page=sfv3')); exit;
    }

    public function save_source() {
        if (!current_user_can('manage_options') || !check_admin_referer(self::NONCE,self::NONCE)) wp_die('Unauthorized');
        $o=$this->opts(); $o['sources'][]=['name'=>sanitize_text_field($_POST['name']??''),'url'=>esc_url_raw($_POST['url']??''),'enabled'=>1,'category'=>sanitize_text_field($_POST['category']??'اخبار')]; update_option(self::OPT,$o);
        wp_safe_redirect(admin_url('admin.php?page=sfv3-sources')); exit;
    }

    public function toggle_source() {
        if (!current_user_can('manage_options') || !check_admin_referer(self::NONCE,self::NONCE)) wp_die('Unauthorized');
        $o=$this->opts(); $i=intval($_POST['i']??-1); if(isset($o['sources'][$i])) $o['sources'][$i]['enabled']=empty($o['sources'][$i]['enabled'])?1:0; update_option(self::OPT,$o);
        wp_safe_redirect(admin_url('admin.php?page=sfv3-sources')); exit;
    }

    public function retry() { if(!current_user_can('manage_options')) wp_die('Unauthorized'); $id=intval($_GET['id']??0); global $wpdb; $wpdb->update($wpdb->prefix.self::TABLE,['status'=>'queued','error'=>'','updated_at'=>current_time('mysql')],['id'=>$id]); $this->run(); wp_safe_redirect(admin_url('admin.php?page=sfv3-logs')); exit; }

    private function normalize($s) {
        $s=wp_strip_all_tags(html_entity_decode((string)$s,ENT_QUOTES,'UTF-8'));
        $s=str_replace(['ي','ى','ك','ۀ','ة','ؤ','إ','أ','‌','ـ'],['ی','ی','ک','ه','ه','و','ا','ا','',''],$s);
        $s=preg_replace('/\s+/u',' ',mb_strtolower(trim($s),'UTF-8'));
        return $s;
    }

    private function hash_title($title) { return hash('sha256',$this->normalize($title)); }

    private function duplicate($title,$url) {
        global $wpdb; $t=$wpdb->prefix.self::TABLE;
        $gh=hash('sha256',esc_url_raw($url)); $th=$this->hash_title($title);
        if($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE guid_hash=%s LIMIT 1",$gh))) return true;
        $rows=$wpdb->get_col($wpdb->prepare("SELECT title_hash FROM $t WHERE created_at>=DATE_SUB(NOW(),INTERVAL 14 DAY) AND title_hash<>''"));
        foreach($rows as $h) if(hash_equals($h,$th)) return true;
        return false;
    }

    private function fetch_feed($url) {
        require_once ABSPATH.WPINC.'/feed.php';
        $f=fetch_feed($url); if(is_wp_error($f)) throw new Exception($f->get_error_message());
        $items=$f->get_items(0,50); $out=[];
        foreach($items as $it) {
            $out[]=['title'=>$it->get_title(),'url'=>$it->get_permalink(),'date'=>$it->get_date('c'),'description'=>$it->get_description(),'content'=>$it->get_content(),'enclosure'=>($it->get_enclosure()? $it->get_enclosure()->get_link() : '')];
        }
        return $out;
    }

    private function page_data($url,$fallback='') {
        $r=wp_remote_get($url,['timeout'=>25,'redirection'=>5,'user-agent'=>'SobheFalat/3.0']);
        if(is_wp_error($r)) return ['text'=>$fallback,'images'=>[]];
        $html=wp_remote_retrieve_body($r);
        if(!$html) return ['text'=>$fallback,'images'=>[]];
        $dom=new DOMDocument(); libxml_use_internal_errors(true); @$dom->loadHTML('<?xml encoding="UTF-8">'.$html); libxml_clear_errors();
        $xp=new DOMXPath($dom); $bad=$xp->query('//script|//style|//nav|//footer|//header|//form|//aside');
        foreach($bad as $n) $n->parentNode->removeChild($n);
        $text=trim(preg_replace('/\s+/u',' ',$dom->textContent));
        $imgs=[]; foreach($xp->query('//img') as $img) {
            $src=$img->getAttribute('src') ?: $img->getAttribute('data-src') ?: $img->getAttribute('data-original') ?: $img->getAttribute('data-lazy-src');
            if($src) { $src=esc_url_raw($src); if(strpos($src,'//')===0) $src='https:'.$src; if(strpos($src,'http')===0) $imgs[]=$src; }
        }
        $imgs=array_values(array_unique($imgs));
        return ['text'=>$text?:$fallback,'images'=>array_slice($imgs,0,12)];
    }

    private function ai($title,$text,$source) {
        $o=$this->opts(); if(empty($o['api_key'])) throw new Exception('OpenAI API key is not configured.');
        $schema=['type'=>'object','additionalProperties'=>false,'properties'=>[
            'title'=>['type'=>'string'],'summary'=>['type'=>'string'],'content'=>['type'=>'string'],
            'category'=>['type'=>'string'],'tags'=>['type'=>'array','items'=>['type'=>'string']]
        ],'required'=>['title','summary','content','category','tags']];
        $prompt="من یک موتور خبری هستم. خبر زیر از منبع «{$source}» آمده است. فقط بر اساس اطلاعات موجود بازنویسی کن؛ هیچ واقعیت، عدد، نام، نقل قول یا جزئیات جدید نساز. متن را حرفه‌ای، روان و فارسی خبری کن. تبلیغات و منوها را حذف کن. عنوان و لید مناسب تولید کن. خروجی فقط JSON مطابق schema باشد.\n\nعنوان: {$title}\n\nمتن: ".mb_substr($text,0,30000,'UTF-8');
        $body=['model'=>$o['model'],'input'=>$prompt,'text'=>['format'=>['type'=>'json_schema','name'=>'news','strict'=>true,'schema'=>$schema]]];
        $r=wp_remote_post('https://api.openai.com/v1/responses',['timeout'=>90,'headers'=>['Authorization'=>'Bearer '.$o['api_key'],'Content-Type'=>'application/json'],'body'=>wp_json_encode($body)]);
        if(is_wp_error($r)) throw new Exception($r->get_error_message());
        $code=wp_remote_retrieve_response_code($r); $raw=wp_remote_retrieve_body($r); if($code<200||$code>=300) throw new Exception('OpenAI HTTP '.$code.': '.mb_substr($raw,0,500));
        $j=json_decode($raw,true); $out='';
        if(isset($j['output'])) foreach($j['output'] as $block) if(isset($block['content'])) foreach($block['content'] as $c) if(isset($c['text'])) $out.=$c['text'];
        if(!$out && isset($j['output_text'])) $out=$j['output_text'];
        $data=json_decode($out,true); if(!is_array($data)) throw new Exception('Invalid structured AI output.');
        return $data;
    }

    private function sideload_images($urls,$post_id) {
        require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/image.php';
        $ids=[]; foreach(array_slice($urls,0,8) as $u) {
            $tmp=download_url($u,25); if(is_wp_error($tmp)) continue;
            $file=['name'=>sanitize_file_name(wp_basename(parse_url($u,PHP_URL_PATH)?:'news-image.jpg')),'tmp_name'=>$tmp];
            $id=media_handle_sideload($file,$post_id); if(is_wp_error($id)){@unlink($tmp);continue;} $ids[]=$id;
        }
        if($ids) set_post_thumbnail($post_id,$ids[0]);
        return $ids;
    }

    public function run() {
        if(get_transient('sfv3_running')) return;
        set_transient('sfv3_running',1,540);
        try {
            $o=$this->opts(); global $wpdb; $t=$wpdb->prefix.self::TABLE; $count=0;
            foreach($o['sources'] as $src) {
                if(empty($src['enabled']) || empty($src['url']) || $count >= intval($o['max_items'])) continue;
                try {
                    foreach($this->fetch_feed($src['url']) as $item) {
                        if($count >= intval($o['max_items'])) break;
                        if(empty($item['url']) || empty($item['title'])) continue;
                        if($this->duplicate($item['title'],$item['url'])) {
                            $wpdb->insert($t,['source'=>$src['name'],'url'=>$item['url'],'guid_hash'=>hash('sha256',$item['url']),'title_hash'=>$this->hash_title($item['title']),'status'=>'duplicate','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]); continue;
                        }
                        $data=$this->page_data($item['url'],$item['content']?:$item['description']);
                        $ai=$this->ai($item['title'],$data['text'],$src['name']);
                        $postarr=['post_title'=>sanitize_text_field($ai['title']),'post_content'=>wp_kses_post($ai['content']),'post_excerpt'=>sanitize_textarea_field($ai['summary']),'post_status'=>($o['publish']=='1'?'publish':'draft'),'post_type'=>'post'];
                        $pid=wp_insert_post($postarr,true); if(is_wp_error($pid)) throw new Exception($pid->get_error_message());
                        wp_set_post_categories($pid,[get_cat_ID($src['category']?:'اخبار')]);
                        wp_set_post_tags($pid,array_map('sanitize_text_field',$ai['tags']??[]));
                        update_post_meta($pid,'sfv3_source',$src['name']); update_post_meta($pid,'sfv3_source_url',esc_url_raw($item['url'])); update_post_meta($pid,'sfv3_source_date',sanitize_text_field($item['date']));
                        $ids=$this->sideload_images($data['images'],$pid);
                        if($ids) update_post_meta($pid,'sfv3_image_ids',$ids);
                        $wpdb->insert($t,['source'=>$src['name'],'url'=>$item['url'],'guid_hash'=>hash('sha256',$item['url']),'title_hash'=>$this->hash_title($item['title']),'content_hash'=>hash('sha256',$this->normalize($data['text'])),'status'=>'published','post_id'=>$pid,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
                        $count++;
                    }
                } catch(Throwable $e) {
                    $wpdb->insert($t,['source'=>$src['name'],'url'=>$src['url'],'guid_hash'=>hash('sha256',$src['url'].microtime(true)),'status'=>'error','error'=>$e->getMessage(),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
                }
            }
            if($count) $this->telegram("🟢 صبح فلات\n{$count} خبر جدید پردازش/منتشر شد.");
        } finally { delete_transient('sfv3_running'); }
    }

    private function telegram($msg) {
        $o=$this->opts(); if(empty($o['telegram_token'])||empty($o['telegram_chat_id'])) return;
        wp_remote_post('https://api.telegram.org/bot'.rawurlencode($o['telegram_token']).'/sendMessage',['timeout'=>10,'body'=>['chat_id'=>$o['telegram_chat_id'],'text'=>$msg]]);
    }

    public function rest() {
        register_rest_route('sobhe-falat/v3','/health',['methods'=>'GET','callback'=>function(){ return ['ok'=>true,'time'=>current_time('mysql'),'cron'=>(bool)wp_next_scheduled(self::CRON)];],'permission_callback'=>'__return_true']);
    }
}
register_activation_hook(__FILE__,['Sobhe_Falat_V3','activate']);
register_deactivation_hook(__FILE__,['Sobhe_Falat_V3','deactivate']);
new Sobhe_Falat_V3();
