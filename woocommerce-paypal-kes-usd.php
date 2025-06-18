<?php
/**
 * Plugin Name:       WooCommerce PayPal KES to USD Converter (Dynamic)
 * Plugin URI:        https://example.com/your-plugin-uri (Replace with your actual URI)
 * Description:       Converts WooCommerce Cart/Order total from KES to USD specifically for PayPal, using dynamic exchange rates.
 * Version:           1.1.0
 * Author:            Your Name
 * Author URI:        https://example.com/your-website (Replace with your actual URI)
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wcpkuc-dynamic
 * Domain Path:       /languages
 *
 * This plugin is a custom solution provided as a starting point for dynamic rates.
 * It integrates with a chosen exchange rate API (you need to sign up for an API key).
 * Extensive testing in a staging environment is CRUCIAL before live deployment.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Main Plugin Class
 */
class WC_PayPal_KES_USD_Converter_Dynamic {

    // --- CONFIGURATION ---
    // You MUST sign up for an account at an exchange rate API provider (e.g., ExchangeRate-API.com, Freecurrencyapi.com).
    // Get your API Key and replace 'YOUR_API_KEY' below.
    // KEEP YOUR API KEY SECURE. For a production site, ideally store this in wp-config.php or as a secure option.
    const EXCHANGE_RATE_API_KEY = 'ebd07e4391a0b2d61fcf3a36'; // <<< REPLACE THIS WITH YOUR ACTUAL API KEY

    // The API endpoint. This example uses ExchangeRate-API.com's free tier structure.
    // If using a different API, this URL will change.
    // Make sure your API supports KES and USD.
    const EXCHANGE_RATE_API_URL = 'https://v6.exchangerate-api.com/v6/' . self::EXCHANGE_RATE_API_KEY . '/latest/USD';

    // How often to update the exchange rate (in seconds).
    // Recommended: HOUR_IN_SECONDS * 12 (twice a day) or DAY_IN_SECONDS (once a day)
    const UPDATE_INTERVAL_SECONDS = DAY_IN_SECONDS; // WordPress constant for 24 hours

    // The currency code for Kenyan Shillings.
    const KES_CURRENCY_CODE = 'KES';

    // The currency code for United States Dollar.
    const USD_CURRENCY_CODE = 'USD';

    // Option name to store the last fetched exchange rate.
    const EXCHANGE_RATE_OPTION_NAME = 'wcpkuc_kes_to_usd_exchange_rate';

    // Cron job hook name.
    const CRON_HOOK_NAME = 'wcpkuc_update_exchange_rate_cron';

    public function __construct() {
        // Only proceed if WooCommerce is active.
        if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
            add_action( 'woocommerce_before_calculate_totals', array( $this, 'maybe_convert_cart_to_usd_for_paypal' ), 99 );
            add_filter( 'woocommerce_currency_symbol', array( $this, 'display_kes_symbol_on_frontend' ), 10, 2 );
            add_filter( 'woocommerce_currency', array( $this, 'maybe_set_checkout_currency_for_paypal' ), 99 );
            add_filter( 'woocommerce_get_shop_currency_tooltip_text', array( $this, 'add_currency_info_tooltip' ) );

            // Hooks for different PayPal plugins
            add_filter( 'woocommerce_paypal_args', array( $this, 'force_paypal_args_currency' ), 99 ); // For PayPal Standard
            add_filter( 'woocommerce_paypal_payments_order_total_currency', array( $this, 'force_wc_paypal_payments_currency' ), 99, 2 ); // For WooCommerce PayPal Payments

            // Cron job for updating exchange rate
            add_action( self::CRON_HOOK_NAME, array( $this, 'update_exchange_rate' ) );
            add_action( 'admin_init', array( $this, 'schedule_exchange_rate_cron' ) );
            add_action( 'admin_notices', array( $this, 'api_key_missing_notice' ) );

            // Add plugin activation/deactivation hooks
            register_activation_hook( __FILE__, array( $this, 'activate' ) );
            register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        } else {
            add_action( 'admin_notices', array( $this, 'woocommerce_not_active_notice' ) );
        }
    }

    /**
     * Plugin Activation Hook.
     * Schedules the cron job and attempts initial rate fetch.
     */
    public function activate() {
        $this->schedule_exchange_rate_cron();
        $this->update_exchange_rate(); // Fetch initial rate on activation
    }

    /**
     * Plugin Deactivation Hook.
     * Clears the scheduled cron job.
     */
    public function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK_NAME );
    }

    /**
     * Schedules the cron job to update exchange rates if not already scheduled.
     */
    public function schedule_exchange_rate_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK_NAME ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK_NAME ); // 'daily' is a default WordPress interval
            // You can add custom intervals if needed using 'cron_schedules' filter.
        }
    }

    /**
     * Fetches and updates the KES to USD exchange rate from the API.
     */
    public function update_exchange_rate() {
        if ( empty( self::EXCHANGE_RATE_API_KEY ) || self::EXCHANGE_RATE_API_KEY === 'YOUR_API_KEY' ) {
            error_log( 'WooCommerce PayPal KES to USD Converter: API Key not set. Cannot fetch dynamic exchange rate.' );
            return;
        }

        $response = wp_remote_get( self::EXCHANGE_RATE_API_URL );

        if ( is_wp_error( $response ) ) {
            error_log( 'WooCommerce PayPal KES to USD Converter: API request failed: ' . $response->get_error_message() );
            return;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        // Example for ExchangeRate-API.com format. Adjust if using another API.
        if ( isset( $data['conversion_rates'][self::KES_CURRENCY_CODE] ) ) {
            $usd_to_kes_rate = (float) $data['conversion_rates'][self::KES_CURRENCY_CODE];
            // We need KES to USD rate, which is 1 / (USD to KES rate)
            $kes_to_usd_rate = 1 / $usd_to_kes_rate;

            update_option( self::EXCHANGE_RATE_OPTION_NAME, $kes_to_usd_rate );
            error_log( 'WooCommerce PayPal KES to USD Converter: Exchange rate updated to 1 USD = ' . $usd_to_kes_rate . ' KES (KES to USD rate: ' . $kes_to_usd_rate . ')' );
        } else {
            error_log( 'WooCommerce PayPal KES to USD Converter: Could not retrieve KES exchange rate from API response.' );
        }
    }

    /**
     * Retrieves the current KES to USD exchange rate.
     * Uses cached rate or falls back to a default if API fails/no rate found.
     *
     * @return float The KES to USD exchange rate.
     */
    private function get_current_exchange_rate() {
        $rate = get_option( self::EXCHANGE_RATE_OPTION_NAME );

        // Fallback to a default if no rate is set or API failed.
        if ( ! $rate || ! is_numeric( $rate ) || $rate <= 0 ) {
            // Log a warning if falling back to default.
            error_log( 'WooCommerce PayPal KES to USD Converter: Falling back to default exchange rate (1 USD = 130 KES) as dynamic rate is not available.' );
            // This is the fallback if API fails or hasn't updated yet.
            // You might want to make this configurable in a real plugin.
            return 1 / 130.0; // Default KES to USD rate if dynamic fails (1 USD = 130 KES)
        }
        return (float) $rate;
    }

    /**
     * Admin notice if WooCommerce is not active.
     */
    public function woocommerce_not_active_notice() {
        ?>
        <div class="notice notice-warning">
            <p><?php esc_html_e( 'WooCommerce PayPal KES to USD Converter requires WooCommerce to be active. Please activate WooCommerce to use this plugin.', 'wcpkuc-dynamic' ); ?></p>
        </div>
        <?php
    }

    /**
     * Admin notice if API Key is missing.
     */
    public function api_key_missing_notice() {
        if ( is_admin() && ( empty( self::EXCHANGE_RATE_API_KEY ) || self::EXCHANGE_RATE_API_KEY === 'YOUR_API_KEY' ) ) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong><?php esc_html_e( 'WooCommerce PayPal KES to USD Converter Alert:', 'wcpkuc-dynamic' ); ?></strong> <?php esc_html_e( 'Please set your Exchange Rate API Key in the plugin file `woocommerce-paypal-kes-usd.php` for dynamic exchange rates to work. Using fallback rate.', 'wcpkuc-dynamic' ); ?></p>
            </div>
            <?php
        }
    }


    /**
     * Displays KES symbol on the frontend if the shop currency is KES.
     * This makes sure your product pages show KES.
     *
     * @param string $currency_symbol The currency symbol.
     * @param string $currency The currency code.
     * @return string
     */
    public function display_kes_symbol_on_frontend( $currency_symbol, $currency ) {
        // Only if WooCommerce's base currency is set to KES.
        if ( get_woocommerce_currency() === self::KES_CURRENCY_CODE ) {
            return 'KSh '; // Or any other KES symbol you prefer.
        }
        return $currency_symbol;
    }

    /**
     * Attempts to force the currency to USD during checkout for PayPal gateway.
     * This is a critical filter for many PayPal integrations.
     *
     * @param string $currency The current currency.
     * @return string
     */
    public function maybe_set_checkout_currency_for_paypal( $currency ) {
        // Check if we're in the context of a checkout or payment processing.
        // This includes AJAX calls for updating checkout.
        if ( is_checkout() || defined( 'WOOCOMMERCE_CHECKOUT' ) || defined( 'WOOCOMMERCE_CART' ) || WC()->session->get( 'chosen_payment_method' ) === 'paypal' || WC()->session->get( 'chosen_payment_method' ) === 'ppec_paypal' ) {
            // Check if the current shop currency is KES
            if ( get_woocommerce_currency() === self::KES_CURRENCY_CODE ) {
                // If PayPal is the chosen payment method, attempt to switch to USD.
                // This targets both initial checkout load and AJAX updates.
                if ( ! is_admin() && ( ( isset( $_POST['payment_method'] ) && ( $_POST['payment_method'] === 'paypal' || $_POST['payment_method'] === 'ppec_paypal' ) ) || ( WC()->session->get( 'chosen_payment_method' ) === 'paypal' ) || ( WC()->session->get( 'chosen_payment_method' ) === 'ppec_paypal' ) ) ) {
                    return self::USD_CURRENCY_CODE;
                }
            }
        }
        return $currency;
    }

    /**
     * Recalculates cart totals based on USD if PayPal is chosen and base currency is KES.
     * This needs to happen before PayPal receives the total.
     *
     * @param WC_Cart $cart The WooCommerce cart object.
     */
    public function maybe_convert_cart_to_usd_for_paypal( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return; // Don't run in admin unless it's an AJAX call related to checkout.
        }

        // Get the chosen payment method during checkout (or if it's already set in session)
        $chosen_payment_method = WC()->session->get( 'chosen_payment_method' );
        if ( isset( $_POST['payment_method'] ) ) {
            $chosen_payment_method = sanitize_text_field( wp_unslash( $_POST['payment_method'] ) );
        }

        // Check if PayPal is the chosen payment method AND shop currency is KES
        if ( ( $chosen_payment_method === 'paypal' || $chosen_payment_method === 'ppec_paypal' ) && get_woocommerce_currency() === self::KES_CURRENCY_CODE ) {

            // Prevent infinite loops if this filter is called multiple times during conversion.
            if ( did_action( 'wcpkuc_currency_conversion_applied' ) ) {
                return;
            }

            $kes_to_usd_rate = $this->get_current_exchange_rate();

            // Only proceed if we have a valid rate
            if ( $kes_to_usd_rate > 0 ) {
                $current_total_kes = $cart->get_total( false ); // Get raw total in KES

                $converted_total_usd = (float) $current_total_kes * $kes_to_usd_rate; // KES * (USD/KES) = USD

                // Round to 2 decimal places for currency.
                $converted_total_usd = round( $converted_total_usd, wc_get_price_decimals() );

                // Temporarily store the original KES total if needed for order notes.
                // $cart->add_fee( 'Original KES Total', $current_total_kes, false, '' ); // Example for debugging/logging

                // Set the cart total to the converted USD value.
                // IMPORTANT: This directly overrides the total.
                $cart->set_total( $converted_total_usd );

                // Mark that conversion has been applied to prevent re-conversion.
                do_action( 'wcpkuc_currency_conversion_applied' );

                error_log( 'WooCommerce PayPal KES to USD Converter: Cart total converted from KES ' . $current_total_kes . ' to USD ' . $converted_total_usd . ' using rate ' . $kes_to_usd_rate );
            } else {
                error_log( 'WooCommerce PayPal KES to USD Converter: Cannot convert cart total. Invalid KES to USD exchange rate.' );
            }
        }
    }

    /**
     * Filter for 'woocommerce_paypal_args' (for PayPal Standard) to ensure currency is USD.
     *
     * @param array $args PayPal arguments.
     * @return array Modified PayPal arguments.
     */
    public function force_paypal_args_currency( $args ) {
        if ( get_woocommerce_currency() === self::KES_CURRENCY_CODE ) {
            // Check if cart total was converted.
            if ( did_action( 'wcpkuc_currency_conversion_applied' ) ) {
                $args['currency_code'] = self::USD_CURRENCY_CODE;
                error_log( 'WooCommerce PayPal KES to USD Converter: PayPal Standard args currency forced to USD.' );
            }
        }
        return $args;
    }

    /**
     * Filter for 'woocommerce_paypal_payments_order_total_currency' (for WooCommerce PayPal Payments)
     * to ensure currency is USD.
     *
     * @param string $currency The current currency.
     * @param WC_Order $order The order object.
     * @return string
     */
    public function force_wc_paypal_payments_currency( $currency, $order ) {
        // This filter fires for the actual payment processing, so we need to ensure the order's currency is adjusted.
        if ( $order->get_currency() === self::KES_CURRENCY_CODE ) {
            // The cart would have already been converted by maybe_convert_cart_to_usd_for_paypal.
            // We just ensure the currency code sent to PayPal is USD.
            error_log( 'WooCommerce PayPal KES to USD Converter: WooCommerce PayPal Payments currency forced to USD.' );
            return self::USD_CURRENCY_CODE;
        }
        return $currency;
    }

    /**
     * Adds a tooltip to the shop currency display to inform users about PayPal conversion.
     * @param string $text The existing tooltip text.
     * @return string
     */
    public function add_currency_info_tooltip( $text ) {
        if ( get_woocommerce_currency() === self::KES_CURRENCY_CODE ) {
            $current_rate = $this->get_current_exchange_rate();
            $usd_to_kes_display_rate = ( $current_rate > 0 ) ? round( 1 / $current_rate, 2 ) : 'N/A';
            $text .= '<br/><small>Note: PayPal payments will be processed in USD (current rate: 1 USD ≈ ' . esc_html( $usd_to_kes_display_rate ) . ' KES).</small>';
        }
        return $text;
    }
}

// Instantiate the class.
new WC_PayPal_KES_USD_Converter_Dynamic();
