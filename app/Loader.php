<?php

declare(strict_types=1);

namespace BeycanPress\CryptoPay\ARM;

use BeycanPress\CryptoPay\Integrator\Type;
use BeycanPress\CryptoPay\Integrator\Hook;
use BeycanPress\CryptoPay\Integrator\Helpers;

class Loader
{
    /**
     * Loader constructor.
     */
    public function __construct()
    {
        Helpers::registerIntegration('arm');

        // add transaction page
        Helpers::createTransactionPage(
            esc_html__('ARMember Transactions', 'pp-cryptopay'),
            'arm',
            10
        );

        Hook::addFilter('before_payment_finished_arm', [$this, 'paymentFinished']);
        Hook::addFilter('payment_redirect_urls_arm', [$this, 'paymentRedirectUrls']);

        add_action('init', [Helpers::class, 'listenSPP']);
        add_filter('arm_get_payment_gateways', [$this, 'addGateway']);
        add_filter('arm_filter_gateway_names', [$this, 'addGatewayNames']);
        add_filter('arm_get_payment_gateways_in_filters', [$this, 'addGateway']);
        add_filter('arm_setup_data_before_setup_shortcode', [$this, 'addGatewayKey']);
        add_filter('arm_payment_gateway_has_ccfields', [$this, 'hasCCFields'], 10, 2);
        add_action('arm_payment_gateway_validation_from_setup', [$this, 'validatePaymentGateway'], 10, 4);
    }

        /**
     * @param object $data
     * @return object
     */
    public function paymentFinished(object $data): object
    {
        if (!$data->getStatus()) {
            return $data;
        }

        global $arm_payment_gateways, $arm_subscription_plans, $wpdb, $ARMemberLite;

        $userId = $data->getUserId();
        $amount = $data->getOrder()->getAmount();
        $entryId = $data->getParams()->get('entryId');
        $gatewayKey = $data->getParams()->get('gatewayKey');
        $postedData = (array) $data->getParams()->get('postedData');

        $currency  = $arm_payment_gateways->arm_get_global_currency();
        $entryData = $arm_payment_gateways->arm_get_entry_data_by_id($entryId);
        $userId    = $entryData['arm_user_id'];

        if (isset($postedData['first_name']) && isset($postedData['last_name'])) {
            $firstName = $postedData['first_name'];
            $firstName = $postedData['last_name'];
        } elseif (!empty($userId)) {
            $userData    = get_userdata($userId);
            $firstName = $userData->first_name;
            $firstName  = $userData->last_name;
        }


        $planId  = (!empty($postedData['subscription_plan'])) ? intval($postedData['subscription_plan']) : 0;
        if (0 == $planId) {
            $planId = (!empty($postedData['_subscription_plan'])) ? intval($postedData['_subscription_plan']) : 0;
        }

        $plan = new \ARM_Plan_Lite($planId);

        $paymentMode = 'one_time';
        if ($plan->is_recurring()) {
            $paymentMode = 'manual_subscription';
        }

        $entryValues  = maybe_unserialize($entryData['arm_entry_value']);
        $paymentCycle = $entryValues['arm_selected_payment_cycle'];

        $extraVars = [
            'plan_amount' => $amount,
        ];

        $oldPlanId = isset($postedData['old_plan_id']) ? $postedData['old_plan_id'] : 0;
        $userOldPlan = (isset($postedData['old_plan_id']) && ! empty($postedData['old_plan_id'])) ? explode(',', $postedData['old_plan_id']) : []; // phpcs:ignore

        $recurringData = $plan->prepare_recurring_data($paymentCycle);

        $isTrial = '0';
        if ($plan->is_recurring() && $plan->has_trial_period() && empty($userOldPlan)) {
            $extraVars['trial']        = $recurringData['trial'];
            $extraVars['arm_is_trial'] = $isTrial = '1';
            $amount = $plan->options['trial']['amount'];
        }

        $extraVars['paid_amount'] = number_format((float) $amount, 2, '.', '');

        $paymentData = [
            'arm_user_id'                  => $userId,
            'arm_first_name'               => $firstName,
            'arm_last_name'                => $firstName,
            'arm_plan_id'                  => $plan->ID,
            'arm_old_plan_id'              => $oldPlanId,
            'arm_payer_email'              => $entryData['arm_entry_email'],
            'arm_amount'                   => $amount,
            'arm_payment_gateway'          => $gatewayKey,
            'arm_payment_type'             => $paymentMode,
            'arm_transaction_payment_type' => $paymentMode,
            'arm_payment_mode'             => $paymentMode,
            'arm_payment_cycle'            => $paymentCycle,
            'arm_currency'                 => $currency,
            'arm_extra_vars'               => maybe_serialize($extraVars),
            'arm_transaction_status'       => 'completed',
            'arm_is_trial'                 => $isTrial,
            'arm_created_date'             => current_time('mysql'),
            'arm_payment_date'             => current_time('mysql'),
            'arm_invoice_id'               => get_option('arm_last_invoice_id', 0)
        ];

        $plan = new \ARM_Plan_Lite($planId);
        $oldPlan = new \ARM_Plan_Lite($oldPlanId);

        $payment = $wpdb->get_row(sprintf("SELECT arm_log_id FROM {$ARMemberLite->tbl_arm_payment_log} WHERE arm_plan_id = %d AND arm_user_id = %d AND arm_old_plan_id = %d ORDER BY arm_log_id DESC", $paymentData['arm_plan_id'], $paymentData['arm_user_id'], $paymentData['arm_old_plan_id'])); // phpcs:ignore

        if (!$payment) {
            $wpdb->insert($ARMemberLite->tbl_arm_payment_log, $paymentData);
            $paymentId = $wpdb->insert_id;
        } else {
            $paymentId = $payment->arm_log_id;
        }

        if ($paymentId) {
            update_option('arm_last_invoice_id', $paymentData['arm_invoice_id'] + 1);
            $data->getOrder()->setId(intval($paymentId));
            $data->getParams()->set('postedData', null);
        }

        $logDetail = $wpdb->get_row($wpdb->prepare('SELECT `arm_log_id`, `arm_user_id`, `arm_token`, `arm_transaction_id`, `arm_extra_vars` FROM `' . $ARMemberLite->tbl_arm_payment_log . "` WHERE `arm_log_id`=%d", $paymentId)); // phpcs:ignore
        update_user_meta($userId, 'arm_entry_id', $entryId);

        $userPlanData['arm_user_gateway'] = $gatewayKey;
        $userOldPlanDetails = (isset($userPlanData['arm_current_plan_detail']) && ! empty($userPlanData['arm_current_plan_detail'])) ? $userPlanData['arm_current_plan_detail'] : []; // phpcs:ignore
        $userOldPlanDetails['arm_user_old_payment_mode'] = $paymentMode;
        $userPlanData['arm_current_plan_detail'] = $userOldPlanDetails;

        if ($plan->is_recurring()) {
            $userPlanData['arm_payment_mode']  = $paymentMode;
            $userPlanData['arm_payment_cycle'] = $paymentCycle;
        } else {
            $userPlanData['arm_payment_mode']  = '';
            $userPlanData['arm_payment_cycle'] = '';
        }

        update_user_meta($userId, 'arm_user_plan_' . $planId, $userPlanData);
        do_action('arm_update_user_meta_after_renew_outside', $userId, $logDetail, $planId, $gatewayKey);

        $defaultPlanData  = $arm_subscription_plans->arm_default_plan_array();
        $oldPlanData = get_user_meta($userId, 'arm_user_plan_' . $oldPlanId, true);
        $oldPlanData = ! empty($oldPlanData) ? $oldPlanData : [];
        $oldPlanData = shortcode_atts($defaultPlanData, $oldPlanData);

        $isUpdatePlan = true;
        if ($oldPlan->is_lifetime() || $oldPlan->is_free() || ($oldPlan->is_recurring() && $plan->is_recurring())) {
            $isUpdatePlan = true;
        } else {
            $changeAct = 'immediate';
            if (1 == $oldPlan->enable_upgrade_downgrade_action) {
                if (!empty($oldPlan->downgrade_plans) && in_array($plan->ID, $oldPlan->downgrade_plans)) {
                    $changeAct = $oldPlan->downgrade_action;
                }
                if (!empty($oldPlan->upgrade_plans) && in_array($plan->ID, $oldPlan->upgrade_plans)) {
                    $changeAct = $oldPlan->upgrade_action;
                }
            }
            $subscrEffective = ! empty($oldPlanData['arm_expire_plan']) ? $oldPlanData['arm_expire_plan'] : '';
            if ('on_expire' == $changeAct && ! empty($subscrEffective)) {
                $isUpdatePlan                        = false;
                $oldPlanData['arm_subscr_effective'] = $subscrEffective;
                $oldPlanData['arm_change_plan_to']   = $planId;
                update_user_meta($userId, 'arm_user_plan_' . $oldPlanId, $oldPlanData);
            }
        }

        $lastPaymentStatus = $wpdb->get_var($wpdb->prepare('SELECT `arm_transaction_status` FROM `' . $ARMemberLite->tbl_arm_payment_log . '` WHERE `arm_user_id`=%d AND `arm_plan_id`=%d AND `arm_created_date`<=%s ORDER BY `arm_log_id` DESC LIMIT 0,1', $userId, $planId, current_time('mysql'))); // phpcs:ignore

        if ($isUpdatePlan) {
            $arm_subscription_plans->arm_update_user_subscription($userId, $planId, '', true, $lastPaymentStatus);
        } else {
            $arm_subscription_plans->arm_add_membership_history($userId, $planId, 'change_subscription');
        }

        return $data;
    }

    /**
     * @param object $data
     * @return array<string>
     */
    public function paymentRedirectUrls(object $data): array
    {
        global $arm_global_settings;
        $redirectionSettings  = maybe_unserialize(get_option('arm_redirection_settings'));
        $pageId = (isset($redirectionSettings['setup_renew']['page_id']) && ! empty($redirectionSettings['setup_renew']['page_id'])) ? $redirectionSettings['setup_renew']['page_id'] : 0; // @phpcs:ignore
        $successUrl = $arm_global_settings->arm_get_permalink('', $pageId) ?? ARMLITE_HOME_URL;

        return [
            'success' => $successUrl,
            'failed' => ARMLITE_HOME_URL
        ];
    }

    /**
     * @param array<mixed> $gateways
     * @return array<mixed>
     */
    public function addGateway(array $gateways): array
    {
        if (Helpers::exists()) {
            $gateways['cryptopay'] = [
                'gateway_name' => 'CryptoPay',
                'note' => esc_html__('You can pay with supported blockchain networks and currencies under these networks.', 'arm-cryptopay'), // phpcs:ignore
                'fields' => []
            ];
        }

        if (Helpers::liteExists()) {
            $gateways['cryptopay_lite'] = [
                'gateway_name' => 'CryptoPay Lite',
                'note' => esc_html__('You can pay with supported blockchain networks and currencies under these networks.', 'arm-cryptopay'), // phpcs:ignore
                'fields' => []
            ];
        }

        return $gateways;
    }

    /**
     * @param array<mixed> $gatewayNames
     * @return array<mixed>
     */
    public function addGatewayNames(array $gatewayNames): array
    {
        if (Helpers::exists()) {
            $gatewayNames['cryptopay'] = esc_html__('CryptoPay', 'arm-cryptopay');
        }

        if (Helpers::liteExists()) {
            $gatewayNames['cryptopay_lite'] = esc_html__('CryptoPay Lite', 'arm-cryptopay');
        }

        return $gatewayNames;
    }

    /**
     * @param array<mixed> $setupData
     * @return array<mixed>
     */
    public function addGatewayKey(array $setupData): array
    {
        $data = [];

        if (Helpers::exists()) {
            $data['modules']['gateways'][] = 'cryptopay';
            $data['modules']['gateways_order']['cryptopay'] = 1;
            $data['modules']['payment_mode']['cryptopay'] = 'manual_subscription';
        }

        if (Helpers::liteExists()) {
            $data['modules']['gateways'][] = 'cryptopay_lite';
            $data['modules']['gateways_order']['cryptopay_lite'] = 2;
            $data['modules']['payment_mode']['cryptopay_lite'] = 'manual_subscription';
        }

        $setupData['setup_modules'] = \array_merge_recursive($setupData['setup_modules'], $data);
        $setupData['arm_setup_modules'] = \array_merge_recursive($setupData['arm_setup_modules'], $data);

        return $setupData;
    }

    /**
     * @param bool $hasCCFields
     * @param string $gatewayKey
     * @return bool
     */
    // phpcs:ignore
    public function hasCCFields($hasCCFields, $gatewayKey): bool
    {
        if ('cryptopay' === $gatewayKey || 'cryptopay_lite' === $gatewayKey) {
            $hasCCFields = false;
        }

        return $hasCCFields;
    }

    /**
     * @param string $gatewayKey
     * @param array<mixed> $gOptions
     * @param array<mixed> $postedData
     * @param int $entryId
     * @return void
     */
    public function validatePaymentGateway(string $gatewayKey, array $gOptions, array $postedData, int $entryId): void
    {
        global $arm_payment_gateways;
        if ('cryptopay' === $gatewayKey || 'cryptopay_lite' === $gatewayKey) {
            $amount = $postedData['arm_total_payable_amount'];
            $currency  = $arm_payment_gateways->arm_get_global_currency();

            $type = 'cryptopay' === $gatewayKey ? Type::PRO : Type::LITE;

            $paymentUrl = Helpers::createSPP([
                'type' => $type,
                'addon' => 'arm',
                'addonName' => 'ARMember',
                'order' => [
                    'amount' => $amount,
                    'currency' => $currency,
                ],
                'params' => [
                    'entryId' => $entryId,
                    'gatewayKey' => $gatewayKey,
                    'postedData' => $postedData,
                ]
            ]);

            echo wp_json_encode([
                'status'  => 'success',
                'type'    => 'redirect',
                'message' => '<script data-cfasync="false" type="text/javascript" language="javascript">window.location.href="' . $paymentUrl . '"</script>', // @phpcs:ignore
            ]);

            exit;
        }
    }
}
