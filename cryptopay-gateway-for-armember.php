<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

// @phpcs:disable PSR1.Files.SideEffects
// @phpcs:disable PSR12.Files.FileHeader
// @phpcs:disable Generic.Files.InlineHTML
// @phpcs:disable Generic.Files.LineLength

/**
 * Plugin Name: CryptoPay Gateway for ARMember
 * Version:     1.0.1
 * Plugin URI:  https://beycanpress.com/cryptopay/
 * Description: Adds Cryptocurrency payment gateway (CryptoPay) for ARMember.
 * Author:      BeycanPress LLC
 * Author URI:  https://beycanpress.com
 * License:     GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: arm-cryptopay
 * Tags: Bitcoin, Ethereum, Crypto, Payment, ARMember
 * Requires at least: 5.0
 * Tested up to: 6.7.1
 * Requires PHP: 8.1
*/

// Autoload
require_once __DIR__ . '/vendor/autoload.php';

define('ARM_CRYPTOPAY_FILE', __FILE__);
define('ARM_CRYPTOPAY_VERSION', '1.0.1');
define('ARM_CRYPTOPAY_KEY', basename(__DIR__));
define('ARM_CRYPTOPAY_URL', plugin_dir_url(__FILE__));
define('ARM_CRYPTOPAY_DIR', plugin_dir_path(__FILE__));
define('ARM_CRYPTOPAY_SLUG', plugin_basename(__FILE__));

use BeycanPress\CryptoPay\Integrator\Helpers;

/**
 * @return void
 */
function armCryptoPayRegisterModels(): void
{
    Helpers::registerModel(BeycanPress\CryptoPay\ARM\Models\TransactionsPro::class);
    Helpers::registerLiteModel(BeycanPress\CryptoPay\ARM\Models\TransactionsLite::class);
}

armCryptoPayRegisterModels();

add_action('init', function (): void {
    load_plugin_textdomain('arm-cryptopay', false, basename(__DIR__) . '/languages');
});

add_action('plugins_loaded', function (): void {
    armCryptoPayRegisterModels();

    if (!defined('MEMBERSHIPLITE_DIR_NAME')) {
        Helpers::requirePluginMessage('ARMember', admin_url('plugin-install.php?s=armember&tab=search&type=term'));
    } elseif (Helpers::bothExists()) {
        new BeycanPress\CryptoPay\ARM\Loader();
    } else {
        Helpers::requireCryptoPayMessage('ARMember');
    }
});
