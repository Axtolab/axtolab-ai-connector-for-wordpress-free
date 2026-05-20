<?php
/**
 * SEO plugin adapter — auto-detect Yoast SEO / Rank Math / All in One SEO
 * and route SEO-meta reads/writes to the active plugin's storage.
 *
 * Supported as of 2026-05-04:
 *   - Yoast SEO (postmeta keys `_yoast_wpseo_*`)
 *   - Rank Math (postmeta keys `rank_math_*`)
 *
 * Pending (deferred to v0.3+):
 *   - AIOSEO v4+ — stores SEO meta in custom table `{prefix}_aioseo_posts`
 *     rather than postmeta. The adapter detects AIOSEO and returns
 *     `aioseo` from active_plugin(), but get/update fall back to
 *     postmeta-style legacy keys (`_aioseo_*`) which only work on
 *     older AIOSEO installs. Full custom-table integration is its own
 *     workstream because it involves direct $wpdb writes on a plugin-
 *     owned table; we'll wire that in v0.3 alongside the Roll Back /
 *     Undo work.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Axtolab_AI_Connector_SEO_Adapter', false ) ) :
final class Axtolab_AI_Connector_SEO_Adapter {

	/**
	 * Standardized SEO field names exposed to MCP clients (provider-neutral).
	 */
	const STANDARD_FIELDS = array(
		'title',
		'description',
		'focus_keyphrase',
		'noindex',
		'nofollow',
		'og_title',
		'og_description',
		'og_image',
		'twitter_title',
		'twitter_description',
		'twitter_image',
	);

	/**
	 * Detect which SEO plugin is active.
	 * Returns one of: 'yoast' | 'rank_math' | 'aioseo' | null
	 *
	 * @return string|null
	 */
	public static function active_plugin() {
		$detected = null;
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options', false ) ) {
			$detected = 'yoast';
		} elseif ( class_exists( 'RankMath', false ) || defined( 'RANK_MATH_VERSION' ) ) {
			$detected = 'rank_math';
		} elseif ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\\Plugin\\AIOSEO', false ) ) {
			$detected = 'aioseo';
		}
		return apply_filters( 'axtolab_ai_connector_active_seo_plugin', $detected );
	}

	/**
	 * Per-plugin meta key map for the standard fields.
	 *
	 * @param string $plugin
	 * @return array<string,string>
	 */
	private static function meta_key_map( $plugin ) {
		switch ( $plugin ) {
			case 'yoast':
				return array(
					'title'               => '_yoast_wpseo_title',
					'description'         => '_yoast_wpseo_metadesc',
					'focus_keyphrase'     => '_yoast_wpseo_focuskw',
					'noindex'             => '_yoast_wpseo_meta-robots-noindex',
					'nofollow'            => '_yoast_wpseo_meta-robots-nofollow',
					'og_title'            => '_yoast_wpseo_opengraph-title',
					'og_description'      => '_yoast_wpseo_opengraph-description',
					'og_image'            => '_yoast_wpseo_opengraph-image',
					'twitter_title'       => '_yoast_wpseo_twitter-title',
					'twitter_description' => '_yoast_wpseo_twitter-description',
					'twitter_image'       => '_yoast_wpseo_twitter-image',
				);
			case 'rank_math':
				return array(
					'title'               => 'rank_math_title',
					'description'         => 'rank_math_description',
					'focus_keyphrase'     => 'rank_math_focus_keyword',
					'noindex'             => 'rank_math_robots',
					'nofollow'            => 'rank_math_robots',
					'og_title'            => 'rank_math_facebook_title',
					'og_description'      => 'rank_math_facebook_description',
					'og_image'            => 'rank_math_facebook_image',
					'twitter_title'       => 'rank_math_twitter_title',
					'twitter_description' => 'rank_math_twitter_description',
					'twitter_image'       => 'rank_math_twitter_image',
				);
			case 'aioseo':
				return array(
					'title'               => '_aioseo_title',
					'description'         => '_aioseo_description',
					'focus_keyphrase'     => '_aioseo_keyphrases',
					'noindex'             => '_aioseo_robots_noindex',
					'nofollow'            => '_aioseo_robots_nofollow',
					'og_title'            => '_aioseo_og_title',
					'og_description'      => '_aioseo_og_description',
					'og_image'            => '_aioseo_og_image_url',
					'twitter_title'       => '_aioseo_twitter_title',
					'twitter_description' => '_aioseo_twitter_description',
					'twitter_image'       => '_aioseo_twitter_image',
				);
		}
		return array();
	}

	/**
	 * Read SEO meta for a post in provider-neutral form.
	 *
	 * @param int $post_id
	 * @return array
	 */
	public static function get_meta( $post_id ) {
		$post_id = (int) $post_id;
		$plugin  = self::active_plugin();
		$out     = array();

		if ( null === $plugin ) {
			return array(
				'plugin' => null,
				'fields' => array_fill_keys( self::STANDARD_FIELDS, '' ),
			);
		}

		$map = self::meta_key_map( $plugin );
		foreach ( self::STANDARD_FIELDS as $field ) {
			if ( ! isset( $map[ $field ] ) ) {
				$out[ $field ] = '';
				continue;
			}
			$value = get_post_meta( $post_id, $map[ $field ], true );

			// Rank Math: noindex/nofollow are stored as a serialized list of
			// robots directives ('index','follow','noindex',...). Translate.
			if ( 'rank_math' === $plugin && in_array( $field, array( 'noindex', 'nofollow' ), true ) ) {
				$robots        = is_array( $value ) ? $value : array();
				$out[ $field ] = in_array( $field, $robots, true ) ? '1' : '';
				continue;
			}

			$out[ $field ] = is_scalar( $value ) ? (string) $value : '';
		}

		return array(
			'plugin' => $plugin,
			'fields' => $out,
		);
	}

	/**
	 * Write SEO meta for a post. Provider-neutral field names; unknown
	 * fields are silently ignored.
	 *
	 * @param int   $post_id
	 * @param array $fields
	 * @return array { plugin: string|null, written: array<string,mixed> }
	 */
	public static function update_meta( $post_id, array $fields ) {
		$post_id = (int) $post_id;
		$plugin  = self::active_plugin();

		if ( null === $plugin ) {
			return array(
				'plugin'  => null,
				'written' => array(),
			);
		}

		$map     = self::meta_key_map( $plugin );
		$written = array();

		foreach ( $fields as $field => $value ) {
			if ( ! isset( $map[ $field ] ) ) {
				continue;
			}
			$meta_key = $map[ $field ];

			// Rank Math: noindex/nofollow are array-of-robots-directives.
			// Read existing, add/remove, write back.
			if ( 'rank_math' === $plugin && in_array( $field, array( 'noindex', 'nofollow' ), true ) ) {
				$robots = (array) get_post_meta( $post_id, $meta_key, true );
				$want   = (bool) $value && '0' !== (string) $value;
				$robots = array_values(
					array_filter(
						$robots,
						function ( $r ) use ( $field ) {
							return $r !== $field;
						}
					)
				);
				if ( $want ) {
					$robots[] = $field;
				}
				update_post_meta( $post_id, $meta_key, $robots );
				$written[ $meta_key ] = $robots;
				continue;
			}

			$write_value = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			update_post_meta( $post_id, $meta_key, $write_value );
			$written[ $meta_key ] = $write_value;
		}

		return array(
			'plugin'  => $plugin,
			'written' => $written,
		);
	}
}
endif;

if ( ! class_exists( 'MCP_Gateway_SEO_Adapter', false ) ) {
	class_alias( 'Axtolab_AI_Connector_SEO_Adapter', 'MCP_Gateway_SEO_Adapter' );
}
