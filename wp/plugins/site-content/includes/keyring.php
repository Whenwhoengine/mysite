<?php
/**
 * 簽章金鑰圈（GAP-KEYRING-01、規劃書 v5 附錄 D.3）：事件 HMAC 與預覽票券各自一個金鑰圈，用途分離。
 *
 * wp-config.php 在有 *_KEYRING_FILE 時解析金鑰圈並定義：
 *   MYSITE_CONTENT_EVENT_HMAC_KEYRING、MYSITE_PREVIEW_SIGNING_KEYRING（array<key_id, array{key,state}>），
 *   以及 active key 的 MYSITE_CONTENT_EVENT_KEY_ID／MYSITE_CONTENT_EVENT_HMAC_KEY、MYSITE_PREVIEW_SIGNING_KEY_ID／MYSITE_PREVIEW_SIGNING_KEY。
 * 沒有金鑰圈時沿用單一金鑰：事件以 CONTENT_EVENT_KEY_ID（預設 k1）、預覽以 k1 視為唯一一把 active。
 *
 * 發送端一律以 active key 簽章並帶 key ID；accept key 只供驗證（WP 端只有預覽票券需要驗證）。
 * 本類別絕不輸出金鑰；states() 只回傳 key ID 與狀態。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

if ( class_exists( __NAMESPACE__ . '\\Keyring', false ) ) {
	return;
}

/**
 * 金鑰圈讀取（只讀 wp-config 定義的常數，不直接讀檔）。
 */
final class Keyring {

	public const EVENT          = 'event-hmac';
	public const PREVIEW        = 'preview';
	public const KEY_ID_PATTERN = '/^k[0-9]{1,6}$/D';
	public const KEY_PATTERN    = '/^[0-9a-f]{64}$/D';
	public const DEFAULT_KEY_ID = 'k1';

	/**
	 * 目前的金鑰圈（key_id → array{key,state}）。
	 *
	 * @param string $kind event-hmac 或 preview。
	 * @return array<string,array{key:string,state:string}>
	 */
	public static function ring( string $kind ): array {
		if ( self::EVENT === $kind ) {
			if ( defined( 'MYSITE_CONTENT_EVENT_HMAC_KEYRING' ) && is_array( MYSITE_CONTENT_EVENT_HMAC_KEYRING ) ) {
				return MYSITE_CONTENT_EVENT_HMAC_KEYRING;
			}
			$id  = defined( 'MYSITE_CONTENT_EVENT_KEY_ID' ) ? (string) MYSITE_CONTENT_EVENT_KEY_ID : '';
			$key = defined( 'MYSITE_CONTENT_EVENT_HMAC_KEY' ) ? (string) MYSITE_CONTENT_EVENT_HMAC_KEY : '';
			return '' === $id ? array() : array(
				$id => array(
					'key'   => $key,
					'state' => 'active',
				),
			);
		}
		if ( self::PREVIEW === $kind ) {
			if ( defined( 'MYSITE_PREVIEW_SIGNING_KEYRING' ) && is_array( MYSITE_PREVIEW_SIGNING_KEYRING ) ) {
				return MYSITE_PREVIEW_SIGNING_KEYRING;
			}
			$key = defined( 'MYSITE_PREVIEW_SIGNING_KEY' ) ? (string) MYSITE_PREVIEW_SIGNING_KEY : '';
			return array(
				self::DEFAULT_KEY_ID => array(
					'key'   => $key,
					'state' => 'active',
				),
			);
		}
		throw new \InvalidArgumentException( 'kind 只允許 event-hmac 或 preview。' );
	}

	/**
	 * 簽章用的 active key。
	 *
	 * @param string                                             $kind 種類。
	 * @param array<string,array{key:string,state:string}>|null $ring 指定金鑰圈（自我測試用）；null 時讀目前設定。
	 * @return array{id:string,key:string}
	 * @throws \RuntimeException 沒有可用的 active key。
	 */
	public static function active( string $kind, ?array $ring = null ): array {
		foreach ( $ring ?? self::ring( $kind ) as $id => $entry ) {
			if ( 'active' === ( $entry['state'] ?? '' ) && '' !== (string) ( $entry['key'] ?? '' ) ) {
				return array(
					'id'  => (string) $id,
					'key' => (string) $entry['key'],
				);
			}
		}
		throw new \RuntimeException( '缺少 ' . $kind . ' 簽章金鑰。' );
	}

	/**
	 * 依 key ID 找驗證用金鑰（active 或 accept）；不在金鑰圈回傳 null。
	 *
	 * @param string                                             $kind 種類。
	 * @param string                                             $id   key ID。
	 * @param array<string,array{key:string,state:string}>|null $ring 指定金鑰圈（自我測試用）。
	 */
	public static function find( string $kind, string $id, ?array $ring = null ): ?string {
		$found = null;
		foreach ( $ring ?? self::ring( $kind ) as $kid => $entry ) {
			if ( hash_equals( (string) $kid, $id ) && '' !== (string) ( $entry['key'] ?? '' ) ) {
				$found = (string) $entry['key'];
			}
		}
		return $found;
	}

	/**
	 * key ID → 狀態（依數字排序；不含金鑰），供 `wp site-content keyring` 與輪替驗證使用。
	 *
	 * @param string $kind 種類。
	 * @return array<string,string>
	 */
	public static function states( string $kind ): array {
		$states = array();
		foreach ( self::ring( $kind ) as $id => $entry ) {
			$states[ (string) $id ] = (string) ( $entry['state'] ?? '' );
		}
		uksort(
			$states,
			static function ( $a, $b ): int {
				$na = preg_match( '/^k([0-9]{1,6})$/D', (string) $a, $ma ) ? (int) $ma[1] : PHP_INT_MAX;
				$nb = preg_match( '/^k([0-9]{1,6})$/D', (string) $b, $mb ) ? (int) $mb[1] : PHP_INT_MAX;
				return ( $na <=> $nb ) ?: strcmp( (string) $a, (string) $b );
			}
		);
		return $states;
	}
}
