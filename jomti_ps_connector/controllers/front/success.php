<?php

class Jomti_Ps_ConnectorSuccessModuleFrontController extends \ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $idCart = (int) \Tools::getValue('id_cart');
        $key = (string) \Tools::getValue('key');

        if ($idCart > 0 && $key !== '') {
            $result = $this->module->getCheckoutService()->hydrateCheckoutContext($idCart, $key);
            if (!empty($result['success'])) {
                $orderUrl = $this->context->link->getPageLink('order', true, null, [
                    'id_cart' => (int) $idCart,
                    'key' => $key,
                ]);

                \Tools::redirect($orderUrl);
            }
        }

        if (\Validate::isLoadedObject($this->context->cart) && $this->context->cart->id > 0) {
            $url = $this->context->link->getPageLink('order', true, null, [
                'id_cart' => (int) $this->context->cart->id,
                'key' => (string) $this->context->customer->secure_key,
            ]);

            \Tools::redirect($url);
        }

        \Tools::redirect($this->context->link->getPageLink('index', true));
    }
}
