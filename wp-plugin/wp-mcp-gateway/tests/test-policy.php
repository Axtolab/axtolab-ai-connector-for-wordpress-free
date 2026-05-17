<?php

class Axtolab_AI_Connector_Policy_Test extends WP_UnitTestCase {
	public function test_disallowed_content_type_returns_wp_error() {
		add_filter(
			'axtolab_ai_connector_config',
			static function( $config ) {
				$config['allowed_content_types'] = array( 'post' );
				return $config;
			}
		);

		$result = Axtolab_AI_Connector_Policy::assert_allowed_content_type( 'page' );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	public function test_allowed_content_type_passes() {
		add_filter(
			'axtolab_ai_connector_config',
			static function( $config ) {
				$config['allowed_content_types'] = array( 'post', 'page' );
				return $config;
			}
		);

		$result = Axtolab_AI_Connector_Policy::assert_allowed_content_type( 'page' );
		$this->assertTrue( $result );
	}
}
