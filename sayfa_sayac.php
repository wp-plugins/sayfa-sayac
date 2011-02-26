<?php
/*
Plugin Name: Sayfa Sayaç
Plugin URI: http://www.dmry.net/wordpress-sayfa-sayac-eklentisi
Description: Yazıların okunma sayısını verir. (Display view count of posts).
Version: 2.6
Author: Hakan Demiray <hakan@dmry.net>
Author URI: http://www.dmry.net/
*/
/*
v.2.6
+ Dil dosyasının okunmaması problemi giderildi.
v.2.5
+ Eklenti adı - URL arasındaki uyumsuzluk çözüldü.
v2.4
+ Eklenti komple yenilendi, kodlar optiimize edildi.
+ Wordpress 3 uyumu getirildi.
+ Yeni fonkiyonlar eklendi
v2.3
+ Kullanıcı role eklendi
+ bugün en çok okunan yazılar hatası giderildi.
+ tablo ekleme sistemi düzeltildi.
*/
#--=== Eklenti WP Action&Filter API -- Plugin WP Action&Filter API ------------------------------------------------------------#


class sayfa_sayac {
	var $_surum = '2.5';
	var $_dizin;
	var $_url;
	var $_img_url;
	var $_ayarlar;
	var $_paypal_buton = 'https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=8505481';
	var $_eklenti_ad = 'sayfa-sayac';
	var $_sayac_tablo; 
	var $_secure;
	var $_memcache;
	var $_cache_zaman_asimi = 3600; // 1 saat cache yapılıyor (metalar için)
	var $_zaman_asimi = 30; //30 saniye içerisinde ajax datası alınmalı. aksi halde zaman aşımı.
	var $_bots = array('Google Bot' => 'googlebot', 'Google Bot' => 'google', 'MSN' => 'msnbot', 'Alex' => 'ia_archiver', 'Lycos' => 'lycos', 'Ask Jeeves' => 'jeeves', 'Altavista' => 'scooter', 'AllTheWeb' => 'fast-webcrawler', 'Inktomi' => 'slurp@inktomi', 'Turnitin.com' => 'turnitinbot', 'Technorati' => 'technorati', 'Yahoo' => 'yahoo', 'Findexa' => 'findexa', 'NextLinks' => 'findlinks', 'Gais' => 'gaisbo', 'WiseNut' => 'zyborg', 'WhoisSource' => 'surveybot', 'Bloglines' => 'bloglines', 'BlogSearch' => 'blogsearch', 'PubSub' => 'pubsub', 'Syndic8' => 'syndic8', 'RadioUserland' => 'userland', 'Gigabot' => 'gigabot', 'Become.com' => 'become.com');
	var $wpdb;
	
	# ana fonksiyon, değişkenleri tanımlıyoruz.
	function sayfa_sayac($wpdb) {
		$this->wpdb = $wpdb;
		$this->_dizin = WP_PLUGIN_DIR .'/'. $this->_eklenti_ad . '/';
		$this->_sayac_tablo	 = $this->wpdb->get_blog_prefix() . 'posts_okunma';
		$this->_ayarlar = get_option('sayfa_sayac');
		$this->_url = get_option('siteurl').'/wp-content/plugins/'. $this->_eklenti_ad.'/sayfa_sayac.php?';
		$this->_img_url = get_option('siteurl') . '/wp-content/plugins/'. $this->_eklenti_ad.'/img/';

		if ($this->_ayarlar['sayac_onbellek'] == 'memcached') {
			$this->_memcache = new Memcache;
			$this->_memcache->connect('127.0.0.1', 11211);
		}

		if(!defined('SAYAC_MODE')) {
			$this->sayfa_sayac_hook();
			$this->sayfa_sayac_add_action();
			$this->sayfa_sayac_add_filter();
		} else {
			$this->sayfa_sayac_dil_yukle();	
		}
		
	}
	
	
		
	# action listemiz
	function sayfa_sayac_add_action() {
		add_action('plugins_loaded', array(&$this , 'sayfa_sayac_dil_yukle'));	
		add_action('manage_posts_custom_column', array(&$this , 'sayfa_sayac_yonetim_sutun_deger'), 10, 2);
		add_action('admin_menu', array(&$this , 'sayfa_sayac_ayar_action'));
		add_action('admin_init', array(&$this , 'sayfa_sayac_init'));
		add_action('save_post', array(&$this , 'sayfa_sayac_meta_box_kaydet'));
		add_action('the_content', array(&$this , 'sayfa_sayac_filtre'));
		
		if($this->_ayarlar['sayac_goster']=='e') {
			add_action('the_content', array(&$this , 'sayfa_sayac_goster'));
		}
		
		add_action('widgets_init', array(&$this , 'sayfa_sayac_widgets_init'));
		add_action('wp_dashboard_setup',array(&$this,'sayfa_sayac_dashboard_widget'));
	}
	
	
	
	# filter listemiz
	function sayfa_sayac_add_filter() {
		add_filter('manage_posts_columns', array(&$this , 'sayfa_sayac_yonetim_sutun'));
		add_filter('wp_head', array(&$this , 'sayac_say_head_js'));
	}
	
	
	
	# hook listeminz
	function sayfa_sayac_hook() {
		register_activation_hook(__FILE__, array(&$this , 'sayfa_sayac_kurulum'));	
	}
	
	
	
	# Dashboard Widget Ekle
	function sayfa_sayac_dashboard_widget() {
		wp_add_dashboard_widget('sayfa_sayac_dashboard_widget',__('Sayfa Sayaç Monitor','sayfa_sayac'),array(&$this,'sayfa_sayac_ver_dashboard_widget'));
	}
	
	
	
	#  Dashboard Widget Göster
	function sayfa_sayac_ver_dashboard_widget() {
		?>
		<style type="text/css">
		#sayfa_sayac_dashboard_widget p.sub {font: italic 13px Georgia, "Times New Roman", "Bitstream Charter", Times, serif;color: #777;margin: -12px;padding: 5px 10px 15px;}
		#sayfa_sayac_dashboard_widget .table {margin: 0 -9px 10px;padding: 0 10px;background: #F9F9F9;border-top: 1px solid #ECECEC;border-bottom: 1px solid #ECECEC;}
		#sayfa_sayac_dashboard_widget table {width: 100%;}
		#sayfa_sayac_dashboard_widget td.b {font: normal 14px Georgia, "Times New Roman", "Bitstream Charter", Times, serif;text-align: right;padding-right: 6px;}#sayfa_sayac_dashboard_widget table td {padding: 3px 0;border-top: 1px solid #ECECEC;white-space: nowrap;}
		#sayfa_sayac_dashboard_widget table tr.first td {border-top: none;}
		#sayfa_sayac_dashboard_widget td.first,#sayfa_sayac_dashboard_widget td.last {width: 1px;}
		#sayfa_sayac_dashboard_widget .t {color: #777;font-size: 12px;padding-top: 6px;padding-right: 12px;}
		#sayfa_sayac_dashboard_widget td.b a {font-size: 18px;}
		#sayfa_sayac_dashboard_widget tr.first td {border: none;}
        </style>
        <script type="text/javascript">
		jQuery(document).ready(function() {
			jQuery('#dashboard_widget_sayac').change(function(){
				if (jQuery(this).val()!='') {
					jQuery('.dashboard_widget_sayac_sonuc').html('<?php  _e('Please wait..', 'sayfa_sayac'); ?>');
					var i_type =  jQuery(this).val();
					jQuery.getJSON('<?php echo $this->_url; ?>','mode=istatistik&type='+ i_type +'&adet=25&kelimekes=&kategori=&kategoricikar=&yazicikar=',function(data){
						if(data!='') {
							var html_cikti = '<ul>';
							for(i in data) {
								switch(i_type) {
									case 'enaztoplamda':
									case 'encoktoplamda':
									case 'enson':
									var sonuc_html = data[i].sayac_toplam;
									break;
									case 'enazbugun':
									case 'encokbugun':
									var sonuc_html = data[i].sayac_bugun;
									break;					
								}
								html_cikti += '<li>'+ (parseInt(i)+1) +' - <a href="' + data[i].yazi_url + '">' + data[i].yazi_baslik + ' - '+ sonuc_html +'</a></li>';
							}
							jQuery('.dashboard_widget_sayac_sonuc').html(html_cikti);
							html_cikti += '</ul>';
						}
					});
					
				}
			});
		});
		</script>
		<p>
			<label for="dashboard_widget_sayac"><?php _e('Statistic Type:', 'sayfa_sayac'); ?>
				<select name="dashboard_widget_sayac" id="dashboard_widget_sayac">
					<option value=""><?php _e('Select', 'sayfa_sayac'); ?></option>
                    <option value="enaztoplamda"><?php _e('Least Viewed for Total', 'sayfa_sayac'); ?></option>
					<option value="encoktoplamda"><?php _e('Most Viewed for Total', 'sayfa_sayac'); ?></option>
					<option value="enazbugun"><?php _e('Least Viewed for Today', 'sayfa_sayac'); ?></option>
					<option value="encokbugun"><?php _e('Most Viewed for Today', 'sayfa_sayac'); ?></option>
                    <option value="enson"><?php _e('Last Viewed Posts', 'sayfa_sayac'); ?></option>
				</select>
			</label>
		</p>
        <p><div class="dashboard_widget_sayac_sonuc"></div></p>
		<?php
	}
	
	
			
	# sayaç filtre
	function sayfa_sayac_filtre($content) {
		$son_okunma_tarihi = $this->son_okuma_tarih_ver();
		$sayac_meta_toplam = $this->toplam_okunma_ver();
		$sayac_meta_bugun = $this->gunluk_okunma_ver();
		$sayac_bilgi_deger = array('%sayac_toplam%'=> $sayac_meta_toplam, '%sayac_bugun%'=>$sayac_meta_bugun, '%son_okuma%'=>$son_okunma_tarihi);
		$content = strtr($content, $sayac_bilgi_deger);
		return $content;
	}
	
	
	
	# sayfa sayaç otomatik olarak görüntüleniyor
	function sayfa_sayac_goster($content) {
		global $post;
		if($this->sayfa_sayac_goster_izin() == true) {
			$son_okunma_tarihi = $this->son_okuma_tarih_ver();
			$sayac_meta_toplam = $this->toplam_okunma_ver();
			$sayac_meta_bugun = $this->gunluk_okunma_ver();
			$sayac_bilgi_deger = array('%sayac_toplam%'=> $sayac_meta_toplam, '%sayac_bugun%'=>$sayac_meta_bugun, '%son_okuma%'=>$son_okunma_tarihi);
			$sayac_bilgi_html = "\n".'<p class="sayac_bilgi">'.strtr(stripslashes($this->_ayarlar['sayac_bilgi_sablon']), $sayac_bilgi_deger).'</p>'."\n";			
			
			if ($this->_ayarlar['sayac_bilgi_tur'] == 'i') {
				$alinacak_deger = (is_single()) ? 'sayac_bilgi_yer_y' : 'sayac_bilgi_yer_l';
				$content = ($this->_ayarlar[$alinacak_deger]=='a') ? $content . $sayac_bilgi_html : $sayac_bilgi_html . $content;
			} else if ($this->_ayarlar['sayac_bilgi_tur'] == 'y' && is_single()) {
				$content = ($this->_ayarlar['sayac_bilgi_yer_y']=='a') ? $content . $sayac_bilgi_html : $sayac_bilgi_html . $content;
			} else if ($this->_ayarlar['sayac_bilgi_tur'] == 'l' && !is_single()) {
				$content = ($this->_ayarlar['sayac_bilgi_yer_l']=='a') ? $content . $sayac_bilgi_html : $sayac_bilgi_html . $content;
			}	
		}
		return $content;
	}
	
	
	
	# toplam okunma return
	function toplam_okunma_ver($id=null) {
		global $post;
		$post_id = (empty($post->ID)) ? $id : $post->ID;
		$sayac_toplam = $this->sayfa_sayac_meta_oku($post_id, 'sayac_toplam');
		$sayac_toplam = number_format_i18n($sayac_toplam);
		return $sayac_toplam;
	}



	# toplam okunma echo
	function toplam_okunma_yaz($id=null) {
		global $post;
		echo $this->toplam_okunma_ver($id);
	}	
	
	
	
	# gunlük okunma return
	function gunluk_okunma_ver($id=null) {
		global $post;
		$post_id = (empty($post->ID)) ? $id : $post->ID;
		$sayac_bugun = $this->sayfa_sayac_meta_oku($post_id, 'sayac_bugun');
		$sayac_bugun = number_format_i18n($sayac_bugun);
		return $sayac_bugun;	
	}



	# gunlük okunma echo
	function gunluk_okunma_yaz($id=null) {
		global $post;
		echo $this->gunluk_okunma_ver($id);
	}



	# son okunma return	
	function son_okuma_tarih_ver($id=null) {
		global $post;
		$post_id = (empty($post->ID)) ? $id : $post->ID;
		$son_okuma = $this->sayfa_sayac_meta_oku($post_id, 'son_okuma');
		$tarih_format = ($this->_ayarlar['sayac_tarih_format']) ? $this->_ayarlar['sayac_tarih_format'] : get_option('date_format');
		$son_okuma = @date($tarih_format, $son_okuma);
		return $son_okuma;
	}
	
	
	
	# son okunma echo
	function son_okuma_tarih_yaz($id=null) {
		global $post;
		echo $this->son_okuma_tarih_ver($id);
	}
	
	
	
	# sayac görüntüleme izinler
	function sayfa_sayac_goster_izin() {
		$__sayac__goster = false;	
		if($this->_ayarlar['sayac_gorebilecekler']!='everyone' && $this->_ayarlar['sayac_gorebilecekler']!='guests') {
			$kullanici_bilgi=wp_get_current_user();
			if($this->_ayarlar['sayac_gorebilecekler']=='allusers' && !empty($kullanici_bilgi->roles)) {
				$__sayac__goster = true;	
			} else {
				foreach($kullanici_bilgi->roles as $_roles) {
					if($_roles == $this->_ayarlar['sayac_gorebilecekler']) {
						$__sayac__goster = true;	
					}
				}
			}
		} else if($this->_ayarlar['sayac_gorebilecekler']=='guests') {
			$kullanici_bilgi=wp_get_current_user();
			if(empty($kullanici_bilgi->roles)) { $__sayac__goster = true; }	
		} else {
			$__sayac__goster = true;
		}
		return $__sayac__goster;
	}
	
	
	
	# Sayfa kaynağına sayaç javascript satırımızı ekliyoruz
	function sayac_say_head_js() {
		global $post;
		if(is_single()) {
			$p_data =  array(
								't'	=> time(),
								'a'	=>$_SERVER['HTTP_USER_AGENT'],
								'p'	=> $post->ID,
								);
			$p_data = base64_encode(serialize($p_data));
			echo '<script type="text/javascript" src="'. $this->_url.'mode=ajax_sayac&amp;p='.$p_data.'"></script>'."\n";
		}
	}
	
	
	
	# sayfa sayaç init fonksiyonlar
	function sayfa_sayac_init($post) {
		add_meta_box('sayfa_sayac_meta_box', "Sayfa Sayaç", array(&$this , 'sayfa_sayac_meta_box'), 'post' , "side", "low");
	}
	
	
	
	# sayfa sayaç meta box
	function sayfa_sayac_meta_box($data) {
		echo '<p>'. str_replace('%', (int) $this->sayfa_sayac_meta_oku($data->ID, 'sayac_toplam'), __('This post <strong>%</strong> times viewed.','sayfa_sayac')) .'</p>';
		$__checkbox = ($this->sayfa_sayac_meta_oku($data->ID, 'sayfa_sayac_durdur') == 1) ? ' checked="checked"' : '';
	?>
    <p><label><input type="checkbox" id="sayfa_sayac_durdur" name="sayfa_sayac_durdur" value="1"<?php echo $__checkbox; ?> /> <?php _e('Not count for this post and stop counter','sayfa_sayac'); ?></label></p>
    <p><a href="javascript:;" onclick="if(confirm('<?php _e('Are you sure?','sayfa_sayac'); ?>')) window.open('<?php echo $this->_url; ?>mode=post_count_reset&p=<?php echo $data->ID; ?>');"><?php _e('Reset count number to this post','sayfa_sayac'); ?></a></p>
    <?php
	}
	
	
	
	# sayfa sayaç meta box kaydedelim
	function sayfa_sayac_meta_box_kaydet() {
		global $post;
		$this->sayfa_sayac_meta_kaydet($post->ID, array('sayfa_sayac_durdur' => (int) $_POST['sayfa_sayac_durdur']) );
		$this->_cache_delete('sayfa_sayac_bilgi_' . $post->ID );
	}
	
	
	
	# yazı sayaç meta bilgisi al
	function sayfa_sayac_meta_oku($id,$meta) {
		$yazi_sayac_bilgi = $this->_cache_get('sayfa_sayac_bilgi_' . $id);
		
		if (!is_array($yazi_sayac_bilgi)) {
			$yazi_sayac_bilgi = get_post_meta($id, 'sayfa_sayac_bilgi',true);

			if(!is_array($yazi_sayac_bilgi)) {
				$sonuc = $this->wpdb->get_results("select sayac_toplam, sayac_bugun,  son_okuma FROM $this->_sayac_tablo WHERE postID='$id'");
				if($sonuc) {
					$yazi_sayac_bilgi['sayac_toplam'] = $sonuc[0]->sayac_toplam;
					$yazi_sayac_bilgi['sayac_bugun'] = $sonuc[0]->sayac_bugun;
					$yazi_sayac_bilgi['son_okuma'] = strtotime($sonuc[0]->son_okuma);
					$this->sayfa_sayac_meta_kaydet($id, $yazi_sayac_bilgi);
				}
			}
			
			if(is_array($yazi_sayac_bilgi)) { $this->_cache_set('sayfa_sayac_bilgi_' . $id,$yazi_sayac_bilgi); }
		}

		#bugün okunma yaması
		if($meta=='sayac_bugun' && !empty($yazi_sayac_bilgi['son_okuma'])) {
			$son_okunma_tarihi = @date('Ymd',$yazi_sayac_bilgi['son_okuma']);
			$bugunun_time = @date('Ymd',strtotime(current_time('mysql')));
			if($bugunun_time > $son_okunma_tarihi) {
				$yazi_sayac_bilgi['sayac_bugun'] = 0;
				$this->sayfa_sayac_meta_kaydet($id, $yazi_sayac_bilgi);
			}
		}


		$yazi_sayac_bilgi[$meta] = (in_array($meta,array('sayac_toplam', 'sayac_bugun'))) ? intval($yazi_sayac_bilgi[$meta]) : $yazi_sayac_bilgi[$meta];
		
		return $yazi_sayac_bilgi[$meta];
	}
	
	
	
	
	# yazı sayaç meta bilgisi kaydet
	function sayfa_sayac_meta_kaydet($id,$metadeger) {
		$yazi_sayac_bilgi = get_post_meta($id, 'sayfa_sayac_bilgi',true);
		foreach($metadeger as $meta=>$deger) {
			$yazi_sayac_bilgi[$meta] = $deger;
		}

		update_post_meta($id, "sayfa_sayac_bilgi", $yazi_sayac_bilgi);
		$this->_cache_set('sayfa_sayac_bilgi_' . $id,$yazi_sayac_bilgi);
	}
	


	# ajax header bilgileri
	function sayfa_sayac_header_cikti_ver($ctype,$chrst) {	
		header("Content-type: $ctype; charset=$chrst");
		header( "Expires: Mon, 26 Jul 1997 05:00:00 GMT" );
		header( "Last-Modified: ".gmdate( "D, d M Y H:i:s" )."GMT" );
		header( "Cache-Control: no-cache, must-revalidate" );
		header( "Pragma: no-cache" );
	}	



	# ajax istatistik çıktı
	# örnek URL: http://<site-adresi.com>/wp-content/plugins/sayfa_sayac/sayfa_sayac.php?mode=istatistik&type=encoktoplamda&adet=10&kelimekes=100&kategori=&kategoricikar=&yazicikar=
	function sayac_mode_istatistik($parametreler) {
		
		$parametreler=$_GET;
		if(empty($parametreler['type'])) {
			wp_die(__('Error 108: Statistic type is not be empty','sayfa_sayac'));	
		} else if(empty($parametreler['adet']) || $parametreler['adet'] == 0) {
			wp_die(__('Error 109: Result limit number must be numeric','sayfa_sayac'));	
		}
		
		
		$instance['type'] = $parametreler['type'];
		$instance['adet'] = (int) $parametreler['adet'];
		$instance['kelimekes'] = (int) $parametreler['kelimekes'];
		$instance['kategori'] = (int) $parametreler['kategori'];
		$instance['kategoricikar'] = esc_attr($parametreler['kategoricikar']);
		$instance['yazicikar'] = esc_attr($parametreler['yazicikar']);
		
		$ciktiver = $this->sayfa_sayac_widget($instance,false,'array');

		$this->sayfa_sayac_header_cikti_ver('application/json', 'UTF-8');
		$ciktiver = json_encode($ciktiver);
		echo $ciktiver;
	}
	
	
	
	# sayac ajax sayım fonksiyonumuz
	function sayac_mode_ajax_sayac($parametre) {
		$this->sayfa_sayac_header_cikti_ver('text/html', 'UTF-8');
		
		$parametreler = unserialize(base64_decode($parametre));
		$parametreler['a'] = $_SERVER['HTTP_USER_AGENT'];
		
		if(!is_numeric($parametreler['p'])) {
			wp_die(__('Error 102: Post ID number is not numeric','sayfa_sayac'));	
		}
		
		/*
		if(time() - $parametreler['t'] > $this->_zaman_asimi) {
			wp_die(str_replace('%', (time() - $parametreler['t'] - $this->_zaman_asimi), __('Error 101: Request timeout: % sn','sayfa_sayac')));
		} else if(empty($parametreler['a'])) {
			wp_die(__('Error 103: HTTP USER AGENT is empty','sayfa_sayac'));	
		}
		*/
		
		$post_id = $parametreler['p'];
		
		$okunanlar	= (is_serialized(base64_decode($_COOKIE['sayfa_sayac_okunan']))) ? unserialize(base64_decode($_COOKIE['sayfa_sayac_okunan'])) : array();
		if(in_array($post_id,$okunanlar)) wp_die(__('Error 104: You already read this post','sayfa_sayac'));	 #cookie kontrolü yaptık, tekrar okunmalar sayılmaz
		
		if($this->sayfa_sayac_meta_oku($post_id, 'sayfa_sayac_durdur')==1) wp_die(__('Error 105: This post is not counting','sayfa_sayac')); #bu yazı için sayaç durdurulmuş, saymıyoruz..
		
		if(in_array('bots',$this->_ayarlar['sayac_sayilmayacaklar'])) { #eğer botsa, saydırmayalım
			foreach ($this->_bots as $botad => $aranacak) { 
				if (stristr($parametreler['a'], $aranacak) !== false) wp_die(__('Error 106: You are a bot','sayfa_sayac'));
			}
		}
		
		$kullanici_bilgi=wp_get_current_user();
		foreach($kullanici_bilgi->roles as $_role) {
			if(in_array($_role,$this->_ayarlar['sayac_sayilmayacaklar'])) wp_die(__('Error 107: Your role is not counting','sayfa_sayac'));	 #kullanıcı seviyesine göre sayıcı durdurduk
		}
		
		$son_okunma_tarihi = @$this->sayfa_sayac_meta_oku($post_id, 'son_okuma');
		$sayac_meta_toplam = @$this->sayfa_sayac_meta_oku($post_id, 'sayac_toplam');
		$sayac_meta_bugun = @$this->sayfa_sayac_meta_oku($post_id, 'sayac_bugun');

		
		$simdiki_tarihimiz = strtotime(current_time('mysql'));
		$yazi_son_okuma = (empty($son_okunma_tarihi)) ? $simdiki_tarihimiz : $son_okunma_tarihi;
		
		$sql_query = "update $this->_sayac_tablo set sayac_toplam = (sayac_toplam+1), ";
		$sql_query .= (date('Ymd',$simdiki_tarihimiz)>date('Ymd',$yazi_son_okuma)) ? 'sayac_bugun=1' : 'sayac_bugun = (sayac_bugun+1)' ;
		$sql_query .=", son_okuma = '". current_time('mysql') ."' WHERE postID='$post_id' ";
	
		if ( !$this->wpdb->query($sql_query) ) {
			$sql_query = "insert into $this->_sayac_tablo (postID, sayac_toplam, sayac_bugun, son_okuma) values ('$post_id', '1', '1', '". current_time('mysql') ."')";
			$this->wpdb->query($sql_query);
		}		
		
		$post_meta_deger = array('sayac_toplam'=>$sayac_meta_toplam+1, 'sayac_bugun'=>( (date('Ymd',$simdiki_tarihimiz)>date('Ymd',$yazi_son_okuma)) ? 1 : $sayac_meta_bugun+1), 'son_okuma'=>$simdiki_tarihimiz);
		
		$this->sayfa_sayac_meta_kaydet($post_id, $post_meta_deger);

		$okunanlar	[] = $post_id;
		setcookie('sayfa_sayac_okunan', base64_encode(serialize($okunanlar)), 0, COOKIEPATH, COOKIE_DOMAIN);
		
	}
	
	
	
	# sayac ayar sayfası action
	function sayfa_sayac_ayar_action() {
		add_submenu_page('plugins.php', __('Sayfa Sayaç Configuration','sayfa_sayac'), __('Sayfa Sayaç Configuration','sayfa_sayac'), $this->_ayarlar['sayac_erisim'], __FILE__, array(&$this , 'sayfa_sayac_ayar_sayfasi'));
	}
	
		
	
	# yazı yönetim paneline okunma sütunu ekleniyor
	function sayfa_sayac_yonetim_sutun($post_columns) {
		$post_columns['sayfa_sayac_total_read'] = __('Total Views', 'sayfa_sayac');
		$post_columns['sayfa_sayac_today_read'] = __('Today Views', 'sayfa_sayac');
		return $post_columns;
	}
	
	
	
	# yazı yönetimindeki okunma sayısı basılıyor
	function sayfa_sayac_yonetim_sutun_deger($column_name) {
		global $post;
		if ($column_name=='sayfa_sayac_total_read') {
			echo (int) $this->sayfa_sayac_meta_oku($post->ID, 'sayac_toplam');
		} else if ($column_name=='sayfa_sayac_today_read') {
			echo (int) $this->sayfa_sayac_meta_oku($post->ID, 'sayac_bugun');
		}
	}
	
	
	# sayac eklentisini kuruyoruz
	function sayfa_sayac_kurulum() {

	   if($this->wpdb->get_var("show tables like '$this->_sayac_tablo'") != $this->_sayac_tablo) {	
			$sayac_tablo_sql= "CREATE TABLE IF NOT EXISTS  " . $this->_sayac_tablo . " (
			  `postID` bigint(20) NOT NULL,
			  `sayac_toplam` int(10) unsigned NOT NULL,
			  `sayac_bugun` mediumint(8) unsigned NOT NULL,
			  `son_okuma` datetime NOT NULL,
			  PRIMARY KEY  (`postID`),
			  KEY `sayac_toplam` (`sayac_toplam`),
			  KEY `sayac_bugun` (`sayac_bugun`)
			);";
			$sonuc = $this->wpdb->query($sayac_tablo_sql);
		}
		
		$tablo_tamammi = $this->wpdb->get_row("show tables like '$this->_sayac_tablo' ");
		if (!$tablo_tamammi) {
			$current = get_option('active_plugins');
			array_splice($current, array_search( 'sayfa_sayac/sayfa_sayac.php', $current), 1 );
			update_option('active_plugins', $current);
			wp_die(__('DB table can not created. So plugin can not be activated. Check your db user permissions for creating table. Please <a href="plugins.php">click here</a> to return manage plugins.','sayfa_sayac'));
		}
		
		#ayarları güncelleştir
		$_eski_ayarlar = get_option('sayfa_sayac');
		$_yeni_ayarlar = array('surum'=>$this->_surum, 'sayac_erisim'=>'administrator', 'sayac_onbellek'=>'yok', 'sayac_tarih_format' => 'd.m.Y', 'sayac_goster' => 'e', 'sayac_erisim' => 'administrator', 'sayac_gorebilecekler' => 'everyone', 'sayac_sayilmayacaklar' => array('bots', 'administrator'), 'sayac_bilgi_tur' => 'i', 'sayac_bilgi_yer_y' => 'a', 'sayac_bilgi_yer_l' => 'a', 'sayac_bilgi_sablon' => __('%sayac_toplam% views', 'sayfa_sayac'), 'encok_toplam_sablon' => __('<li><a href="%yazi_url%" title="%yazi_baslik%">%yazi_baslik% (%sayac_toplam%)</a></li>','sayfa_sayac'));
		$_gun_ayarlar = array();
		foreach($_yeni_ayarlar as $__ayar_ad => $__ayar_deger) {
			$_gun_ayarlar[$__ayar_ad] = ($_eski_ayarlar[$__ayar_ad]) ? $_eski_ayarlar[$__ayar_ad] : $_yeni_ayarlar[$__ayar_ad];
		}

		update_option('sayfa_sayac', $_gun_ayarlar);
		
		#yükseltme yapalım ve mevcut hataları düzeltelim
		# önce yazı türü "page" olanların bilgilerini silmeliyiz.
		$bul = $this->wpdb->get_results("SELECT ID FROM ". $this->wpdb->posts ." WHERE post_type IN ('page', 'revision', 'attachment')");
		if($bul) {
			$_id_array = array();
			foreach($bul as $__id) {
				delete_post_meta($__id->ID, 'sayfa_sayac_bilgi');	
				$_id_array[] = $__id->ID;
			}			
			$this->wpdb->query("delete from $this->_sayac_tablo WHERE postID IN(". implode(', ', $_id_array) .") ");			
		}
		# tamam, şimdi de sayaç tablosunda 0 olanları bulalım ve silelim
		$bul2 = $this->wpdb->get_results("SELECT postID FROM $this->_sayac_tablo WHERE sayac_toplam='0' ");
		
		if($bul2) {
			$_id_array = array();
			foreach($bul2 as $__id) {
				delete_post_meta($__id->postID, 'sayfa_sayac_bilgi');	
				$_id_array[] = $__id->postID;
			}			
			$this->wpdb->query("delete from $this->_sayac_tablo WHERE postID IN(". implode(', ', $_id_array) .") ");			
		}
		
		# post metası olmayan yazılar için post meta açmalıyız.
		$bul3 = $this->wpdb->get_results("select wpo.postID
FROM $this->_sayac_tablo AS wpo,
". $this->wpdb->postmeta ." AS wpm
WHERE wpm.post_id = wpo.postID AND wpm.meta_key='sayfa_sayac_bilgi'
GROUP BY wpo.postID");
	if($bul3) {
		$_id_array2 = array();
		foreach($bul3 as $__id) {
			$_id_array2[] = $__id->postID;
		}			
		$bul4 = $this->wpdb->get_results("SELECT postID, sayac_toplam, sayac_bugun, son_okuma FROM $this->_sayac_tablo WHERE postID NOT IN(". implode(', ', $_id_array2) .") ");
		if($bul4) {
			foreach($bul4 as $__id) {
				$yazi_sayac_bilgi['sayac_toplam'] = $__id->sayac_toplam;
				$yazi_sayac_bilgi['sayac_bugun'] = $__id->sayac_bugun;
				$yazi_sayac_bilgi['son_okuma'] = strtotime($__id->son_okuma);
				$this->sayfa_sayac_meta_kaydet($__id->postID, $yazi_sayac_bilgi);
			}		
		}
			
	}	
		
	}
	
	
	
	# dil dosyasını yüklüyoruz
	function sayfa_sayac_dil_yukle() {
		load_plugin_textdomain('sayfa_sayac', false, $this->_eklenti_ad.'/lang');
	}
	
	
	
	# cache reset zımbırtısı
	function sayac_mode_cache_reset($parametreler) {
		$kullanici_bilgi=wp_get_current_user();
		
		if(!empty($kullanici_bilgi->roles) && in_array($this->_ayarlar['sayac_erisim'], $kullanici_bilgi->roles)) {
		
			$this->_cache_delete('sayfa_sayac_toplam_okunma_ver');
			$this->_cache_delete('sayfa_sayac_bugun_okunma_ver');
			$this->_cache_delete('sayfa_sayac_widget_data');
			$sonuclar = $this->wpdb->get_results("select ID FROM ". $this->wpdb->posts);
			foreach($sonuclar  as $_sonuc) {
				$this->_cache_delete('sayfa_sayac_bilgi_' . $_sonuc->ID);
			}
			$__url = get_option('siteurl') .'/wp-admin/plugins.php?page=sayfa_sayac/sayfa_sayac.php';
			$__url = str_replace('%',$__url,__('Cache cleaned. <a href="%">Click here</a> to return admin page.','sayfa_sayac'));
			wp_die($__url);	
		} else {
			wp_die(__('Error 110: You have not permission for access here.','sayfa_sayac'));	
		}
	}
	
	

	# cache reset reset zımbırtısı
	function sayac_mode_cache_reset_counter($parametreler) {
		$kullanici_bilgi=wp_get_current_user();
		
		if(!empty($kullanici_bilgi->roles) && in_array($this->_ayarlar['sayac_erisim'], $kullanici_bilgi->roles)) {
		
			$this->_cache_delete('sayfa_sayac_toplam_okunma_ver');
			$this->_cache_delete('sayfa_sayac_bugun_okunma_ver');
			$this->_cache_delete('sayfa_sayac_widget_data');
			$sonuclar = $this->wpdb->get_results("select ID FROM ". $this->wpdb->posts);
			foreach($sonuclar  as $_sonuc) {
				$this->_cache_delete('sayfa_sayac_bilgi_' . $_sonuc->ID);
			}
			$this->wpdb->query("delete from $this->_sayac_tablo");
			$this->wpdb->query("delete from ". $this->wpdb->postmeta ." WHERE meta_key='sayfa_sayac_bilgi' ");
			
			$__url = get_option('siteurl') .'/wp-admin/plugins.php?page=sayfa_sayac/sayfa_sayac.php';
			$__url = str_replace('%',$__url,__('Counter reseted. <a href="%">Click here</a> to return admin page.','sayfa_sayac'));
			wp_die($__url);	
		} else {
			wp_die(__('Error 110: You have not permission for access here.','sayfa_sayac'));	
		}
	}
	
	
	
	# post sayaç reset zımbırtısı
	function sayac_mode_post_count_reset($parametreler) {
		$kullanici_bilgi=wp_get_current_user();
		
		if(!empty($kullanici_bilgi->roles) && in_array($this->_ayarlar['sayac_erisim'], $kullanici_bilgi->roles)) {
		
			$parametreler=$_GET;
			if(empty($parametreler['p'])) { wp_die(__('Error 111: Post ID is not be null','sayfa_sayac')); }	
			$post_id = (int) $parametreler['p'];
			
			$this->_cache_delete('sayfa_sayac_toplam_okunma_ver');
			$this->_cache_delete('sayfa_sayac_bugun_okunma_ver');
			$this->_cache_delete('sayfa_sayac_widget_data');
			$this->_cache_delete('sayfa_sayac_bilgi_' . $post_id);
			
			delete_post_meta($post_id, 'sayfa_sayac_bilgi');
			$this->wpdb->query("delete from $this->_sayac_tablo WHERE postID='$post_id' ");
			
			wp_die(__('Post view counter reseted. Now you can close this window.','sayfa_sayac'));
		} else {
			wp_die(__('Error 110: You have not permission for access here.','sayfa_sayac'));	
		}
	}
	
	
	
	# sayfa sayaç kaldırma işlemi
	function sayac_mode_uninstall_sayfa_sayac($parametreler) {
		$kullanici_bilgi=wp_get_current_user();
		
		if(!empty($kullanici_bilgi->roles) && in_array($this->_ayarlar['sayac_erisim'], $kullanici_bilgi->roles)) {
		
			$this->_cache_delete('sayfa_sayac_toplam_okunma_ver');
			$this->_cache_delete('sayfa_sayac_bugun_okunma_ver');
			$this->_cache_delete('sayfa_sayac_widget_data');
			$sonuclar = $this->wpdb->get_results("select ID FROM ". $this->wpdb->posts);
			foreach($sonuclar  as $_sonuc) {
				$this->_cache_delete('sayfa_sayac_bilgi_' . $_sonuc->ID);
			}
			
			$this->wpdb->query("DROP TABLE $this->_sayac_tablo");
			$this->wpdb->query("delete from ". $this->wpdb->postmeta ." WHERE meta_key='sayfa_sayac_bilgi' ");			
			
			delete_option('sayfa_sayac');
			
			$__url = get_option('siteurl') .'/wp-admin/plugins.php?action=deactivate&plugin=sayfa_sayac/sayfa_sayac.php';
			
			$__url = 'plugins.php?action=deactivate&amp;plugin=sayfa_sayac/sayfa_sayac.php';
			if(function_exists('wp_nonce_url')) { 
				$__url = wp_nonce_url($__url, 'deactivate-plugin_sayfa_sayac/sayfa_sayac.php');
			}	
			$__url = get_option('siteurl') .'/wp-admin/'.$__url;
			
			
			$__url = str_replace('%',$__url,__('Sayfa sayaç uninstalled. <a href="%">Click here</a> deactive sayfa sayaç.','sayfa_sayac'));
			wp_die($__url);	
		} else {
			wp_die(__('Error 110: You have not permission for access here.','sayfa_sayac'));	
		}	
	}
	
	
	
	# wp postviews dönüştürelim
	function sayac_mode_import_from_wp_postviews($parametreler) {
		$kullanici_bilgi=wp_get_current_user();
		
		if(!empty($kullanici_bilgi->roles) && in_array($this->_ayarlar['sayac_erisim'], $kullanici_bilgi->roles)) {
			$_sql_insert = array();
			$sonuclar = $this->wpdb->get_results("select post_id, meta_value FROM ". $this->wpdb->postmeta ." WHERE meta_key='views' ");
			foreach($sonuclar  as $_sonuc) {
				$_sql_insert[] = "('$_sonuc->post_id','$_sonuc->meta_value')";
			
				$post_meta_deger = array('sayac_toplam'=>$_sonuc->meta_value, 'sayac_bugun'=> 0, 'son_okuma'=>'');
				$this->sayfa_sayac_meta_kaydet($_sonuc->post_id, $post_meta_deger);
			}
			
			$this->wpdb->query("insert into $this->_sayac_tablo (postID, sayac_toplam) VALUES " . implode(', ', $_sql_insert));

			$this->wpdb->query("delete from ". $this->wpdb->postmeta ." WHERE meta_key='views' ");			
			$__url = get_option('siteurl') .'/wp-admin/plugins.php?page=sayfa_sayac/sayfa_sayac.php';
			$__url = str_replace('%',$__url,__('Import action is completed. <a href="%">Click here</a> to return admin page.','sayfa_sayac'));
			wp_die($__url);	
		} else {
			wp_die(__('Error 110: You have not permission for access here.','sayfa_sayac'));	
		}	
	}	



	# wp meta ve okunma fix
	function sayac_mode_fix_meta_table($parametreler) {
		$kullanici_bilgi=wp_get_current_user();
		
		if(!empty($kullanici_bilgi->roles) && in_array($this->_ayarlar['sayac_erisim'], $kullanici_bilgi->roles)) {
			$_sql_insert = array();
			$sonuclar = $this->wpdb->get_results("SELECT wpo.postID, wpo.sayac_toplam, wpo.sayac_bugun, wpo.son_okuma, wpm.meta_value, wpm.meta_id FROM ". $this->_sayac_tablo ." as wpo, ". $this->wpdb->postmeta ." AS wpm WHERE wpm.post_id = wpo.postID AND wpm.meta_key='sayfa_sayac_bilgi'");
			
			foreach($sonuclar  as $_sonuc) {
				$post_meta_deger = array();
				$_meta_data = unserialize($_sonuc->meta_value);
				if($_meta_data['sayac_toplam']!=$_sonuc->sayac_toplam) {
					$post_meta_deger['sayac_toplam'] = ($_sonuc->sayac_toplam > $_meta_data['sayac_toplam']) ? $_sonuc->sayac_toplam : $_meta_data['sayac_toplam'];
				}
				if($_meta_data['sayac_bugun']!=$_sonuc->sayac_bugun) {
					$post_meta_deger['sayac_bugun'] = ($_sonuc->sayac_bugun > $_meta_data['sayac_bugun']) ? $_sonuc->sayac_bugun : $_meta_data['sayac_bugun'];
				}			
				if (count($post_meta_deger) > 0) {
					$this->sayfa_sayac_meta_kaydet($_sonuc->postID, $post_meta_deger);
				}
			}
			
			
			$__url = get_option('siteurl') .'/wp-admin/plugins.php?page=sayfa_sayac/sayfa_sayac.php';
			$__url = str_replace('%',$__url,__('Fix action is completed. <a href="%">Click here</a> to return admin page.','sayfa_sayac'));
			wp_die($__url);	
		} else {
			wp_die(__('Error 110: You have not permission for access here.','sayfa_sayac'));	
		}	
	}	
	
	
	
	# sayfa sayaç ayarlar ekranı yükleniyor
	function sayfa_sayac_ayar_sayfasi() {
		
		if($_POST) {
			if (!empty($_POST['submit'])) {
				unset($_POST['submit']);	
				update_option('sayfa_sayac', $_POST);
				$this->_ayarlar = get_option('sayfa_sayac');
			}
		}
	?>
		<div class="wrap">
        <div id="icon-options-general" class="icon32"><br /></div> 
		<h2><?php _e('Sayfa Sayaç Configuration','sayfa_sayac'); ?></h2>
		
<p><?php _e('Do you like plugin? So you will donate for plugin','sayfa_sayac'); ?></p>        
<p><a href="<?php echo $this->_paypal_buton; ?>" title="<?php _e('Donate','sayfa_sayac'); ?>" target="_blank"><img src="<?php echo $this->_img_url . 'paypal.gif'; ?>" alt="<?php _e('Donate','sayfa_sayac'); ?>" /></a></p>
        
		<form action="" method="post" id="syc-ayar">
		
<h3 class="title"><?php _e('General Options','sayfa_sayac'); ?></h3>   
		
		<table class="form-table">       
        
<tr> 
<th scope="row"><?php _e('Cache Type:','sayfa_sayac'); ?></th> 
<td> 
<select name="sayac_onbellek" size="1">
<?php
$__cache_tur = array(
	'yok'=>array('Not use caching'),
	'memcached'=>array('Memcached','Memcache'),
	'xcache'=>array('xCache','xcache_get'),
	'apc'=>array('Alternative PHP Cache (APC)','apc_store'),
	'ea'=>array('eAccelerator','eaccelerator_get '),
);

foreach($__cache_tur as $_cache_ad=>$_cache) {
	echo '<option value="'.  $_cache_ad .'"' . ( ($this->_ayarlar['sayac_onbellek']==$_cache_ad)  ? ' selected="selected"' : '' ) . ( (isset($_cache[1]) && (!function_exists($_cache[1]) && !class_exists($_cache[1])) ) ? ' disabled="disabled"' : ''  ) .'>'. __($_cache[0],'sayfa_sayac') .  ( (isset($_cache[1]) && (!function_exists($_cache[1]) && !class_exists($_cache[1])) ) ? __(' - Not Installed','sayfa_sayac') : ''  ) . '</option>';
	
}
?>
</select>
</fieldset> 
</td> 
</tr>         
        
<tr valign="top"> 
<th scope="row"><label for="sayac_tarih_format"><?php _e('Date Format:','sayfa_sayac'); ?></label></th> 
<td>
<input id="sayac_tarih_format" name="sayac_tarih_format" type="text" class="regular-text code" value="<?php echo $this->_ayarlar['sayac_tarih_format'];?>" />  <a href="http://codex.wordpress.org/Formatting_Date_and_Time" target="_blank"><small><?php _e('Documentation on date formatting','sayfa_sayac'); ?></small></a>
</td> 
</tr>         
        
<tr valign="top"> 
<th scope="row"><?php _e('Display counter (automatically)','sayfa_sayac'); ?></th> 
<td>
<select name="sayac_goster" size="1"> 
    <option value="e"<?php echo ($this->_ayarlar['sayac_goster']=='e') ? ' selected="selected"' : '';?>><?php _e('Yes','sayfa_sayac'); ?></option> 
    <option value="h"<?php echo ($this->_ayarlar['sayac_goster']=='h') ? ' selected="selected"' : '';?>><?php _e('No','sayfa_sayac'); ?></option> 
</select>
<small><?php _e("&#8211; Display counter information bottom or top postion the post. In this mode, you must not add counter function in the template file.",'sayfa_sayac'); ?></small>
</td> 
</tr>        
        
        
<tr valign="top"> 
<th scope="row"><?php _e('Role','sayfa_sayac'); ?></th> 
<td>
<select name="sayac_erisim" id="sayac_erisim">
    <?php wp_dropdown_roles($this->_ayarlar['sayac_erisim']); ?>
</select>
<small><?php _e('Select user capability who can display configuration page','sayfa_sayac'); ?></small>
</td> 
</tr>


<tr valign="top"> 
<th scope="row"><?php _e('Count Views From','sayfa_sayac'); ?></th> 
<td>
<select name="sayac_gorebilecekler" size="1"> 
    <option value="everyone"<?php echo ($this->_ayarlar['sayac_gorebilecekler']=='everyone') ? ' selected="selected"' : '';?>><?php _e('Everyone','sayfa_sayac'); ?></option> 
    <option value="guests"<?php echo ($this->_ayarlar['sayac_gorebilecekler']=='guests') ? ' selected="selected"' : '';?>><?php _e('Guests','sayfa_sayac'); ?></option>
    <option value="allusers"<?php echo ($this->_ayarlar['sayac_gorebilecekler']=='allusers') ? ' selected="selected"' : '';?>><?php _e('All Registered Users','sayfa_sayac'); ?></option> 
<?php
$editable_roles = get_editable_roles();
foreach ( $editable_roles as $role => $details ) {
	$name = translate_user_role($details['name'] );
	if ( $this->_ayarlar['sayac_gorebilecekler'] == $role ) {
		echo "<option selected='selected' value='" . esc_attr($role) . "'>$name</option>";
	} else {
		echo "<option value='" . esc_attr($role) . "'>$name</option>";
	}
}
?>    
</select>
</td> 
</tr>  

        
<tr valign="top"> 
<th scope="row"><?php _e('Exclude Views From','sayfa_sayac'); ?></th> 
<td>
<select name="sayac_sayilmayacaklar[]" size="4" multiple="multiple" style="height:120px;"> 
    <option value="bots"<?php echo (in_array('bots',$this->_ayarlar['sayac_sayilmayacaklar'])) ? ' selected="selected"' : '';?>><?php _e('Bots','sayfa_sayac'); ?></option> 
    <option value="allusers"<?php echo (in_array('allusers',$this->_ayarlar['sayac_sayilmayacaklar'])) ? ' selected="selected"' : '';?>><?php _e('All Registered Users','sayfa_sayac'); ?></option>
<?php
$editable_roles = get_editable_roles();
foreach ( $editable_roles as $role => $details ) {
	$name = translate_user_role($details['name'] );
	if ( in_array($role,$this->_ayarlar['sayac_sayilmayacaklar']) ) {
		echo "<option selected='selected' value='" . esc_attr($role) . "'>$name</option>";
	} else {
		echo "<option value='" . esc_attr($role) . "'>$name</option>";
	}
}
?>      
</select>
</td> 
</tr> 


		</table>

	
 <h3 class="title"><?php _e('Counter Information','sayfa_sayac'); ?></h3>     
 		<table class="form-table"> 
		
		 <tr valign="top"> 
		<th scope="row"><?php _e('Display for','sayfa_sayac'); ?></th> 
		<td>
		<label><input type="radio" name="sayac_bilgi_tur" value="y" <?php echo ($this->_ayarlar['sayac_bilgi_tur']=='y') ? ' checked="checked"' : '';?> /> <?php _e('Only single post template (single.php)','sayfa_sayac'); ?></label><br />
		<label><input type="radio" name="sayac_bilgi_tur" value="l" <?php echo ($this->_ayarlar['sayac_bilgi_tur']=='l') ? ' checked="checked"' : '';?> /> <?php _e('Only listing templates (index, archives etc.)','sayfa_sayac'); ?></label><br />
		<label><input type="radio" name="sayac_bilgi_tur" value="i" <?php echo ($this->_ayarlar['sayac_bilgi_tur']=='i') ? ' checked="checked"' : '';?> /> <?php _e('Both','sayfa_sayac'); ?></label>
		</td> 
		<td rowspan="3">
	<pre style='font-size: 11px; border: 1px solid #E5E3DA; margin: 0px; padding:10px; background-color:#F9F7ED;'>
	%sayac_toplam%	- <?php _e('Total view number of the post','sayfa_sayac'); ?>		
	%sayac_bugun%	- <?php _e('Daily view number of the post','sayfa_sayac'); ?>	
	%son_okuma%	- <?php _e('Last reading date of the post','sayfa_sayac'); ?></pre>    
		</td>    
		</tr>    
		  <tr valign="top"> 
		<th scope="row"><?php _e('Position for single template','sayfa_sayac'); ?></th> 
		<td>
		<label><input type="radio" name="sayac_bilgi_yer_y" value="u" <?php echo ($this->_ayarlar['sayac_bilgi_yer_y']=='u') ? ' checked="checked"' : '';?> /> <?php _e('Top','sayfa_sayac'); ?></label>
		<label><input type="radio" name="sayac_bilgi_yer_y" value="a" <?php echo ($this->_ayarlar['sayac_bilgi_yer_y']=='a') ? ' checked="checked"' : '';?> /> <?php _e('Bottom','sayfa_sayac'); ?></label>
		</td> 
		</tr>
		
		  <tr valign="top"> 
		<th scope="row"><?php _e('Position for listing templates','sayfa_sayac'); ?></th> 
		<td>
		<label><input type="radio" name="sayac_bilgi_yer_l" value="u" <?php echo ($this->_ayarlar['sayac_bilgi_yer_l']=='u') ? ' checked="checked"' : '';?> /> <?php _e('Top','sayfa_sayac'); ?></label>
		 <label><input type="radio" name="sayac_bilgi_yer_l" value="a" <?php echo ($this->_ayarlar['sayac_bilgi_yer_l']=='a') ? ' checked="checked"' : '';?> /><?php _e('Bottom','sayfa_sayac'); ?></label>
		</td> 
		</tr>        
	 
		<tr valign="top"> 
		<th scope="row"><label for="sayac_bilgi_sablon"><?php _e('Template','sayfa_sayac'); ?></label></th> 
		<td>
		<textarea cols="50" rows="2" wrap="virtual" id="sayac_bilgi_sablon" name="sayac_bilgi_sablon"><?php echo htmlspecialchars(stripslashes($this->_ayarlar['sayac_bilgi_sablon']));?></textarea>
		</td> 
		</tr>          
	</table>

 <h3 class="title"><?php _e('Templates For Listing','sayfa_sayac'); ?></h3>     
 		<table class="form-table"> 
		<tr valign="top"> 
		<th scope="row"><label for="encok_toplam_sablon"><?php _e('Template','sayfa_sayac'); ?></label></th> 
		<td>
		<textarea cols="50" rows="2" wrap="virtual" id="encok_toplam_sablon" name="encok_toplam_sablon"><?php echo htmlspecialchars(stripslashes($this->_ayarlar['encok_toplam_sablon']));?></textarea>
		</td>
		<td rowspan="3">
	<pre style='font-size: 11px; border: 1px solid #E5E3DA; margin: 0px; padding:10px; background-color:#F9F7ED;'>
	%sayac_toplam%	- <?php _e('Total view number of the post','sayfa_sayac'); ?>		
	%sayac_bugun%	- <?php _e('Daily view number of the post','sayfa_sayac'); ?>	
	%yazi_baslik%	- <?php _e('Title of the post','sayfa_sayac'); ?>		
	%yazi_url%	- <?php _e('Link to the post','sayfa_sayac'); ?></pre>    
		</td>
		</tr>
	</table>


	<p class="submit"><input type="submit" name="submit" value="<?php _e('Save Options &raquo;','sayfa_sayac'); ?>" /></p>
		</form>
        
        
<h2><?php _e('Reset Caches','sayfa_sayac'); ?></h2>
<p><?php _e('Do you want to reset caches? Ok?, click below :)','sayfa_sayac'); ?></p>
<p><form action="" method="post" id="syc-ayar"><input type="button" name="resetcaches" value="<?php _e('Reset Caches','sayfa_sayac'); ?>" onclick="window.location='<?php echo $this->_url; ?>mode=cache_reset';" /></form></p>	        

<h2><?php _e('Reset Counter','sayfa_sayac'); ?></h2>
<p><?php _e('Do you want to reset counter? All of post view numbers will be reset? Ok?, click below :)','sayfa_sayac'); ?></p>
<p><form action="" method="post" id="syc-ayar"><input type="button" name="resetcounter" value="<?php _e('Reset Counter','sayfa_sayac'); ?>" onclick="if(confirm('<?php _e('Are you sure? All counter data will erased?','sayfa_sayac'); ?>')) window.location='<?php echo $this->_url; ?>mode=cache_reset_counter';" /></form></p>	        


<h2><?php _e('Uninstall Sayfa Sayaç','sayfa_sayac'); ?></h2>
<p><?php _e('Do you want to uninstall sayfa sayaç? All of counter data will erased and database table will droped? Ok?, click below :)','sayfa_sayac'); ?></p>
<p><form action="" method="post" id="syc-ayar"><input type="button" name="uninstallcounter" value="<?php _e('Uninstall Sayfa Sayaç','sayfa_sayac'); ?>" onclick="if(confirm('<?php _e('Are you sure? All counter data will erased?','sayfa_sayac'); ?>')) window.location='<?php echo $this->_url; ?>mode=uninstall_sayfa_sayac';" /></form></p>       

<h2><?php _e('Import From WP-PostViews','sayfa_sayac'); ?></h2>
<p><?php _e('If you want to import WP-PostViews data to Sayfa Sayaç, click below button.','sayfa_sayac'); ?></p>
<p><form action="" method="post" id="syc-ayar"><input type="button" name="importpostviews" value="<?php _e('Import From WP-PostViews','sayfa_sayac'); ?>" onclick="if(confirm('<?php _e('Are you sure? All WP-PostViews data will erased after convert finished','sayfa_sayac'); ?>')) window.location='<?php echo $this->_url; ?>mode=import_from_wp_postviews';" /></form></p>


<h2><?php _e('Repair post-meta and counter table errors','sayfa_sayac'); ?></h2>
<p><?php _e('Sometimes, postmeta data and counter table not return the same counter results. So, you can fix this results','sayfa_sayac'); ?></p>
<p><form action="" method="post" id="syc-ayar"><input type="button" name="importpostviews" value="<?php _e('Repair Table And Meta Data Errors','sayfa_sayac'); ?>" onclick="window.location='<?php echo $this->_url; ?>mode=fix_meta_table';" /></form></p>

		</div>    
	<?php
	}



	# cache get
	function _cache_get($key) {
		switch($this->_ayarlar['sayac_onbellek']) {
			case 'yok';
			return false;
			break;
			
			case 'memcached':
			$this->_memcache->get($key);
			break;
			
			case 'xcache';	
			if(xcache_isset($key)) return xcache_get($key);
			break;
			
			case 'apc';
			if(apc_exists($key)) return apc_fetch($key);
			break;
			
			case 'ea';
			return eaccelerator_get($key);
			break;
		}	
	}
	
	
	
	# cache set
	function _cache_set($key,$value) {
		switch($this->_ayarlar['sayac_onbellek']) {
			case 'yok';
			return false;
			break;
			
			case 'memcached':
			$this->_memcache->set($key,$value,0,$this->_cache_zaman_asimi);
			break;
			
			case 'xcache';	
			xcache_set($key, $value,$this->_cache_zaman_asimi);
			break;
			
			case 'apc';
			apc_store($key, $value,$this->_cache_zaman_asimi);
			break;
			
			case 'ea';
			eaccelerator_put($key, $value,$this->_cache_zaman_asimi);
			break;
		}	
	}	
	
	
	
	# cache delete
	function _cache_delete($key) {
		switch($this->_ayarlar['sayac_onbellek']) {
			case 'yok';
			return false;
			break;
			
			case 'memcached':
			$this->_memcache->delete($key);
			break;
				
			case 'xcache';
			xcache_unset($key);
			break;
			
			case 'apc';
			apc_delete($key);
			break;
			
			case 'ea';
			eaccelerator_rm($key);
			break;
		}	
	}
	
	
	
	# tüm  zamanlarda okunan tüm yazıların toplam okunma sayısı - return
	function sayfa_sayac_toplam_okunma_ver() {
		$sonuc = $this->_cache_get('sayfa_sayac_toplam_okunma_ver');
		if(empty($sonuc)) {
			$sonuc = $this->wpdb->get_row("SELECT sum(sayac_toplam) as toplamokunma FROM $this->_sayac_tablo");
			$sonuc = number_format_i18n($sonuc->toplamokunma);
			$this->_cache_set('sayfa_sayac_toplam_okunma_ver', $sonuc);
		}
		return $sonuc;
	}
	
	
	
	# tüm zamanlarda okunan tüm yazıların toplam okunma sayısı - echo
	function sayfa_sayac_toplam_okunma_yaz() {
		$sonuc = $this->sayfa_sayac_toplam_okunma_ver();
		echo $sonuc;
	}
	
	
	
	# bugün okunan tüm yazıların toplam okunma sayısı - return
	function sayfa_sayac_bugun_okunma_ver() {
		$sonuc = $this->_cache_get('sayfa_sayac_bugun_okunma_ver');
		if(empty($sonuc)) {
			$timestamp = strtotime(current_time('mysql'));
			$sonuc = $this->wpdb->get_row("SELECT sum(sayac_bugun) as toplamokunmabugun FROM $this->_sayac_tablo WHERE date_format(son_okuma,'%d-%m-%Y')='". date('d-m-Y', $timestamp) ."'");
			$sonuc = number_format_i18n($sonuc->toplamokunmabugun);
			$this->_cache_set('sayfa_sayac_bugun_okunma_ver', $sonuc);
		}
		return $sonuc;
	}
	
	
	
	# bugün okunan tüm yazıların toplam okunma sayısı - echo
	function sayfa_sayac_bugun_okunma_yaz() {
		$sonuc = $this->sayfa_sayac_bugun_okunma_ver();
		echo $sonuc;
	}		



	# sayfa sayaç widget ekliyorum
	function sayfa_sayac_widgets_init() {
		register_widget('WP_Widget_Sayfa_Sayac');
	}
	
	
	
	# sayfa sayaç başlık kısaltma işlemi
	function sayfa_sayac_baslik_kes($baslik, $kes) {
		if(function_exists('mb_substr')) {
			$baslik = (mb_strlen($baslik,'UTF-8') > $kes) ? mb_substr($baslik,0,$kes).'..' : $baslik;	
		} else {
			$baslik = (strlen($baslik,'UTF-8') > $kes) ? substr($baslik,0,$kes).'..' : $baslik;
		}
		return $baslik;
	}
	
	
	
	# sayfa sayaç widget çıktısı
	function sayfa_sayac_widget($instance, $echo=true, $cikti='widget') {
		
		$type = esc_attr($instance['type']);
		$adet = intval($instance['adet']);
		$kelimekes = intval($instance['kelimekes']);
		$kategori = intval($instance['kategori']);
		$kategoricikar = esc_attr($instance['kategoricikar']);
		$yazicikar = esc_attr($instance['yazicikar']);
		$timestamp = strtotime(current_time('mysql'));
		
		$_onbellek_id = md5(implode('-',$instance) .'-'. $cikti);
		
		$sonuc_listesi = $this->_cache_get('sayfa_sayac_widget_data');

		$sonuc_listesi = $sonuc_listesi[$_onbellek_id];
		if(empty($sonuc_listesi)) {
			
			$SQL = "SELECT ";
			$SQL .="wpo.sayac_toplam, wpo.sayac_bugun, wp.ID, wp.* "; 
			$SQL .="FROM "; 
			$SQL .="$this->_sayac_tablo AS wpo, ". $this->wpdb->posts ." AS wp, ". $this->wpdb->postmeta ." AS wpm "; 
			$SQL .=(!empty($kategori) || !empty($kategoricikar)) ? ', '. $this->wpdb->term_taxonomy . ' AS wtt, '. $this->wpdb->term_relationships .' AS wtr ' : '';
			$SQL .="WHERE "; 
			$SQL .="wpo.postID = wp.ID AND wp.ID = wpm.post_id AND wpm.meta_key='sayfa_sayac_bilgi' AND wp.post_status='publish' ";
			$SQL .=(in_array($type,array('enazbugun','encokbugun'))) ? "AND date_format(wpo.son_okuma,'%d-%m-%Y') = '". date('d-m-Y', $timestamp) ."' " : '';
			if(!empty($kategori)) {
				$SQL .= "AND wtt.term_id = '$kategori' AND wtt.term_taxonomy_id = wtr.term_taxonomy_id AND wtr.object_id = wp.ID ";
			} else if(!empty($kategoricikar)) {
				$SQL .= "AND wtt.term_id NOT IN($kategoricikar) AND wtt.term_taxonomy_id = wtr.term_taxonomy_id AND wtr.object_id = wp.ID  AND wtt.taxonomy='category' ";
			}
			
			$SQL .=(!empty($yazicikar)) ? "AND wp.ID NOT IN ($yazicikar) " : '';
			$SQL .='GROUP BY wp.ID '; 
			$SQL .="ORDER BY "; 
			if ($type == 'enson') {
				$SQL .= (in_array($type,array('enson'))) ? 'wpo.son_okuma ' : '';
				$SQL .= (in_array($type,array('enson'))) ? 'DESC ' : '';
			} else {
				$SQL .= (in_array($type,array('encoktoplamda','enaztoplamda'))) ? 'wpo.sayac_toplam ' : 'wpo.sayac_bugun ';
				$SQL .= (in_array($type,array('encoktoplamda','encokbugun'))) ? 'DESC ' : 'ASC ';
			}
			$SQL .="LIMIT ". $adet;
			
			$sonuclar = $this->wpdb->get_results($SQL);

			if($sonuclar) {
				
				$i=0;
				foreach ($sonuclar as $post) {
					$yazi_baslik = get_the_title($post->ID);
					$yazi_baslik = ($kelimekes > 0) ? $this->sayfa_sayac_baslik_kes($yazi_baslik,$kelimekes) : $yazi_baslik;
					if($cikti=='widget') {
						$satir_degiskenler = array('%sayac_toplam%'=>number_format_i18n($post->sayac_toplam), '%sayac_bugun%'=>number_format_i18n($post->sayac_bugun), '%yazi_baslik%'=>$yazi_baslik, '%yazi_url%'=>get_permalink($post->ID));
						$sonuc_listesi .= strtr(stripslashes($this->_ayarlar['encok_toplam_sablon']), $satir_degiskenler);	
					} else {
						$sonuc_listesi[$i]['sayac_toplam'] = number_format_i18n($post->sayac_toplam);
						$sonuc_listesi[$i]['sayac_bugun'] = number_format_i18n($post->sayac_bugun);
						$sonuc_listesi[$i]['yazi_baslik'] = $yazi_baslik;
						$sonuc_listesi[$i]['yazi_url'] = get_permalink($post->ID);
					}
					$i++;
				}
			} else {
				if($cikti=='widget') {
					$sonuc_listesi = '<li>'.__('No result', 'sayfa_sayac').'</li>';	
				}
			}
			
			$data[$_onbellek_id]=$sonuc_listesi;
			
			$this->_cache_set('sayfa_sayac_widget_data', $data);
		}
		
		if ($echo == true) {
			echo $sonuc_listesi;
		} else {
			return $sonuc_listesi;	
		}
			
		
	}
}




if(defined('ABSPATH') && defined('WPINC')) {
	global $wpdb, $post;
	
	$sayfa_sayac = new sayfa_sayac($wpdb);
	
	class WP_Widget_Sayfa_Sayac extends WP_Widget {
		 function WP_Widget_Sayfa_Sayac() {
			$widget_ops = array('description' => __('Sayfa sayaç statistics widgets', 'sayfa_sayac'));
			$this->WP_Widget('sayfa_sayac', __('Sayfa sayaç', 'sayfa_sayac'), $widget_ops);
		 }
	
		 function widget($args, $instance) {
			global $sayfa_sayac;
			extract($args);
			$title = apply_filters('widget_title', esc_attr($instance['title']));
			echo $before_widget.$before_title.$title.$after_title;
			echo '<ul>'."\n";
			$sayfa_sayac->sayfa_sayac_widget($instance);
			echo '</ul>'."\n";
			echo $after_widget;
		 }
	
		 function update($new_instance, $old_instance) {
			if (!isset($new_instance['submit'])) {
				return false;
			}
			$instance = $old_instance;
			$instance['title'] = strip_tags($new_instance['title']);
			$instance['type'] = strip_tags($new_instance['type']);
			$instance['adet'] = intval($new_instance['adet']);
			$instance['kelimekes'] = intval($new_instance['kelimekes']);
			$instance['kategori'] = intval($new_instance['kategori']);
			$instance['kategoricikar'] = strip_tags($new_instance['kategoricikar']);
			$instance['yazicikar'] = strip_tags($new_instance['yazicikar']);
			return $instance;
		 }
	
		 function form($instance) {
			 global $wpdb;
			 $instance = wp_parse_args((array) $instance, array('title' => __('Least Viewed Posts', 'sayfa_sayac'), 'type' => 'enaztoplamda', 'adet' => 10, 'limit' => 10, 'kelimekes' => 100, 'kategori' => '', 'kategoricikar'=>'', 'yazicikar'=>''));
			 $title = esc_attr($instance['title']);
			 $type = esc_attr($instance['type']);
			 $adet = intval($instance['adet']);
			 $kelimekes = intval($instance['kelimekes']);
			 $kategori = intval($instance['kategori']);
			 $kategoricikar = esc_attr($instance['kategoricikar']);
			 $yazicikar = esc_attr($instance['yazicikar']);
			 
		?>
		<p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title', 'sayfa_sayac'); ?> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" /></label></p>
		<p>
			<label for="<?php echo $this->get_field_id('type'); ?>"><?php _e('Widget Type:', 'sayfa_sayac'); ?>
				<select name="<?php echo $this->get_field_name('type'); ?>" id="<?php echo $this->get_field_id('type'); ?>" class="widefat">
					<option value="enaztoplamda"<?php selected('enaztoplamda', $type); ?>><?php _e('Least Viewed for Total', 'sayfa_sayac'); ?></option>
					<option value="encoktoplamda"<?php selected('encoktoplamda', $type); ?>><?php _e('Most Viewed for Total', 'sayfa_sayac'); ?></option>
					<option value="enazbugun"<?php selected('enazbugun', $type); ?>><?php _e('Least Viewed for Today', 'sayfa_sayac'); ?></option>
					<option value="encokbugun"<?php selected('encokbugun', $type); ?>><?php _e('Most Viewed for Today', 'sayfa_sayac'); ?></option>
					<option value="enson"<?php selected('enson', $type); ?>><?php _e('Last Viewed Posts', 'sayfa_sayac'); ?></option> 
				</select>
			</label>
		</p>
		<p><label for="<?php echo $this->get_field_id('adet'); ?>"><?php _e('Max. Record:', 'sayfa_sayac'); ?> <input class="widefat" id="<?php echo $this->get_field_id('adet'); ?>" name="<?php echo $this->get_field_name('adet'); ?>" type="text" value="<?php echo attribute_escape($adet); ?>" /></label></p>    
		<p><label for="<?php echo $this->get_field_id('kelimekes'); ?>"><?php _e('Maximum Post Title Length:', 'sayfa_sayac'); ?> <input class="widefat" id="<?php echo $this->get_field_id('kelimekes'); ?>" name="<?php echo $this->get_field_name('kelimekes'); ?>" type="text" value="<?php echo attribute_escape($kelimekes); ?>" /></label></p>
		 <p><label for="<?php echo $this->get_field_id('kategori'); ?>"><?php _e('Category ID:', 'sayfa_sayac'); ?> <input class="widefat" id="<?php echo $this->get_field_id('kategori'); ?>" name="<?php echo $this->get_field_name('kategori'); ?>" type="text" value="<?php echo attribute_escape($kategori); ?>" /></label><br />
			<small><?php _e('Enter category ID for list post viewed statistic by category. Else leave empty', 'sayfa_sayac'); ?></small>
		</p>       
	
		 <p><label for="<?php echo $this->get_field_id('kategoricikar'); ?>"><?php _e('Exclude Categories IDs:', 'sayfa_sayac'); ?> <input class="widefat" id="<?php echo $this->get_field_id('kategoricikar'); ?>" name="<?php echo $this->get_field_name('kategoricikar'); ?>" type="text" value="<?php echo attribute_escape($kategoricikar); ?>" /></label><br />
			<small><?php _e('If you want to exclude all of the posts under a category, you can enter category ID above box. More than one category, please separet them by comma', 'sayfa_sayac'); ?></small>
		</p>  
		
		 <p><label for="<?php echo $this->get_field_id('yazicikar'); ?>"><?php _e('Exclude Posts IDs:', 'sayfa_sayac'); ?> <input class="widefat" id="<?php echo $this->get_field_id('yazicikar'); ?>" name="<?php echo $this->get_field_name('yazicikar'); ?>" type="text" value="<?php echo attribute_escape($yazicikar); ?>" /></label><br />
			<small><?php _e('If you want to exclude some of the posts, you can enter post ID above box. More than one post, please separet them by comma', 'sayfa_sayac'); ?></small>
		</p>                
		<input type="hidden" id="<?php echo $this->get_field_id('submit'); ?>" name="<?php echo $this->get_field_name('submit'); ?>" value="1" />
		<?php
		 }
	}
	
	
	
} else if (isset($_GET['mode']) && !empty($_GET['mode'])) {
	
	
	if(method_exists('sayfa_sayac', 'sayac_mode_'.$_GET['mode'])) {
		define('SAYAC_MODE', true);
		include('../../../wp-config.php');
		global $wpdb;
		$sayfa_sayac = new sayfa_sayac($wpdb);
		call_user_func(array($sayfa_sayac, 'sayac_mode_'.$_GET['mode']),$_GET['p']);
	}
	
}
?>