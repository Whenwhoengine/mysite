<?php
/**
 * Plugin Name:       mysite 內容整合（site-content）
 * Description:       影片／Podcast 內容型別、公開 REST（site-content/v1）、預覽票券、內容事件 outbox 與對帳。
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Author:            mysite
 * License:           GPL-2.0-or-later
 * Text Domain:       site-content
 */

defined( 'ABSPATH' ) || exit;

define( 'SITE_CONTENT_VERSION', '0.1.0' );
define( 'SITE_CONTENT_FILE', __FILE__ );
define( 'SITE_CONTENT_DIR', __DIR__ );

require_once __DIR__ . '/includes/bootstrap.php';
