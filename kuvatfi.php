<?php

defined( 'ABSPATH' ) or die( 'get out' );

/**
 * Plugin Name: Kuvat.fi picker
 * Description: Adds a tab in the WordPress media picker to access your Kuvat.fi galleries. Requires a Kuvat.fi API Key.
 * Version: 1.0.8
 * Author: Mediadrive
 * Author URI: https://kuvat.fi
 */

function kuvatfi_register_settings() {
	register_setting(
		'kuvatfi_options',
		'kuvatfi_option_apikey',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => null,
		)
	);
}

add_action( 'admin_init', 'kuvatfi_register_settings' );

function kuvatfi_options() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}

	if ( ! ini_get( 'allow_url_fopen' ) ) {
		wp_die( "This plugin requires the PHP setting 'allow_url_fopen' to be enabled in order to work correctly, but it is currently disabled. Please contact your hosting provider if you can't enable it yourself." );
	}

	?>
	
	<div class="wrap">
		<h1>Kuvat.fi Media Picker's Settings</h1>
		
		<?php
		if ( kuvatfi_session() ) {
			if ( ( $res = kuvatfi_getJSON(
				kuvatfi_endpoint(),
				array(
					'type' => 'getUserInformation',
				)
			) ) && ! empty( $res['status'] ) && $user = @$res['message'] ) {
				?>
				<h2 class="title">Linked with Kuvat.fi</h2>
				
				<?php

					$site = false;

				if ( $sites = @$user['ownsites'] ) {
					$def = $user['config']['profiili']['oletustunnus'];

					if ( $tunnus = $sites[ array_search( $def, array_column( $sites, 'tunnus' ) ) ] ) {
						$site = @$tunnus['fqdn'];
					}
				}

				if ( empty( $site ) ) {
					kuvatfi_logout();

					echo '<meta http-equiv="refresh" content="0" />';

					die;
				}

				?>
				
				<p>This plugin is linked with the Kuvat.fi account <b><?php echo @$site; ?></b>.</p>
				
				<form method="post" action="options.php">
					<?php

						settings_fields( 'kuvatfi_options' );
						do_settings_sections( 'kuvatfi_options' );

					?>
					
					<input type="hidden" name="kuvatfi_option_apikey" value="logout" />
					
					<?php submit_button( 'Log Out and Unlink' ); ?>
				</form>
				<?php
			} else {
				kuvatfi_logout();

				echo '<meta http-equiv="refresh" content="0" />';

				die;
			}
		} else {
			?>
			<h2 class="title">Plugin is not linked</h2>
			
			<p>Please enter your <a href="https://tuki.kuvat.fi/artikkelit/kuvien-haku-wordpressiin-valitsin-lisaosalla/" target="_blank">Kuvat.fi API Key</a> to link this WordPress plugin with your Kuvat.fi galleries.</p>
			
			<hr />
			
			<form method="post" action="options.php">
				<?php

					settings_fields( 'kuvatfi_options' );
					do_settings_sections( 'kuvatfi_options' );

				?>
				
				<h2 class="title">Settings</h2>
				
				<table class="form-table">
					<tr valign="top">
						<th scope="row">API Key</th>
						<td><input type="text" name="kuvatfi_option_apikey" value="<?php echo esc_attr( get_option( 'kuvatfi_option_apikey' ) ); ?>" /></td>
					</tr>
				</table>
				
				<?php submit_button(); ?>
			</form>
		<?php } ?>
	</div>
	
	<?php
}

function kuvatfi_menu() {
	add_options_page( "Kuvat.fi Media Picker's Settings", 'Kuvat.fi', 'manage_options', 'kuvatfi-picker', 'kuvatfi_options' );
}

add_action( 'admin_menu', 'kuvatfi_menu' );

add_action(
	'update_option',
	function( $opt ) {
		if ( $opt == 'kuvatfi_option_apikey' ) {
			kuvatfi_logout();
		}
	},
	10,
	3
);

add_action(
	'updated_option',
	function( $opt ) {
		if ( $opt == 'kuvatfi_option_apikey' ) {
			kuvatfi_session();
		}
	},
	10,
	3
);

function kuvatfi_action_links( $links ) {
	return array_merge(
		$links,
		array(
			'<a href="' . admin_url( 'options-general.php?page=kuvatfi-picker' ) . '">Settings</a>',
		)
	);
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'kuvatfi_action_links' );

function kuvatfi_session() {
	if ( current_user_can( 'upload_files' ) ) {
		if ( ( $sid = get_transient( 'kuvatfi_sid' ) ) && strlen( $sid ) > 60 ) {
			if ( ( $uid = get_transient( 'kuvatfi_uid' ) ) && is_numeric( $uid ) && $uid > 0 ) {
				if ( get_transient( 'kuvatfi_validated' ) ) {
					return $sid;
				} elseif ( ( $res = kuvatfi_getJSON(
					kuvatfi_endpoint( 'session' ),
					array(
						'action'      => 'validateSessionId',
						'usersession' => '',
						'sid'         => $sid,
						'uid'         => $uid,
					)
				) ) && ! empty( $res['status'] ) ) {
					set_transient( 'kuvatfi_validated', true, ( 60 * 60 ) );

					return $sid;
				} elseif ( kuvatfi_login( $sid ) ) {
					return $sid;
				}
			} elseif ( kuvatfi_login( $sid ) ) {
				return $sid;
			}
		} else {
			if ( ( $res = kuvatfi_getJSON(
				kuvatfi_endpoint( 'session' ),
				array(
					'action'      => 'getSessionId',
					'usersession' => '',
				)
			) ) && $sid = @$res['sid'] ) {
				if ( kuvatfi_login( $sid ) ) {
					set_transient( 'kuvatfi_sid', $sid );

					return $sid;
				}
			}
		}

		kuvatfi_logout();
	}

	return false;
}

function kuvatfi_login( $sid ) {
	if ( current_user_can( 'upload_files' ) ) {
		if ( ! empty( $sid ) ) {
			if ( $key = get_option( 'kuvatfi_option_apikey' ) ) {
				if ( ( $res = kuvatfi_getJSON(
					kuvatfi_endpoint( 'session' ),
					array(
						'action'      => 'loginSessionWithToken',
						'usersession' => '',
						'sid'         => $sid,
						'token'       => $key,
					)
				) ) && ! empty( $res['status'] ) && $uid = @$res['user_id'] ) {
					if ( is_numeric( $uid ) ) {
						$uid = intval( $uid );

						if ( $uid > 0 ) {
							set_transient( 'kuvatfi_uid', $uid );

							return true;
						}
					}
				}
			}
		}
	}

	kuvatfi_logout();

	return false;
}

function kuvatfi_logout() {
	delete_transient( 'kuvatfi_lastgallery' );
	delete_transient( 'kuvatfi_foldertree' );
	delete_transient( 'kuvatfi_validated' );
	delete_transient( 'kuvatfi_sid' );
	delete_transient( 'kuvatfi_uid' );

	delete_option( 'kuvatfi_option_apikey' );

	return true;
}

register_deactivation_hook( __FILE__, 'kuvatfi_logout' );
register_uninstall_hook( __FILE__, 'kuvatfi_logout' );

function kuvatfi_enqueues( $hook ) {
	if ( current_user_can( 'upload_files' ) ) {
		if ( kuvatfi_session() ) {
			wp_enqueue_media();

			wp_register_style( 'kuvatfi_picker_css', plugin_dir_url( __FILE__ ) . 'kuvatfi.css' );
			wp_enqueue_style( 'kuvatfi_picker_css' );

			wp_enqueue_script( 'kuvatfi_picker_js', plugin_dir_url( __FILE__ ) . 'kuvatfi.js', array( 'jquery' ) );

			$last = get_transient( 'kuvatfi_lastgallery' );

			$args = array(
				'lastgallery' => ( $last ? $last : '' ),
			);

			wp_localize_script( 'kuvatfi_picker_js', 'kuvatfi', $args );
		}
	}
}

add_action( 'admin_enqueue_scripts', 'kuvatfi_enqueues', 1 );

function kuvatfi_endpoint( $sub = '' ) {
	return 'https://' . ( ! empty( $sub ) ? $sub . '.' : '' ) . 'kuvat.fi/';
}

function kuvatfi_getParams( $params = array() ) {
	if ( current_user_can( 'upload_files' ) ) {
		$params = array_merge(
			$params,
			array(
				'application'  => 'wppicker',
				'version'      => '1.0.8',
				'sourcedomain' => $_SERVER['HTTP_HOST'],
			)
		);

		if ( ! isset( $params['usersession'] ) ) {
			if ( $sid = kuvatfi_session() ) {
				$params['usersession'] = $sid;
			}
		}

		return $params;
	}

	return array();
}

function kuvatfi_getJSON( $url, $params = array() ) {
	if ( current_user_can( 'upload_files' ) ) {
		$u = parse_url( $url );

		$res = array();

		if ( $q = @$u['query'] ) {
			parse_str( $q, $res );
		}

		$u['query'] = http_build_query( array_merge( $res, kuvatfi_getParams( $params ) ) );
		$url        = kuvatfi_build_url( $u );

		if ( $json = file_get_contents( $url ) ) {
			if ( $data = json_decode( $json, true ) ) {
				return $data;
			}
		}
	}

	return false;
}

function kuvatfi_folders() {
	if ( current_user_can( 'upload_files' ) ) {
		if ( $data = kuvatfi_getJSON(
			kuvatfi_endpoint(),
			array(
				'type' => 'galleryList',
			)
		) ) {
			if ( ( $cachedFolders = get_transient( 'kuvatfi_foldertree' ) ) && ! empty( $cachedFolders ) ) {
				$foldersForGallery = $cachedFolders;
			} else {
				$foldersForGallery = array();

				foreach ( $data as $gallery ) {
					if ( @$gallery['type'] == 'admin' ) {
						$url = $gallery['url'];

						if ( $folders = kuvatfi_getJSON(
							kuvatfi_endpoint(),
							array(
								'type' => 'getFolderTree',
								'host' => $url,
							)
						) ) {
							$foldersForGallery[ $url ] = $folders;
						}
					}
				}

				set_transient( 'kuvatfi_foldertree', $foldersForGallery, ( 60 * 15 ) );
			}

			if ( ! empty( $foldersForGallery ) ) {
				wp_send_json_success( $foldersForGallery );
			} else {
				wp_send_json_error( 'ERR_NO_GALLERIES' );
			}

			return;
		}

		wp_send_json_error( 'ERR_NO_GALLERIES' );

		return;
	}

	wp_send_json_error();
}

add_action( 'wp_ajax_kuvatfi_folders', 'kuvatfi_folders' );

function kuvatfi_flush() {
	if ( current_user_can( 'upload_files' ) ) {
		delete_transient( 'kuvatfi_foldertree' );

		wp_send_json_success();

		return;
	}

	wp_send_json_error();
}

add_action( 'wp_ajax_kuvatfi_flush', 'kuvatfi_flush' );

function kuvatfi_get_id( $host, $id ) {
	if ( ! empty( $host ) && ! empty( $id ) && is_numeric( $id ) ) {
		if ( ( $filesById = kuvatfi_getJSON(
			kuvatfi_endpoint(),
			array(
				'type' => 'getFilesById',
				'ids'  => array( $id ),
				'host' => $host,
			)
		) ) && ! empty( $filesById['status'] ) ) {
			if ( ( $folder = $filesById['message'][ $id ]['folder'] ) && ( $fl = kuvatfi_getJSON(
				kuvatfi_endpoint(),
				array(
					'type'   => 'getFileListJSON',
					'folder' => $folder,
					'host'   => $host,
				)
			) ) ) {
				$i = array_search( $id, array_column( $fl, 'id' ) );

				if ( is_numeric( $i ) && ( $img = @$fl[ $i ] ) ) {
					return $img;
				}
			}
		}
	}

	return false;
}

function kuvatfi_dl_id( $host, $id ) {
	if ( $img = kuvatfi_get_id( $host, $id ) ) {
		if ( ( $t = kuvatfi_getJSON(
			kuvatfi_endpoint(),
			array(
				'type'     => 'downloadFiles',
				'getToken' => 1,
				'ids'      => array( $id ),
				'size'     => 'original',
				'host'     => $host,
			)
		) ) && ( $token = $t['token'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			$tempfile = download_url( kuvatfi_endpoint( 'dl' ) . "?token={$token}", 10 );

			if ( is_wp_error( $tempfile ) ) {
				return false;
			}

			$path = pathinfo( $img['filepath'] );

			$name = $path['basename'];
			$mime = wp_check_filetype( $name )['type'];

			$file = array(
				'error'    => 0,
				'name'     => $name,
				'type'     => $mime,
				'tmp_name' => $tempfile,
				'size'     => filesize( $tempfile ),
			);

			$overrides = array(

				'test_form'   => false,

				'test_size'   => true,

				'test_upload' => true,
			);

			$attrs = wp_handle_sideload( $file, $overrides );

			if ( isset( $attrs['error'] ) ) {
				return false;
			}

			if ( $attid = wp_insert_attachment(
				array(
					'guid'           => wp_upload_dir()['url'] . '/' . basename( $attrs['file'] ),
					'post_mime_type' => $mime,
					'post_title'     => $path['filename'],
					'post_content'   => '',
					'post_status'    => 'inherit',
				),
				$attrs['file']
			) ) {
				if ( $attpath = get_attached_file( $attid ) ) {
					require_once ABSPATH . 'wp-admin/includes/image.php';

					$attdata = wp_generate_attachment_metadata( $attid, $attpath );
					wp_update_attachment_metadata( $attid, $attdata );

					$update = array();

					if ( $caption = @$img['kuvaus'] ) {
						$update['post_excerpt'] = $caption;
					}

					if ( $tags = @$img['tagit'] ) {
						$update['post_content'] = implode( ', ', explode( ',', $tags ) );
					}

					if ( ! empty( $update ) ) {
						$update['ID'] = $attid;

						wp_update_post( $update );
					}

					update_post_meta( $attid, '_wp_attachment_image_alt', $path['filename'] );

					return $attid;
				}
			}
		}
	}

	return false;
}

function kuvatfi_dl() {
	if ( current_user_can( 'upload_files' ) ) {
		$attachments = array();

		if ( ( $host = @$_REQUEST['host'] ) && ( $ids = @$_REQUEST['ids'] ) ) {
			foreach ( $ids as $id ) {
				if ( ! empty( $id ) && is_numeric( $id ) ) {
					if ( $attid = kuvatfi_dl_id( $host, intval( $id ) ) ) {
						$attachments[] = $attid;
					}
				}
			}
		}

		if ( ! empty( $attachments ) ) {
			set_transient( 'kuvatfi_lastgallery', $host );

			wp_send_json_success( $attachments );
		} else {
			wp_send_json_error( 'ERR_NOTHING_DLED' );
		}
	}

	wp_send_json_error();
}

add_action( 'wp_ajax_kuvatfi_dl', 'kuvatfi_dl' );

function kuvatfi_getThumbUrl( $img, $size ) {
	if ( ! empty( $img ) && ! empty( $size ) ) {
		if ( ( $hash = @$img['url']['hash'] ) && ( $ts = @$img['url']['ts'] ) && ( $sizes = @$img['url']['sizes'] ) && in_array( $size, $sizes ) ) {
			return "https://{$ts}/tt/?key={$hash}&size=$size";
		}
	}

	return false;
}

function kuvatfi_thumb_url( $host, $id ) {
	if ( ! empty( $host ) && is_numeric( $id ) && $id > 0 ) {
		if ( kuvatfi_session() ) {
			$id = intval( $id );

			if ( $img = kuvatfi_get_id( $host, $id ) ) {
				$size = 'sqr480';

				$url = kuvatfi_getThumbUrl( $img, $size );

				if ( empty( $url ) ) {
					$url = 'https://' . $host . str_replace( ' ', '+', $img['filepath'] ) . '?' . http_build_query( kuvatfi_getParams() ) . "&img={$size}";
				}

				return $url;
			}
		}
	}

	return false;
}

function kuvatfi_get_thumb() {
	if ( current_user_can( 'upload_files' ) ) {
		if ( ( $host = @$_REQUEST['host'] ) && ( $id = @$_REQUEST['id'] ) ) {
			if ( $url = kuvatfi_thumb_url( $host, $id ) ) {
				header( 'Content-Type: image/jpeg' );

				echo file_get_contents( $url );

				die;
			}
		}

		header( 'HTTP/1.0 404 Not Found' );
	}

	header( 'HTTP/1.0 401 Unauthorized' );

	die;
}

add_action( 'wp_ajax_kuvatfi_get_thumb', 'kuvatfi_get_thumb' );

function kuvatfi_taxonomies() {
	if ( current_user_can( 'upload_files' ) ) {
		foreach ( array(
			'kuvatfi_host',
			'kuvatfi_folder',
		) as $t ) {
			register_taxonomy(
				$t,
				array( 'attachment' ),
				array(
					'labels'            => false,
					'hierarchical'      => false,
					'public'            => false,
					'show_ui'           => false,
					'show_admin_column' => false,
					'show_in_nav_menus' => false,
					'show_tagcloud'     => false,
				)
			);

			register_taxonomy_for_object_type( $t, 'attachment' );
		}
	}
}

add_action( 'init', 'kuvatfi_taxonomies', 0 );

function kuvatfi_attachments( $query = array() ) {
	if ( current_user_can( 'upload_files' ) ) {
		if ( isset( $query['kuvatfi_host'] ) && isset( $query['kuvatfi_folder'] ) ) {
			if ( ( $host = $query['kuvatfi_host'] ) && ( $folder = $query['kuvatfi_folder'] ) ) {
				if ( $sid = kuvatfi_session() ) {
					$imgs = array();

					if ( $data = kuvatfi_getJSON(
						kuvatfi_endpoint(),
						array(
							'type'   => 'getFileListJSON',
							'folder' => $folder,
							'host'   => $host,
						)
					) ) {
						foreach ( $data as $img ) {
							if ( @$img['type'] == 'image' ) {
								$id = intval( $img['id'] );

								$width       = intval( @$img['i_x'] ) ?: 100;
								$height      = intval( @$img['i_y'] ) ?: 100;
								$orientation = ( $width == $height ? 'square' : ( $width > $height ? 'landscape' : 'portrait' ) );

								$url = kuvatfi_getThumbUrl( $img, 'sqr480' );

								if ( empty( $url ) ) {
									$url = admin_url( 'admin-ajax.php' ) . "?action=kuvatfi_get_thumb&host={$host}&id={$id}";
								}

								$path = pathinfo( $img['filepath'] );

								if ( in_array( strtolower( $path['extension'] ), array( 'jpg', 'jpeg', 'png', 'gif' ) ) ) {
									$imgs[] = array(
										'type'        => 'image',
										'id'          => $id,
										'host'        => $host,
										'title'       => $path['filename'],
										'url'         => "https://{$host}" . $img['filepath'],
										'link'        => "https://{$host}" . $img['filepath'],
										'width'       => $width,
										'height'      => $height,
										'orientation' => $orientation,
										'sizes'       => array(
											'full' => array(
												'url'    => $url,
												'width'  => $width,
												'height' => $height,
												'orientation' => $orientation,
											),
										),
									);
								}
							}
						}
					}

					wp_send_json_success( $imgs );
					die;
				} else {
					wp_send_json_success( array() );
					die;
				}
			} else {
				wp_send_json_success( array() );
				die;
			}
		}
	}

	return $query;
}

add_filter( 'ajax_query_attachments_args', 'kuvatfi_attachments', 10, 1 );

function kuvatfi_build_url( array $parts ) {
	return ( isset( $parts['scheme'] ) ? "{$parts['scheme']}:" : '' ) .
		( ( isset( $parts['user'] ) || isset( $parts['host'] ) ) ? '//' : '' ) .
		( isset( $parts['user'] ) ? "{$parts['user']}" : '' ) .
		( isset( $parts['pass'] ) ? ":{$parts['pass']}" : '' ) .
		( isset( $parts['user'] ) ? '@' : '' ) .
		( isset( $parts['host'] ) ? "{$parts['host']}" : '' ) .
		( isset( $parts['port'] ) ? ":{$parts['port']}" : '' ) .
		( isset( $parts['path'] ) ? "{$parts['path']}" : '' ) .
		( isset( $parts['query'] ) ? "?{$parts['query']}" : '' ) .
		( isset( $parts['fragment'] ) ? "#{$parts['fragment']}" : '' );
}

?>
