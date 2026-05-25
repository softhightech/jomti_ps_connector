<?php

class PslandingpageApiModuleFrontController extends \ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
        if ($method !== 'POST') {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Method not allowed.',
            ], 405);

            return;
        }

        $rawBody = file_get_contents('php://input');
        $payload = json_decode((string) $rawBody, true);

        if (!is_array($payload)) {
            \PsLandingPageLogger::warning('Invalid JSON payload.', ['raw' => $rawBody]);
            $this->jsonResponse([
                'success' => false,
                'error' => 'Invalid JSON payload.',
            ], 400);

            return;
        }

        if (empty($payload['api_key'])) {
            $headerKey = (string) \Tools::getValue('HTTP_X_API_KEY', '');
            if ($headerKey === '' && function_exists('apache_request_headers')) {
                $headers = apache_request_headers();
                if (is_array($headers) && isset($headers['X-API-Key'])) {
                    $headerKey = (string) $headers['X-API-Key'];
                }
            }
            if ($headerKey !== '') {
                $payload['api_key'] = trim($headerKey);
            }
        }

        \PsLandingPageLogger::info('API request received.', [
            'ip' => \Tools::getRemoteAddr(),
            'email' => isset($payload['customer']['email']) ? (string) $payload['customer']['email'] : null,
        ]);

        $service = $this->module->getCheckoutService();
        $result = $service->process($payload, (string) \Tools::getRemoteAddr());
        $httpCode = isset($result['http_code']) ? (int) $result['http_code'] : 200;
        unset($result['http_code']);

        $this->jsonResponse($result, $httpCode);
    }

    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }

    protected function geolocationManagement($defaultCountry)
    {
        return false;
    }

    private function jsonResponse(array $data, $httpCode)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        http_response_code((int) $httpCode);

        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
