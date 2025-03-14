<?php
/*
Plugin Name: Wordpress Update Watcher
Plugin URI: https://www.pivotalagency.com.au/
Description: Checks Wordpress & Plugins for required updates and sends a notification when updates are required
Version: 1.0.8
Author: Pivotal Agency
Author URI: https://www.pivotalagency.com.au/
Text Domain: pvtl-update-watcher
License: GPL3+
*/

// Only load class if it hasn't already been loaded
if ( !class_exists( 'PvtlUpdateWatcher' ) ) {

	// PVTL Update Watcher - All the magic happens here!
	class PvtlUpdateWatcher {
		const OPT_FIELD         = "puw_settings";
		const OPT_VERSION_FIELD = "puw_settings_ver";
		const OPT_VERSION       = "5.0";
		const CRON_NAME         = "puw_update_check";

		static $didInit = false;

		private $from_name = 'Pivotal Agency';

		public function __construct() {
			if (!self::$didInit) {
				$this->init();
				self::$didInit = true;
			}
		}

		private function init() {
			// Check settings are up to date
			$this->settingsUpToDate();
			// Create Activation and Deactivation Hooks
			register_activation_hook( __FILE__, array( $this, 'activate' ) );
			register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
			// Internationalization
			load_plugin_textdomain( 'pvtl-update-watcher', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
			// Add Filters
			add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 ); // Add settings link to plugin in plugin list
			add_filter( 'puw_plugins_need_update', array( $this, 'check_plugins_against_notified' ) ); // Filter out plugins that need update if already been notified
			add_filter( 'puw_themes_need_update', array( $this, 'check_themes_against_notified' ) ); // Filter out themes that need update if already been notified
			add_filter( 'auto_core_update_email', array( $this, 'filter_auto_core_update_email' ), 1 ); // Filter the background update notification email.
			add_filter( 'cron_schedules', array( $this, 'create_monthly_schedule' ), 10, 1 );
			// Add Actions
			add_action( 'admin_menu', array( $this, 'admin_settings_menu' ) ); // Add menu to options
			add_action( 'admin_init', array( $this, 'admin_settings_init' ) ); // Add admin init functions
			add_action( 'admin_init', array( $this, 'remove_update_nag_for_nonadmins' ) ); // See if we remove update nag for non admins
			add_action( 'admin_init', array( $this, 'admin_register_scripts_styles' ) );
			add_action( 'puw_enable_cron', array( $this, 'enable_cron' ) ); // action to enable cron
			add_action( 'puw_disable_cron', array( $this, 'disable_cron' ) ); // action to disable cron
			add_action( self::CRON_NAME, array( $this, 'do_update_check' ) ); // action to link cron task to actual task
			add_action( 'wp_ajax_puw_check', array( $this, 'puw_check' ) ); // Admin ajax hook for remote cron method.
			add_action( 'wp_ajax_nopriv_puw_check', array( $this, 'puw_check' ) ); // Admin ajax hook for remote cron method.

			add_action('admin_notices', function () {
                if(
                    !isset($_GET['page'])
                    || $_GET['page'] != 'pvtl-update-watcher'
                    || !isset($_GET['no-updates'])
                ) {
                    return;
                }

                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('No updates available.', 'pvtl-update-watcher'); ?></p>
                </div>
                <?php
            });
		}

		/**
		 * Check if this plugin settings are up to date. Firstly check the version in
		 * the DB. If they don't match then load in defaults but don't override values
		 * already set. Also this will remove obsolete settings that are not needed.
		 *
		 * @return void
		 */
		private function settingsUpToDate() {
			$current_ver = $this->getSetOptions( self::OPT_VERSION_FIELD ); // Get current plugin version
			if ( self::OPT_VERSION != $current_ver ) { // is the version the same as this plugin?
				$options  = (array) get_option( self::OPT_FIELD ); // get current settings from DB
				$defaults = array( // Here are our default values for this plugin
					'cron_method'      => 'wordpress', // Cron method to be used for scheduling scans
					'frequency'        => 'hourly',
					'notify_to'        => get_option( 'admin_email' ),
					'notify_from'      => get_option( 'admin_email' ),
					'notify_plugins'   => 1,
					'notify_themes'    => 1,
					'notify_automatic' => 1,
					'hide_updates'     => 1,
					'notified'         => array(
						'core'   => "",
						'plugin' => array(),
						'theme'  => array(),
					),
					'security_key'     => sha1( microtime( true ) . mt_rand( 10000, 90000 ) ), // Generate a random key to be used for Other Cron Method,
					'last_check_time'  => false
				);
				// Intersect current options with defaults. Basically removing settings that are obsolete
				$options = array_intersect_key( $options, $defaults );
				// Merge current settings with defaults. Basically adding any new settings with defaults that we dont have.
				$options = array_merge( $defaults, $options );
				$this->getSetOptions( self::OPT_FIELD, $options ); // update settings
				$this->getSetOptions( self::OPT_VERSION_FIELD, self::OPT_VERSION ); // update settings version
			}
		}

		/**
		 * Filter for when getting or settings this plugins settings
		 *
		 * @param string     $field    Option field name of where we are getting or setting plugin settings
		 * @param bool|mixed $settings False if getting settings else an array with settings you are saving
		 *
		 * @return bool|mixed True or false if setting or an array of settings if getting
		 */
		private function getSetOptions( $field, $settings = false ) {
			if ( $settings === false ) {
				return apply_filters( 'puw_get_options_filter', get_option( $field ), $field );
			}

			return update_option( $field, apply_filters( 'puw_put_options_filter', $settings, $field ) );
		}


		/**
		 * Function that deals with activation of this plugin
		 *
		 * @return void
		 */
		public function activate() {
			do_action( "puw_enable_cron" ); // Enable cron
		}


		/**
		 * Function that deals with de-activation of this plugin
		 *
		 * @return void
		 */
		public function deactivate() {
			do_action( "puw_disable_cron" ); // Disable cron
		}


		/**
		 * Enable cron for this plugin. Check if a cron should be scheduled.
		 *
		 * @param bool|string $manual_interval For setting a manual cron interval.
		 *
		 * @return void
		 */
		public function enable_cron( $manual_interval = false ) {
			$options         = $this->getSetOptions( self::OPT_FIELD ); // Get settings
			$currentSchedule = wp_get_schedule( self::CRON_NAME ); // find if a schedule already exists

			// if a manual cron interval is set, use this
			if ( false !== $manual_interval ) {
				$options['frequency'] = $manual_interval;
			}

			if ( "manual" == $options['frequency'] ) {
				do_action( "puw_disable_cron" ); // Make sure no cron is setup as we are manual
			}
			else {
				// check if the current schedule matches the one set in settings
				if ( $currentSchedule == $options['frequency'] ) {
					return;
				}

				// check the cron setting is valid
				if ( !in_array( $options['frequency'], $this->get_intervals() ) ) {
					return;
				}

				// Remove any cron's for this plugin first so we don't end up with multiple cron's doing the same thing.
				do_action( "puw_disable_cron" );

				// Schedule cron for this plugin.
				wp_schedule_event( time(), $options['frequency'], self::CRON_NAME );
			}
		}


		/**
		 * Removes cron for this plugin.
		 *
		 * @return void
		 */
		public function disable_cron() {
			wp_clear_scheduled_hook( self::CRON_NAME ); // clear cron
		}

		/**
         * Creates the once monthly WP schedule
         *
         * @param array $schedules
         *
         * @return array
         */
        public function create_monthly_schedule($schedules) {
            if (!isset($schedules['pvtl_monthly'])) {
                $schedules['pvtl_monthly'] = array(
                    'interval' => 86400 * 30,
                    'display' => __('Once Monthly')
                );
            }
            return $schedules;
        }

		/**
		 * Adds the settings link under the plugin on the plugin screen.
		 *
		 * @param array  $links
		 * @param string $file
		 *
		 * @return array $links
		 */
		public function plugin_action_links( $links, $file ) {
			if ( $file == plugin_basename( __FILE__ ) ) {
				$settings_link = '<a href="' . admin_url( 'options-general.php?page=pvtl-update-watcher' ) . '">' . __( "Settings", "pvtl-update-watcher" ) . '</a>';
				array_unshift( $links, $settings_link );
			}
			return $links;
		}


		/**
		 * This is run by the cron. The update check checks the core always, the
		 * plugins and themes if asked. If updates found email notification sent.
		 *
		 * @return void
		 */
		public function do_update_check($print_to_screen = false) {
            $message      = ""; // start with a blank message
			[$core_updated, $plugins_updated, $themes_updated] = $this->update_check($message);

            if ($print_to_screen) {
                die( trim($message) ); // Heavy handed way of doing it, hey?
            }

			if ( $core_updated || $plugins_updated || $themes_updated ) { // Did anything come back as need updating?
			    //$message = __( "There are updates available for your WordPress site:", "pvtl-update-watcher" ) . "\n" . $message . "\n";
				//$message .= sprintf( __( "Please visit %s to update.", "pvtl-update-watcher" ), admin_url( 'update-core.php' ) );
				$this->send_notification_email( trim($message) ); // send our notification email.
			}

			$this->log_last_check_time();
		}

        public function update_check(&$message, $ignore_settings = false)
        {
            $options      = $this->getSetOptions( self::OPT_FIELD ); // get settings
			$core_updated = $this->core_update_check( $message ); // check the WP core for updates

			if ( 0 != $options['notify_plugins'] || $ignore_settings ) { // are we to check for plugin updates?
				$plugins_updated = $this->plugins_update_check( $message, $options['notify_plugins'] ); // check for plugin updates
			} else {
				$plugins_updated = false; // no plugin updates
			}

			if ( 0 != $options['notify_themes'] && $ignore_settings) { // are we to check for theme updates?
				$themes_updated = $this->themes_update_check( $message, $options['notify_themes'] ); // check for theme updates
			} else {
				$themes_updated = false; // no theme updates
			}

            return [
                $core_updated,
                $plugins_updated,
                $themes_updated,
            ];
        }

		/**
		 * Checks to see if any WP core updates
		 *
		 * @param string $message holds message to be sent via notification
		 *
		 * @return bool
		 */
		private function core_update_check( &$message ) {
			global $wp_version;
			$settings = $this->getSetOptions( self::OPT_FIELD ); // get settings
			do_action( "wp_version_check" ); // force WP to check its core for updates
			$update_core = get_site_transient( "update_core" ); // get information of updates
			if ( 'upgrade' == $update_core->updates[0]->response ) { // is WP core update available?
				if ( $update_core->updates[0]->current != $settings['notified']['core'] ) { // have we already notified about this version?
					require_once( ABSPATH . WPINC . '/version.php' ); // Including this because some plugins can mess with the real version stored in the DB.
					$new_core_ver = $update_core->updates[0]->current; // The new WP core version
					$old_core_ver = $wp_version; // the old WP core version
					$message .= "\n" . sprintf( __( "WP-Core: WordPress is out of date. Please update from version %s to %s", "pvtl-update-watcher" ), $old_core_ver, $new_core_ver ) . "\n";
					$settings['notified']['core'] = $new_core_ver; // set core version we are notifying about
					$this->getSetOptions( self::OPT_FIELD, $settings ); // update settings
					return true; // we have updates so return true
				}
				else {
					return false; // There are updates but we have already notified in the past.
				}
			}
			$settings['notified']['core'] = ""; // no updates lets set this nothing
			$this->getSetOptions( self::OPT_FIELD, $settings ); // update settings
			return false; // no updates return false
		}


		/**
		 * Check to see if any plugin updates.
		 *
		 * @param string $message     holds message to be sent via notification
		 * @param int    $allOrActive should we look for all plugins or just active ones
		 *
		 * @return bool
		 */
		private function plugins_update_check( &$message, $allOrActive ) {
			global $wp_version;
			$cur_wp_version = preg_replace( '/-.*$/', '', $wp_version );
			$settings       = $this->getSetOptions( self::OPT_FIELD ); // get settings
			do_action( "wp_update_plugins" ); // force WP to check plugins for updates
			$update_plugins = get_site_transient( 'update_plugins' ); // get information of updates
			if ( !empty( $update_plugins->response ) ) { // any plugin updates available?
				$plugins_need_update = $update_plugins->response; // plugins that need updating
				if ( 2 == $allOrActive ) { // are we to check just active plugins?
					$active_plugins      = array_flip( get_option( 'active_plugins' ) ); // find which plugins are active
					$plugins_need_update = array_intersect_key( $plugins_need_update, $active_plugins ); // only keep plugins that are active
				}

				$plugins_need_update = apply_filters( 'puw_plugins_need_update', $plugins_need_update ); // additional filtering of plugins need update

				if ( count( $plugins_need_update ) >= 1 ) { // any plugins need updating after all the filtering gone on above?
					require_once( ABSPATH . 'wp-admin/includes/plugin-install.php' ); // Required for plugin API
					require_once( ABSPATH . WPINC . '/version.php' ); // Required for WP core version
					foreach ( $plugins_need_update as $key => $data ) { // loop through the plugins that need updating
						$plugin_info = get_plugin_data( WP_PLUGIN_DIR . "/" . $key ); // get local plugin info
						$info        = plugins_api( 'plugin_information', array( 'slug' => $data->slug ) ); // get repository plugin info
						$message .= "\n" . sprintf( __( "Plugin: %s is out of date. Please update from version %s to %s", "pvtl-update-watcher" ), $plugin_info['Name'], $plugin_info['Version'], $data->new_version ) . "\n";
						$message .= "\t" . sprintf( __( "Details: %s", "pvtl-update-watcher" ), $data->url ) . "\n";
						$message .= "\t" . sprintf( __( "Changelog: %s%s", "pvtl-update-watcher" ), $data->url, "changelog/" ) . "\n";
						if ( isset( $info->tested ) && version_compare( $info->tested, $wp_version, '>=' ) ) {
							$compat = sprintf( __( 'Compatibility with WordPress %1$s: 100%% (according to its author)' ), $cur_wp_version );
						}
						elseif ( isset( $info->compatibility[$wp_version][$data->new_version] ) ) {
							$compat = $info->compatibility[$wp_version][$data->new_version];
							$compat = sprintf( __( 'Compatibility with WordPress %1$s: %2$d%% (%3$d "works" votes out of %4$d total)' ), $wp_version, $compat[0], $compat[2], $compat[1] );
						}
						else {
							$compat = sprintf( __( 'Compatibility with WordPress %1$s: Unknown' ), $wp_version );
						}
						$message .= "\t" . sprintf( __( "Compatibility: %s", "pvtl-update-watcher" ), $compat ) . "\n";
						$settings['notified']['plugin'][$key] = $data->new_version; // set plugin version we are notifying about
					}
					$this->getSetOptions( self::OPT_FIELD, $settings ); // save settings
					return true; // we have plugin updates return true
				}
			}
			else {
				if ( 0 != count( $settings['notified']['plugin'] ) ) { // is there any plugin notifications?
					$settings['notified']['plugin'] = array(); // set plugin notifications to empty as all plugins up-to-date
					$this->getSetOptions( self::OPT_FIELD, $settings ); // save settings
				}
			}
			return false; // No plugin updates so return false
		}


		/**
		 * Check to see if any theme updates.
		 *
		 * @param string $message     holds message to be sent via notification
		 * @param int    $allOrActive should we look for all themes or just active ones
		 *
		 * @return bool
		 */
		private function themes_update_check( &$message, $allOrActive ) {
			$settings = $this->getSetOptions( self::OPT_FIELD ); // get settings
			do_action( "wp_update_themes" ); // force WP to check for theme updates
			$update_themes = get_site_transient( 'update_themes' ); // get information of updates
			if ( !empty( $update_themes->response ) ) { // any theme updates available?
				$themes_need_update = $update_themes->response; // themes that need updating
				if ( 2 == $allOrActive ) { // are we to check just active themes?
					$active_theme       = array( get_option( 'template' ) => array() ); // find current theme that is active
					$themes_need_update = array_intersect_key( $themes_need_update, $active_theme ); // only keep theme that is active
				}
				$themes_need_update = apply_filters( 'puw_themes_need_update', $themes_need_update ); // additional filtering of themes need update
				if ( count( $themes_need_update ) >= 1 ) { // any themes need updating after all the filtering gone on above?
					foreach ( $themes_need_update as $key => $data ) { // loop through the themes that need updating
						$theme_info = wp_get_theme( $key ); // get theme info
						$message .= "\n" . sprintf( __( "Theme: %s is out of date. Please update from version %s to %s", "pvtl-update-watcher" ), $theme_info['Name'], $theme_info['Version'], $data['new_version'] ) . "\n";
						$settings['notified']['theme'][$key] = $data['new_version']; // set theme version we are notifying about
					}
					$this->getSetOptions( self::OPT_FIELD, $settings ); // save settings
					return true; // we have theme updates return true
				}
			}
			else {
				if ( 0 != count( $settings['notified']['theme'] ) ) { // is there any theme notifications?
					$settings['notified']['theme'] = array(); // set theme notifications to empty as all themes up-to-date
					$this->getSetOptions( self::OPT_FIELD, $settings ); // save settings
				}
			}
			return false; // No theme updates so return false
		}


		/**
		 * Filter for removing plugins from update list if already been notified about
		 *
		 * @param array $plugins_need_update
		 *
		 * @return array $plugins_need_update
		 */
		public function check_plugins_against_notified( $plugins_need_update ) {
			$settings = $this->getSetOptions( self::OPT_FIELD ); // get settings
			//foreach ( $plugins_need_update as $key => $data ) { // loop through plugins that need update
			//	if ( isset( $settings['notified']['plugin'][$key] ) ) { // has this plugin been notified before?
			//		if ( $data->new_version == $settings['notified']['plugin'][$key] ) { // does this plugin version match that of the one that's been notified?
			//			unset( $plugins_need_update[$key] ); // don't notify this plugin as has already been notified
			//		}
			//	}
			//}
			return $plugins_need_update;
		}


		/**
		 * Filter for removing themes from update list if already been notified about
		 *
		 * @param array $themes_need_update
		 *
		 * @return array $themes_need_update
		 */
		public function check_themes_against_notified( $themes_need_update ) {
			$settings = $this->getSetOptions( self::OPT_FIELD ); // get settings
			foreach ( $themes_need_update as $key => $data ) { // loop through themes that need update
				if ( isset( $settings['notified']['theme'][$key] ) ) { // has this theme been notified before?
					if ( $data['new_version'] == $settings['notified']['theme'][$key] ) { // does this theme version match that of the one that's been notified?
						unset( $themes_need_update[$key] ); // don't notify this theme as has already been notified
					}
				}
			}
			return $themes_need_update;
		}


		/**
		 * Sends email notification.
		 *
		 * @param string $message holds message to be sent in body of email
		 *
		 * @return void
		 */
		public function send_notification_email( $message ) {
		    $message = $this->puw_email_content_template($message);
			$settings = $this->getSetOptions( self::OPT_FIELD ); // get settings
			$subject = __( "We've scanned your site, here's what we found", "pvtl-update-watcher" );
			$headers = [];
			$headers[] = 'Reply-To: Pivotal Agency <hello@pivotalagency.com.au>';
			add_filter( 'wp_mail_from', array( $this, 'puw_wp_mail_from' ) ); // add from filter
			add_filter( 'wp_mail_from_name', array( $this, 'puw_wp_mail_from_name' ) ); // add from name filter
			add_filter( 'wp_mail_content_type', array( $this, 'puw_wp_mail_content_type' ) ); // add content type filter
			wp_mail( $settings['notify_to'], apply_filters( 'puw_email_subject', $subject ), $message, $headers ); // send email
			remove_filter( 'wp_mail_from', array( $this, 'puw_wp_mail_from' ) ); // remove from filter
			remove_filter( 'wp_mail_from_name', array( $this, 'puw_wp_mail_from_name' ) ); // remove from name filter
			remove_filter( 'wp_mail_content_type', array( $this, 'puw_wp_mail_content_type' ) ); // remove content type filter
		}

		public function puw_wp_mail_from() {
			$settings = $this->getSetOptions( self::OPT_FIELD );
			return $settings['notify_from'];
		}

		public function puw_wp_mail_from_name() {
			return $this->from_name;
		}

		public function puw_wp_mail_content_type() {
			return "text/html";
		}

		public function puw_email_content_template($message) {
			$name = $this->getSetOptions( self::OPT_FIELD )['notify_to_name'] ? $this->getSetOptions( self::OPT_FIELD )['notify_to_name'] : 'there';
            ob_start();
		    include 'templates/email-notification.php';
		    $message = ob_get_clean();
			return $message;
		}

        public function puw_pdf_content_template($message) {
			$name = $this->getSetOptions( self::OPT_FIELD )['notify_to_name'] ? $this->getSetOptions( self::OPT_FIELD )['notify_to_name'] : 'there';
            $date = wp_date('D, d M Y H:i:s');
            ob_start();
		    include 'templates/pdf-notification.php';
		    $message = ob_get_clean();
			return $message;
		}

		private function log_last_check_time() {
			$options                    = $this->getSetOptions( self::OPT_FIELD );
			$options['last_check_time'] = current_time( "timestamp" );
			$this->getSetOptions( self::OPT_FIELD, $options );
		}

		/**
		 * Filter the background update notification email
		 *
		 * @param array $email Array of email arguments that will be passed to wp_mail().
		 *
		 * @return array Modified array containing the new email address.
		 */
		public function filter_auto_core_update_email( $email ) {
			$options = $this->getSetOptions( self::OPT_FIELD ); // Get settings

			if ( 0 != $options['notify_automatic'] ) {
				if ( ! empty( $options['notify_to'] ) ) { // If an email address has been set, override the WordPress default.
					$email['to'] = $options['notify_to'];
				}

				if ( ! empty( $options['notify_from'] ) ) { // If an email address has been set, override the WordPress default.
					$email['headers'][] = 'From: ' . $this->puw_wp_mail_from_name() . ' <' . $options['notify_from'] . '>';
				}
			}

			return $email;
		}


		/**
		 * Removes the update nag for non admin users.
		 *
		 * @return void
		 */
		public function remove_update_nag_for_nonadmins() {
			$settings = $this->getSetOptions( self::OPT_FIELD ); // get settings
			if ( 1 == $settings['hide_updates'] ) { // is this enabled?
				if ( !current_user_can( 'update_plugins' ) ) { // can the current user update plugins?
					remove_action( 'admin_notices', 'update_nag', 3 ); // no they cannot so remove the nag for them.
				}
			}
		}


		/**
		 * Adds JS to admin settings screen for this plugin
		 */
		public function admin_register_scripts_styles() {
			wp_register_script( 'wp_updates_monitor_js_function', plugins_url( 'js/function.js', __FILE__ ), array( 'jquery' ), '1.0', true );
		}


		public function puw_check() {
			$options = $this->getSetOptions( self::OPT_FIELD ); // get settings

			if ( !isset( $_GET['puw_key'] ) || $options['security_key'] != $_GET['puw_key'] || "other" != $options['cron_method'] ) {
				return;
			}

			$this->do_update_check();

			die( __( "Successfully checked for updates.", "pvtl-update-watcher" ) );
		}


		private function get_schedules() {
			$schedules = wp_get_schedules();
			uasort( $schedules, array( $this, 'sort_by_interval' ) );
			return $schedules;
		}


		private function get_intervals() {
			$intervals   = array_keys( $this->get_schedules() );
			$intervals[] = "manual";
			return $intervals;
		}


		private function sort_by_interval( $a, $b ) {
			return $a['interval'] - $b['interval'];
		}


		/*
		 * EVERYTHING SETTINGS
		 *
		 * I'm not going to comment any of this as its all pretty
		 * much straight forward use of the WordPress Settings API.
		 */
		public function admin_settings_menu() {
			$page = add_options_page( 'Update Watcher', 'Update Watcher', 'manage_options', 'pvtl-update-watcher', array( $this, 'settings_page' ) );
			add_action( "admin_print_scripts-{$page}", array( $this, 'enqueue_plugin_script' ) );
		}

		public function enqueue_plugin_script() {
			wp_enqueue_script( 'wp_updates_monitor_js_function' );
		}

		public function settings_page() {
			$options     = $this->getSetOptions( self::OPT_FIELD );
			$date_format = get_option( 'date_format' );
			$time_format = get_option( 'time_format' );
			?>
			<div class="wrap">
				<h2><?php _e( "Update Watcher", "pvtl-update-watcher" ); ?></h2>

				<p>
                    <span class="description">
                    <?php
					if ( false === $options["last_check_time"] ) {
						$scan_date = __( "Never", "pvtl-update-watcher" );
					}
					else {
						$scan_date = sprintf(
							__( "%1s @ %2s", "pvtl-update-watcher" ),
							date( $date_format, $options["last_check_time"] ),
							date( $time_format, $options['last_check_time'] )
						);
					}

					echo sprintf( __( "Last scanned: %s", "pvtl-update-watcher" ), $scan_date );
					?>
                    </span>
				</p>

				<form action="<?php echo admin_url( "options.php" ); ?>" method="post">
					<?php
					settings_fields( "puw_settings" );
					do_settings_sections( "pvtl-update-watcher" );
					?>
					<p>&nbsp;</p>
					<input class="button-primary" name="Submit" type="submit" value="<?php _e( "Save settings", "pvtl-update-watcher" ); ?>" />
                    &nbsp;&nbsp;|&nbsp;&nbsp;
					<input class="button" name="submitwithemail" type="submit" value="<?php _e( "Save settings &amp; Send email now", "pvtl-update-watcher" ); ?>" />
					<input class="button" name="submitwithprinttoscreen" type="submit" value="<?php _e( "Save settings &amp; Show all updates", "pvtl-update-watcher" ); ?>" />
					<input class="button" name="submitdownloadpdf" type="submit" value="<?php _e( "Download Report", "pvtl-update-watcher" ); ?>" />
				</form>
			</div>
		<?php
		}

		public function admin_settings_init() {
            $this->maybe_download_pdf();
			register_setting( self::OPT_FIELD, self::OPT_FIELD, array( $this, "puw_settings_validate" ) ); // Register Main Settings
			add_settings_section( "puw_settings_main", __( "Settings", "pvtl-update-watcher" ), array( $this, "puw_settings_main_text" ), "pvtl-update-watcher" ); // Make settings main section
			add_settings_field( "puw_settings_main_cron_method", __( "Cron Method", "pvtl-update-watcher" ), array( $this, "puw_settings_main_field_cron_method" ), "pvtl-update-watcher", "puw_settings_main" );
			add_settings_field( "puw_settings_main_frequency", __( "Frequency to check", "pvtl-update-watcher" ), array( $this, "puw_settings_main_field_frequency" ), "pvtl-update-watcher", "puw_settings_main" );
			add_settings_field( "puw_settings_main_notify_to", __( "Notify email to", "pvtl-update-watcher" ), array( $this, "puw_settings_main_field_notify_to" ), "pvtl-update-watcher", "puw_settings_main" );
			add_settings_field( "puw_settings_main_notify_from", __( "Notify email from", "pvtl-update-watcher" ), array( $this, "puw_settings_main_field_notify_from" ), "pvtl-update-watcher", "puw_settings_main" );
			add_settings_field( "puw_settings_main_notify_to_name", __( "Recipient name", "pvtl-update-watcher" ), array( $this, "puw_settings_main_field_notify_to_name" ), "pvtl-update-watcher", "puw_settings_main" );
			add_settings_field( "puw_settings_main_notify_plugins", __( "Notify about plugin updates?", "pvtl-update-watcher" ), array( $this, "puw_settings_main_field_notify_plugins" ), "pvtl-update-watcher", "puw_settings_main" );
			add_settings_field( "puw_settings_main_notify_themes", __( "Notify about theme updates?", "pvtl-update-watcher" ), array( $this, "puw_settings_main_field_notify_themes" ), "pvtl-update-watcher", "puw_settings_main" );
			add_settings_field( "puw_settings_main_notify_automatic", __( "Notify automatic core updates to this address?", "pvtl-update-watcher" ), array( $this, "puw_settings_main_field_notify_automatic" ), "pvtl-update-watcher", "puw_settings_main" );
			add_settings_field( "puw_settings_main_hide_updates", __( "Hide core WP update nag from non-admin users?", "pvtl-update-watcher" ), array( $this, "puw_settings_main_field_hide_updates" ), "pvtl-update-watcher", "puw_settings_main" );
		}

        public function maybe_download_pdf() {
            if(isset($_POST['submitdownloadpdf'])){
                $this->download_pdf();
                exit;
            }
        }
        public function download_pdf() {
            $message      = ""; // start with a blank message
			[$core_updated, $plugins_updated, $themes_updated] = $this->update_check($message, true);

            if ( $core_updated || $plugins_updated || $themes_updated ) {
                $message = $this->puw_pdf_content_template(trim($message));
                $date = wp_date('d_m_Y_H_i_s');
                $filename = "wp-updates-report-{$date}.pdf";

                $options = new \Dompdf\Options();
                $options->set('isRemoteEnabled', TRUE);
                $dompdf = new \Dompdf\Dompdf($options);
                $dompdf->loadHtml($message);
                $dompdf->render();

                // Make the browser download the PDF output
                $dompdf->stream($filename, ['Attachment'=>1]);
            }
            else {
                wp_redirect(admin_url('options-general.php?page=pvtl-update-watcher&no-updates'));
            }
        }

		public function puw_settings_validate( $input ) {
			$valid = $this->getSetOptions( self::OPT_FIELD );

			if ( isset( $input['cron_method'] ) && in_array( $input['cron_method'], array( "wordpress", "other" ) ) ) {
				$valid['cron_method'] = $input['cron_method'];
			}
			else {
				add_settings_error( "puw_settings_main_cron_method", "puw_settings_main_cron_method_error", __( "Invalid cron method selected", "pvtl-update-watcher" ), "error" );
			}

			if ( "other" == $valid['cron_method'] ) {
				$input['frequency'] = "manual";
			}

			if ( in_array( $input['frequency'], $this->get_intervals() ) ) {
				$valid['frequency'] = $input['frequency'];
				do_action( "puw_enable_cron", $input['frequency'] );
			}
			else {
				add_settings_error( "puw_settings_main_frequency", "puw_settings_main_frequency_error", __( "Invalid frequency entered", "pvtl-update-watcher" ), "error" );
			}

			$emails_to = explode( ",", $input['notify_to'] );
			if ( $emails_to ) {
				$sanitized_emails = array();
				$was_error        = false;
				foreach ( $emails_to as $email_to ) {
					$address = sanitize_email( trim( $email_to ) );
					if ( !is_email( $address ) ) {
						add_settings_error( "puw_settings_main_notify_to", "puw_settings_main_notify_to_error", __( "One or more email to addresses are invalid", "pvtl-update-watcher" ), "error" );
						$was_error = true;
						break;
					}
					$sanitized_emails[] = $address;
				}
				if ( !$was_error ) {
					$valid['notify_to'] = implode( ',', $sanitized_emails );
				}
			}
			else {
				add_settings_error( "puw_settings_main_notify_to", "puw_settings_main_notify_to_error", __( "No email to address entered", "pvtl-update-watcher" ), "error" );
			}

			$sanitized_email_from = sanitize_email( $input['notify_from'] );
			if ( is_email( $sanitized_email_from ) ) {
				$valid['notify_from'] = $sanitized_email_from;
			}
			else {
				add_settings_error( "puw_settings_main_notify_from", "puw_settings_main_notify_from_error", __( "Invalid email from entered", "pvtl-update-watcher" ), "error" );
			}

			$sanitized_email_to_name = sanitize_text_field( $input['notify_to_name'] );
			if ( !empty( $sanitized_email_to_name ) ) {
				$valid['notify_to_name'] = $sanitized_email_to_name;
			}
			else {
				add_settings_error( "puw_settings_main_notify_to_name", "puw_settings_main_notify_to_name", __( "Invalid recipient name", "pvtl-update-watcher" ), "error" );
			}

			$sanitized_notify_plugins = absint( isset( $input['notify_plugins'] ) ? $input['notify_plugins'] : 0 );
			if ( $sanitized_notify_plugins >= 0 && $sanitized_notify_plugins <= 2 ) {
				$valid['notify_plugins'] = $sanitized_notify_plugins;
			}
			else {
				add_settings_error( "puw_settings_main_notify_plugins", "puw_settings_main_notify_plugins_error", __( "Invalid plugin updates value entered", "pvtl-update-watcher" ), "error" );
			}

			$sanitized_notify_themes = absint( isset( $input['notify_themes'] ) ? $input['notify_themes'] : 0 );
			if ( $sanitized_notify_themes >= 0 && $sanitized_notify_themes <= 2 ) {
				$valid['notify_themes'] = $sanitized_notify_themes;
			}
			else {
				add_settings_error( "puw_settings_main_notify_themes", "puw_settings_main_notify_themes_error", __( "Invalid theme updates value entered", "pvtl-update-watcher" ), "error" );
			}

			$sanitized_notify_automatic = absint( isset( $input['notify_automatic'] ) ? $input['notify_automatic'] : 0 );
			if ( $sanitized_notify_automatic >= 0 && $sanitized_notify_automatic <= 1 ) {
				$valid['notify_automatic'] = $sanitized_notify_automatic;
			}
			else {
				add_settings_error( "puw_settings_main_notify_automatic", "puw_settings_main_notify_automatic_error", __( "Invalid automatic updates value entered", "pvtl-update-watcher" ), "error" );
			}

			$sanitized_hide_updates = absint( isset( $input['hide_updates'] ) ? $input['hide_updates'] : 0 );
			if ( $sanitized_hide_updates <= 1 ) {
				$valid['hide_updates'] = $sanitized_hide_updates;
			}
			else {
				add_settings_error( "puw_settings_main_hide_updates", "puw_settings_main_hide_updates_error", __( "Invalid hide updates value entered", "pvtl-update-watcher" ), "error" );
			}

			if ( isset( $_POST['submitwithemail'] ) ) {
				add_filter( 'pre_set_transient_settings_errors', array( $this, "send_test_email" ) );
			}

			if ( isset( $_POST['submitwithprinttoscreen'] ) ) {
				add_filter( 'pre_set_transient_settings_errors', array( $this, "print_updates_to_screen" ) );
			}

			if ( isset( $input['cron_method'] ) && in_array( $input['cron_method'], array( "wordpress", "other" ) ) ) {
				$valid['cron_method'] = $input['cron_method'];
			}
			else {
				add_settings_error( "puw_settings_main_cron_method", "puw_settings_main_cron_method_error", __( "Invalid cron method selected", "pvtl-update-watcher" ), "error" );
			}

			return $valid;
		}

		public function send_test_email( $settings_errors ) {
			if ( isset( $settings_errors[0]['type'] ) && $settings_errors[0]['type'] == "success" ) {
				// $this->send_notification_email( __( "This is a test message from PVTL Update Watcher.", "pvtl-update-watcher" ) );
                $this->do_update_check();
			}
		}

		public function print_updates_to_screen( $settings_errors ) {
			if ( isset( $settings_errors[0]['type'] ) && $settings_errors[0]['type'] == "success" ) {
				// $this->send_notification_email( __( "This is a test message from PVTL Update Watcher.", "pvtl-update-watcher" ) );
                $this->do_update_check(true);
			}
		}

		public function puw_settings_main_text() {
		}

		public function puw_settings_main_field_cron_method() {
			$options = $this->getSetOptions( self::OPT_FIELD );
			?>
			<select name="<?php echo self::OPT_FIELD; ?>[cron_method]">
				<option value="wordpress" <?php selected( $options['cron_method'], "wordpress" ); ?>><?php _e( "WordPress Cron", "pvtl-update-watcher" ); ?></option>
				<option value="other" <?php selected( $options['cron_method'], "other" ); ?>><?php _e( "Other Cron", "pvtl-update-watcher" ); ?></option>
			</select>
			<div>
				<br />
				<span class="description"><?php _e( "Cron Command: ", "pvtl-update-watcher" ); ?></span>
				<pre>wget -q "<?php echo admin_url( "/admin-ajax.php?action=puw_check&puw_key=" . $options['security_key'] ); ?>" -O /dev/null >/dev/null 2>&amp;1</pre>
			</div>
		<?php
		}

		public function puw_settings_main_field_frequency() {
			$options = $this->getSetOptions( self::OPT_FIELD );
			?>
			<select id="puw_settings_main_frequency" name="<?php echo self::OPT_FIELD; ?>[frequency]">
			<?php foreach ( $this->get_schedules() as $k => $v ): ?>
				<option value="<?php echo $k; ?>" <?php selected( $options['frequency'], $k ); ?>><?php echo $v['display']; ?></option>
			<?php endforeach; ?>
			<select>
		<?php
		}

		public function puw_settings_main_field_notify_to() {
			$options = $this->getSetOptions( self::OPT_FIELD );
			?>
			<input id="puw_settings_main_notify_to" class="regular-text" name="<?php echo self::OPT_FIELD; ?>[notify_to]" value="<?php echo $options['notify_to']; ?>" />
			<span class="description"><?php _e( "Separate multiple email address with a comma (,)", "pvtl-update-watcher" ); ?></span><?php
		}

		public function puw_settings_main_field_notify_to_name() {
			$options = $this->getSetOptions( self::OPT_FIELD );
			?>
			<input id="puw_settings_main_notify_to_name" class="regular-text" name="<?php echo self::OPT_FIELD; ?>[notify_to_name]" value="<?php echo $options['notify_to_name']; ?>" /><?php
		}

		public function puw_settings_main_field_notify_from() {
			$options = $this->getSetOptions( self::OPT_FIELD );
			?>
			<input id="puw_settings_main_notify_from" class="regular-text" name="<?php echo self::OPT_FIELD; ?>[notify_from]" value="<?php echo $options['notify_from']; ?>" /><?php
		}

		public function puw_settings_main_field_notify_plugins() {
			$options = $this->getSetOptions( self::OPT_FIELD );
			?>
			<label><input name="<?php echo self::OPT_FIELD; ?>[notify_plugins]" type="radio" value="0" <?php checked( $options['notify_plugins'], 0 ); ?> /> <?php _e( "No", "pvtl-update-watcher" ); ?>
			</label><br />
			<label><input name="<?php echo self::OPT_FIELD; ?>[notify_plugins]" type="radio" value="1" <?php checked( $options['notify_plugins'], 1 ); ?> /> <?php _e( "Yes", "pvtl-update-watcher" ); ?>
			</label><br />
			<label><input name="<?php echo self::OPT_FIELD; ?>[notify_plugins]" type="radio" value="2" <?php checked( $options['notify_plugins'], 2 ); ?> /> <?php _e( "Yes but only active plugins", "pvtl-update-watcher" ); ?>
			</label>
		<?php
		}

		public function puw_settings_main_field_notify_themes() {
			$options = $this->getSetOptions( self::OPT_FIELD );
			?>
			<label><input name="<?php echo self::OPT_FIELD; ?>[notify_themes]" type="radio" value="0" <?php checked( $options['notify_themes'], 0 ); ?> /> <?php _e( "No", "pvtl-update-watcher" ); ?>
			</label><br />
			<label><input name="<?php echo self::OPT_FIELD; ?>[notify_themes]" type="radio" value="1" <?php checked( $options['notify_themes'], 1 ); ?> /> <?php _e( "Yes", "pvtl-update-watcher" ); ?>
			</label><br />
			<label><input name="<?php echo self::OPT_FIELD; ?>[notify_themes]" type="radio" value="2" <?php checked( $options['notify_themes'], 2 ); ?> /> <?php _e( "Yes but only active themes", "pvtl-update-watcher" ); ?>
			</label>
		<?php
		}

		public function puw_settings_main_field_notify_automatic() {
			$options = $this->getSetOptions( self::OPT_FIELD );
			?>
			<label><input name="<?php echo self::OPT_FIELD; ?>[notify_automatic]" type="checkbox" value="1" <?php checked( $options['notify_automatic'], 1 ); ?> /> <?php _e( "Yes", "pvtl-update-watcher" ); ?>
			</label>
		<?php
		}

		public function puw_settings_main_field_hide_updates() {
			$options = $this->getSetOptions( self::OPT_FIELD );
			?>
			<select id="puw_settings_main_hide_updates" name="<?php echo self::OPT_FIELD; ?>[hide_updates]">
				<option value="1" <?php selected( $options['hide_updates'], 1 ); ?>><?php _e( "Yes", "pvtl-update-watcher" ); ?></option>
				<option value="0" <?php selected( $options['hide_updates'], 0 ); ?>><?php _e( "No", "pvtl-update-watcher" ); ?></option>
			</select>
		<?php
		}
		/**** END EVERYTHING SETTINGS ****/
	}
}

require_once 'vendor/autoload.php';
new PvtlUpdateWatcher();
