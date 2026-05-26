<?php
class Jomti_Ps_ConnectorApiModuleFrontController extends \ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $method = strtoupper((string) $_SERVER['REQUEST_METHOD']);

        if ($method !== 'POST') {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Method not allowed.',
            ], 405);
        }

        $rawBody = file_get_contents('php://input');

        $payload = json_decode((string) $rawBody, true);

        if (!is_array($payload)) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Invalid JSON payload.',
                'code' => 400,
            ], 400);
        }

        if (empty($payload['api_key'])) {
            $headerApiKey = trim((string) \Tools::getValue('HTTP_X_API_KEY', ''));
            if ($headerApiKey !== '') {
                $payload['api_key'] = $headerApiKey;
            }
        }

        \PsLandingPageLogger::info('Checkout API request received.', [
            'ip' => (string) \Tools::getRemoteAddr(),
            'has_api_key' => !empty($payload['api_key']),
            'source' => isset($payload['meta']['source']) ? (string) $payload['meta']['source'] : null,
            'order_id' => isset($payload['meta']['order_id']) ? (int) $payload['meta']['order_id'] : 0,
            'lp_id' => isset($payload['meta']['lp_id']) ? (int) $payload['meta']['lp_id'] : 0,
        ]);

        $service = $this->module->getCheckoutService();

        $result = $service->process(
            $payload,
            (string) \Tools::getRemoteAddr()
        );

        $httpCode = isset($result['http_code'])
            ? (int) $result['http_code']
            : 200;

        unset($result['http_code']);

        return $this->jsonResponse($result, $httpCode);
    }

    private function jsonResponse(array $data, int $httpCode = 200)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($httpCode);

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        die(json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }
}