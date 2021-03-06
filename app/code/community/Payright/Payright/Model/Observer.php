<?php

class Payright_Payright_Model_Observer {

    // TODO When using auth-token / Access Token, need a auth verification call from byron-bay
    // TODO Then update config.xml under adminhtml > events (if needed - to tell admin Access Token entered was correct / incorrect)
    /**
     * Toggle enable/disable Payright payment method plugin, if certain Payright business rules are met.
     *
     * @param $observer
     */
    public function updateAdminConfiguration($observer) {
        // We try to get 'rates', to determine if 'access token' is invalid
        $data = Mage::helper('payright')->performApiGetRates();
        $isInvalidAccessToken = isset($data['status']) && isset($data['message']);

        $authToken = Mage::helper('payright')->getAccessToken();

        $emptyAuthToken = is_string($authToken) && strlen(trim($authToken)) === 0;

        if ($emptyAuthToken) {
            $message = 'We require your \'Access Token\', it can be obtained from your merchant store at the developer portal.';
            Mage::getSingleton('adminhtml/session')->addError($message);
        } else if($isInvalidAccessToken) {
            $message = 'Your \'Access Token\' is invalid, please specify the correct \'access token\' and store \'region\'.';
            Mage::getSingleton('adminhtml/session')->addError($message);
        } else {
            $message = 'Your access token is saved. Please back up your access token ' . $authToken . ' for safe-keeping.';
            Mage::getSingleton('adminhtml/session')->addSuccess($message);
        }
    }

    /**
     * Toggle enable/disable Payright payment method plugin, if certain Payright business rules are met.
     *
     * @param $observer
     */
    public function disablePayright($observer) {
        $event = $observer->getEvent();
        $result = $event->getResult();
        $method = $observer->getMethodInstance();

        if (!$result->isAvailable) {
            return;
        }

        if ($method->getCode() == 'payrightcheckout') {

            $orderTotal = floatval(Mage::helper('checkout')->getQuote()->getGrandTotal());
            $minValue = Mage::helper('payright')->getConfigValue('min_amount');

            $installments = $this->fetchInstallments();
            $result->isAvailable = $installments !== "exceed_amount" && $installments !== "auth_token_error" && $installments !== "rates_error" && $orderTotal >= $minValue;
        }
    }

    /**
     * Activate Plans after shipment.
     *
     * @param $observer
     */
    public function payrightOrderShipment($observer) {
        $order = $observer->getEvent()->getShipment()->getOrder();

        // Retrieve Payright plan Id
        $planId = $order->getPayrightPlanId();

        // Check if it is a 'completed order / checkout', if so then activate plan.
        if ($planId !== null) {
            // Retrieve Payright checkout Id
            $checkoutId = $order->getPayrightCheckoutId();

            // Activate Payright payment plan
            $helper = Mage::helper('payright');
            $helper->activatePlan($checkoutId);

            // Add 'plan activation' comment into order comment history
            $order->addStatusHistoryComment('Payright payment plan '.$planId.'has been activated.');
        } else {
            // Return payment plan failed to activate message to administrator
            $message = 'The payment plan failed to be activated.';
            Mage::getSingleton('adminhtml/session')->addError($message);
        }
    }

    /**
     * Fetch payment installments.
     *
     */
    private function fetchInstallments() {
        $orderTotal = Mage::helper('checkout')->getQuote()->getGrandTotal();
        return Mage::helper('payright')->calculateSingleProductInstallment($orderTotal);
    }

    /**
     * Test API Connection, with defined 'Access Token' in system configuration.
     *
     * @return bool
     */
    private function testApiConnection() {
        // Get 'Access Token' from system configuration
        $authToken = Mage::helper('payright')->getAccessToken();

        // Get the API Url endpoint, from 'config.xml'
        $getEnvironmentEndpoints = $this->getEnvironmentEndpoints();
        $apiEndpoint = $getEnvironmentEndpoints['ApiUrl'];

        try {
            // Define API GET call for 'data' = 'rates', 'establishmentFees' and 'otherFees'
            $client = new Zend_Http_Client($apiEndpoint . "api/v1/merchant/configuration");
            $client->setHeaders(
                array(
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $authToken
                )
            );
            $client->setConfig(array('timeout' => 15));

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

}
