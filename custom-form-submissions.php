<?php
/**
 * Plugin Name: HD Custom Forms
 * Description: Handles custom HTML form submissions with admin dashboard
 * Version: 3.0.0
 * Author: Henrich Digital
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CFS_DB_VERSION', '3.0.0');

require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$hdFormsUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/khdigital94/HD-wp-forms-plugin/',
    __FILE__,
    'hd-custom-forms'
);

class Custom_Form_Submissions {

    private static $instance = null;
    private $table_name;
    private $rate_limit_table;
    private $forms_table;
    private $uploads_dir;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'custom_form_submissions';
        $this->rate_limit_table = $wpdb->prefix . 'custom_form_rate_limit';
        $this->forms_table = $wpdb->prefix . 'custom_forms';

        $upload_dir = wp_upload_dir();
        $this->uploads_dir = $upload_dir['basedir'] . '/form-submissions';

        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('plugins_loaded', [$this, 'check_db_version']);
        add_action('init', [$this, 'maybe_create_uploads_dir']);

        add_action('wp_ajax_custom_form_submit', [$this, 'handle_submission']);
        add_action('wp_ajax_nopriv_custom_form_submit', [$this, 'handle_submission']);

        add_action('wp_ajax_custom_form_upload', [$this, 'handle_file_upload']);
        add_action('wp_ajax_nopriv_custom_form_upload', [$this, 'handle_file_upload']);

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
        add_action('admin_init', [$this, 'register_settings']);

        add_shortcode('custom_form', [$this, 'render_form_shortcode']);

        add_action('cfs_cleanup_rate_limit', [$this, 'cleanup_rate_limit']);
        if (!wp_next_scheduled('cfs_cleanup_rate_limit')) {
            wp_schedule_event(time(), 'hourly', 'cfs_cleanup_rate_limit');
        }
    }

    public function activate() {
        $this->install_db();
        update_option('cfs_db_version', CFS_DB_VERSION);
    }

    public function check_db_version() {
        $installed_version = get_option('cfs_db_version', '0');
        if (version_compare($installed_version, CFS_DB_VERSION, '<')) {
            $this->upgrade_db($installed_version);
            update_option('cfs_db_version', CFS_DB_VERSION);
        }
    }

    public function upgrade_db($from_version) {
        global $wpdb;

        if (version_compare($from_version, '2.2.0', '<')) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'files'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN files longtext DEFAULT NULL AFTER form_data");
            }
        }

        if (version_compare($from_version, '3.0.0', '<')) {
            $this->install_db();
        }
    }

    public function maybe_create_uploads_dir() {
        if (!file_exists($this->uploads_dir)) {
            wp_mkdir_p($this->uploads_dir);
            file_put_contents($this->uploads_dir . '/.htaccess', 'Options -Indexes');
            file_put_contents($this->uploads_dir . '/index.php', '<?php // Silence is golden');
        }
    }

    public function install_db() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE {$this->table_name} (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  form_id varchar(100) NOT NULL,
  form_name varchar(255) NOT NULL,
  form_data longtext NOT NULL,
  files longtext NULL,
  user_ip varchar(45) NULL,
  user_agent text NULL,
  referer varchar(500) NULL,
  created_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'unread',
  PRIMARY KEY  (id),
  KEY form_id (form_id),
  KEY status (status),
  KEY created_at (created_at)
) $charset_collate;";

        $sql2 = "CREATE TABLE {$this->rate_limit_table} (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  ip_address varchar(45) NOT NULL,
  submission_count int NOT NULL DEFAULT 1,
  first_attempt datetime NOT NULL,
  last_attempt datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY ip_address (ip_address),
  KEY last_attempt (last_attempt)
) $charset_collate;";

        $sql3 = "CREATE TABLE {$this->forms_table} (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL,
  form_code longtext NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
    }

    public function register_settings() {
        register_setting('cfs_email_settings', 'cfs_email_enabled');
        register_setting('cfs_email_settings', 'cfs_email_recipients');
        register_setting('cfs_email_settings', 'cfs_email_cc');
        register_setting('cfs_email_settings', 'cfs_email_from_name');
        register_setting('cfs_email_settings', 'cfs_email_from_email');
        register_setting('cfs_email_settings', 'cfs_email_subject');
    }

    private function check_rate_limit($ip) {
        global $wpdb;

        $one_hour_ago = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->rate_limit_table} WHERE ip_address = %s AND last_attempt > %s",
            $ip, $one_hour_ago
        ));

        if ($record) {
            if ($record->submission_count >= 3) {
                return false;
            }

            $wpdb->update(
                $this->rate_limit_table,
                [
                    'submission_count' => $record->submission_count + 1,
                    'last_attempt' => current_time('mysql')
                ],
                ['id' => $record->id]
            );
        } else {
            $wpdb->delete($this->rate_limit_table, ['ip_address' => $ip]);

            $wpdb->insert($this->rate_limit_table, [
                'ip_address' => $ip,
                'submission_count' => 1,
                'first_attempt' => current_time('mysql'),
                'last_attempt' => current_time('mysql')
            ]);
        }

        return true;
    }

    public function cleanup_rate_limit() {
        global $wpdb;
        $one_hour_ago = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->rate_limit_table} WHERE last_attempt < %s",
            $one_hour_ago
        ));
    }

    public function handle_file_upload() {
        try {
            $user_ip = $this->get_user_ip();

            if (!$this->check_rate_limit($user_ip)) {
                wp_send_json_error(['message' => 'Zu viele Anfragen. Bitte versuche es in einer Stunde erneut.']);
                return;
            }

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                wp_send_json_error(['message' => 'Datei-Upload fehlgeschlagen']);
                return;
            }

            $file = $_FILES['file'];
            $max_size = 3 * 1024 * 1024;

            if ($file['size'] > $max_size) {
                wp_send_json_error(['message' => 'Datei zu groß (max 3MB)']);
                return;
            }

            $allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed_types)) {
                wp_send_json_error(['message' => 'Dateityp nicht erlaubt']);
                return;
            }

            $filename = wp_unique_filename($this->uploads_dir, sanitize_file_name($file['name']));
            $filepath = $this->uploads_dir . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                wp_send_json_error(['message' => 'Datei konnte nicht gespeichert werden']);
                return;
            }

            wp_send_json_success([
                'filename' => $filename,
                'original_name' => $file['name'],
                'size' => $file['size'],
                'url' => content_url('uploads/form-submissions/' . $filename)
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Upload-Fehler: ' . $e->getMessage()]);
        }
    }

    public function handle_submission() {
        global $wpdb;

        try {
            $user_ip = $this->get_user_ip();

            if (!$this->check_rate_limit($user_ip)) {
                wp_send_json_error(['message' => 'Zu viele Anfragen. Bitte versuche es in einer Stunde erneut.']);
                return;
            }

            if (empty($_POST['formData'])) {
                wp_send_json_error(['message' => 'Keine Formulardaten erhalten']);
                return;
            }

            $raw_data = wp_unslash($_POST['formData']);

            if (strlen($raw_data) > 100 * 1024) {
                wp_send_json_error(['message' => 'Formulardaten zu groß']);
                return;
            }

            $form_data = json_decode($raw_data, true);

            if (!$form_data) {
                wp_send_json_error(['message' => 'Ungültige Formulardaten']);
                return;
            }

            if (isset($form_data['_hp_field']) && !empty($form_data['_hp_field'])) {
                wp_send_json_error(['message' => 'Spam erkannt']);
                return;
            }

            unset($form_data['_hp_field']);

            $form_id = isset($form_data['form_id']) ? sanitize_text_field($form_data['form_id']) : 'unknown';
            $form_name = isset($form_data['form_name']) ? sanitize_text_field($form_data['form_name']) : 'Custom Form';

            $files_data = null;
            if (isset($_POST['files'])) {
                $files_data = wp_json_encode(json_decode(wp_unslash($_POST['files'])));
            }

            $insert_data = [
                'form_id' => $form_id,
                'form_name' => $form_name,
                'form_data' => wp_json_encode($form_data),
                'files' => $files_data,
                'user_ip' => $user_ip,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
                'referer' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '',
                'created_at' => current_time('mysql'),
                'status' => 'unread'
            ];

            $result = $wpdb->insert($this->table_name, $insert_data);

            if ($result === false) {
                throw new Exception($wpdb->last_error);
            }

            $submission_id = $wpdb->insert_id;

            $this->maybe_send_email($form_data, $form_name, $submission_id, $files_data);

            wp_send_json_success([
                'message' => 'Formular erfolgreich gesendet',
                'submission_id' => $submission_id
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => 'Fehler beim Verarbeiten: ' . $e->getMessage()
            ]);
        }
    }

    private function maybe_send_email($form_data, $form_name, $submission_id, $files_data = null) {
        if (!get_option('cfs_email_enabled', false)) {
            return;
        }

        $recipients = get_option('cfs_email_recipients', '');
        if (empty($recipients)) {
            return;
        }

        $recipients = array_map('trim', explode(',', $recipients));
        $cc = get_option('cfs_email_cc', '');
        $from_name = get_option('cfs_email_from_name', get_bloginfo('name'));
        $from_email = get_option('cfs_email_from_email', get_option('admin_email'));
        $subject_template = get_option('cfs_email_subject', 'Neue Anfrage: {form_name}');

        $subject = str_replace('{form_name}', $form_name, $subject_template);

        $message = "Neue Anfrage über: {$form_name}\n\n";
        $message .= "Submission ID: #{$submission_id}\n";
        $message .= "Datum: " . date_i18n('d.m.Y H:i') . "\n\n";

        foreach ($form_data as $key => $value) {
            if (in_array($key, ['form_id', 'form_name', 'post_id'])) {
                continue;
            }
            $label = ucfirst(str_replace('_', ' ', $key));

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $message .= "{$label}: {$value}\n";
        }

        if ($files_data) {
            $files = json_decode($files_data, true);
            if (!empty($files)) {
                $message .= "\n--- Hochgeladene Dateien ---\n";
                foreach ($files as $file) {
                    $message .= "- {$file['original_name']} ({$file['url']})\n";
                }
            }
        }

        $headers = [];
        $headers[] = "From: {$from_name} <{$from_email}>";
        $headers[] = "Reply-To: {$from_email}";

        if (!empty($cc)) {
            $cc_emails = array_map('trim', explode(',', $cc));
            foreach ($cc_emails as $cc_email) {
                if (is_email($cc_email)) {
                    $headers[] = "Cc: {$cc_email}";
                }
            }
        }

        wp_mail($recipients, $subject, $message, $headers);
    }

    private function get_user_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public function add_admin_menu() {
        add_menu_page(
            'Anfragen',
            'Anfragen',
            'manage_options',
            'custom-form-submissions',
            [$this, 'render_admin_page'],
            'dashicons-email-alt',
            30
        );

        add_submenu_page(
            'custom-form-submissions',
            'Anfragen',
            'Anfragen',
            'manage_options',
            'custom-form-submissions',
            [$this, 'render_admin_page']
        );

        add_submenu_page(
            'custom-form-submissions',
            'Formulare',
            'Formulare',
            'manage_options',
            'custom-forms',
            [$this, 'render_forms_page']
        );

        add_submenu_page(
            'custom-form-submissions',
            'E-Mail Einstellungen',
            'E-Mail Einstellungen',
            'manage_options',
            'custom-form-email-settings',
            [$this, 'render_email_settings_page']
        );
    }

    public function enqueue_admin_styles($hook) {
        if (strpos($hook, 'custom-form') === false) {
            return;
        }

        wp_add_inline_style('wp-admin', $this->get_admin_css());
    }

    private function get_admin_css() {
        return "
            .cfs-container { max-width: 1400px; margin: 20px 0; }
            .cfs-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
            .cfs-search { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; width: 300px; }
            .cfs-stats { display: flex; gap: 20px; margin-bottom: 20px; }
            .cfs-stat-box { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ddd; flex: 1; }
            .cfs-stat-number { font-size: 32px; font-weight: bold; color: #2271b1; margin: 0; }
            .cfs-stat-label { color: #666; margin: 5px 0 0 0; }
            .cfs-table-wrapper { background: #fff; border-radius: 8px; border: 1px solid #ddd; overflow: hidden; }
            .cfs-table { width: 100%; border-collapse: collapse; }
            .cfs-table th { background: #f6f7f7; padding: 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #ddd; }
            .cfs-table td { padding: 12px; border-bottom: 1px solid #f0f0f0; }
            .cfs-table tr:hover { background: #f9f9f9; }
            .cfs-badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 500; }
            .cfs-badge.unread { background: #eef7ff; color: #2271b1; }
            .cfs-badge.read { background: #f0f0f0; color: #666; }
            .cfs-details { background: #f9f9f9; padding: 15px; margin: 10px 0; border-radius: 4px; display: none; }
            .cfs-details.active { display: block; }
            .cfs-field { margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0; }
            .cfs-field:last-child { border-bottom: none; }
            .cfs-field-label { font-weight: 600; color: #333; margin-bottom: 4px; }
            .cfs-field-value { color: #666; }
            .cfs-view-btn { cursor: pointer; color: #2271b1; text-decoration: none; }
            .cfs-view-btn:hover { text-decoration: underline; }
            .cfs-no-data { text-align: center; padding: 40px; color: #666; }
            .cfs-settings-section { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 20px; }
            .cfs-settings-section h2 { margin-top: 0; }
            .cfs-form-table { width: 100%; max-width: 800px; }
            .cfs-form-table th { text-align: left; padding: 15px 10px 15px 0; width: 200px; vertical-align: top; }
            .cfs-form-table td { padding: 10px 0; }
            .cfs-form-table input[type='text'], .cfs-form-table input[type='email'], .cfs-form-table textarea { width: 100%; max-width: 500px; }
            .cfs-form-table textarea { height: 80px; }
            .cfs-help-text { color: #666; font-size: 13px; margin-top: 5px; }
            .notice code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
            .cfs-file-link { display: inline-block; padding: 5px 10px; background: #f0f0f0; border-radius: 4px; margin: 5px 5px 5px 0; text-decoration: none; color: #333; }
            .cfs-file-link:hover { background: #e0e0e0; }
            .cfs-shortcode { background: #f0f0f0; padding: 6px 10px; border-radius: 4px; font-family: monospace; font-size: 13px; color: #d63638; cursor: pointer; }
            .cfs-shortcode:hover { background: #e0e0e0; }
            .cfs-code-editor { width: 100%; height: 500px; font-family: monospace; font-size: 13px; }
        ";
    }

    private function extract_contact_info($data) {
        $contact = [];

        $name_fields = ['name', 'vorname', 'nachname', 'full_name', 'fullname'];
        $email_fields = ['email', 'e_mail', 'e-mail', 'mail'];

        foreach ($name_fields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $contact['name'] = $data[$field];
                break;
            }
        }

        if (!isset($contact['name']) && isset($data['vorname']) && isset($data['nachname'])) {
            $contact['name'] = $data['vorname'] . ' ' . $data['nachname'];
        }

        foreach ($email_fields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $contact['email'] = $data[$field];
                break;
            }
        }

        return $contact;
    }

    public function render_forms_page() {
        global $wpdb;

        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            check_admin_referer('delete-form_' . absint($_GET['id']));
            $id = absint($_GET['id']);
            $wpdb->delete($this->forms_table, ['id' => $id]);
            wp_safe_redirect(admin_url('admin.php?page=custom-forms'));
            exit;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
            $this->render_form_edit_page(absint($_GET['id']));
            return;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'new') {
            $this->render_form_edit_page();
            return;
        }

        $forms = $wpdb->get_results("SELECT * FROM {$this->forms_table} ORDER BY created_at DESC");

        ?>
        <div class="wrap cfs-container">
            <div class="cfs-header">
                <h1>Formulare</h1>
                <a href="?page=custom-forms&action=new" class="button button-primary">Neues Formular</a>
            </div>

            <?php if (isset($_GET['created'])): ?>
                <div class="notice notice-success is-dismissible"><p>Formular erfolgreich erstellt!</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Formular erfolgreich aktualisiert!</p></div>
            <?php endif; ?>

            <div class="cfs-table-wrapper">
                <?php if (empty($forms)): ?>
                    <div class="cfs-no-data">
                        <p>Noch keine Formulare erstellt.</p>
                    </div>
                <?php else: ?>
                    <table class="cfs-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Titel</th>
                                <th>Shortcode</th>
                                <th>Erstellt</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forms as $form): ?>
                                <tr>
                                    <td><strong>#<?php echo esc_html($form->id); ?></strong></td>
                                    <td><strong><?php echo esc_html($form->title); ?></strong></td>
                                    <td>
                                        <code class="cfs-shortcode-copy" data-shortcode="[custom_form id=<?php echo esc_attr($form->id); ?>]" style="cursor: pointer;" title="Zum Kopieren klicken">
                                            [custom_form id=<?php echo esc_attr($form->id); ?>]
                                        </code>
                                    </td>
                                    <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($form->created_at))); ?></td>
                                    <td>
                                        <a href="?page=custom-forms&action=edit&id=<?php echo esc_attr($form->id); ?>">Bearbeiten</a>
                                        | <a href="?page=custom-forms&action=delete&id=<?php echo esc_attr($form->id); ?>" onclick="return confirm('Wirklich löschen?');" style="color: #d63638;">Löschen</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <script>
                    document.querySelectorAll('.cfs-shortcode-copy').forEach(function(el) {
                        el.addEventListener('click', function() {
                            const shortcode = this.getAttribute('data-shortcode');
                            const originalText = this.textContent;
                            navigator.clipboard.writeText(shortcode).then(function() {
                                el.textContent = '✓ Kopiert!';
                                el.style.background = '#00a32a';
                                el.style.color = '#fff';
                                el.style.padding = '4px 8px';
                                el.style.borderRadius = '3px';
                                setTimeout(function() {
                                    el.textContent = originalText;
                                    el.style.background = '';
                                    el.style.color = '';
                                    el.style.padding = '';
                                    el.style.borderRadius = '';
                                }, 2000);
                            });
                        });
                    });
                    </script>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_form_edit_page($form_id = null) {
        global $wpdb;

        if (isset($_POST['cfs_save_form'])) {
            check_admin_referer('cfs_form_edit');

            $title = sanitize_text_field($_POST['form_title']);
            $code = wp_unslash($_POST['form_code']);

            if ($form_id) {
                $wpdb->update(
                    $this->forms_table,
                    [
                        'title' => $title,
                        'form_code' => $code,
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $form_id]
                );
                wp_safe_redirect(admin_url('admin.php?page=custom-forms&updated=1'));
                exit;
            } else {
                $wpdb->insert($this->forms_table, [
                    'title' => $title,
                    'form_code' => $code,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ]);
                wp_safe_redirect(admin_url('admin.php?page=custom-forms&created=1'));
                exit;
            }
        }

        $form = null;
        if ($form_id) {
            $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->forms_table} WHERE id = %d", $form_id));
        }

        $sample_form_content = file_get_contents(plugin_dir_path(__FILE__) . 'sample-form.html');
        ?>
        <div class="wrap cfs-container">
            <h1><?php echo $form ? 'Formular bearbeiten' : 'Neues Formular'; ?></h1>

            <div style="display: flex; gap: 30px; align-items: flex-start;">
                <div style="flex: 1;">
                    <div class="cfs-settings-section">
                        <form method="post" action="">
                            <?php wp_nonce_field('cfs_form_edit'); ?>

                            <table class="cfs-form-table">
                                <tr>
                                    <th><label for="form_title">Formular-Titel <span style="color: red;">*</span></label></th>
                                    <td>
                                        <input type="text" id="form_title" name="form_title" value="<?php echo $form ? esc_attr($form->title) : ''; ?>" required style="width: 100%; max-width: 500px;">
                                        <p class="cfs-help-text">Wird als formName verwendet</p>
                                    </td>
                                </tr>

                                <tr>
                                    <th><label for="form_code">Formular-Code <span style="color: red;">*</span></label></th>
                                    <td>
                                        <textarea id="form_code" name="form_code" class="cfs-code-editor" required><?php echo $form ? esc_textarea($form->form_code) : ''; ?></textarea>
                                        <p class="cfs-help-text">Paste hier deinen HTML/CSS/JS Code (ohne formId, formName, postId im Config)</p>
                                    </td>
                                </tr>
                            </table>

                            <p>
                                <button type="submit" name="cfs_save_form" class="button button-primary">Speichern</button>
                                <a href="?page=custom-forms" class="button">Abbrechen</a>
                            </p>
                        </form>
                    </div>
                </div>

                <div style="width: 380px; flex-shrink: 0;">
                    <div class="cfs-settings-section" style="background: #f8f9fa; border: 1px solid #ddd; padding: 20px;">
                        <h3 style="margin-top: 0; font-size: 16px; color: #333; margin-bottom: 8px;">💡 Individuelles Formular erstellen</h3>
                        <p style="font-size: 13px; color: #666; line-height: 1.5; margin-bottom: 16px;">
                            Kopiere das Template und beschreibe der KI was du brauchst. Hier ein paar Beispiele:
                        </p>

                        <div style="background: #fff; border: 1px solid #e0e0e0; padding: 12px; margin-bottom: 10px; border-radius: 4px;">
                            <strong style="font-size: 12px; color: #333; display: block; margin-bottom: 6px;">📝 Einfaches Kontaktformular</strong>
                            <p style="font-size: 12px; color: #888; line-height: 1.5; margin: 0; font-style: italic;">
                                "Hier ist ein Formular-Template. Bitte passe es an für ein einfaches Kontaktformular mit den Feldern: Vorname, Nachname, E-Mail, Telefon und Nachricht. Keine Multi-Steps, nur ein einzelner Screen."
                            </p>
                        </div>

                        <div style="background: #fff; border: 1px solid #e0e0e0; padding: 12px; margin-bottom: 10px; border-radius: 4px;">
                            <strong style="font-size: 12px; color: #333; display: block; margin-bottom: 6px;">🎯 Multi-Step Bewerbung</strong>
                            <p style="font-size: 12px; color: #888; line-height: 1.5; margin: 0; font-style: italic;">
                                "Hier ist ein Formular-Template. Erstelle ein 3-stufiges Bewerbungsformular: Step 1 Position auswählen, Step 2 Erfahrung, Step 3 Kontaktdaten."
                            </p>
                        </div>

                        <div style="background: #fff; border: 1px solid #e0e0e0; padding: 12px; margin-bottom: 16px; border-radius: 4px;">
                            <strong style="font-size: 12px; color: #333; display: block; margin-bottom: 6px;">📎 Mit Datei-Upload</strong>
                            <p style="font-size: 12px; color: #888; line-height: 1.5; margin: 0; font-style: italic;">
                                "Hier ist ein Formular-Template. Füge ein Upload-Feld für Lebenslauf hinzu (PDF, max 5MB). Nach Upload soll der Dateiname angezeigt werden."
                            </p>
                        </div>

                        <button type="button" id="cfs-copy-template" class="button button-secondary" style="width: 100%; height: 40px; font-weight: 600;">
                            📄 Template kopieren
                        </button>
                        <p style="font-size: 11px; color: #999; margin-top: 10px; margin-bottom: 0; text-align: center;">
                            Funktioniert mit ChatGPT, Claude & Co.
                        </p>
                    </div>
                </div>
            </div>

            <script>
            document.getElementById('cfs-copy-template').addEventListener('click', function() {
                const templateContent = <?php echo json_encode($sample_form_content); ?>;
                navigator.clipboard.writeText(templateContent).then(function() {
                    const btn = document.getElementById('cfs-copy-template');
                    const originalText = btn.textContent;
                    btn.textContent = '✓ Template kopiert!';
                    btn.style.background = '#00a32a';
                    btn.style.color = '#fff';
                    setTimeout(function() {
                        btn.textContent = originalText;
                        btn.style.background = '';
                        btn.style.color = '';
                    }, 2000);
                });
            });
            </script>

            <?php if ($form): ?>
            <div class="cfs-settings-section">
                <h2>Shortcode</h2>
                <p>Nutze diesen Shortcode in Elementor oder einem beliebigen Widget:</p>
                <code id="cfs-shortcode-display" class="cfs-shortcode" style="display: inline-block; margin-top: 10px; cursor: pointer; padding: 8px 12px; background: #f0f0f1; border: 1px solid #ddd; border-radius: 3px;" title="Zum Kopieren klicken">
                    [custom_form id=<?php echo esc_attr($form->id); ?>]
                </code>
            </div>

            <script>
            document.getElementById('cfs-shortcode-display').addEventListener('click', function() {
                const shortcode = '[custom_form id=<?php echo esc_attr($form->id); ?>]';
                navigator.clipboard.writeText(shortcode).then(function() {
                    const code = document.getElementById('cfs-shortcode-display');
                    const originalText = code.textContent;
                    const originalBg = code.style.background;
                    const originalColor = code.style.color;
                    code.textContent = '✓ Shortcode kopiert!';
                    code.style.background = '#00a32a';
                    code.style.color = '#fff';
                    setTimeout(function() {
                        code.textContent = originalText;
                        code.style.background = originalBg;
                        code.style.color = originalColor;
                    }, 2000);
                });
            });
            </script>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_form_shortcode($atts) {
        global $wpdb;

        $atts = shortcode_atts(['id' => 0], $atts);
        $form_id = absint($atts['id']);

        if (!$form_id) {
            return '<p style="color: red;">Fehler: Formular ID fehlt</p>';
        }

        $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->forms_table} WHERE id = %d", $form_id));

        if (!$form) {
            return '<p style="color: red;">Fehler: Formular nicht gefunden</p>';
        }

        $post_id = get_the_ID() ?: 0;
        $form_slug = sanitize_title($form->title);

        $injected_config = "
        const INJECTED_CONFIG = {
            formId: 'form_{$form_id}_{$form_slug}',
            formName: " . wp_json_encode($form->title) . ",
            postId: {$post_id}
        };
        ";

        $modified_code = preg_replace(
            '/const\s+FORM_CONFIG\s*=\s*\{/',
            $injected_config . 'const FORM_CONFIG = { ...INJECTED_CONFIG, ',
            $form->form_code,
            1
        );

        return $modified_code;
    }

    public function render_admin_page() {
        global $wpdb;

        if (isset($_GET['action']) && $_GET['action'] === 'mark_read' && isset($_GET['id'])) {
            $id = absint($_GET['id']);
            $wpdb->update($this->table_name, ['status' => 'read'], ['id' => $id]);
            wp_redirect(admin_url('admin.php?page=custom-form-submissions'));
            exit;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            $id = absint($_GET['id']);

            $submission = $wpdb->get_row($wpdb->prepare("SELECT files FROM {$this->table_name} WHERE id = %d", $id));
            if ($submission && $submission->files) {
                $files = json_decode($submission->files, true);
                foreach ($files as $file) {
                    $filepath = $this->uploads_dir . '/' . $file['filename'];
                    if (file_exists($filepath)) {
                        unlink($filepath);
                    }
                }
            }

            $wpdb->delete($this->table_name, ['id' => $id]);
            wp_redirect(admin_url('admin.php?page=custom-form-submissions'));
            exit;
        }

        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        $where = '';
        if (!empty($search)) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where = $wpdb->prepare(
                "WHERE form_name LIKE %s OR form_data LIKE %s OR user_ip LIKE %s",
                $search_like, $search_like, $search_like
            );
        }

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $unread = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'unread'");
        $today = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE DATE(created_at) = %s",
            current_time('Y-m-d')
        ));

        $submissions = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} {$where} ORDER BY created_at DESC LIMIT 100"
        );

        ?>
        <div class="wrap cfs-container">
            <div class="cfs-header">
                <h1>Anfragen</h1>
                <form method="get" action="">
                    <input type="hidden" name="page" value="custom-form-submissions">
                    <input type="text" name="s" class="cfs-search" placeholder="Suchen..." value="<?php echo esc_attr($search); ?>">
                </form>
            </div>

            <div class="cfs-stats">
                <div class="cfs-stat-box">
                    <p class="cfs-stat-number"><?php echo esc_html($total); ?></p>
                    <p class="cfs-stat-label">Gesamt</p>
                </div>
                <div class="cfs-stat-box">
                    <p class="cfs-stat-number"><?php echo esc_html($unread); ?></p>
                    <p class="cfs-stat-label">Ungelesen</p>
                </div>
                <div class="cfs-stat-box">
                    <p class="cfs-stat-number"><?php echo esc_html($today); ?></p>
                    <p class="cfs-stat-label">Heute</p>
                </div>
            </div>

            <div class="cfs-table-wrapper">
                <?php if (empty($submissions)): ?>
                    <div class="cfs-no-data">
                        <p><?php echo empty($search) ? 'Noch keine Submissions vorhanden.' : 'Keine Ergebnisse gefunden.'; ?></p>
                    </div>
                <?php else: ?>
                    <table class="cfs-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Kontakt</th>
                                <th>Formular</th>
                                <th>Datum</th>
                                <th>Status</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissions as $submission):
                                $data = json_decode($submission->form_data, true);
                                $contact = $this->extract_contact_info($data);
                            ?>
                                <tr>
                                    <td><strong>#<?php echo esc_html($submission->id); ?></strong></td>
                                    <td>
                                        <?php if (isset($contact['name'])): ?>
                                            <strong><?php echo esc_html($contact['name']); ?></strong><br>
                                        <?php endif; ?>
                                        <?php if (isset($contact['email'])): ?>
                                            <small style="color: #666;"><?php echo esc_html($contact['email']); ?></small>
                                        <?php endif; ?>
                                        <?php if (empty($contact)): ?>
                                            <small style="color: #999;">Keine Kontaktdaten</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($submission->form_name); ?></strong>
                                    </td>
                                    <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($submission->created_at))); ?></td>
                                    <td>
                                        <span class="cfs-badge <?php echo esc_attr($submission->status); ?>">
                                            <?php echo $submission->status === 'unread' ? 'Neu' : 'Gelesen'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="#" class="cfs-view-btn" onclick="toggleDetails(<?php echo esc_js($submission->id); ?>); return false;">
                                            Anzeigen
                                        </a>
                                        <?php if ($submission->status === 'unread'): ?>
                                            | <a href="?page=custom-form-submissions&action=mark_read&id=<?php echo esc_attr($submission->id); ?>">Als gelesen markieren</a>
                                        <?php endif; ?>
                                        | <a href="?page=custom-form-submissions&action=delete&id=<?php echo esc_attr($submission->id); ?>" onclick="return confirm('Wirklich löschen?');" style="color: #d63638;">Löschen</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="6" style="padding: 0;">
                                        <div id="details-<?php echo esc_attr($submission->id); ?>" class="cfs-details">
                                            <?php $this->render_submission_details($submission); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <script>
        function toggleDetails(id) {
            const details = document.getElementById('details-' + id);
            details.classList.toggle('active');
        }
        </script>
        <?php
    }

    private function render_submission_details($submission) {
        $data = json_decode($submission->form_data, true);
        $files = $submission->files ? json_decode($submission->files, true) : null;

        echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">';

        echo '<div>';
        echo '<h3 style="margin-top: 0;">Formulardaten</h3>';
        foreach ($data as $key => $value) {
            if (in_array($key, ['form_id', 'form_name', 'post_id'])) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            echo '<div class="cfs-field">';
            echo '<div class="cfs-field-label">' . esc_html(ucfirst(str_replace('_', ' ', $key))) . ':</div>';
            echo '<div class="cfs-field-value">' . esc_html($value) . '</div>';
            echo '</div>';
        }

        if ($files && !empty($files)) {
            echo '<h3>Hochgeladene Dateien</h3>';
            foreach ($files as $file) {
                echo '<a href="' . esc_url($file['url']) . '" target="_blank" class="cfs-file-link">';
                echo esc_html($file['original_name']);
                echo '</a>';
            }
        }
        echo '</div>';

        echo '<div>';
        echo '<h3 style="margin-top: 0;">Meta-Informationen</h3>';
        echo '<div class="cfs-field">';
        echo '<div class="cfs-field-label">IP-Adresse:</div>';
        echo '<div class="cfs-field-value">' . esc_html($submission->user_ip) . '</div>';
        echo '</div>';
        echo '<div class="cfs-field">';
        echo '<div class="cfs-field-label">Referer:</div>';
        echo '<div class="cfs-field-value">' . esc_html($submission->referer) . '</div>';
        echo '</div>';
        echo '<div class="cfs-field">';
        echo '<div class="cfs-field-label">User Agent:</div>';
        echo '<div class="cfs-field-value" style="font-size: 11px;">' . esc_html($submission->user_agent) . '</div>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    public function render_email_settings_page() {
        if (isset($_POST['cfs_save_settings'])) {
            check_admin_referer('cfs_email_settings');

            update_option('cfs_email_enabled', isset($_POST['cfs_email_enabled']) ? '1' : '0');
            update_option('cfs_email_recipients', sanitize_textarea_field($_POST['cfs_email_recipients']));
            update_option('cfs_email_cc', sanitize_textarea_field($_POST['cfs_email_cc']));
            update_option('cfs_email_from_name', sanitize_text_field($_POST['cfs_email_from_name']));
            update_option('cfs_email_from_email', sanitize_email($_POST['cfs_email_from_email']));
            update_option('cfs_email_subject', sanitize_text_field($_POST['cfs_email_subject']));

            echo '<div class="notice notice-success is-dismissible"><p>Einstellungen gespeichert!</p></div>';
        }

        $enabled = get_option('cfs_email_enabled', '0');
        $recipients = get_option('cfs_email_recipients', '');
        $cc = get_option('cfs_email_cc', '');
        $from_name = get_option('cfs_email_from_name', get_bloginfo('name'));
        $from_email = get_option('cfs_email_from_email', get_option('admin_email'));
        $subject = get_option('cfs_email_subject', 'Neue Anfrage: {form_name}');

        ?>
        <div class="wrap cfs-container">
            <h1>E-Mail Einstellungen</h1>

            <div class="notice notice-info" style="margin: 20px 0;">
                <p><strong>Hinweis zu Absender-E-Mail:</strong></p>
                <p>WordPress versendet E-Mails standardmäßig über die PHP mail() Funktion. Wenn du die Absender-E-Mail änderst:</p>
                <ul style="margin-left: 20px;">
                    <li>Nutze idealerweise eine E-Mail von derselben Domain (z.B. <code>info@deine-domain.de</code>)</li>
                    <li>Bei fremden Domains (z.B. Gmail) können E-Mails als Spam markiert werden</li>
                    <li>Für zuverlässigen Versand empfehlen wir ein SMTP-Plugin wie <strong>WP Mail SMTP</strong> oder <strong>Post SMTP</strong></li>
                </ul>
            </div>

            <div class="cfs-settings-section">
                <form method="post" action="">
                    <?php wp_nonce_field('cfs_email_settings'); ?>

                    <table class="cfs-form-table">
                        <tr>
                            <th>E-Mail Benachrichtigungen</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cfs_email_enabled" value="1" <?php checked($enabled, '1'); ?>>
                                    E-Mail bei neuer Anfrage versenden
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th>Empfänger <span style="color: red;">*</span></th>
                            <td>
                                <textarea name="cfs_email_recipients" rows="3"><?php echo esc_textarea($recipients); ?></textarea>
                                <p class="cfs-help-text">E-Mail-Adressen (eine pro Zeile oder kommagetrennt)</p>
                            </td>
                        </tr>

                        <tr>
                            <th>CC (optional)</th>
                            <td>
                                <textarea name="cfs_email_cc" rows="2"><?php echo esc_textarea($cc); ?></textarea>
                                <p class="cfs-help-text">CC E-Mail-Adressen (kommagetrennt)</p>
                            </td>
                        </tr>

                        <tr>
                            <th>Absender Name</th>
                            <td>
                                <input type="text" name="cfs_email_from_name" value="<?php echo esc_attr($from_name); ?>">
                                <p class="cfs-help-text">Standard: <?php echo esc_html(get_bloginfo('name')); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th>Absender E-Mail</th>
                            <td>
                                <input type="email" name="cfs_email_from_email" value="<?php echo esc_attr($from_email); ?>">
                                <p class="cfs-help-text">Standard: <?php echo esc_html(get_option('admin_email')); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th>Betreff</th>
                            <td>
                                <input type="text" name="cfs_email_subject" value="<?php echo esc_attr($subject); ?>">
                                <p class="cfs-help-text">Variablen: {form_name}</p>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" name="cfs_save_settings" class="button button-primary">Einstellungen speichern</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}

Custom_Form_Submissions::get_instance();
