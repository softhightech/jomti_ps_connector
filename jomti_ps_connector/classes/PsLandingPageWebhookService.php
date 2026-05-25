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

        $ch = curl_init($webhookUrl);
        if ($ch === false) {
            \PsLandingPageLogger::error('Unable to init cURL for webhook.', ['url' => $webhookUrl]);

            return;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $this->module->getApiKey(),
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            \PsLandingPageLogger::error('Webhook request failed.', [
                'url' => $webhookUrl,
                'order_id' => (int) $order->id,
                'error' => $curlError,
            ]);

            return;
        }

        \PsLandingPageLogger::info('Webhook sent.', [
            'url' => $webhookUrl,
            'order_id' => (int) $order->id,
            'http_code' => $httpCode,
            'response' => $this->module->isDebugEnabled() ? $responseBody : null,
        ]);
    }
}
