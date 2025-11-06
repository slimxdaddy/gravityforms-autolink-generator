<?php
/**
 * Plugin Name: V8 Gravity Forms Permalinks (/f/{slug}) + Forms List “Link” Column
 * Description: Clean URLs like /f/{slug} rendered with your theme (no preview). Adds a “Link” column on the Forms list for per-form slugs (saved via admin-post). Hides theme title/meta/banner on virtual pages and prints our own H1 using the GF form title.
 * Version: 1.5.2
 * Author: Web V8
 * Author URI: https://webv8.net/
 * License: GPL-2.0+
 * Text Domain: v8-gf-permalinks
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class V8_GF_Form_Permalinks {
	const BASE       = 'f';                     
	const QUERY_VAR  = 'v8_f';
	const OPTION_KEY = 'v8_gf_permalinks_map'; 
	const FLUSH_KEY  = 'v8_gf_permalinks_flushed';
	const NONCE_ACT  = 'v8gf_save_slug';

	private static bool $is_form_page = false;
	private static string $form_title = '';

	public static function init() : void {
		add_action( 'init',              [ __CLASS__, 'add_rewrite' ], 1 );
		add_filter( 'query_vars',        [ __CLASS__, 'add_query_var' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ] );
		add_action( 'init',              [ __CLASS__, 'maybe_flush_once' ], 99 );

		add_filter( 'gform_form_list_columns',        [ __CLASS__, 'add_list_col' ] );
		add_action( 'gform_form_list_column_v8_link', [ __CLASS__, 'render_list_col' ] );
		add_action( 'admin_post_v8_gf_perma_save',    [ __CLASS__, 'handle_save' ] );

		add_filter( 'pre_get_document_title', [ __CLASS__, 'filter_doc_title' ], 99 );
		add_filter( 'document_title_parts',   [ __CLASS__, 'filter_doc_title_parts' ], 99 );
		add_filter( 'body_class',             [ __CLASS__, 'body_class' ] );
		add_action( 'wp_head',                [ __CLASS__, 'head_styles' ], 99 );
	}

	/* ---------- Routing ---------- */

	public static function add_rewrite() : void {
		$base = trim( self::BASE, '/' );
		add_rewrite_rule(
			'^' . preg_quote( $base, '/' ) . '/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	public static function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function maybe_render() : void {
		$slug = sanitize_title( (string) get_query_var( self::QUERY_VAR ) );
		if ( ! $slug ) return;
		if ( ! class_exists( 'GFAPI' ) ) { status_header(404); exit; }

		$form = self::locate_form_by_slug( $slug );
		if ( ! $form ) {
			status_header( 404 );
			if ( function_exists( 'get_header' ) ) {
				get_header();
				echo '<main id="primary" class="site-main"><div class="wrap"><p>Form not found.</p></div></main>';
				get_footer();
			}
			exit;
		}

		global $wp_query;
		if ( $wp_query ) {
			$wp_query->is_404      = false;
			$wp_query->is_page     = true;
			$wp_query->is_singular = true;
		}

		self::$is_form_page = true;
		self::$form_title   = (string) ( $form['title'] ?? '' );

		status_header( 200 );
		nocache_headers();

		$form_id = (int) ( $form['id'] ?? 0 );

		get_header();
		echo '<main id="primary" class="site-main v8-form-page-main"><div class="wrap">';
		echo '<header class="v8-form-header"><h1 class="v8-form-title">' . esc_html( self::$form_title ) . '</h1></header>';
		echo do_shortcode( sprintf( '[gravityform id="%d" title="false" description="false" ajax="true"]', $form_id ) );
		echo '</div></main>';
		get_footer();
		exit;
	}

	private static function locate_form_by_slug( string $slug ) {
		$map = get_option( self::OPTION_KEY, [] );
		if ( is_array( $map ) ) {
			foreach ( $map as $id => $s ) {
				if ( sanitize_title( (string) $s ) === $slug ) {
					$form = GFAPI::get_form( (int) $id );
					if ( $form && ! is_wp_error( $form ) ) return $form;
				}
			}
		}
		$forms = GFAPI::get_forms( true );
		foreach ( $forms as $form ) {
			if ( sanitize_title( (string) ( $form['title'] ?? '' ) ) === $slug ) return $form;
		}
		if ( ctype_digit( $slug ) ) {
			$form = GFAPI::get_form( (int) $slug );
			if ( $form && ! is_wp_error( $form ) ) return $form;
		}
		return null;
	}

	public static function maybe_flush_once() : void {
		if ( get_option( self::FLUSH_KEY ) ) return;
		flush_rewrite_rules( false );
		update_option( self::FLUSH_KEY, 1 );
	}

	/* ---------- Admin column ---------- */

	public static function add_list_col( $cols ) {
		$out = [];
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( $k === 'title' ) $out['v8_link'] = __( 'Link', 'v8-gf-permalinks' );
		}
		return $out;
	}

	public static function render_list_col( $form ) {
		$form_id = 0;
		if ( is_array( $form ) && isset( $form['id'] ) ) {
			$form_id = (int) $form['id'];
		} elseif ( is_object( $form ) && isset( $form->id ) ) {
			$form_id = (int) $form->id;
		}

		$map  = get_option( self::OPTION_KEY, [] );
		$slug = '';
		if ( is_array( $map ) && isset( $map[ $form_id ] ) && $map[ $form_id ] !== '' ) {
			$slug = sanitize_title( (string) $map[ $form_id ] );
		}
		if ( $slug === '' ) {
			$title = is_array( $form ) ? ( $form['title'] ?? '' ) : ( $form->title ?? '' );
			$slug  = sanitize_title( $title );
		}

		$base  = trim( self::BASE, '/' );
		$url   = esc_url( home_url( '/' . $base . '/' . $slug . '/' ) );

		$action = esc_url( admin_url( 'admin-post.php' ) );
		$nonce  = wp_create_nonce( self::NONCE_ACT . '_' . $form_id );

		echo '<div class="v8-gf-link-col" style="min-width:300px">';
		echo '  <form method="post" action="' . $action . '" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">';
		echo '    <input type="hidden" name="action" value="v8_gf_perma_save" />';
		echo '    <input type="hidden" name="form_id" value="' . esc_attr( $form_id ) . '" />';
		echo '    <input type="hidden" name="v8gf_nonce" value="' . esc_attr( $nonce ) . '" />';
		echo '    <span style="opacity:.7">/' . esc_html( $base ) . '/</span>';
		echo '    <input type="text" name="slug" value="' . esc_attr( $slug ) . '" style="width:180px" />';
		echo '    <button type="submit" class="button button-small">Save</button>';
		echo '  </form>';
		echo '  <div class="v8-gf-link" style="margin-top:6px"><a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a></div>';
		echo '</div>';
	}

	public static function handle_save() {
		if ( ! current_user_can( 'gravityforms_edit_forms' ) && ! current_user_can( 'manage_options' ) )
			wp_die( 'Permission denied' );

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$nonce   = isset( $_POST['v8gf_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['v8gf_nonce'] ) ) : '';

		if ( ! $form_id || ! wp_verify_nonce( $nonce, self::NONCE_ACT . '_' . $form_id ) )
			wp_die( 'Invalid request' );
		if ( ! class_exists( 'GFAPI' ) )
			wp_die( 'Gravity Forms not active' );

		$form = GFAPI::get_form( $form_id );
		if ( is_wp_error( $form ) || ! $form )
			wp_die( 'Form not found' );

		$slug_in    = isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '';
		$final_slug = sanitize_title( $slug_in ?: ( $form['title'] ?? '' ) );

		$map = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $map ) ) $map = [];
		$map[ $form_id ] = $final_slug;
		update_option( self::OPTION_KEY, $map, false );

		wp_safe_redirect( admin_url( 'admin.php?page=gf_edit_forms' ) );
		exit;
	}

	/* ---------- Title + Styling ---------- */

	public static function filter_doc_title( $title ) {
		if ( self::$is_form_page && self::$form_title !== '' )
			return self::$form_title . ' – ' . wp_strip_all_tags( get_bloginfo( 'name' ) );
		return $title;
	}

	public static function filter_doc_title_parts( $parts ) {
		if ( self::$is_form_page && self::$form_title !== '' )
			$parts['title'] = self::$form_title;
		return $parts;
	}

	public static function body_class( array $classes ) : array {
		if ( self::$is_form_page ) $classes[] = 'v8-form-page';
		return $classes;
	}

	public static function head_styles() : void {
		if ( ! self::$is_form_page ) return; ?>
		<style id="v8-form-page-css">
			/* Hide theme-rendered title/meta/banner on /f/ pages */
			body.v8-form-page .entry-meta,
			body.v8-form-page .post-meta,
			body.v8-form-page .page-meta,
			body.v8-form-page .byline,
			body.v8-form-page .posted-on,
			body.v8-form-page .entry-header .meta,
			body.v8-form-page .single-meta,
			body.v8-form-page .article-meta,
			body.v8-form-page .entry-title,
			body.v8-form-page .page-title,
			body.v8-form-page h1.entry-title,
			body.v8-form-page .wp-block-post-title,
			body.v8-form-page .ast-archive-entry-banner { display:none !important; }

			/* Our custom title */
			body.v8-form-page .v8-form-header { margin:20px 0 1rem 0; }
			body.v8-form-page .v8-form-title  { font-size:2rem; line-height:1.2; margin:0; font-weight:700; }

			/* Remove leftover padding/margins */
			body.v8-form-page .site-content,
			body.v8-form-page main#primary,
			body.v8-form-page .ast-container { padding-top:0 !important; margin-top:0 !important; }
		</style>
	<?php }
}

V8_GF_Form_Permalinks::init();
