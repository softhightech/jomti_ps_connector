<?php

class PsLandingPageWebhookService
{
    /** @var \Jomti_Ps_Connector */
    private $module;

    public function __construct(\Jomti_Ps_Connector $module)
    {
        $this->module = $module;
    }

    public function sendOrderEvent(\Order $order, $trigger)
    {
        $webhookUrl = $this->module->getExternalWebhookUrl();
        if ($webhookUrl === '') {
            return;
        }

        $customer = new \Customer((int) $order->id_customer);
        $state = new \OrderState((int) $order->current_state, (int) $order->id_lang);

        $products = [];
        foreach ($order->getProducts() as $product) {
            $products[] = [
                'id_product' => (int) $product['product_id'],
                'id_product_attribute' => (int) $product['product_attribute_id'],
                'name' => (string) $product['product_name'],
                'reference' => (string) $product['product_reference'],
                'quantity' => (int) $product['product_quantity'],
                'unit_price_tax_incl' => (float) $product['unit_price_tax_incl'],
                'total_price_tax_incl' => (float) $product['total_price_tax_incl'],
            ];
        }

        $payload = [
            'event' => 'order_status_changed',
            'trigger' => (string) $trigger,
            'order_id' => (int) $order->id,
            'id_cart' => (int) $order->id_cart,
            'reference' => (string) $order->reference,
            'status' => [
                'id' => (int) $order->current_state,
                'name' => \Validate::isLoadedObject($state) ? (string) $state->name : '',
            ],
            'total_paid' => (float) $order->total_paid,
            'currency' => (string) $order->getCurrency()->iso_code,
            'customer' => [
                'id' => (int) $customer->id,
                'firstname' => (string) $customer->firstname,
                'lastname' => (string) $customer->lastname,
                'email' => (string) $customer->email,
            ],
            'products' => $products,
            'timestamp' => date('c'),
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            \PsLandingPageLogger::error('Failed to encode webhook payload.', ['order_id' => (int) $order->id]);

            return;
        }

        $webhookId = sha1((int) $order->id . '|' . (string) $order->reference . '|' . (string) $trigger . '|' . (int) $order->current_state);
        $result = $this->postJsonWithRetry($webhookUrl, $body, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $this->module->getApiKey(),
            'X-Webhook-Id: ' . $webhookId,
        ], 3);

        if (!$result['success']) {
            \PsLandingPageLogger::error('Webhook request failed.', [
                'url' => $webhookUrl,
                'order_id' => (int) $order->id,
                'error' => $result['error'],
                'http_code' => $result['http_code'],
            ]);

            return;
        }

        \PsLandingPageLogger::info('Webhook sent.', [
            'url' => $webhookUrl,
            'order_id' => (int) $order->id,
            'http_code' => $result['http_code'],
            'response' => $this->module->isDebugEnabled() ? $result['response'] : null,
        ]);
    }

    private function postJsonWithRetry($url, $body, array $headers, $maxAttempts)
    {
        $attempt = 0;
        $lastError = '';
        $lastHttpCode = 0;
        $lastResponse = '';

        while ($attempt < (int) $maxAttempts) {
            $attempt++;

            $ch = curl_init($url);
            if ($ch === false) {
                return [
                    'success' => false,
                    'error' => 'Unable to initialize cURL.',
                    'http_code' => 0,
                ];
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $responseBody = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $lastResponse = (string) $responseBody;
            $lastHttpCode = $httpCode;
            $lastError = (string) $curlError;

            if ($curlError === '' && $httpCode >= 200 && $httpCode < 300) {
                return [
                    'success' => true,
                    'error' => '',
                    'http_code' => $httpCode,
                    'response' => (string) $responseBody,
                ];
            }

            if ($attempt < (int) $maxAttempts) {
                usleep(250000 * $attempt);
            }
        }

        return [
            'success' => false,
            'error' => $lastError !== '' ? $lastError : 'Webhook endpoint returned HTTP ' . (int) $lastHttpCode . '.',
            'http_code' => $lastHttpCode,
            'response' => $lastResponse,
        ];
    }
}
