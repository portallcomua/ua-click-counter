<?php
/**
 * Plugin Name: UA Click Counter
 * Description: Кнопки завантаження з лічильником кліків, збором email та розсилкою per-button.
 * Version: 2.0.0
 * Author: UAServer
 */
if (!defined('ABSPATH')) exit;

class UACC {
    const CPT = 'uacc_button';

    function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_shortcode('ua_click_counter', [$this, 'shortcode']);
        add_action('wp_ajax_uacc_go', [$this, 'go']);
        add_action('wp_ajax_nopriv_uacc_go', [$this, 'go']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_uacc_save', [$this, 'save_button']);
        add_action('admin_post_uacc_delete', [$this, 'delete_button']);
        add_action('admin_post_uacc_send', [$this, 'send_newsletter']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        register_activation_hook(__FILE__, [$this, 'install']);
    }

    /* ---------- Установка ---------- */
    function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'uacc_emails';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            button_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY btn_email (button_id, email),
            KEY button_id (button_id)
        ) $charset;");
        $counters = $wpdb->prefix . 'uacc_counters';
        dbDelta("CREATE TABLE $counters (
            button_id BIGINT UNSIGNED PRIMARY KEY,
            clicks BIGINT UNSIGNED NOT NULL DEFAULT 0
        ) $charset;");
    }

    /* ---------- CPT ---------- */
    function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => ['name' => 'Кнопки', 'singular_name' => 'Кнопка'],
            'public' => false,
            'show_ui' => false,
            'supports' => ['title'],
        ]);
    }

    /* ---------- Допоміжні ---------- */
    static function get_counter($id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT clicks FROM {$wpdb->prefix}uacc_counters WHERE button_id=%d", $id
        ));
    }
    static function increment_counter($id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}uacc_counters (button_id, clicks) VALUES (%d,1)
             ON DUPLICATE KEY UPDATE clicks = clicks + 1", $id
        ));
    }

    /* ---------- Shortcode ---------- */
    function shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts, 'ua_click_counter');
        $ids = $atts['id'] ? [(int) $atts['id']] : get_posts([
            'post_type' => self::CPT, 'numberposts' => -1,
            'post_status' => 'publish', 'fields' => 'ids',
        ]);
        if (!$ids) return '';
        wp_enqueue_script('uacc-front', '', [], '2.0', true);
        wp_add_inline_script('uacc-front', $this->front_js());

        ob_start();
        echo '<style>.uacc-wrap{display:flex;flex-wrap:wrap;gap:12px;margin:18px 0}.uacc-btn{display:inline-block;padding:13px 22px;border-radius:7px;text-decoration:none!important;font-weight:bold;text-align:center;cursor:pointer;border:0}.uacc-modal{display:none;position:fixed;inset:0;z-index:999999;background:#0009;align-items:center;justify-content:center;padding:16px}.uacc-modal.open{display:flex}.uacc-box{background:#fff;color:#222;padding:24px;border-radius:10px;width:100%;max-width:420px;position:relative}.uacc-box h3{margin:0 0 14px}.uacc-box input[type=email]{box-sizing:border-box;width:100%;padding:12px;margin-bottom:12px;border:1px solid #ccc;border-radius:6px}.uacc-submit{width:100%;padding:12px;background:#1769aa;color:white;border:0;border-radius:6px;font-weight:bold;cursor:pointer}.uacc-close{position:absolute;top:8px;right:12px;border:0;background:none;font-size:24px;cursor:pointer}.uacc-error{color:#b00020;margin-top:8px;font-size:14px}@media(max-width:520px){.uacc-wrap{flex-direction:column}.uacc-btn{box-sizing:border-box;width:100%}}</style>';
        echo '<div class="uacc-wrap" id="uacc-wrap">';
        foreach ($ids as $id) {
            $text = get_post_meta($id, '_uacc_text', true) ?: get_the_title($id);
            $url  = get_post_meta($id, '_uacc_url', true);
            $bg   = get_post_meta($id, '_uacc_bg', true) ?: '#1769aa';
            $fg   = get_post_meta($id, '_uacc_fg', true) ?: '#ffffff';
            $cnt  = self::get_counter($id);
            printf(
                '<a class="uacc-btn" href="%s" data-id="%d" data-url="%s" style="background:%s;color:%s">%s (<span data-count="%d">%d</span>)</a>',
                esc_url($url), $id, esc_url($url), esc_attr($bg), esc_attr($fg),
                esc_html($text), $id, $cnt
            );
        }
        echo '</div>';
        // модальне вікно
        echo '<div class="uacc-modal" id="uacc-modal"><div class="uacc-box"><button class="uacc-close" type="button" aria-label="Закрити">×</button><h3>Введіть email, щоб завантажити</h3><form class="uacc-form"><input type="email" name="email" placeholder="Ваш email" required autocomplete="email"><label style="display:flex;gap:8px;font-size:13px;line-height:1.4;margin-bottom:14px"><input type="checkbox" name="consent" value="1" required><span>Погоджуюся отримувати повідомлення про оновлення та новини на цей email. Згоду можна відкликати.</span></label><button class="uacc-submit" type="submit">Продовжити</button><div class="uacc-error"></div></form></div></div>';
        return ob_get_clean();
    }

    function front_js() {
        $ajax  = esc_url(admin_url('admin-ajax.php'));
        $nonce = wp_create_nonce('uacc');
        return <<<JS
(function(){
  var modal=document.getElementById('uacc-modal'),form=modal.querySelector('form'),err=modal.querySelector('.uacc-error'),picked=null;
  var ajax='$ajax',nonce='$nonce';
  function close(){modal.classList.remove('open');}
  document.querySelectorAll('.uacc-btn').forEach(function(a){
    a.addEventListener('click',function(e){
      e.preventDefault();
      picked={id:a.dataset.id,url:a.dataset.url,el:a};
      form.reset();err.textContent='';modal.classList.add('open');
      form.email.focus();
    });
  });
  modal.querySelector('.uacc-close').onclick=close;
  modal.addEventListener('click',function(e){if(e.target===modal)close();});
  form.addEventListener('submit',function(e){
    e.preventDefault();
    var b=form.querySelector('button[type=submit]');b.disabled=true;err.textContent='';
    var d=new URLSearchParams({action:'uacc_go',nonce:nonce,email:form.email.value,consent:form.consent.checked?'1':'0',id:picked.id});
    fetch(ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:d})
      .then(function(r){return r.json();})
      .then(function(j){
        if(!j.success)throw new Error((j.data&&j.data.message)||'Помилка');
        var span=picked.el.querySelector('[data-count]');
        if(span)span.textContent=j.data.clicks;
        window.location.href=picked.url;
      })
      .catch(function(x){err.textContent=x.message;})
      .finally(function(){b.disabled=false;});
  });
})();
JS;
    }

    /* ---------- AJAX ---------- */
    function go() {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'uacc')) {
            wp_send_json_error(['message' => 'Оновіть сторінку.'], 403);
        }
        $id     = absint($_POST['id'] ?? 0);
        $email  = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $consent = ($_POST['consent'] ?? '') === '1';
        if (!is_email($email)) wp_send_json_error(['message' => 'Перевірте email.'], 400);
        if (!$id || get_post_type($id) !== self::CPT) wp_send_json_error(['message' => 'Кнопку не знайдено.'], 404);
        if (!$consent) wp_send_json_error(['message' => 'Потрібна згода на розсилку.'], 400);

        // rate limit
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $rk = 'uacc_rate_' . md5($ip);
        $n  = (int) get_transient($rk);
        if ($n >= 30) wp_send_json_error(['message' => 'Забагато спроб. Спробуйте пізніше.'], 429);
        set_transient($rk, $n + 1, HOUR_IN_SECONDS);

        global $wpdb;
        self::increment_counter($id);
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}uacc_emails (button_id,email,created_at) VALUES (%d,%s,%s)",
            $id, $email, current_time('mysql')
        ));
        $url = get_post_meta($id, '_uacc_url', true);
        wp_send_json_success(['clicks' => self::get_counter($id), 'url' => $url]);
    }

    /* ---------- Admin menu ---------- */
    function menu() {
        add_menu_page('Кнопки кліків', 'Кнопки кліків', 'manage_options', 'uacc', [$this, 'admin_list'], 'dashicons-download', 58);
        add_submenu_page('uacc', 'Створити кнопку', 'Створити кнопку', 'manage_options', 'uacc_new', [$this, 'admin_form']);
        add_submenu_page(null, 'Редагувати кнопку', 'Редагувати кнопку', 'manage_options', 'uacc_edit', [$this, 'admin_form']);
        add_submenu_page(null, 'Розсилка', 'Розсилка', 'manage_options', 'uacc_newsletter', [$this, 'admin_newsletter']);
    }

    function admin_assets($hook) {
        if (strpos($hook, 'uacc') === false) return;
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }

    /* ---------- Список кнопок ---------- */
    function admin_list() {
        if (!current_user_can('manage_options')) return;
        $buttons = get_posts(['post_type' => self::CPT, 'numberposts' => -1, 'post_status' => 'any']);
        $notice = isset($_GET['uacc_msg']) ? sanitize_text_field(wp_unslash($_GET['uacc_msg'])) : '';
        ?>
        <div class="wrap">
            <h1>Кнопки кліків
                <a href="<?php echo esc_url(admin_url('admin.php?page=uacc_new')); ?>" class="page-title-action">Створити кнопку</a>
            </h1>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <table class="widefat striped" style="max-width:1000px">
                <thead><tr>
                    <th>Назва</th><th>Текст на кнопці</th><th>URL</th>
                    <th>Кліки</th><th>Email</th><th>Shortcode</th><th>Дії</th>
                </tr></thead>
                <tbody>
                <?php if (!$buttons): ?>
                    <tr><td colspan="7">Ще немає кнопок. Натисніть «Створити кнопку».</td></tr>
                <?php endif; ?>
                <?php global $wpdb; foreach ($buttons as $b):
                    $clicks = self::get_counter($b->ID);
                    $emails = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}uacc_emails WHERE button_id=%d", $b->ID));
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($b->post_title); ?></strong></td>
                        <td><?php echo esc_html(get_post_meta($b->ID, '_uacc_text', true)); ?></td>
                        <td style="word-break:break-all"><?php echo esc_html(get_post_meta($b->ID, '_uacc_url', true)); ?></td>
                        <td><?php echo absint($clicks); ?></td>
                        <td><?php echo absint($emails); ?></td>
                        <td><code>[ua_click_counter id="<?php echo absint($b->ID); ?>"]</code></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=uacc_edit&id=' . $b->ID)); ?>">Редагувати</a> |
                            <a href="<?php echo esc_url(admin_url('admin.php?page=uacc_newsletter&id=' . $b->ID)); ?>">Розсилка</a> |
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=uacc_delete&id=' . $b->ID), 'uacc_delete_' . $b->ID)); ?>"
                               onclick="return confirm('Видалити кнопку та всі її email?');" style="color:#b00020">Видалити</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ---------- Форма створення / редагування ---------- */
    function admin_form() {
        if (!current_user_can('manage_options')) return;
        $id = absint($_GET['id'] ?? 0);
        $post = $id ? get_post($id) : null;
        if ($post && $post->post_type !== self::CPT) $post = null;
        $title = $post ? $post->post_title : '';
        $text  = $post ? get_post_meta($post->ID, '_uacc_text', true) : '';
        $url   = $post ? get_post_meta($post->ID, '_uacc_url', true) : '';
        $bg    = $post ? (get_post_meta($post->ID, '_uacc_bg', true) ?: '#1769aa') : '#1769aa';
        $fg    = $post ? (get_post_meta($post->ID, '_uacc_fg', true) ?: '#ffffff') : '#ffffff';
        ?>
        <div class="wrap">
            <h1><?php echo $post ? 'Редагувати кнопку' : 'Створити кнопку'; ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:640px">
                <input type="hidden" name="action" value="uacc_save">
                <input type="hidden" name="id" value="<?php echo absint($id); ?>">
                <?php wp_nonce_field('uacc_save'); ?>
                <table class="form-table">
                    <tr><th><label for="uacc_title">Назва для адмінки</label></th>
                        <td><input class="regular-text" id="uacc_title" name="title" value="<?php echo esc_attr($title); ?>" required></td></tr>
                    <tr><th><label for="uacc_text">Текст на кнопці</label></th>
                        <td><input class="regular-text" id="uacc_text" name="text" value="<?php echo esc_attr($text); ?>" required></td></tr>
                    <tr><th><label for="uacc_url">Посилання для скачування</label></th>
                        <td><input class="large-text" id="uacc_url" name="url" type="url" value="<?php echo esc_attr($url); ?>" required></td></tr>
                    <tr><th><label for="uacc_bg">Колір фону</label></th>
                        <td><input id="uacc_bg" name="bg" type="text" class="uacc-color" value="<?php echo esc_attr($bg); ?>" data-default-color="#1769aa"></td></tr>
                    <tr><th><label for="uacc_fg">Колір тексту</label></th>
                        <td><input id="uacc_fg" name="fg" type="text" class="uacc-color" value="<?php echo esc_attr($fg); ?>" data-default-color="#ffffff"></td></tr>
                </table>
                <?php submit_button($post ? 'Зберегти' : 'Створити'); ?>
            </form>
            <script>jQuery(function($){$('.uacc-color').wpColorPicker();});</script>
        </div>
        <?php
    }

    function save_button() {
        if (!current_user_can('manage_options')) wp_die('Немає прав');
        check_admin_referer('uacc_save');
        $id    = absint($_POST['id'] ?? 0);
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $text  = sanitize_text_field(wp_unslash($_POST['text'] ?? ''));
        $url   = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        $bg    = sanitize_hex_color(wp_unslash($_POST['bg'] ?? '')) ?: '#1769aa';
        $fg    = sanitize_hex_color(wp_unslash($_POST['fg'] ?? '')) ?: '#ffffff';
        if ($title === '' || $text === '' || $url === '') {
            wp_die('Заповніть усі обов’язкові поля. <a href="javascript:history.back()">Назад</a>');
        }
        $args = ['post_title' => $title, 'post_type' => self::CPT, 'post_status' => 'publish'];
        if ($id) { $args['ID'] = $id; $new_id = wp_update_post($args); }
        else     { $new_id = wp_insert_post($args); }
        if (is_wp_error($new_id) || !$new_id) wp_die('Не вдалося зберегти.');
        update_post_meta($new_id, '_uacc_text', $text);
        update_post_meta($new_id, '_uacc_url', $url);
        update_post_meta($new_id, '_uacc_bg', $bg);
        update_post_meta($new_id, '_uacc_fg', $fg);
        wp_safe_redirect(admin_url('admin.php?page=uacc&uacc_msg=' . rawurlencode($id ? 'Кнопку оновлено.' : 'Кнопку створено.')));
        exit;
    }

    function delete_button() {
        if (!current_user_can('manage_options')) wp_die('Немає прав');
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('uacc_delete_' . $id);
        if (!$id || get_post_type($id) !== self::CPT) wp_die('Кнопку не знайдено.');
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'uacc_emails', ['button_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'uacc_counters', ['button_id' => $id]);
        wp_delete_post($id, true);
        wp_safe_redirect(admin_url('admin.php?page=uacc&uacc_msg=' . rawurlencode('Кнопку видалено.')));
        exit;
    }

    /* ---------- Сторінка розсилки ---------- */
    function admin_newsletter() {
        if (!current_user_can('manage_options')) return;
        $id = absint($_GET['id'] ?? 0);
        $post = $id ? get_post($id) : null;
        if (!$post || $post->post_type !== self::CPT) { echo '<div class="wrap"><h1>Кнопку не знайдено</h1></div>'; return; }
        global $wpdb;
        $emails = $wpdb->get_col($wpdb->prepare(
            "SELECT email FROM {$wpdb->prefix}uacc_emails WHERE button_id=%d ORDER BY created_at DESC", $id
        ));
        $sent = isset($_GET['sent']) ? absint($_GET['sent']) : null;
        ?>
        <div class="wrap">
            <h1>Розсилка для: <?php echo esc_html($post->post_title); ?></h1>
            <p>Отримувачів: <strong><?php echo count($emails); ?></strong></p>
            <?php if ($sent !== null): ?>
                <div class="notice notice-success is-dismissible"><p>Листів надіслано: <?php echo $sent; ?></p></div>
            <?php endif; ?>

            <?php if (!$emails): ?>
                <p><em>Ще немає жодного email для цієї кнопки.</em></p>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:700px"
                      onsubmit="return confirm('Надіслати лист на <?php echo (int) count($emails); ?> адрес(и)?');">
                    <input type="hidden" name="action" value="uacc_send">
                    <input type="hidden" name="id" value="<?php echo absint($id); ?>">
                    <?php wp_nonce_field('uacc_send'); ?>
                    <table class="form-table">
                        <tr><th><label for="uacc_subject">Тема</label></th>
                            <td><input class="large-text" id="uacc_subject" name="subject" required></td></tr>
                        <tr><th><label for="uacc_message">Текст повідомлення</label></th>
                            <td><?php wp_editor('', 'uacc_message', ['textarea_name' => 'message', 'textarea_rows' => 10, 'media_buttons' => false]); ?></td></tr>
                    </table>
                    <?php submit_button('Розіслати'); ?>
                </form>

                <h2>Адреси</h2>
                <table class="widefat striped" style="max-width:600px">
                    <thead><tr><th>Email</th><th>Дата</th></tr></thead>
                    <tbody>
                    <?php foreach ($wpdb->get_results($wpdb->prepare(
                        "SELECT email, created_at FROM {$wpdb->prefix}uacc_emails WHERE button_id=%d ORDER BY created_at DESC", $id
                    )) as $row): ?>
                        <tr><td><?php echo esc_html($row->email); ?></td><td><?php echo esc_html($row->created_at); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    function send_newsletter() {
        if (!current_user_can('manage_options')) wp_die('Немає прав');
        check_admin_referer('uacc_send');
        $id  = absint($_POST['id'] ?? 0);
        $sub = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $msg = wp_kses_post(wp_unslash($_POST['message'] ?? ''));
        if (!$id || get_post_type($id) !== self::CPT) wp_die('Кнопку не знайдено.');
        if ($sub === '' || trim(wp_strip_all_tags($msg)) === '') wp_die('Заповніть тему і текст.');

        global $wpdb;
        $emails = $wpdb->get_col($wpdb->prepare(
            "SELECT email FROM {$wpdb->prefix}uacc_emails WHERE button_id=%d", $id
        ));
        $sent = 0;
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        foreach ($emails as $email) {
            if (!is_email($email)) continue;
            $body = wpautop($msg) . '<hr><p style="font-size:12px;color:#777">Ви отримали цей лист, бо завантажували файл і погодилися на розсилку. Щоб відписатися — дайте відповідь на цей лист.</p>';
            if (wp_mail($email, $sub, $body, $headers)) $sent++;
        }
        wp_safe_redirect(admin_url('admin.php?page=uacc_newsletter&id=' . $id . '&sent=' . $sent));
        exit;
    }
}
new UACC();