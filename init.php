<?php
/**
 * Plugin Name: ImportUserCSV
 * Description: Add avatar upload field and import users from CSV with all metadata support.
 * Version: 2.0.0
 * Author: Kagorec, Contributors
 * Text Domain: import-user-csv
 *
 * https://wordpress.org/plugins/basic-user-avatars/ - Special thanks to Jared Atchison, the user avatar is based on this plugin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Basic User Avatars. If not, see <http://www.gnu.org/licenses/>.
 */

class basic_user_avatars {

	/**
	 * User ID
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $user_id_being_edited;

	/**
	 * Initialize all the things
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		// Text domain
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Actions
		add_action( 'admin_init',				 array( $this, 'admin_init'               )        );
		add_action( 'show_user_profile',		 array( $this, 'edit_user_profile'        )        );
		add_action( 'edit_user_profile',		 array( $this, 'edit_user_profile'        )        );
		add_action( 'personal_options_update',	 array( $this, 'edit_user_profile_update' )        );
		add_action( 'edit_user_profile_update',	 array( $this, 'edit_user_profile_update' )        );
		add_action( 'bbp_user_edit_after_about', array( $this, 'bbpress_user_profile'     )        );

		// Shortcode
		add_shortcode( 'basic-user-avatars',	 array( $this, 'shortcode'                )        );

		// Filters
		add_filter( 'get_avatar_data',			 array( $this, 'get_avatar_data'               ), 10, 2 );
		add_filter( 'get_avatar',				 array( $this, 'get_avatar'               ), 10, 6 );
		add_filter( 'avatar_defaults',			 array( $this, 'avatar_defaults'          )        );
	}

	/**
	 * Loads the plugin language files.
	 *
	 * @since 1.0.1
	 */
	public function load_textdomain() {
		$domain = 'basic-user-avatars';
		$locale = apply_filters( 'plugin_locale', get_locale(), $domain );
		load_textdomain( $domain, WP_LANG_DIR . '/' . $domain . '/' . $domain . '-' . $locale . '.mo' );
		load_plugin_textdomain( $domain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Start the admin engine.
	 *
	 * @since 1.0.0
	 */
	public function admin_init() {

		// Register/add the Discussion setting to restrict avatar upload capabilites
		register_setting( 'discussion', 'basic_user_avatars_caps', array( $this, 'sanitize_options' ) );
		add_settings_field( 'basic-user-avatars-caps', esc_html__( 'Local Avatar Permissions', 'basic-user-avatars' ), array( $this, 'avatar_settings_field' ), 'discussion', 'avatars' );
	}

	/**
	 * Discussion settings option
	 *
	 * @since 1.0.0
	 * @param array $args [description]
	 */
	public function avatar_settings_field( $args ) {
		$options = get_option( 'basic_user_avatars_caps' );

		$basic_user_avatars_caps = ! empty( $options['basic_user_avatars_caps'] ) ? 1 : 0;

		?>
		<label for="basic_user_avatars_caps">
			<input type="checkbox" name="basic_user_avatars_caps" id="basic_user_avatars_caps" value="1" <?php checked( $basic_user_avatars_caps, 1 ); ?>/>
			<?php esc_html_e( 'Only allow users with file upload capabilities to upload local avatars (Authors and above)', 'basic-user-avatars' ); ?>
		</label>
		<?php
	}

	/**
	 * Sanitize the Discussion settings option
	 *
	 * @since 1.0.0
	 * @param array $input
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$new_input['basic_user_avatars_caps'] = empty( $input ) ? 0 : 1;
		return $new_input;
	}

	/**
	 * Filter the normal avatar data and show our avatar if set.
	 *
	 * @since 1.0.6
	 * @param array $args        Arguments passed to get_avatar_data(), after processing.
	 * @param mixed $id_or_email The avatar to retrieve. Accepts a user_id, Gravatar MD5 hash,
	 *                           user email, WP_User object, WP_Post object, or WP_Comment object.
	 * @return array             The filtered avatar data.
	 */
	public function get_avatar_data( $args, $id_or_email ) {
		if ( ! empty( $args['force_default'] ) ) {
			return $args;
		}

		global $wpdb;

		$return_args = $args;

		// Determine if we received an ID or string. Then, set the $user_id variable.
		if ( is_numeric( $id_or_email ) && 0 < $id_or_email ) {
			$user_id = (int) $id_or_email;
		} elseif ( is_object( $id_or_email ) && isset( $id_or_email->user_id ) && 0 < $id_or_email->user_id ) {
			$user_id = $id_or_email->user_id;
		} elseif ( is_object( $id_or_email ) && isset( $id_or_email->ID ) && isset( $id_or_email->user_login ) && 0 < $id_or_email->ID ) {
			$user_id = $id_or_email->ID;
		} elseif ( is_string( $id_or_email ) && false !== strpos( $id_or_email, '@' ) ) {
			$_user = get_user_by( 'email', $id_or_email );

			if ( ! empty( $_user ) ) {
				$user_id = $_user->ID;
			}
		}

		if ( empty( $user_id ) ) {
			return $args;
		}

		$user_avatar_url = null;

		// Get the user's local avatar from usermeta.
		$local_avatars = get_user_meta( $user_id, 'basic_user_avatar', true );

		if ( empty( $local_avatars ) || empty( $local_avatars['full'] ) ) {
			// Try to pull avatar from WP User Avatar.
			$wp_user_avatar_id = get_user_meta( $user_id, $wpdb->get_blog_prefix() . 'user_avatar', true );
			if ( ! empty( $wp_user_avatar_id ) ) {
				$wp_user_avatar_url = wp_get_attachment_url( intval( $wp_user_avatar_id ) );
				$local_avatars = array( 'full' => $wp_user_avatar_url );
				update_user_meta( $user_id, 'basic_user_avatar', $local_avatars );
			} else {
				// We don't have a local avatar, just return.
				return $args;
			}	
		}

		/**
		 * Filter the default avatar size during upload.
		 * @param $size int The default avatar size. Default 96.
		 * @param $args array The default avatar args available at the time of this filter.
		 */
		$size = apply_filters( 'basic_user_avatars_default_size', (int) $args['size'], $args );

		// Generate a new size
		if ( empty( $local_avatars[$size] ) ) {

			$upload_path      = wp_upload_dir();
			$avatar_full_path = str_replace( $upload_path['baseurl'], $upload_path['basedir'], $local_avatars['full'] );
			$image            = wp_get_image_editor( $avatar_full_path );
			$image_sized      = null;

			if ( ! is_wp_error( $image ) ) {
				$image->resize( $size, $size, true );
				$image_sized = $image->save();
			}

			// Deal with original being >= to original image (or lack of sizing ability).
			if ( empty( $image_sized ) || is_wp_error( $image_sized ) ) {
				$local_avatars[ $size ] = $local_avatars['full'];
			} else {
				$local_avatars[ $size ] = str_replace( $upload_path['basedir'], $upload_path['baseurl'], $image_sized['path'] );
			}

			// Save updated avatar sizes
			update_user_meta( $user_id, 'basic_user_avatar', $local_avatars );

		} elseif ( substr( $local_avatars[ $size ], 0, 4 ) != 'http' ) {
			$local_avatars[ $size ] = home_url( $local_avatars[ $size ] );
		}

		if ( is_ssl() ) {
			$local_avatars[ $size ] = str_replace( 'http:', 'https:', $local_avatars[ $size ] );
		}

		$user_avatar_url = $local_avatars[ $size ];

		if ( $user_avatar_url ) {
			$return_args['url'] = $user_avatar_url;
			$return_args['found_avatar'] = true;
		}

		/**
		 * Allow filtering the avatar data that we are overriding.
		 *
		 * @since 1.0.6
		 *
		 * @param array $return_args The list of user avatar data arguments.
		 */
		return apply_filters( 'basic_user_avatar_data', $return_args );
	}

	/**
	 * Add a backwards compatible hook to further filter our customized avatar HTML.
	 *
	 * @since 1.0.0
	 * 
	 * @param string $avatar      HTML for the user's avatar.
	 * @param mixed  $id_or_email The avatar to retrieve. Accepts a user_id, Gravatar MD5 hash,
	 *                            user email, WP_User object, WP_Post object, or WP_Comment object.
	 * @param int    $size        Square avatar width and height in pixels to retrieve.
	 * @param string $default     URL for the default image or a default type. Accepts '404', 'retro', 'monsterid',
	 *                            'wavatar', 'indenticon', 'mystery', 'mm', 'mysteryman', 'blank', or 'gravatar_default'.
	 * @param string $alt         Alternative text to use in the avatar image tag.
	 * @param array  $args        Arguments passed to get_avatar_data(), after processing.
	 * @return string             The filtered avatar HTML.
	 */
	public function get_avatar( $avatar, $id_or_email, $size = 96, $default = '', $alt = false, $args = array() ) {
		/**
		 * Filter to further customize the avatar HTML.
		 * 
		 * @since 1.0.0
		 * @param string $avatar HTML for the user's avatar.
		 * @param mixed  $id_or_email The avatar to retrieve. Accepts a user_id, Gravatar MD5 hash,
	 	 *                            user email, WP_User object, WP_Post object, or WP_Comment object.
	 	 * @return string The filtered avatar HTML.
		 * @deprecated since 1.0.6
		 */
		return apply_filters( 'basic_user_avatar', $avatar, $id_or_email );
	}

	/**
	 * Form to display on the user profile edit screen
	 *
	 * @since 1.0.0
	 * @param object $profileuser
	 * @return
	 */
	public function edit_user_profile( $profileuser ) {

		// bbPress will try to auto-add this to user profiles - don't let it.
		// Instead we hook our own proper function that displays cleaner.
		if ( function_exists( 'is_bbpress') && is_bbpress() )
			return;
		?>

		<h2><?php _e( 'Avatar', 'basic-user-avatars' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="basic-user-avatar"><?php esc_html_e( 'Upload Avatar', 'basic-user-avatars' ); ?></label></th>
				<td style="width: 50px;" valign="top">
					<?php echo get_avatar( $profileuser->ID ); ?>
				</td>
				<td>
				<?php
				$options = get_option( 'basic_user_avatars_caps' );
				if ( empty( $options['basic_user_avatars_caps'] ) || current_user_can( 'upload_files' ) ) {
					// Nonce security ftw
					wp_nonce_field( 'basic_user_avatar_nonce', '_basic_user_avatar_nonce', false );
					
					// File upload input
					echo '<input type="file" name="basic-user-avatar" id="basic-local-avatar" />';

					if ( empty( $profileuser->basic_user_avatar ) ) {
						echo '<p class="description">' . esc_html__( 'No local avatar is set. Use the upload field to add a local avatar.', 'basic-user-avatars' ) . '</p>';
					} else {
						echo '<p><input type="checkbox" name="basic-user-avatar-erase" id="basic-user-avatar-erase" value="1" /><label for="basic-user-avatar-erase">' . esc_html__( 'Delete local avatar', 'basic-user-avatars' ) . '</label></p>';
						echo '<p class="description">' . esc_html__( 'Replace the local avatar by uploading a new avatar, or erase the local avatar (falling back to a gravatar) by checking the delete option.', 'basic-user-avatars' ) . '</p>';
					}

				} else {
					if ( empty( $profileuser->basic_user_avatar ) ) {
						echo '<p class="description">' . esc_html__( 'No local avatar is set. Set up your avatar at Gravatar.com.', 'basic-user-avatars' ) . '</p>';
					} else {
						echo '<p class="description">' . esc_html__( 'You do not have media management permissions. To change your local avatar, contact the site administrator.', 'basic-user-avatars' ) . '</p>';
					}	
				}
				?>
				</td>
			</tr>
		</table>
		<script type="text/javascript">var form = document.getElementById('your-profile');form.encoding = 'multipart/form-data';form.setAttribute('enctype', 'multipart/form-data');</script>
		<?php
	}

	/**
	 * Update the user's avatar setting
	 *
	 * @since 1.0.0
	 * @param int $user_id
	 */
	public function edit_user_profile_update( $user_id ) {

		// Check for nonce otherwise bail
		if ( ! isset( $_POST['_basic_user_avatar_nonce'] ) || ! wp_verify_nonce( $_POST['_basic_user_avatar_nonce'], 'basic_user_avatar_nonce' ) )
			return;

		if ( ! empty( $_FILES['basic-user-avatar']['name'] ) ) {

			// Allowed file extensions/types
			$mimes = array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'gif'          => 'image/gif',
				'png'          => 'image/png',
			);

			// Front end support - shortcode, bbPress, etc
			if ( ! function_exists( 'wp_handle_upload' ) )
				require_once ABSPATH . 'wp-admin/includes/file.php';

			$this->avatar_delete( $this->user_id_being_edited );

			// Need to be more secure since low privelege users can upload
			if ( strstr( $_FILES['basic-user-avatar']['name'], '.php' ) )
				wp_die( 'For security reasons, the extension ".php" cannot be in your file name.' );

			// Make user_id known to unique_filename_callback function
			$this->user_id_being_edited = $user_id; 
			$avatar = wp_handle_upload( $_FILES['basic-user-avatar'], array( 'mimes' => $mimes, 'test_form' => false, 'unique_filename_callback' => array( $this, 'unique_filename_callback' ) ) );

			// Handle failures
			if ( empty( $avatar['file'] ) ) {  
				switch ( $avatar['error'] ) {
				case 'File type does not meet security guidelines. Try another.' :
					add_action( 'user_profile_update_errors', function( $error = 'avatar_error' ){
						esc_html__("Please upload a valid image file for the avatar.","basic-user-avatars");
					} );
					break;
				default :
					add_action( 'user_profile_update_errors', function( $error = 'avatar_error' ){
						// No error let's bail.
						if ( empty( $avatar['error'] ) ) {
							return;
						}

						"<strong>".esc_html__("There was an error uploading the avatar:","basic-user-avatars")."</strong> ". esc_attr( $avatar['error'] );
					} );
				}
				return;
			}

			// Save user information (overwriting previous)
			update_user_meta( $user_id, 'basic_user_avatar', array( 'full' => $avatar['url'] ) );

		} elseif ( ! empty( $_POST['basic-user-avatar-erase'] ) ) {
			// Nuke the current avatar
			$this->avatar_delete( $user_id );
		}
	}

	/**
	 * Enable avatar management on the frontend via this shortocde.
	 *
	 * @since 1.0.0
	 */
	function shortcode() {

		// Don't bother if the user isn't logged in
		if ( ! is_user_logged_in() )
			return;

		$user_id     = get_current_user_id();
		$profileuser = get_userdata( $user_id );

		if ( isset( $_POST['manage_avatar_submit'] ) ){
			$this->edit_user_profile_update( $user_id );
		}

		ob_start();
		?>
		<form id="basic-user-avatar-form" method="post" enctype="multipart/form-data">
			<?php
			echo get_avatar( $profileuser->ID );

			$options = get_option( 'basic_user_avatars_caps' );
			if ( empty( $options['basic_user_avatars_caps'] ) || current_user_can( 'upload_files' ) ) {
				// Nonce security ftw
				wp_nonce_field( 'basic_user_avatar_nonce', '_basic_user_avatar_nonce', false );
				
				// File upload input
				echo '<p><input type="file" name="basic-user-avatar" id="basic-local-avatar" /></p>';

				if ( empty( $profileuser->basic_user_avatar ) ) {
					echo '<p class="description">' . apply_filters( 'bu_avatars_no_avatar_set_text',esc_html__( 'No local avatar is set. Use the upload field to add a local avatar.', 'basic-user-avatars' ), $profileuser ) . '</p>';
				} else {
					echo '<p><input type="checkbox" name="basic-user-avatar-erase" id="basic-user-avatar-erase" value="1" /> <label for="basic-user-avatar-erase">' . apply_filters( 'bu_avatars_delete_avatar_text', esc_html__( 'Delete local avatar', 'basic-user-avatars' ), $profileuser ) . '</label></p>';					
					echo '<p class="description">' . apply_filters( 'bu_avatars_replace_avatar_text', esc_html__( 'Replace the local avatar by uploading a new avatar, or erase the local avatar (falling back to a gravatar) by checking the delete option.', 'basic-user-avatars' ), $profileuser ) . '</p>';
				}

				echo '<input type="submit" name="manage_avatar_submit" value="' . apply_filters( 'bu_avatars_update_button_text', esc_attr__( 'Update Avatar', 'basic-user-avatars' ) ) . '" />';

			} else {
				if ( empty( $profileuser->basic_user_avatar ) ) {
					echo '<p class="description">' . apply_filters( 'bu_avatars_no_avatar_set_text', esc_html__( 'No local avatar is set. Set up your avatar at Gravatar.com.', 'basic-user-avatars' ), $profileuser ) . '</p>';
				} else {
					echo '<p class="description">' . apply_filters( 'bu_avatars_permissions_text', esc_html__( 'You do not have media management permissions. To change your local avatar, contact the site administrator.', 'basic-user-avatars' ), $profileuser ) . '</p>';
				}	
			}
			?>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Form to display on the bbPress user profile edit screen
	 *
	 * @since 1.0.0
	 */
	public function bbpress_user_profile() {

		if ( !bbp_is_user_home_edit() )
			return;

		$user_id     = get_current_user_id();
		$profileuser = get_userdata( $user_id );

		echo '<div>';
			echo '<label for="basic-local-avatar">' . esc_html__( 'Avatar', 'basic-user-avatars' ) . '</label>';
 			echo '<fieldset class="bbp-form avatar">';

	 			echo get_avatar( $profileuser->ID );
				$options = get_option( 'basic_user_avatars_caps' );
				if ( empty( $options['basic_user_avatars_caps'] ) || current_user_can( 'upload_files' ) ) {
					// Nonce security ftw
					wp_nonce_field( 'basic_user_avatar_nonce', '_basic_user_avatar_nonce', false );
					
					// File upload input
					echo '<br /><input type="file" name="basic-user-avatar" id="basic-local-avatar" /><br />';

					if ( empty( $profileuser->basic_user_avatar ) ) {
						echo '<span class="description" style="margin-left:0;">' . apply_filters( 'bu_avatars_no_avatar_set_text', esc_html__( 'No local avatar is set. Use the upload field to add a local avatar.', 'basic-user-avatars' ), $profileuser ) . '</span>';
					} else {
						echo '<input type="checkbox" name="basic-user-avatar-erase" id="basic-user-avatar-erase" value="1" style="width:auto" /> <label for="basic-user-avatar-erase">' . apply_filters( 'bu_avatars_delete_avatar_text', __( 'Delete local avatar', 'basic-user-avatars' ), $profileuser ) . '</label><br />';
						echo '<span class="description" style="margin-left:0;">' . apply_filters( '', esc_html__( 'Replace the local avatar by uploading a new avatar, or erase the local avatar (falling back to a gravatar) by checking the delete option.', 'basic-user-avatars' ), $profileuser ) . '</span>';
					}

				} else {
					if ( empty( $profileuser->basic_user_avatar ) ) {
						echo '<span class="description" style="margin-left:0;">' . apply_filters( 'bu_avatars_no_avatar_set_text', esc_html__( 'No local avatar is set. Set up your avatar at Gravatar.com.', 'basic-user-avatars' ), $profileuser ) . '</span>';
					} else {
						echo '<span class="description" style="margin-left:0;">' . apply_filters( 'bu_avatars_permissions_text', esc_html__( 'You do not have media management permissions. To change your local avatar, contact the site administrator.', 'basic-user-avatars' ), $profileuser ) . '</span>';
					}	
				}

			echo '</fieldset>';
		echo '</div>';
		?>
		<script type="text/javascript">var form = document.getElementById('bbp-your-profile');form.encoding = 'multipart/form-data';form.setAttribute('enctype', 'multipart/form-data');</script>
		<?php
	}

	/**
	 * Remove the custom get_avatar hook for the default avatar list output on 
	 * the Discussion Settings page.
	 *
	 * @since 1.0.0
	 * @param array $avatar_defaults
	 * @return array
	 */
	public function avatar_defaults( $avatar_defaults ) {
		remove_action( 'get_avatar', array( $this, 'get_avatar' ) );
		return $avatar_defaults;
	}

	/**
	 * Delete avatars based on user_id
	 *
	 * @since 1.0.0
	 * @param int $user_id
	 */
	public function avatar_delete( $user_id ) {
		$old_avatars = get_user_meta( $user_id, 'basic_user_avatar', true );
		$upload_path = wp_upload_dir();

		if ( is_array( $old_avatars ) ) {
			foreach ( $old_avatars as $old_avatar ) {
				$old_avatar_path = str_replace( $upload_path['baseurl'], $upload_path['basedir'], $old_avatar );
				@unlink( $old_avatar_path );
			}
		}

		delete_user_meta( $user_id, 'basic_user_avatar' );
	}

	/**
	 * File names are magic
	 *
	 * @since 1.0.0
	 * @param string $dir
	 * @param string $name
	 * @param string $ext
	 * @return string
	 */
	public function unique_filename_callback( $dir, $name, $ext ) {
		$user = get_user_by( 'id', (int) $this->user_id_being_edited );
		$name = $base_name = sanitize_file_name( strtolower( $user->display_name ) . '_avatar' );

		$number = 1;

		while ( file_exists( $dir . "/$name$ext" ) ) {
			$name = $base_name . '_' . $number;
			$number++;
		}

		return $name . $ext;
	}
}
$basic_user_avatars = new basic_user_avatars;

/**
 * During uninstallation, remove the custom field from the users and delete the local avatars
 *
 * @since 1.0.0
 */
function basic_user_avatars_uninstall() {
	$basic_user_avatars = new basic_user_avatars;
	$users = get_users();

	foreach ( $users as $user )
		$basic_user_avatars->avatar_delete( $user->user_id );

	delete_option( 'basic_user_avatars_caps' );
}
register_uninstall_hook( __FILE__, 'basic_user_avatars_uninstall' );

// --- CSV Import functionality ---

if (is_admin()) {
    add_action('admin_menu', 'bua_add_admin_menu');
    add_action('admin_init', 'bua_process_csv_import');
}

function bua_add_admin_menu() {
    add_users_page(
        'Import Users CSV',
        'Import Users CSV',
        'manage_options',
        'import-users-csv',
        'bua_admin_page'
    );
}

function bua_admin_page() {
    ?>
    <div class="wrap">
        <h1>Import Users from CSV</h1>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('bua_import_csv', 'bua_import_csv_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="bua_csv_file">CSV File</label></th>
                    <td>
                        <input type="file" id="bua_csv_file" name="bua_csv_file" accept=".csv" required>
                        <p class="description">First row should be headers. Use semicolon (;) as separator.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Start Import', 'primary', 'bua_import_csv'); ?>
        </form>
        <h3>CSV Format Requirements:</h3>
        <ul>
            <li><strong>user_email</strong> - Required for identification</li>
            <li><strong>user_login</strong> - Optional (will be generated if empty)</li>
            <li><strong>user_role</strong> - subscriber, contributor, author, editor (default: subscriber)</li>
            <li><strong>user_name</strong> - First name</li>
            <li><strong>user_last_name</strong> - Last name</li>
            <li><strong>user_nickname</strong> - Optional (will be generated if empty)</li>
            <li><strong>user_description</strong> - About section</li>
            <li><strong>user_profile_picture</strong> - URL to profile image</li>
            <li><strong>user_url</strong> - Website URL</li>
            <li><strong>user_facebook</strong> - Facebook profile</li>
            <li><strong>user_vkontakte</strong> - VKontakte profile</li>
            <li><strong>user_twitter</strong> - Twitter profile</li>
            <li><strong>user_telegram</strong> - Telegram profile</li>
            <li><strong>user_youtube</strong> - YouTube profile</li>
            <li><strong>user_instagram</strong> - Instagram profile</li>
            <li><strong>user_tiktok</strong> - TikTok profile</li>
            <li><strong>user_linkedin</strong> - LinkedIn profile</li>
            <li><strong>user_pinterest</strong> - Pinterest profile</li>
        </ul>
    </div>
    <?php
}

function bua_process_csv_import() {
    if (!isset($_POST['bua_import_csv']) || !wp_verify_nonce($_POST['bua_import_csv_nonce'], 'bua_import_csv')) return;
    if (!current_user_can('manage_options')) wp_die('You do not have sufficient permissions to access this page.');

    if (!isset($_FILES['bua_csv_file']) || $_FILES['bua_csv_file']['error'] !== UPLOAD_ERR_OK) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>Error uploading file. Please try again.</p></div>';
        });
        return;
    }

    $results = bua_import_csv_file($_FILES['bua_csv_file']['tmp_name']);
    add_action('admin_notices', function() use ($results) {
        $class = $results['errors'] > 0 ? 'notice-warning' : 'notice-success';
        echo '<div class="notice ' . $class . '"><p>';
        echo sprintf('Import completed: %d users imported, %d users updated, %d errors.', $results['imported'], $results['updated'], $results['errors']);
        echo '</p></div>';
        if (!empty($results['error_details'])) {
            echo '<div class="notice notice-error"><ul>';
            foreach ($results['error_details'] as $error) echo '<li>' . esc_html($error) . '</li>';
            echo '</ul></div>';
        }
    });
}

function bua_import_csv_file($file_path) {
    $results = array('imported' => 0, 'updated' => 0, 'errors' => 0, 'error_details' => array());
    if (($handle = fopen($file_path, 'r')) !== FALSE) {
        $header = fgetcsv($handle, 0, ';');
        if (!$header) {
            $results['errors']++;
            $results['error_details'][] = 'Could not read CSV header row';
            fclose($handle);
            return $results;
        }
        $row_number = 1;
        while (($row = fgetcsv($handle, 0, ';')) !== FALSE) {
            $row_number++;
            $user_data = array_combine($header, $row);
            if ($user_data === FALSE) {
                $results['errors']++;
                $results['error_details'][] = "Row $row_number: Could not parse data";
                continue;
            }
            $result = bua_process_user_data($user_data, $row_number);
            if ($result['success']) {
                if ($result['action'] === 'imported') $results['imported']++;
                else $results['updated']++;
            } else {
                $results['errors']++;
                $results['error_details'][] = "Row $row_number: " . $result['error'];
            }
        }
        fclose($handle);
    } else {
        $results['errors']++;
        $results['error_details'][] = 'Could not open CSV file';
    }
    return $results;
}

function bua_process_user_data($user_data, $row_number) {
    if (empty($user_data['user_email']) || !is_email($user_data['user_email'])) {
        return array('success' => false, 'error' => 'Invalid or missing email address');
    }
    $email = sanitize_email($user_data['user_email']);
    $existing_user_id = email_exists($email);
    if ($existing_user_id) {
        return bua_update_existing_user($existing_user_id, $user_data);
    } else {
        return bua_create_new_user($user_data);
    }
}

function bua_create_new_user($user_data) {
    $user_args = array();
    $user_args['user_email'] = sanitize_email($user_data['user_email']);
    $user_args['user_login'] = !empty($user_data['user_login']) ? sanitize_user($user_data['user_login']) : bua_generate_username($user_data);
    $original_login = $user_args['user_login'];
    $counter = 1;
    while (username_exists($user_args['user_login'])) {
        $user_args['user_login'] = $original_login . $counter;
        $counter++;
    }
    $user_args['role'] = bua_get_valid_user_role($user_data);
    if (!empty($user_data['user_name'])) $user_args['first_name'] = sanitize_text_field($user_data['user_name']);
    if (!empty($user_data['user_last_name'])) $user_args['last_name'] = sanitize_text_field($user_data['user_last_name']);
    if (!empty($user_args['first_name']) || !empty($user_args['last_name'])) $user_args['display_name'] = trim($user_args['first_name'] . ' ' . $user_args['last_name']);
    $user_args['nickname'] = !empty($user_data['user_nickname']) ? sanitize_text_field($user_data['user_nickname']) : bua_generate_nickname($user_data);
    if (!empty($user_data['user_description'])) $user_args['description'] = mb_substr(sanitize_textarea_field($user_data['user_description']), 0, 250);
    if (!empty($user_data['user_url'])) $user_args['user_url'] = esc_url_raw($user_data['user_url']);
    $user_args['user_pass'] = wp_generate_password(12, false);
    $user_id = wp_insert_user($user_args);
    if (is_wp_error($user_id)) return array('success' => false, 'error' => $user_id->get_error_message());
    bua_update_user_social_meta($user_id, $user_data);
    if (!empty($user_data['user_profile_picture'])) {
        bua_set_user_avatar($user_id, $user_data['user_profile_picture']);
    }
    return array('success' => true, 'action' => 'imported');
}

function bua_update_existing_user($user_id, $user_data) {
    $user = get_userdata($user_id);
    $user_args = array('ID' => $user_id);
    $updated = false;
    if (empty($user->first_name) && !empty($user_data['user_name'])) {
        $user_args['first_name'] = sanitize_text_field($user_data['user_name']);
        $updated = true;
    }
    if (empty($user->last_name) && !empty($user_data['user_last_name'])) {
        $user_args['last_name'] = sanitize_text_field($user_data['user_last_name']);
        $updated = true;
    }
    if (isset($user_args['first_name']) || isset($user_args['last_name'])) {
        $first_name = isset($user_args['first_name']) ? $user_args['first_name'] : $user->first_name;
        $last_name = isset($user_args['last_name']) ? $user_args['last_name'] : $user->last_name;
        if (!empty($first_name) || !empty($last_name)) {
            $user_args['display_name'] = trim($first_name . ' ' . $last_name);
        }
    }
    if (empty($user->nickname) && !empty($user_data['user_nickname'])) {
        $user_args['nickname'] = sanitize_text_field($user_data['user_nickname']);
        $updated = true;
    } elseif (empty($user->nickname) && (isset($user_args['first_name']) || isset($user_args['last_name']))) {
        $user_args['nickname'] = bua_generate_nickname($user_data);
        $updated = true;
    }
    if (empty($user->description) && !empty($user_data['user_description'])) {
        $user_args['description'] = mb_substr(sanitize_textarea_field($user_data['user_description']), 0, 250);
        $updated = true;
    }
    if (empty($user->user_url) && !empty($user_data['user_url'])) {
        $user_args['user_url'] = esc_url_raw($user_data['user_url']);
        $updated = true;
    }
    if ($updated) {
        $result = wp_update_user($user_args);
        if (is_wp_error($result)) return array('success' => false, 'error' => $result->get_error_message());
    }
    $meta_updated = bua_update_user_social_meta($user_id, $user_data, true);
    $current_avatar = get_user_meta($user_id, 'basic_user_avatar', true);
    if (empty($current_avatar) && !empty($user_data['user_profile_picture'])) {
        bua_set_user_avatar($user_id, $user_data['user_profile_picture']);
        $updated = true;
    }
    return array('success' => true, 'action' => ($updated || $meta_updated) ? 'updated' : 'skipped');
}

function bua_update_user_social_meta($user_id, $user_data, $only_empty = false) {
    $social_fields = array(
        'user_facebook' => 'facebook',
        'user_vkontakte' => 'vkontakte',
        'user_twitter' => 'twitter',
        'user_telegram' => 'telegram',
        'user_youtube' => 'youtube',
        'user_instagram' => 'instagram',
        'user_tiktok' => 'tiktok',
        'user_linkedin' => 'linkedin',
        'user_pinterest' => 'pinterest'
    );
    $updated = false;
    foreach ($social_fields as $csv_field => $meta_key) {
        if (!empty($user_data[$csv_field])) {
            $current_value = get_user_meta($user_id, $meta_key, true);
            if (!$only_empty || empty($current_value)) {
                update_user_meta($user_id, $meta_key, esc_url_raw($user_data[$csv_field]));
                $updated = true;
            }
        }
    }
    return $updated;
}

// --- 5. Transliteration and image processing functions ---

function bua_generate_username($user_data) {
    $username = '';
    if (!empty($user_data['user_name'])) $username .= bua_transliterate($user_data['user_name']);
    if (!empty($user_data['user_last_name'])) {
        if (!empty($username)) $username .= '-';
        $username .= bua_transliterate($user_data['user_last_name']);
    }
    if (empty($username) && !empty($user_data['user_email'])) $username = explode('@', $user_data['user_email'])[0];
    $username = sanitize_user($username, true);
    if (empty($username)) $username = 'user' . time();
    return strtolower($username);
}

function bua_generate_nickname($user_data) {
    $nickname = '';
    if (!empty($user_data['user_name'])) $nickname .= bua_transliterate($user_data['user_name']);
    if (!empty($user_data['user_last_name'])) {
        if (!empty($nickname)) $nickname .= '-';
        $nickname .= bua_transliterate($user_data['user_last_name']);
    }
    return !empty($nickname) ? strtolower($nickname) : 'user';
}

function bua_get_valid_user_role($user_data) {
    $valid_roles = array('subscriber', 'contributor', 'author', 'editor');
    $role = 'subscriber';
    if (!empty($user_data['user_role'])) {
        $csv_role = strtolower(trim($user_data['user_role']));
        if (in_array($csv_role, $valid_roles)) $role = $csv_role;
    }
    return $role;
}

function bua_transliterate($text) {
    $cyrillic = array(
        'а','б','в','г','д','е','ё','ж','з','и','й','к','л','м','н','о','п',
        'р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ы','ь','э','ю','я',
        'А','Б','В','Г','Д','Е','Ё','Ж','З','И','Й','К','Л','М','Н','О','П',
        'Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ы','Ь','Э','Ю','Я'
    );
    $latin = array(
        'a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p',
        'r','s','t','u','f','h','ts','ch','sh','sch','','y','','e','yu','ya',
        'A','B','V','G','D','E','Yo','Zh','Z','I','Y','K','L','M','N','O','P',
        'R','S','T','U','F','H','Ts','Ch','Sh','Sch','','Y','','E','Yu','Ya'
    );
    $text = str_replace($cyrillic, $latin, $text);
    $text = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $text);
    $text = preg_replace('/\s+/', '-', trim($text));
    $text = preg_replace('/-+/', '-', $text);
    return $text;
}

function bua_set_user_avatar($user_id, $image_url) {
    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) return;
    $file_array = array(
        'name'     => basename($image_url),
        'tmp_name' => $tmp
    );
    if (!function_exists('media_handle_sideload')) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
    }
    $id = media_handle_sideload($file_array, 0);
    if (is_wp_error($id)) {
        @unlink($tmp);
        return;
    }
    $url = wp_get_attachment_url($id);
    if ($url) {
        update_user_meta($user_id, 'basic_user_avatar', array('full' => $url));
    }
}



register_activation_hook(__FILE__, function() {});
register_deactivation_hook(__FILE__, function() {});

?>
