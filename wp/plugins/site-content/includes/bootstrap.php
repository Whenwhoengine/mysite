<?php
/**
 * site-content 載入點（M1 最小版：健康檢查、角色與服務帳號）。M2 由內容工作包擴充。
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'site-content/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					global $wpdb;
					$ok = (bool) $wpdb->get_var( 'SELECT 1' );
					return new WP_REST_Response(
						array(
							'data' => array( 'status' => $ok ? 'ok' : 'unavailable' ),
							'meta' => array(
								'api_version'   => 'v1',
								'model_version' => 1,
								'generated_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
							),
						),
						$ok ? 200 : 503
					);
				},
			)
		);
	}
);

/**
 * 內部診斷（INF-01）：只接受容器內 8080／8443 直接連線＋診斷權杖；回白名單欄位。
 */
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'site-content/v1',
			'/diagnostics',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function ( WP_REST_Request $request ) {
					$token = defined( 'MYSITE_DIAGNOSTICS_TOKEN' ) ? (string) MYSITE_DIAGNOSTICS_TOKEN : '';
					$given = (string) $request->get_header( 'x-mysite-diagnostics-token' );
					$port  = (int) ( $_SERVER['SERVER_PORT'] ?? 0 );
					if ( '' === $token || '' === $given || ! hash_equals( $token, $given ) || ! in_array( $port, array( 8080, 8443 ), true ) ) {
						return new WP_Error( 'site_content_not_found', '找不到內容', array( 'status' => 404 ) );
					}
					return true;
				},
				'callback'            => static function () {
					return new WP_REST_Response(
						array(
							'service'                         => 'wordpress',
							'sapi'                            => PHP_SAPI,
							'php_version'                     => PHP_VERSION,
							'session.upload_progress.enabled' => ini_get( 'session.upload_progress.enabled' ),
							'expose_php'                      => ini_get( 'expose_php' ),
							'display_errors'                  => ini_get( 'display_errors' ),
							'log_errors'                      => ini_get( 'log_errors' ),
							'wp_version'                      => get_bloginfo( 'version' ),
							'environment'                     => wp_get_environment_type(),
							'source_commit'                   => getenv( 'MYSITE_SOURCE_COMMIT' ) ?: 'unknown',
						),
						200
					);
				},
			)
		);
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * `wp site-content setup`：冪等建立本外掛擁有的角色與能力（M1 最小版）。
	 */
	WP_CLI::add_command(
		'site-content setup',
		static function () {
			$service = get_role( 'mysite_service' );
			if ( ! $service ) {
				add_role( 'mysite_service', 'mysite 服務帳號', array( 'read' => true ) );
				$service = get_role( 'mysite_service' );
			}
			foreach ( array( 'site_content_read_preview', 'site_content_diagnostics' ) as $cap ) {
				$service->add_cap( $cap );
			}
			WP_CLI::success( 'site-content 角色與能力已就緒。' );
		}
	);
}
