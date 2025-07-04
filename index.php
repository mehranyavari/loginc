<?php
/**
 * Plugin Name:       LoginC (Optimized Version)
 * Description:       Custom Login/Register flow for WooCommerce.
 * Version:           2.0.0
 * Author:            codera (Refactored by AI Assistant)
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * WC tested up to:    8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * کلیدهای API را در فایل wp-config.php تعریف کنید تا امن بمانند.
 * این خطوط را به wp-config.php اضافه کنید:
 * define('SMS_IR_API_KEY', 'YOUR_SMS_IR_API_KEY_HERE');
 * define('SMS_IR_TEMPLATE_ID', '837388');
 */
define('SMS_IR_API_KEY', '4CyUtWmXAF1SQ9EZhjnfD0bRy6hx9BdrAOknNdq7ehFEKZqB');
define('SMS_IR_TEMPLATE_ID', 454839);
class LoginC_Plugin {

	public function __construct() {
		add_action( 'init', [ $this, 'init_hooks' ] );
		add_filter( 'template_include', [ $this, 'override_account_template' ], 99 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * تمام اکشن‌های AJAX را اینجا ثبت می‌کنیم
	 */
	public function init_hooks() {
		add_action( 'wp_ajax_nopriv_loginc_request_code', [ $this, 'ajax_request_code' ] );
		add_action( 'wp_ajax_nopriv_loginc_verify_code', [ $this, 'ajax_verify_code' ] );
	}

	/**
	 * جایگزینی تمپلیت صفحه حساب کاربری فقط برای کاربران خارج شده
	 */
	public function override_account_template( $template ) {
		if ( is_account_page() && ! is_user_logged_in() ) {
			$new_template = plugin_dir_path( __FILE__ ) . 'html.php';
			if ( file_exists( $new_template ) ) {
				return $new_template;
			}
		}
		return $template;
	}

	/**
	 * ثبت و فراخوانی اسکریپت‌ها و استایل‌ها به روش استاندارد
	 */
	public function enqueue_scripts() {
		// فقط در صفحه حساب کاربری برای کاربران خارج شده اسکریپت را لود کن
		if ( is_account_page() && ! is_user_logged_in() ) {

			// فایل CSS خود را اینجا ثبت کنید (اگر دارید)
			// wp_enqueue_style( 'loginc-style', plugin_dir_url(__FILE__) . 'dist/assets/index.css' );

			// ثبت و فراخوانی فایل جاوااسکریپت
			wp_enqueue_script(
				'loginc-script',
				plugin_dir_url( __FILE__ ) . 'dist/loginc.js',
				[ 'jquery' ],
				'2.0.0', // نسخه افزونه
				true // در فوتر لود شود
			);

			// ارسال داده‌های لازم به جاوااسکریپت به روش امن
			wp_localize_script(
				'loginc-script',
				'jsdata', // نام آبجکت در جاوااسکریپت
				[
					'ajax_url'    => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'loginc_ajax_nonce' ), // ایجاد نانس امنیتی
					'redirect_url' => wc_get_page_permalink( 'myaccount' ), // لینک صفحه حساب کاربری
				]
			);
		}
	}

	/**
	 * درخواست کد تایید با AJAX
	 */
	public function ajax_request_code() {
		check_ajax_referer( 'loginc_ajax_nonce', 'nonce' ); // بررسی نانس امنیتی

		$phone_or_email = sanitize_text_field( $_POST['phone_or_email'] ?? '' );
		$fullname       = sanitize_text_field( $_POST['fullname'] ?? '' );
		$code           = random_int( 10000, 99999 );
		$user_id        = false;

		// اگر شماره موبایل بود
		if ( preg_match( '/^(09\d{9})$/', $phone_or_email, $matches ) ) {
			$phone = $matches[1];
			$user_id = $this->get_user_id_by_phone( $phone );

			// اگر کاربر وجود نداشت و نام کامل وارد شده بود، کاربر جدید بساز
			if ( ! $user_id && ! empty( $fullname ) ) {
				$user_id = wp_create_user( $phone, wp_generate_password(), "$phone@temp.email" );
				if ( is_wp_error( $user_id ) ) {
					wp_send_json_error( [ 'message' => 'خطا در ساخت کاربر.' ] );
				}
				update_user_meta( $user_id, 'first_name', $fullname );
				update_user_meta( $user_id, 'billing_phone', $phone );
				do_action( 'woocommerce_created_customer', $user_id );
			}

			if ( $user_id ) {
				set_transient( 'verification_code_' . $user_id, $code, 300 ); // 5 دقیقه اعتبار
				$this->send_sms_code( $phone, $code );
				wp_send_json_success( [ 'message' => 'کد تایید ارسال شد.' ] );
			}
		}
		// اگر ایمیل بود
		elseif ( is_email( $phone_or_email ) ) {
			$user = get_user_by( 'email', $phone_or_email );
			if ( $user ) {
				$user_id = $user->ID;
				set_transient( 'verification_code_' . $user_id, $code, 300 ); // 5 دقیقه اعتبار
				wp_mail( $phone_or_email, 'کد تایید ورود', "کد تایید شما: $code" );
				wp_send_json_success( [ 'message' => 'کد تایید به ایمیل شما ارسال شد.' ] );
			}
		}

		wp_send_json_error( [ 'message' => 'کاربری با این مشخصات یافت نشد. لطفا ابتدا ثبت‌نام کنید.' ] );
	}

	/**
	 * تایید کد و ورود کاربر با AJAX
	 */
	public function ajax_verify_code() {
		check_ajax_referer( 'loginc_ajax_nonce', 'nonce' ); // بررسی نانس امنیتی

		$phone_or_email = sanitize_text_field( $_POST['phone_or_email'] ?? '' );
		$verification_code = sanitize_text_field( $_POST['verification_code'] ?? '' );
		$user_id = false;
		
		if ( preg_match( '/^(09\d{9})$/', $phone_or_email, $matches ) ) {
			$user_id = $this->get_user_id_by_phone( $matches[1] );
		} elseif ( is_email( $phone_or_email ) ) {
			$user = get_user_by( 'email', $phone_or_email );
			if ( $user ) $user_id = $user->ID;
		}

		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => 'کاربر یافت نشد.' ] );
		}

		$stored_code = get_transient( 'verification_code_' . $user_id );
		if ( false === $stored_code ) {
			wp_send_json_error( [ 'message' => 'کد منقضی شده است، لطفا دوباره تلاش کنید.' ] );
		}
		if ( (string) $stored_code !== (string) $verification_code ) {
			wp_send_json_error( [ 'message' => 'کد وارد شده اشتباه است.' ] );
		}

		// کد صحیح است، کاربر را وارد کن
		delete_transient( 'verification_code_' . $user_id );
		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );
		$user = get_user_by( 'ID', $user_id );
		do_action( 'wp_login', $user->user_login, $user );

		wp_send_json_success( [ 'redirect_url' => wc_get_page_permalink('myaccount') ] );
	}
	
	private function get_user_id_by_phone( $phone ) {
		// جستجو بر اساس نام کاربری (که شماره موبایل است)
		$user = get_user_by( 'login', $phone );
		if ( $user ) {
			return $user->ID;
		}
		
		// جستجو بر اساس متا (شماره صورتحساب)
		$users = get_users([
			'meta_key' => 'billing_phone',
			'meta_value' => $phone,
			'number' => 1,
			'fields' => 'ID',
		]);
		return ! empty( $users ) ? $users[0] : false;
	}

	private function send_sms_code( $phone, $code ) {
		if ( ! defined('SMS_IR_API_KEY') || ! defined('SMS_IR_TEMPLATE_ID') ) {
			error_log( 'SMS.IR API Key or Template ID not defined in wp-config.php' );
			return false;
		}

		$api_key    = SMS_IR_API_KEY;
		$templateId = SMS_IR_TEMPLATE_ID;
		$url        = 'https://api.sms.ir/v1/send/verify';
		$payload    = json_encode([
			'mobile'     => $phone,
			'templateId' => (int) $templateId,
			'parameters' => [
				[ 'name' => 'code', 'value' => (string) $code ],
			],
		]);


		$response = wp_remote_post($url, [
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'text/plain',
				'x-api-key'    => $api_key,
			],
			'body' => $payload,
		]);
		
		if ( is_wp_error( $response ) ) {
			error_log( 'SMS Sending Error: ' . $response->get_error_message() );
			return false;
		}
		return true;
	}
}

new LoginC_Plugin();