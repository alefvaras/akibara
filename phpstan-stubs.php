<?php
/**
 * Stubs adicionales para librerías de terceros (RankMath, WP_CLI, etc.) para análisis estático.
 *
 * @package Akibara\DevSecOps
 */

namespace RankMath\Sitemap\Providers {
	if ( ! class_exists( Post_Type::class ) ) {
		class Post_Type {
			public function get_sitemap_links( $type, $max_entries, $current_page ) {
				return array();
			}

			/**
			 * @param string $post_type
			 * @param int $count
			 * @param int $offset
			 * @return array<int, object>|false
			 */
			protected function get_posts( $post_type, $count, $offset ) {
				return array();
			}
		}
	}
}

namespace RankMath\Redirections {
	if ( ! class_exists( 'RankMath\Redirections\DB' ) ) {
		class DB {
			public static function get_redirections( $args = array() ) {
				return array();
			}
			public static function change_status( $id, $status ) {
				return true;
			}
		}
	}
}

namespace RankMath {
	if ( ! class_exists( 'RankMath\Helper' ) ) {
		class Helper {
			public static function get_settings( $key = null, $default = null ) {
				return array();
			}
			public static function replace_vars( $string, $args = array() ) {
				return $string;
			}
			public static function is_breadcrumbs_enabled() {
				return true;
			}
		}
	}
}

namespace Automattic\WooCommerce\StoreApi\Exceptions {
	if ( ! class_exists( 'Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
		class RouteException extends \Exception {
			public function __construct( $code = '', $message = '', $status = 400, $additional_data = array() ) {
				parent::__construct( $message, $status );
			}
		}
	}
}

namespace Automattic\WooCommerce\Internal\CustomerEmailVerification {
	if ( ! class_exists( 'Automattic\WooCommerce\Internal\CustomerEmailVerification\EmailVerificationService' ) ) {
		class EmailVerificationService {
			public function is_verification_required( $user ) {
				return false;
			}
			public function is_verified( $user ) {
				return true;
			}
		}
	}
}

namespace {
	if ( ! class_exists( 'ActionScheduler' ) ) {
		class ActionScheduler {
			public static function store() {
				return new class {
					public function query_actions( $args = array() ) { return array(); }
					public function cancel_action( $action_id ) {}
				};
			}
		}
	}
	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
			return 1;
		}
	}
	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
			return 1;
		}
	}
	if ( ! function_exists( 'as_next_scheduled_action' ) ) {
		function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
			return false;
		}
	}
	if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
		function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
			return 0;
		}
	}
	if ( ! function_exists( 'as_has_scheduled_action' ) ) {
		function as_has_scheduled_action( $hook, $args = array(), $group = '' ) {
			return false;
		}
	}

	/**
	 * Propiedades de elementos de menú inyectadas por wp_setup_nav_menu_item() en WP_Post.
	 *
	 * @property array<int, string>|string $classes
	 * @property string $url
	 * @property string $title
	 * @property string $target
	 * @property string $attr_title
	 * @property string $xfn
	 * @property bool $current
	 * @property bool $current_item_ancestor
	 * @property bool $current_item_parent
	 * @property string|int $menu_item_parent
	 * @property string|int $object_id
	 * @property string $object
	 * @property string $type
	 * @property string $type_label
	 * @property int $db_id
	 */
	if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		/** @var int */
		public $ID;
		/** @var string */
		public $post_author = '';
		/** @var string */
		public $post_date = '';
		/** @var string */
		public $post_date_gmt = '';
		/** @var string */
		public $post_content = '';
		/** @var string */
		public $post_title = '';
		/** @var string */
		public $post_excerpt = '';
		/** @var string */
		public $post_status = '';
		/** @var string */
		public $comment_status = '';
		/** @var string */
		public $ping_status = '';
		/** @var string */
		public $post_password = '';
		/** @var string */
		public $post_name = '';
		/** @var string */
		public $to_ping = '';
		/** @var string */
		public $pinged = '';
		/** @var string */
		public $post_modified = '';
		/** @var string */
		public $post_modified_gmt = '';
		/** @var string */
		public $post_content_filtered = '';
		/** @var int */
		public $post_parent = 0;
		/** @var string */
		public $guid = '';
		/** @var int */
		public $menu_order = 0;
		/** @var string */
		public $post_type = '';
		/** @var string */
		public $post_mime_type = '';
		/** @var string */
		public $comment_count = '';
		/** @var string */
		public $filter = '';
		/** @var array<int, string>|string */
		public $classes = array();
		/** @var string */
		public $url = '';
		/** @var string */
		public $title = '';
		/** @var string */
		public $target = '';
		/** @var string */
		public $attr_title = '';
		/** @var string */
		public $xfn = '';
		/** @var bool */
		public $current = false;
		/** @var bool */
		public $current_item_ancestor = false;
		/** @var bool */
		public $current_item_parent = false;
		/** @var string|int */
		public $menu_item_parent = 0;
		/** @var string|int */
		public $object_id = 0;
		/** @var string */
		public $object = '';
		/** @var string */
		public $type = '';
	}

	/**
	 * Propiedades anulables en tiempo de ejecución de WooCommerce (CLI, REST, cron).
	 */
	}
	if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {
		/** @var \WC_Cart|null */
		public $cart = null;
		/** @var \WC_Customer|null */
		public $customer = null;
		/** @var \WC_Session|\WC_Session_Handler|null */
		public $session = null;
		/** @var \WC_Countries|null */
		public $countries = null;
		/** @var \WC_Query|null */
		public $query = null;
		/** @var \WC_Shipping|null */
		public $shipping = null;
		/** @var object|null */
		public $structured_data = null;
		/** @return \WC_Shipping */
		public function shipping() {}
		/** @return \WC_Mailer */
		public function mailer() {}
		/** @return \WC_Payment_Gateways */
		public function payment_gateways() {}
		/** @return \WC_Countries */
		public function countries() {}
		/** @param string $url @return string */
		public function api_request_url( $url ) { return $url; }
	}

	}
	if ( ! class_exists( 'WC_Mailer' ) ) {
		class WC_Mailer {
			public function wrap_message( $email_heading, $message, $plain_text = false ) { return ''; }
			public function get_emails() { return array(); }
		}
	}
	if ( ! class_exists( 'WC_Order_Item' ) ) {
	class WC_Order_Item {
		/** @return int */
		public function get_id() { return 0; }
		/** @return string */
		public function get_name() { return ''; }
		/** @return string */
		public function get_type() { return ''; }
		/** @return int */
		public function get_quantity() { return 1; }
		/** @return string */
		public function get_total() { return '0'; }
		/** @return string */
		public function get_total_tax() { return '0'; }
		/** @return string */
		public function get_subtotal() { return '0'; }
		/** @return string */
		public function get_subtotal_tax() { return '0'; }
		/** @return int */
		public function get_product_id() { return 0; }
		/** @return int */
		public function get_variation_id() { return 0; }
		/** @return \WC_Product|false */
		public function get_product() { return false; }
		/** @param string $key @param bool $single @return mixed */
		public function get_meta( $key = '', $single = true ) { return ''; }
		/** @param string $key @param mixed $value @param bool $unique @return void */
		public function add_meta_data( $key, $value, $unique = false ) {}
		/** @param string $key @param mixed $value @param int $meta_id @return void */
		public function update_meta_data( $key, $value, $meta_id = 0 ) {}
		/** @param string $key @return void */
		public function delete_meta_data( $key ) {}
		/** @return int */
		public function save() { return 0; }
	}
	}
}

namespace {
	if ( ! function_exists( 'akibara_abilities_audit_quien' ) ) {
		function akibara_abilities_audit_quien( ...$args ) { return null; }
	}
	if ( ! function_exists( 'akibara_gp_archive_sidebar_layout' ) ) {
		function akibara_gp_archive_sidebar_layout( ...$args ) { return null; }
	}
	if ( ! function_exists( 'akibara_gp_asset' ) ) {
		function akibara_gp_asset( ...$args ) { return null; }
	}
	if ( ! function_exists( 'akibara_gp_empty_popular_title' ) ) {
		function akibara_gp_empty_popular_title( ...$args ) { return null; }
	}
	if ( ! function_exists( 'akibara_gp_icon' ) ) {
		function akibara_gp_icon( ...$args ) { return null; }
	}
	if ( ! function_exists( 'akibara_gp_is_catalog_archive' ) ) {
		function akibara_gp_is_catalog_archive( ...$args ) { return null; }
	}
	if ( ! function_exists( 'akibara_gp_is_content_page' ) ) {
		function akibara_gp_is_content_page( ...$args ) { return null; }
	}
	if ( ! function_exists( 'akibara_gp_is_home_surface' ) ) {
		function akibara_gp_is_home_surface( ...$args ) { return null; }
	}
	if ( ! function_exists( 'akibara_gp_promise_installments' ) ) {
		function akibara_gp_promise_installments( ...$args ) { return null; }
	}
	if ( ! function_exists( 'akibara_gp_reviews_title' ) ) {
		function akibara_gp_reviews_title( ...$args ) { return null; }
	}
	if ( ! function_exists( 'akibara_gp_surface_has_product_loop' ) ) {
		function akibara_gp_surface_has_product_loop( ...$args ) { return null; }
	}
	if ( ! function_exists( 'akibara_gp_wc_activo' ) ) {
		function akibara_gp_wc_activo( ...$args ) { return null; }
	}
	if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
		function as_schedule_recurring_action( ...$args ) { return null; }
	}
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			public static function add_command( $name, $callable, $args = array() ) {}
			public static function success( $message ) {}
			public static function error( $message, $exit = true ) {}
			public static function warning( $message ) {}
			public static function log( $message ) {}
			public static function line( $message = '' ) {}
			public static function confirm( $question, $assoc_args = array() ) {}
			public static function colorize( $string ) { return $string; }
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		function format_items( $format, $items, $fields ) {}
	}
	if ( ! function_exists( 'WP_CLI\Utils\make_progress_bar' ) ) {
		function make_progress_bar( $message, $count ) {
			return new class {
				public function tick() {}
				public function finish() {}
			};
		}
	}
}
