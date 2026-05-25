<?php

class PslandingpageWebhookModuleFrontController extends \ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $this->jsonResponse([
            'success' => true,
            'module' => 'pslandingpage',
            'message' => 'Webhook endpoint is reachable.',
            'timestamp' => date('c'),
        ], 200);
    }

    private function jsonResponse(array $data, $httpCode)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        http_response_code((int) $httpCode);
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
