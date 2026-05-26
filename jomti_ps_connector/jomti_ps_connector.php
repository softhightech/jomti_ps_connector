<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/PsLandingPageLogger.php';
require_once __DIR__ . '/classes/PsLandingPageCheckoutService.php';
require_once __DIR__ . '/classes/PsLandingPageWebhookService.php';

class Jomti_Ps_Connector extends \Module
{
    const CONFIG_ENABLED = 'JOMTI_PS_CONNECTOR_ENABLED';
    const CONFIG_API_KEY = 'JOMTI_PS_CONNECTOR_API_KEY';
    const CONFIG_DEBUG = 'JOMTI_PS_CONNECTOR_DEBUG';

    public function __construct()
    {
        $this->name = 'jomti_ps_connector';
        $this->tab = 'advertising_marketing';
        $this->version = '1.0.0';
        $this->author = 'Custom';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->controllers = ['api', 'webhook', 'success'];
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('Jomti PS Connector', [], 'Modules.JomtiPsConnector.Admin');
        $this->description = $this->trans('Creates customer carts from a Laravel landing page and redirects users to checkout.', [], 'Modules.JomtiPsConnector.Admin');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && \Configuration::updateValue(self::CONFIG_ENABLED, 1)
            && \Configuration::updateValue(self::CONFIG_API_KEY, '')
            && \Configuration::updateValue(self::CONFIG_DEBUG, 0);
    }

    public function uninstall()
    {
        return \Configuration::deleteByName(self::CONFIG_ENABLED)
            && \Configuration::deleteByName(self::CONFIG_API_KEY)
            && \Configuration::deleteByName(self::CONFIG_DEBUG)
            && parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (\Tools::isSubmit('submitJomtiPsConnectorConfig')) {
            try {
                $this->postProcess();
                $output .= $this->displayConfirmation($this->trans('Settings updated.', [], 'Admin.Notifications.Success'));
            } catch (\Exception $e) {
                $output .= $this->displayError($e->getMessage());
            }
        }

        return $output . $this->renderForm();
    }

    public function postProcess()
    {
        $enabled = (int) \Tools::getValue(self::CONFIG_ENABLED, 1);
        $apiKey = trim((string) \Tools::getValue(self::CONFIG_API_KEY, ''));
        $debug = (int) \Tools::getValue(self::CONFIG_DEBUG, 0);

        if ($apiKey === '') {
            throw new \PrestaShopException($this->trans('API key is required.', [], 'Modules.JomtiPsConnector.Admin'));
        }

        \Configuration::updateValue(self::CONFIG_ENABLED, $enabled);
        \Configuration::updateValue(self::CONFIG_API_KEY, pSQL($apiKey));
        \Configuration::updateValue(self::CONFIG_DEBUG, $debug);
    }

    protected function renderForm()
    {
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Settings', [], 'Admin.Global'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('API Key', [], 'Modules.JomtiPsConnector.Admin'),
                        'name' => self::CONFIG_API_KEY,
                        'required' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                    'name' => 'submitJomtiPsConnectorConfig',
                ],
            ],
        ];

        $helper = new \HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = \Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = \AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitJomtiPsConnectorConfig';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) \Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');

        $helper->fields_value = [
            self::CONFIG_API_KEY => (string) \Configuration::get(self::CONFIG_API_KEY),
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    public function hookActionValidateOrder($params)
    {
        if (!$this->isModuleEnabled()) {
            return;
        }

        if (empty($params['order']) || !\Validate::isLoadedObject($params['order'])) {
            return;
        }

        $order = $params['order'];
        $this->getWebhookService()->sendOrderEvent($order, 'actionValidateOrder');
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        if (!$this->isModuleEnabled()) {
            return;
        }

        $idOrder = (int) (isset($params['id_order']) ? $params['id_order'] : 0);
        if ($idOrder <= 0) {
            return;
        }

        $order = new \Order($idOrder);
        if (!\Validate::isLoadedObject($order)) {
            return;
        }

        $this->getWebhookService()->sendOrderEvent($order, 'actionOrderStatusPostUpdate');
    }

    public function isModuleEnabled()
    {
        return (bool) \Configuration::get(self::CONFIG_ENABLED);
    }

    public function isDebugEnabled()
    {
        return (bool) \Configuration::get(self::CONFIG_DEBUG);
    }

    public function getApiKey()
    {
        return (string) \Configuration::get(self::CONFIG_API_KEY);
    }

    public function getJomtiUrl()
    {
        return 'https://jomti.com';
    }

    public function getExternalWebhookUrl()
    {
        $baseUrl = $this->getJomtiUrl();
        if ($baseUrl === '') {
            return '';
        }

        return $baseUrl . '/api/prestashop/webhook';
    }

    public function getCheckoutService()
    {
        return new \PsLandingPageCheckoutService($this);
    }

    public function getWebhookService()
    {
        return new \PsLandingPageWebhookService($this);
    }

    public function validateRemoteApiKey($jomtiUrl = null, $apiKey = null)
    {
        $baseUrl = rtrim((string) ($jomtiUrl !== null ? $jomtiUrl : $this->getJomtiUrl()), '/');
        $key = trim((string) ($apiKey !== null ? $apiKey : $this->getApiKey()));
        if ($baseUrl === '' || $key === '') {
            return [
                'success' => false,
                'error' => 'Missing Jomti URL or API key.',
            ];
        }

        $body = json_encode([
            'api_key' => $key,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            return [
                'success' => false,
                'error' => 'Failed to encode validation request.',
            ];
        }

        $ch = curl_init($baseUrl . '/api/prestashop/validate-key');

        if ($ch === false) {
            return [
                'success' => false,
                'error' => 'Unable to initialize cURL.',
            ];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'error' => 'Jomti request failed: ' . $curlError,
            ];
        }

        $response = json_decode((string) $responseBody, true);
        if (!is_array($response)) {
            return [
                'success' => false,
                'error' => 'Invalid response from Jomti.',
            ];
        }

        $isValid = !empty($response['success']);
        if (array_key_exists('valid', $response)) {
            $isValid = $isValid && !empty($response['valid']);
        }
        if (array_key_exists('active', $response)) {
            $isValid = $isValid && !empty($response['active']);
        }
        if (array_key_exists('allowed', $response)) {
            $isValid = $isValid && !empty($response['allowed']);
        }
        if (!$isValid || $httpCode < 200 || $httpCode >= 300) {
            return [
                'success' => false,
                'error' => isset($response['message']) ? (string) $response['message'] : 'Invalid API key or access denied.',
            ];
        }

        return [
            'success' => true,
        ];
    }
}
