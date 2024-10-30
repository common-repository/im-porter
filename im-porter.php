<?php

/*
Plugin Name: IM-porter
Description: Import chat transcripts into WordPress.
Author: cfinke
Version: 1.0.1
License: GPLv2 or later
*/

if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
	return;

define( 'IMPORT_DEBUG', true );

require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';

	if ( file_exists( $class_wp_importer ) )
		require $class_wp_importer;
}

/**
 * Chat Importer imports chat transcripts into WordPress.
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
	class Chat_IMporter_Import extends WP_Importer {
		var $posts = array();

		var $id = null;
		var $author = null;
		var $status = null;
		var $autotag = false;
		var $categories = null;

		var $file_count = 0;

		public function register_format( $format_class ) {
			$this->formats[] = $format_class;
		}

		public function dispatch() {
			$this->header();

			$step = empty( $_GET['step'] ) ? 0 : intval( $_GET['step'] );

			switch ( $step ) {
				case 0:
					$this->greet();
				break;
				case 1:
					check_admin_referer( 'import-upload' );

					if ( $this->handle_upload() )
						$this->import_options();
				break;
				case 2:
					check_admin_referer( 'import-chats' );
					$this->id = intval( $_POST['import_id'] );
					$this->author = (int) $_POST['author'];
					$this->status = $_POST['status'] == 'private' ? 'private' : 'public';
					$this->autotag = isset( $_POST['autotag'] );
					$this->categories = isset( $_POST['cat'] ) ? array( (int) $_POST['cat'] ) : array();

					set_time_limit( 0 );
					$this->import();
				break;
			}

			$this->footer();
		}

		function handle_upload() {
			$original_filename = $_FILES['import']['name'];

			$file = wp_import_handle_upload();

			if ( isset( $file['error'] ) ) {
				echo '<p><strong>' . __( 'Sorry, there has been an error.', 'chat-importer' ) . '</strong><br />';
				echo esc_html( $file['error'] ) . '</p>';
				return false;
			} else if ( ! file_exists( $file['file'] ) ) {
				echo '<p><strong>' . __( 'Sorry, there has been an error.', 'chat-importer' ) . '</strong><br />';
				printf( __( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'chat-importer' ), esc_html( $file['file'] ) );
				echo '</p>';
				return false;
			}

			$this->id = (int) $file['id'];

			update_post_meta( $this->id, 'original_filename', $original_filename );

			return true;
		}

		function import_options() {
			$j = 0;

			?>
			<form action="<?php echo admin_url( 'admin.php?import=chats&step=2' ); ?>" method="post">
				<?php wp_nonce_field( 'import-chats' ); ?>
				<input type="hidden" name="import_id" value="<?php echo $this->id; ?>" />

				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label><?php esc_html_e( 'Set post statuses as...', 'chat-importer' ); ?></label>
							</th>
							<td>
								<label>
									<input name="status" type="radio" value="private" checked="checked" />
									<?php esc_html_e( 'Private', 'chat-importer' ); ?>
								</label>
								<label>
									<input name="status" type="radio" value="public" />
									<?php esc_html_e( 'Public', 'chat-importer' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="chat-importer-autotag"><?php esc_html_e( 'Tag posts with participant usernames', 'chat-importer' ); ?></label>
							</th>
							<td>
								<input name="autotag" type="checkbox" value="1" id="chat-importer-autotag" checked="checked" />
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label for="category"><?php esc_html_e( 'Import posts into Category', 'chat-importer' ) ?></label>
							</th>
							<td>
								<?php wp_dropdown_categories( array(
									'hide_empty' => false,
									'id' => 'category',
									'hierarchical' => true,
									) ); ?>
								(<a href="edit-tags.php?taxonomy=category"><?php _e( 'Add New Category', 'chat-importer' ); ?></a>)
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<?php esc_html_e( 'Assign posts to:', 'chat-importer' ); ?>
							</th>
							<td>
								<?php wp_dropdown_users( array( 'name' => 'author', 'selected' => get_current_user_id() ) ); ?>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit"><input type="submit" class="button-primary" value="<?php esc_attr_e( 'Import Transcripts', 'chat-importer' ); ?>" /></p>
			</form>
			<?php
		}

		private function import() {
			$file_path = get_attached_file( $this->id );

			$original_filename = get_post_meta( $this->id, 'original_filename', true );

			if ( substr( $original_filename, -4, 4 ) == '.zip' && function_exists( 'zip_open' ) ) {
				if ( $zip_handle = zip_open( $file_path ) ) {
					while ( $zip_entry = zip_read( $zip_handle ) ) {
						$filepath = zip_entry_name( $zip_entry );
						$filename = basename( $filepath );

						if ( $filename[0] == '_' || $filename[0] == '.' )
							continue;

						$filesize = zip_entry_filesize( $zip_entry );

						if ( $filesize > 0 && zip_entry_open( $zip_handle, $zip_entry, "r" ) ) {
							$before_post_count = count( $this->posts );
							$this->import_file(
								$filename,
								zip_entry_read( $zip_entry, $filesize )
							);
							$after_post_count = count( $this->posts );

							if ( $after_post_count == $before_post_count )
								echo '<p>' . esc_html( sprintf( _x( 'No posts imported from %s.', 'Placeholder is a filename.', 'chat-importer' ), $filename ) ) . '</p>';

							++$this->file_count;
						}
					}

					zip_close( $zip_handle );
				}
			}
			else {
				$this->file_count = 1;
				$this->import_file( $original_filename, file_get_contents( $file_path ) );
			}

			$this->process_posts();
			$this->import_end();
		}

		private function import_file( $filename, $file_contents ) {
			$this->import_posts( $file_contents, $filename );
		}

		private function import_posts( $chat_contents, $filename ) {
			$raw_transcript = $chat_contents;

			$chats = array();

			$formats = apply_filters( 'chat_importer_formats', array(
				'Chat_IMporter_Format_AIM_HTML',
				'Chat_IMporter_Format_AIM_Text',
				'Chat_IMporter_Format_MSN',
				'Chat_IMporter_Format_Colloquy',
				'Chat_IMporter_Format_Adium',
			) );

			foreach ( $formats as $format_class ) {
				if ( $format_class::is_handler( $chat_contents, $filename ) ) {
					$chats = $format_class::parse( $chat_contents, $filename );
				}
			}

			foreach ( $chats as $chat ) {
				$chat_contents = $chat['transcript'];
				$timestamp = date( 'Y-m-d H:i:s', $chat['timestamp'] );
				$tags = empty( $chat['tags'] ) ? array() : $chat['tags'];

				preg_match_all( '/\n(?<username>[^\(^\n]+) \([^\)\n]+\): /', "\n" . $chat_contents, $username_matches );

				$usernames = array_unique( array_map( 'trim', $username_matches['username'] ) );

				foreach ( $usernames as $index => $username ) {
					if ( strpos( $usernames[$index], 'Auto response from' ) !== false ) {
						unset( $usernames[$index] );
					}
					else if ( strpos( $usernames[$index], 'signed off' ) !== false ) {
						unset( $usernames[$index] );
					}
					else if ( strpos( $usernames[$index], 'signed on' ) !== false ) {
						unset( $usernames[$index] );
					}
					else if ( strpos( $usernames[$index], 'Session concluded' ) !== false ) {
						unset( $usernames[$index] );
					}
				}

				$usernames = array_values( $usernames );
				sort( $usernames );

				if ( $this->autotag ) {
					$tags = array_values( array_unique( array_merge( $tags, $usernames ) ) );
				}

				if ( count( $usernames ) > 0 ) {
					if ( count( $usernames ) == 1 )
						$title = sprintf( __( 'Conversation with %s', 'chat-importer' ), $usernames[0] );
					else if ( count( $usernames ) == 2 )
						$title = sprintf( _x( 'Conversation between %1$s and %2$s', 'The two placeholders are each a single username.', 'chat-importer' ), $usernames[0], $usernames[1] );
					else
						$title = sprintf( _x( 'Conversation between %1$s, and %2$s', 'The first placeholder is a comma-separated list of usernames; the second placeholder is a single username.', 'chat-importer' ), implode( ', ', array_slice( $usernames, 0, count( $usernames ) - 1 ) ), $usernames[ count( $usernames ) - 1 ] );

					$title = apply_filters( 'chat_importer_post_title', $title, $usernames, $chat_contents );

					$this->posts[] = array(
						'post_title' => $title,
						'post_date_gmt' => get_gmt_from_date( $timestamp ),
						'post_date' => $timestamp,
						'post_content' => $this->chat_markup( $chat_contents ),
						'post_status' => $this->status,
						'post_category' => $this->categories,
						'post_author' => $this->author,
						'tags' => $tags,
						'transcript_raw' => $raw_transcript,
						'original_filename' => $filename,
					);
				}
			}
		}

		private function process_posts() {
			$this->posts = apply_filters( 'wp_import_posts', $this->posts );

			foreach ( $this->posts as $post ) {
				$post = apply_filters( 'wp_import_post_data_raw', $post );

				$post_id = wp_insert_post( $post );

				if ( ! empty( $post['tags'] ) ) {
					wp_set_post_tags( $post_id, $post['tags'], true );
				}

				add_post_meta( $post_id, 'im-porter_raw_transcript', $post['transcript_raw'] );
				add_post_meta( $post_id, 'im-porter_original_filename', $post['original_filename'] );

				set_post_format( $post_id, 'chat' );
			}
		}

		private function header() {
			echo '<div class="wrap">';
			screen_icon();
			echo '<h2>' . __( 'Import Chat Transcripts', 'chat-importer' ) . '</h2>';
		}

		private function footer() {
			echo '</div>';
		}

		private function greet() {
			echo '<div class="narrow">';
			wp_import_upload_form( 'admin.php?import=chats&step=1' );
			echo '</div>';
		}

		private function import_end() {
			wp_import_cleanup( $this->id );

			wp_cache_flush();
			foreach ( get_taxonomies() as $tax ) {
				delete_option( "{$tax}_children" );
				_get_term_hierarchy( $tax );
			}

			wp_defer_term_counting( false );
			wp_defer_comment_counting( false );

			if ( $this->file_count == 1 ) {
				echo '<p>' . sprintf( _n( 'One chat imported from one file.', '%s chats imported from one file.', count( $this->posts ), 'chat-importer' ), number_format( count( $this->posts ) ) ) . '</p>';
			}
			else {
				echo '<p>' . sprintf( _n( '%1$s chat imported from %2$s$2%s files.', '%1$s chats imported from %2$s files.', count( $this->posts ), 'chat-importer' ), number_format( count( $this->posts ) ), number_format( $this->file_count ) ) . '</p>';
			}

			echo '<p><a href="' . admin_url( 'edit.php' ) . '">' . __( 'Have fun!', 'chat-importer' ) . '</a>' . '</p>';

			do_action( 'import_end' );
		}

		private function chat_markup( $chat, $timestamp = null, $chat_with = null ) {
			$chat_html = '';
			$participants = array();

			$lines = explode( "\n", trim( $chat ) );

			foreach ( $lines as $line ) {
				if ( strpos( $line, ': ' ) !== false ) {
					list( $prefix, $message ) = array_map( 'trim', explode( ': ', $line, 2 ) );

					if ( preg_match_all( '/\([^\)]+\)$/', $prefix, $parenthetical ) ) {
						$prefix = trim( str_replace( $parenthetical[0][0], '', $prefix ) ) . ' <time>' . $parenthetical[0][0] . '</time>';
						list( $participant, $unused ) = explode( ' <time>', $prefix, 2 );
					}
					else {
						$participant = $prefix;
					}

					$class = "participant";

					if ( in_array( $participant, $participants ) ) {
						$class = "participant-" . ( array_search( $participant, $participants ) + 1 );
					}
					else {
						$participants[] = $participant;
						$class = "participant-" . count( $participants );
					}

					$chat_html .= '<p><span class="' . $class . '">' . $prefix . '</span>: ' . $message . '</p>';
				}
				else {
					$chat_html .= '<p>' . $line . '</p>';
				}
			}

			return $chat_html;
		}

	}

	class Chat_IMporter_Format {
		/**
		 * Determine if a given chat is to be handled by this importer.
		 *
		 * @param string $chat_contents The raw chat data.
		 * @param string $filename The original filename of the uploaded file.
		 * @return bool
		 */
		static function is_handler( $chat_contents, $filename ) { }

		/**
		 * Parse a chat transcript.
		 *
		 * @param string $chat_contents The raw chat data.
		 * @param string $filename The original filename of the uploaded file.
		 * @return array An array consisting of:
		 *         [int timestamp] A Unix timestamp of the chat's date/time.
		 *         [string transcript] A transcript of the chat, with each line formatted like so:
		 *                             username (12:34:56 PM): This is the message that was sent.
		 *         (optional) [array tags] An array of string tags to apply to the imported post.
		 */
		static function parse( $chat_contents, $filename ) { }

		static function date_from_filename( $filename ) {
			if ( preg_match( '/([0-9]{4}-[0-9]{2}-[0-9]{2})/', $filename, $date_matches ) ) {
				return $date_matches[1];
			}
			else if ( preg_match( '/([0-9]{1,2}-[0-9]{1,2}-[0-9]{2})/', $filename, $date_matches ) ) {
				$date = $date_matches[1];
				$date_parts = explode( "-", $date );

				$year = $date_parts[2];

				if ( $year < 60 )
					$year += 2000;
				else
					$year += 1900;

				return sprintf( "%04d-%02d-%02d", intval( $year ), intval( $date_parts[0] ), intval( $date_parts[1] ) );
			}
			else {
				return date( 'Y-m-d' );
			}
		}
	}

	class Chat_IMporter_Format_AIM_HTML extends Chat_IMporter_Format {
		static function is_handler( $chat_contents, $filename ) {
			if ( preg_match( '/^<HTML>/', $chat_contents ) )
				return true;

			return false;
		}

		static function parse( $chat_contents, $filename ) {
			$chats = array();

			// Find the first timestamp in the chat.
			if ( preg_match_all( '/\(([0-9]+):([0-9]+):([0-9]+) ([AP]M)\)/', $chat_contents, $time_matches ) ) {
				if ( $time_matches[4][0] == 'PM' ) {
					if ( $time_matches[1][0] != '12' ) {
						$time_matches[1][0] += 12;
					}
				}
				else if ( $time_matches[1][0] == '12' ) {
					$time_matches[1][0] = 0;
				}

				$time = sprintf( '%02d:%02d:%02d', $time_matches[1][0], $time_matches[2][0], $time_matches[3][0] );

				$timestamp = self::date_from_filename( $filename ) . ' ' . $time;
			}
			else {
				$timestamp = self::date_from_filename( $filename ) . ' 00:00:00';
			}

			$chats[] = array(
				'timestamp' => strtotime( $timestamp ),
				'transcript' => self::clean( $chat_contents )
			);

			return $chats;
		}

		static function clean( $chat_contents ) {
			// br2nl
			$chat_contents = preg_replace( '/\<br(\s*)?\/?\>/i', "\n", $chat_contents );

			// Make comments not comments
			$chat_contents = str_replace( array( '<!--', '-->' ), '', $chat_contents );

			// Strip all tags.
			$chat_contents = strip_tags( $chat_contents, array( 'hr', 'HR' ) );

			$chat_contents = html_entity_decode( $chat_contents );

			// Remove whitespace-only lines
			$chat_contents = implode( "\n", array_map( 'trim', explode( "\n", $chat_contents ) ) );

			// Remove consecutive newlines
			$chat_contents = preg_replace( "/\n{2,}/", "\n", $chat_contents );

			return $chat_contents;
		}
	}

	class Chat_IMporter_Format_AIM_Text extends Chat_IMporter_Format {
		static function is_handler( $chat_contents, $filename) {
			if ( preg_match_all( '/^Conversation with ([\S]+) at ([^\n]*?) on/', $chat_contents, $matches ) )
				return true;

			return false;
		}

		static function parse( $chat_contents, $filename ) {
			$chats = array();

			preg_match_all( '/^Conversation with ([\S]+) at ([^\n]*?) on/', $chat_contents, $matches );

			$timestamp = $matches[2][0];
			list( $transcript, $unused ) = explode( "\n", $chat_contents, 2 );

			$chats[] = array(
				'timestamp' => strtotime( $timestamp ),
				'transcript' => $transcript,
			);

			return $chats;
		}
	}

	class Chat_IMporter_Format_MSN extends Chat_IMporter_Format {
		static function is_handler( $chat_contents, $filename ) {
			if ( preg_match( '/\.xml$/', $filename ) && strpos( $chat_contents, 'MessageLog.xsl' ) !== false )
				return true;

			return false;
		}

		/**
		 * MSN Messenger stores all chats with a contact in a single XML log.
		 */
		static function parse( $chat_contents, $filename ) {
			$chats = array();

			$xml = simplexml_load_string( $chat_contents );

			$last_date = '';
			$chat_time = '';
			$chat_contents = '';

			foreach ( $xml->Message as $message ) {
				$date = (string) $message['Date'];

				if ( $date != $last_date ) {
					if ( $chat_contents ) {
						$chats[] = array(
							'timestamp' => strtotime( $date . ' ' . $chat_time ),
							'transcript' => $chat_contents,
						);
						$chat_contents = '';
					}

					$chat_time = (string) $message['Time'];
					$last_date = $date;
				}

				$chat_contents .= trim( str_replace( '(E-mail Address Not Verified)', '', (string) $message->From->User['FriendlyName'] ) ) . ' (' . (string) $message['Time'] . '): ' . (string) $message->Text . "\n";
			}

			if ( $chat_contents ) {
				$chats[] = array(
					'timestamp' => strtotime( $date . ' ' . $chat_time ),
					'transcript' => $chat_contents,
				);
			}

			return $chats;
		}
	}

	class Chat_IMporter_Format_Colloquy extends Chat_IMporter_Format {
		static function is_handler( $chat_contents, $filename ) {
			if ( preg_match( '/\.colloquyTranscript$/', $filename ) )
				return true;

			return false;
		}

		static function parse( $chat_contents, $filename ) {
			// Note: Dates in Colloquy are formatted like 2008-02-18 11:32:06 -0600, and doing
			// strtotime on them seems to leave us with GMT times instead of local times.
			// I think I'm doing something wrong.

			$chats = array();

			$xml = simplexml_load_string( $chat_contents );

			$tags = array();

			if ( $xml['source'] ) {
				if ( strpos( (string) $xml['source'], '/' ) === false ) {
					$tags[] = urldecode( (string) $xml['source'] );
				}
				else {
					list( $tag, $unused ) = explode( '/', (string) $xml['source'], 2 );

					$tags[] = urldecode( $tag );
				}
			}

			$last_date = '';
			$chat_time = '';
			$chat_contents = '';
			$first_timestamp = '';

			foreach ( $xml->envelope as $envelope ) {
				foreach ( $envelope->message as $message ) {
					$timestamp = (string) $message['received'];

					if ( ! $first_timestamp )
						$first_timestamp = $timestamp;

					$date = date( 'Y-m-d', strtotime( $timestamp ) );

					if ( $date != $last_date ) {
						if ( $chat_contents ) {
							$chats[] = array(
								'timestamp' => get_date_from_gmt( $first_timestamp, 'U' ),
								'transcript' => $chat_contents
							);

							$chat_contents = '';
						}

						$last_date = $date;
						$first_timestamp = $timestamp;
					}

					$chat_contents .= trim( (string) $envelope->sender ) . ' (' . get_date_from_gmt( $timestamp, 'g:i:s A' ) . '): ' . strip_tags( (string) self::SimpleXMLElement_innerXML( $message ) ) . "\n";
				}
			}

			if ( $chat_contents ) {
				$chats[] = array(
					'timestamp' => strtotime( $first_timestamp ),
					'transcript' => $chat_contents,
					'tags' => $tags,
				);
			}

			return $chats;
		}

		static function SimpleXMLElement_innerXML($xml) {
			$innerXML =  '';

			foreach ( dom_import_simplexml($xml)->childNodes as $child )
				$innerXML .= $child->ownerDocument->saveXML( $child );

			return $innerXML;
		}
	}

	class Chat_IMporter_Format_Adium extends Chat_IMporter_Format {
		static $chat_contents = '';
		static $timestamp = 0;
		static $current_message = '';

		static function is_handler( $chat_contents, $filename ) {
			if ( preg_match( '/\.chatlog$/', $filename ) )
				return true;

			return false;
		}

		static function parse( $chat_contents, $filename ) {
			$xml_parser = xml_parser_create();
			xml_parser_set_option( $xml_parser, XML_OPTION_SKIP_WHITE, 1 ); 
			xml_set_default_handler( $xml_parser, array( self, "defaultHandler" ) );
			xml_set_element_handler( $xml_parser, array( self, "startElement" ), array( self, "endElement" ) );

			$string_location = 0;

			while ( $data = substr( $chat_contents, $string_location, 100 ) ) {
				$string_location += 100;

				if ( ! xml_parse( $xml_parser, $data, ( $string_location + 100 ) >= strlen( $chat_contents ) ) ) {
					// echo '<p>' . xml_error_string( xml_get_error_code( $xml_parser ) ) . '</p>';
					self::endElement( $xml_parser, 'MESSAGE' );
					break;
				}
			}

			xml_parser_free( $xml_parser );

			if ( self::$chat_contents ) {
				$chats[] = array(
					'timestamp' => strtotime( self::$timestamp ),
					'transcript' => self::$chat_contents,
				);
			}

			self::$chat_contents = '';
			self::$current_message = '';
			self::$timestamp = 0;

			return $chats;
		}

		static function startElement( $parser, $name, $attrs ) {
			if ( 'MESSAGE' == $name ) {
				if ( ! self::$timestamp ) {
					self::$timestamp = $attrs['TIME'];
				}

				self::$chat_contents .= sprintf( "%s (%s): ", trim( $attrs['SENDER'] ),  date( 'g:i:s A', strtotime( $attrs['TIME'] ) ) );
			}
		}

		static function endElement( $parser, $name ) {
			if ( self::$current_message ) {
				self::$chat_contents .= trim( strip_tags( self::$current_message ), "\n\r" );
				self::$current_message = '';
			}

			if ( 'MESSAGE' == $name )
				self::$chat_contents .= "\n";
		}

		static function defaultHandler( $parser, $data ) {
			self::$current_message .= $data;
		}
	}

	$__im_porter = new Chat_IMporter_Import();

	register_importer( 'chats', 'Chat Transcripts', __( 'Import chat transcripts (AIM, MSN, Colloquy, Adium) as posts.', 'chat-importer' ), array( $__im_porter, 'dispatch' ) );
}