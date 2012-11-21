<?php


if ( ! defined( 'ABSPATH' ) ) exit;


if ( ! class_exists( 'UCC_bbPress_Topic_Post_Converter' ) ) {
class UCC_bbPress_Topic_Post_Converter {
	public static $instance;
	public static $version;
	
	public function __construct() {
		self::$instance = $this;
		add_action( 'bbp_init', array( $this, 'init' ), 11 );
		$this->version = '2012032801';
	}

	public function init() {
		load_plugin_textdomain( 'bbpress-topic-post-converter', false, basename( dirname( __FILE__ ) ) . '/languages' );

		// WordPress compatability.
		add_filter( 'edit_post_link', array( $this, 'edit_post_link' ), 1 );
		add_action( 'template_redirect', array( $this, 'convert_post' ), 2 );
			
		// bbPress compatability.
		add_filter( 'bbp_get_topic_admin_links', array( $this, 'get_topic_admin_links' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'convert_topic' ), 2 );
	}

	public function get_topic_admin_links( $links, $args ) {
		if ( ! bbp_is_single_topic() )
			return;

		$defaults = array (
			'id'     => bbp_get_topic_id(),
			'before' => '<span class="bbp-admin-links">',
			'after'  => '</span>',
			'sep'    => ' | ',
			'links'  => array()
		);
		$r = wp_parse_args( $args, $defaults );

		if ( ! current_user_can( 'edit_topic', $r['id'] ) )
			return;

		if ( empty( $r['links'] ) ) {
			$r['links'] = array(
				'edit'    => bbp_get_topic_edit_link ( $r ),
				'close'   => bbp_get_topic_close_link( $r ),
				'stick'   => bbp_get_topic_stick_link( $r ),
				'merge'   => bbp_get_topic_merge_link( $r ),
				'trash'   => bbp_get_topic_trash_link( $r ),
				'spam'    => bbp_get_topic_spam_link ( $r ),
				'convert' => $this->get_topic_convert_link( $r )
			);
		}

		// Check caps for trashing the topic.
		if ( ! current_user_can( 'delete_topic', $r['id'] ) && !empty( $r['links']['trash'] ) )
			unset( $r['links']['trash'] );

		$topic_status = bbp_get_topic_status( $r['id'] );
		if ( in_array( $topic_status, array( bbp_get_spam_status_id(), bbp_get_trash_status_id() ) ) ) {
			// Close/convert link shouldn't be visible on trashed/spammed topics.
			unset( $r['links']['close'] );
			unset( $r['links']['convert'] );

			// Spam link shouldn't be visible on trashed topics.
			if ( $topic_status == bbp_get_trash_status_id() )
				unset( $r['links']['spam'] );

			// Trash link shouldn't be visible on spam topics.
			elseif ( $topic_status == bbp_get_spam_status_id() )
				unset( $r['links']['trash'] );
		}

		// Process the admin links.
		$links = implode( $r['sep'], array_filter( $r['links'] ) );

		return $r['before'] . $links . $r['after'];
	}

	public function get_topic_convert_link( $args = '' ) {
		$defaults = array (
			'id'	   => 0,
			'link_before'  => '',
			'link_after'   => '',
			'convert_text' => __( 'Convert to Post', 'bbpress-topic-post-converter' )
		);
		$r = wp_parse_args( $args, $defaults );
		extract( $r );

		$topic = bbp_get_topic( bbp_get_topic_id( (int) $id ) );

		// Only display for privileged users.
		if ( empty( $topic ) || ! current_user_can( 'moderate', $topic->ID ) )
			return;

		$uri = add_query_arg( array( 'action' => 'ucc_btpc_topic_convert', 'topic_id' => $topic->ID ) );
		$uri = esc_url( wp_nonce_url( $uri, '_ucc_btpc_nonce_' . $topic->ID ) );

		return apply_filters( 'ucc_btpc_get_topic_convert_link', $link_before . '<a href="' . $uri . '">' . $convert_text . '</a>' . $link_after, $args );
	}

	public function convert_topic() {
		global $wpdb;

		// Only proceed if GET is a topic convert action.
		if ( 'GET' == $_SERVER['REQUEST_METHOD'] && ! empty( $_GET['action'] ) && ( $_GET['action'] == 'ucc_btpc_topic_convert' ) && ! empty( $_GET['topic_id'] ) ) {
			$topic_id  = (int) $_GET['topic_id'];
			$success   = false;
			$post_data = array( 'ID' => $topic_id );

			if ( ! $topic = bbp_get_topic( $topic_id ) )
				wp_die( __( 'The topic was not found!', 'bbpress-topic-post-converter' ) );

			if ( ! current_user_can( 'moderate', $topic_id ) )
				wp_die( __( 'You do not have the permission to do that!', 'bbpress-topic-post-converter' ) );

			// Check for author.
			$post = get_post( $topic_id );
			if ( $post->post_author < 1 )
				wp_die( __( 'This is an anonymous post and cannot be converted to a blog entry.', 'bbpress-topic-post-converter' ) );

			check_admin_referer( '_ucc_btpc_nonce_' . $topic_id );

			// Change post type of topic.
			$args = array(
				'ID' => $topic_id,
				'post_type' => 'post',
				'comment_status' => 'open'
			);
			wp_update_post( $args );

			// Deal with replies.
			$post_stati = join( ',', array( bbp_get_public_status_id(), bbp_get_spam_status_id(), bbp_get_trash_status_id() ) );
			$args = array(
				'post_type'      => bbp_get_reply_post_type(),
				'orderby'	=> 'post_date',
				'order'	  => 'ASC',
				'post_status'    => $post_stati, 
				'posts_per_page' => -1,
				'meta_query'     => array( array(
					'key'     => '_bbp_topic_id',
					'value'   => $topic_id,
					'compare' => '='
				) )
			);
			$replies = get_posts( $args );
			if ( ! empty( $replies ) ) {
				$in_reply_to_lookup = array();
				foreach ( $replies as $reply ) {
					$data = array();

					// bbPress-specific comment meta (data preservation).
					$reply_id = $reply->ID;
					$in_reply_to = get_post_meta( $reply_id, '_ucc_btr_in_reply_to', true );
					$forum_id = get_post_meta( $reply_id, '_bbp_forum_id', true );
					$topic_id = get_post_meta( $reply_id, '_bbp_topic_id', true );

					// Comment values.
					$data['comment_post_ID'] = $topic_id;
					$data['comment_content'] = $reply->post_content;
					$data['comment_type'] = '';
					$data['user_id'] = $reply->post_author;
					$data['comment_author_IP'] = get_post_meta( $reply_id, '_bbp_author_ip', true );
					$data['comment_date'] = get_the_time( 'Y-m-d H:i:s', $reply_id );

					// Deal with guest reply.
					if ( $reply->post_author < 1 ) {
						$data['comment_author'] = $wpdb->escape( get_post_meta( $reply_id, '_bbp_anonymous_name', true ) );
						$data['comment_author_email'] = $wpdb->escape( get_post_meta( $reply_id, '_bbp_anonymous_email', true ) );
						$data['comment_author_url'] = $wpdb->escape( get_post_meta( $reply_id, '_bbp_anonymous_website', true ) );
					} else {
						$user = get_userdata( $reply->post_author );
						if ( empty( $user->display_name ) )
							$user->display_name=$user->user_login;
						$data['comment_author'] = $wpdb->escape($user->display_name);
						$data['comment_author_email'] = $wpdb->escape($user->user_email);
						$data['comment_author_url'] = $wpdb->escape($user->user_url);
					}

					if ( $reply->post_status == bbp_get_public_status_id() )
						$data['comment_approved'] = 1;
					else
						$data['comment_approved'] = 0;

					// bbPress Threaded Replies compat: inline because a comment should not reply to a comment after it chronologically.
					if ( array_key_exists( $in_reply_to, $in_reply_to_lookup ) )
						$data['comment_parent'] = $in_reply_to_lookup[$in_reply_to];
					else
						$data['comment_parent'] = 0;

					$comment_id = wp_insert_comment( $data );
					if ( ! empty( $comment_id ) ) {
						$in_reply_to_lookup[$reply_id] = $comment_id;
						update_comment_meta( $comment_id, '_bbp_reply_id', $reply_id );
						update_comment_meta( $comment_id, '_bbp_forum_id', $forum_id );
						update_comment_meta( $comment_id, '_bbp_topic_id', $topic_id );

						wp_delete_post( $reply_id, true );
					}
				}
			}
			$redirect = get_permalink( $topic_id );
			wp_redirect( $redirect );
		}
	}

	public function edit_post_link( $link ) {
		$form = $this->get_post_convert_form();
		return $link . $form;
	}

	public function get_post_convert_form() {
		global $post;

		$id = $post->ID;

		// Only display on posts.
		if ( $post->post_type != 'post' )
			return;

		// Only display on single entries.
		if ( ! is_single() )
			return;

		// Only display for privileged users.
		if ( empty( $id ) || ! current_user_can( 'moderate' ) )
			return;

		ob_start();
		?>
		<form action="" method="POST" style="display:inline;">
		<input type="hidden" name="post_id" value="<?php echo $id; ?>" />
		<input type="hidden" name="action" value="ucc_btpc_post_convert" />
		<?php wp_nonce_field( '_ucc_btpc_nonce_' . $id ); ?>
		<?php bbp_dropdown( array( 'selected' => bbp_get_form_topic_forum() ) ); ?>
		<input type="submit" name="submit" value="<?php _e( 'Convert to Topic', 'bbpress-topic-post-convert' ); ?>" />
		</form>
		<?php

		$form = ob_get_contents();
		ob_end_clean();

		return apply_filters( 'ucc_btpc_get_post_convert_form', $form );
	}

	public function convert_post() {
		// Only proceed of GET is a post convert action.
		if ( 'POST' == $_SERVER['REQUEST_METHOD'] && 
			isset( $_POST['action'] ) && ( $_POST['action'] == 'ucc_btpc_post_convert' ) &&
			isset( $_POST['post_id'] ) && ! empty( $_POST['post_id'] ) &&
			isset( $_POST['bbp_forum_id'] ) && ! empty( $_POST['bbp_forum_id'] ) ) {

			$post_id  = (int) $_POST['post_id'];
			$success   = false;
			$post = get_post( $post_id );

			if ( empty( $post ) ) 
				wp_die( __( 'This post was not found!', 'bbpress-topic-post-converter' ) );

			$forum_id = (int) $_POST['bbp_forum_id'];
			if ( ! empty( $forum_id ) ) {
				if ( ! bbp_get_forum_id( $forum_id ) )
					wp_die( 'Invalid forum specified.', 'bbpress-topic-post-converter' );

				if ( bbp_is_forum_category( $forum_id ) )
					wp_die( 'You cannot move a post to a forum category.', 'bbpress-topic-post-converter' );

				if ( bbp_is_forum_closed( $forum_id ) && ! current_user_can( 'edit_forum', $forum_id ) )
					wp_die( 'This forum has been closed to new topics.', 'bbpress-topic-post-converter' );

				if ( bbp_is_forum_private( $forum_id ) && ! current_user_can( 'read_private_forums' ) )
					wp_die( 'This forum is private.', 'bbpress-topic-post-converter' );

				if ( bbp_is_forum_hidden( $forum_id ) && ! current_user_can( 'read_hidden_forums' ) )
					wp_die( 'This forum is hidden.', 'bbpress-topic-post-converter' );
			} else {
				wp_die( 'No forum specified.', 'bbpress-topic-post-converter' );
			}

			if ( ! current_user_can( 'moderate' ) )
				wp_die( __( 'You do not have the permission to do that!', 'bbpress-topic-post-converter' ) );

			check_admin_referer( '_ucc_btpc_nonce_' . $post_id );

			// Change post into topic. 
			$topic_id = $post_id;
			$args = array(
				'ID' => $topic_id,
				'post_type' => bbp_get_topic_post_type(),
				'post_parent' => $forum_id,
				'comment_status' => 'open'
			);
			wp_update_post( $args );
			$topic = get_post( $topic_id );

			// bbPress topic meta.
			update_post_meta( $topic_id, '_bbp_forum_id', $forum_id );
			update_post_meta( $topic_id, '_bbp_topic_id', $topic_id );
			update_post_meta( $topic_id, '_bbp_author_ip', bbp_current_author_ip() );

			// Deal with comments.
			$args = array(
				'order'   => 'ASC',
				'post_id' => $topic_id
			);
			$comments = get_comments( $args );

			if ( ! empty( $comments ) ) {
				$in_reply_to_lookup = array();
				foreach ( $comments as $comment ) {
					// Reply values.
					$data = array(
						'post_title' => __( 'Reply to: ', 'bbpress-topic-post-converter' ) . $post->post_title,
						'post_parent' => $topic_id,
						'post_content' => $comment->comment_content,
						'post_date' => $comment->comment_date,
						'post_date_gmt' => $comment->comment_date_gmt,
						'post_author' => $comment->user_id
					);
					if ( $comment->comment_approved == 1 )
						$data['post_status'] = bbp_get_public_status_id();
					else
						$data['post_status'] = bbp_get_trash_status_id();

					// Reply meta values.
					$meta = array(
						'author_ip' => $comment->comment_author_IP,
						'forum_id' => $forum_id,
						'topic_id' => $topic_id
					);
					$anon = array();
					if ( $comment->user_id < 1 ) {
						if ( property_exists( $comment, 'comment_author' ) )
							$anon['anonymous_name'] = $comment->comment_author;
						if ( property_exists( $comment, 'comment_author_email' ) )
							$anon['anonymous_email'] = $comment->comment_author_email;
						if ( property_exists( $comment, 'comment_author_url' ) )
							$anon['anonymous_website'] = $comment->comment_author_url;
						$meta = array_merge( $meta, $anon );
					}

					$reply_id = bbp_insert_reply( $data, $meta );
					if ( ! empty( $reply_id ) ) {
						// bbPress Threaded Replies compat: inline because a comment should not reply to a comment after it chronologically.
						$in_reply_to = $comment->comment_parent;
						if ( array_key_exists( $in_reply_to, $in_reply_to_lookup ) )
							update_post_meta( $reply_id, '_ucc_btr_in_reply_to', $in_reply_to_lookup[$in_reply_to] );
						else
							update_post_meta( $reply_id, '_ucc_btr_in_reply_to', 0 );
						$in_reply_to_lookup[$comment->comment_ID] = $reply_id;

						if ( $comment->comment_approved != 1 ) {
							wp_trash_post( $reply_id );

							// Deal with pre_trashed_replies.
							$pre_trashed_replies = get_post_meta( $topic_id, '_bbp_pre_trashed_replies', true );
							$pre_trashed_replies[] = $reply_id;
							update_post_meta( $topic_id, '_bbp_pre_trashed_replies', $pre_trashed_replies );
						}

						wp_delete_comment( $comment->comment_ID );
					}
				}
			}

			wp_cache_delete( 'bbp_parent_' . $topic_id . '_type_reply_child_last_id', 'bbpress' );
			bbp_update_topic_voice_count( $topic_id );

			wp_cache_delete( 'bbp_parent_' . $topic_id . '_type_reply_child_last_id', 'bbpress' );
			bbp_update_topic_last_reply_id( $topic_id );

			bbp_update_topic_last_active_id( $topic_id );

			bbp_update_topic_last_active_time( $topic_id );

			wp_cache_delete( 'bbp_parent_' . $topic_id . '_type_reply_child_count', 'bbpress' );
			bbp_update_topic_reply_count( $topic_id, 0 );

			bbp_update_topic_reply_count_hidden( $topic_id, 0 );

			$redirect = get_permalink( $topic_id );
			wp_redirect( $redirect );
		}
	}
} }
