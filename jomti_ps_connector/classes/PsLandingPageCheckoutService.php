<?php

class PsLandingPageCheckoutService
{
    /** @var \Jomti_Ps_Connector */
    private $module;

    /** @var \Context */
    private $context;

    public function __construct(\Jomti_Ps_Connector $module)
    {
        $this->module = $module;
        $this->context = \Context::getContext();
    }

    public function process($payload, $remoteIp)
    {
        if (!$this->module->isModuleEnabled()) {
            return $this->errorResponse('Module is disabled.', 503);
        }

        $apiKey = isset($payload['api_key']) ? trim((string) $payload['api_key']) : '';
        if ($apiKey === '') {
            \PsLandingPageLogger::warning('Missing API key.', ['ip' => $remoteIp]);

            return $this->errorResponse('API key is required.', 401);
        }

        $validation = $this->module->validateRemoteApiKey(null, $apiKey);
        if (!$validation['success']) {
            \PsLandingPageLogger::warning('API key validation failed.', [
                'ip' => $remoteIp,
                'error' => $validation['error'],
            ]);

            return $this->errorResponse('Invalid API key.', 401, [
                'debug_message' => $this->module->isDebugEnabled() ? $validation['error'] : null,
            ]);
        }

        $normalized = $this->validatePayload($payload);
        if (!$normalized['success']) {
            return $normalized;
        }

        $data = $normalized['data'];

        try {
            $customer = $this->getOrCreateCustomer($data['customer']);
            $address = $this->getOrCreateAddress($customer, $data['customer']);
            $cart = $this->createCart($customer, $address);
            $this->addProductsToCart($cart, $data['products']);
            $this->authenticateCustomer($customer, $cart);

            $checkoutUrl = $this->context->link->getPageLink('order', true, null, [
                'id_cart' => (int) $cart->id,
                'key' => (string) $customer->secure_key,
            ]);

            \PsLandingPageLogger::info('Checkout URL generated.', [
                'id_customer' => (int) $customer->id,
                'id_cart' => (int) $cart->id,
                'ip' => (string) $remoteIp,
            ]);

            return [
                'success' => true,
                'checkout_url' => $checkoutUrl,
                'id_cart' => (int) $cart->id,
                'http_code' => 200,
            ];
        } catch (\Exception $e) {
            \PsLandingPageLogger::error('Checkout process failed.', [
                'error' => $e->getMessage(),
                'trace' => $this->module->isDebugEnabled() ? $e->getTraceAsString() : null,
            ]);

            return $this->errorResponse('Unable to prepare checkout.', 500, [
                'debug_message' => $this->module->isDebugEnabled() ? $e->getMessage() : null,
            ]);
        }
    }

    private function validatePayload($payload)
    {
        if (!is_array($payload)) {
            return $this->errorResponse('Invalid JSON payload.', 400);
        }

        if (empty($payload['customer']) || !is_array($payload['customer'])) {
            return $this->errorResponse('Customer payload is required.', 400);
        }

        if (empty($payload['product']) && empty($payload['products'])) {
            return $this->errorResponse('Product payload is required.', 400);
        }

        $customer = [
            'firstname' => $this->sanitizeText(isset($payload['customer']['firstname']) ? $payload['customer']['firstname'] : '', 255),
            'lastname' => $this->sanitizeText(isset($payload['customer']['lastname']) ? $payload['customer']['lastname'] : '', 255),
            'email' => trim((string) (isset($payload['customer']['email']) ? $payload['customer']['email'] : '')),
            'phone' => $this->sanitizePhone(isset($payload['customer']['phone']) ? $payload['customer']['phone'] : ''),
            'address' => $this->sanitizeText(isset($payload['customer']['address']) ? $payload['customer']['address'] : '', 128),
        ];

        if ($customer['firstname'] === '' || $customer['lastname'] === '' || !\Validate::isEmail($customer['email'])) {
            return $this->errorResponse('Invalid customer data.', 422);
        }

        if ($customer['address'] === '') {
            $customer['address'] = 'N/A';
        }

        $rawProducts = [];
        if (!empty($payload['product']) && is_array($payload['product'])) {
            $rawProducts[] = $payload['product'];
        }

        if (!empty($payload['products']) && is_array($payload['products'])) {
            $rawProducts = array_merge($rawProducts, $payload['products']);
        }

        if (empty($rawProducts)) {
            return $this->errorResponse('Product payload is required.', 400);
        }

        $products = [];
        foreach ($rawProducts as $index => $productLine) {
            if (!is_array($productLine)) {
                return $this->errorResponse('Invalid product line at index ' . (int) $index . '.', 422);
            }

            $reference = trim((string) (isset($productLine['reference']) ? $productLine['reference'] : ''));
            $quantity = (int) (isset($productLine['quantity']) ? $productLine['quantity'] : 0);

            if ($reference === '' || $quantity <= 0) {
                return $this->errorResponse('Invalid product reference or quantity at index ' . (int) $index . '.', 422);
            }

            $resolved = $this->resolveProductByReference($reference);
            if (!$resolved['success']) {
                return $this->errorResponse($resolved['error'], 422);
            }

            $idProduct = (int) $resolved['id_product'];
            $idProductAttribute = (int) $resolved['id_product_attribute'];
            $product = new \Product($idProduct, false, (int) \Configuration::get('PS_LANG_DEFAULT'), (int) $this->context->shop->id);
            if (!\Validate::isLoadedObject($product) || !$product->active) {
                return $this->errorResponse('Product not found or inactive for reference: ' . $reference . '.', 422);
            }

            $products[] = [
                'id_product' => $idProduct,
                'id_product_attribute' => $idProductAttribute,
                'reference' => $reference,
                'quantity' => $quantity,
            ];
        }

        return [
            'success' => true,
            'data' => [
                'customer' => $customer,
                'products' => $products,
            ],
            'http_code' => 200,
        ];
    }

    private function getOrCreateCustomer(array $customerData)
    {
        $idCustomer = (int) \Customer::customerExists($customerData['email'], true, true);
        if ($idCustomer > 0) {
            $customer = new \Customer($idCustomer);
            if (\Validate::isLoadedObject($customer)) {
                return $customer;
            }
        }

        $customer = new \Customer();
        $customer->firstname = $customerData['firstname'];
        $customer->lastname = $customerData['lastname'];
        $customer->email = $customerData['email'];
        $customer->passwd = \Tools::hash(\Tools::passwdGen(16));
        $customer->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $customer->id_shop = (int) $this->context->shop->id;
        $customer->id_shop_group = (int) $this->context->shop->id_shop_group;
        $customer->id_default_group = (int) \Configuration::get('PS_CUSTOMER_GROUP');
        $customer->active = 1;

        if (!$customer->add()) {
            throw new \PrestaShopException('Customer creation failed.');
        }

        $customer->addGroups([$customer->id_default_group]);

        \PsLandingPageLogger::info('Customer created.', ['id_customer' => (int) $customer->id]);

        return $customer;
    }

    private function getOrCreateAddress(\Customer $customer, array $customerData)
    {
        $existingAddressId = (int) \Address::getFirstCustomerAddressId((int) $customer->id);
        if ($existingAddressId > 0) {
            $address = new \Address($existingAddressId);
            if (\Validate::isLoadedObject($address)) {
                return $address;
            }
        }

        $idCountry = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        $country = new \Country($idCountry);
        $states = \State::getStatesByIdCountry($idCountry);

        $address = new \Address();
        $address->id_customer = (int) $customer->id;
        $address->alias = 'Landing Page';
        $address->firstname = $customer->firstname;
        $address->lastname = $customer->lastname;
        $address->address1 = $customerData['address'];
        $address->city = $this->sanitizeCityFromAddress($customerData['address']);
        $address->id_country = $idCountry;
        $address->id_state = !empty($states) ? (int) $states[0]['id_state'] : 0;
        $address->postcode = $this->buildPostcode($country);
        $address->phone_mobile = $customerData['phone'];

        if (!$address->add()) {
            throw new \PrestaShopException('Address creation failed.');
        }

        \PsLandingPageLogger::info('Address created.', [
            'id_address' => (int) $address->id,
            'id_customer' => (int) $customer->id,
        ]);

        return $address;
    }

    private function createCart(\Customer $customer, \Address $address)
    {
        $cart = new \Cart();
        $cart->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $cart->id_shop = (int) $this->context->shop->id;
        $cart->id_shop_group = (int) $this->context->shop->id_shop_group;
        $cart->id_customer = (int) $customer->id;
        $cart->secure_key = (string) $customer->secure_key;
        $cart->id_address_delivery = (int) $address->id;
        $cart->id_address_invoice = (int) $address->id;

        if (!$cart->add()) {
            throw new \PrestaShopException('Cart creation failed.');
        }

        return $cart;
    }

    private function addProductsToCart(\Cart $cart, array $products)
    {
        foreach ($products as $productLine) {
            $idProduct = (int) $productLine['id_product'];
            $idProductAttribute = (int) $productLine['id_product_attribute'];
            $reference = (string) $productLine['reference'];
            $quantity = (int) $productLine['quantity'];

            $product = new \Product($idProduct, false, (int) $cart->id_lang, (int) $cart->id_shop);
            if (!\Validate::isLoadedObject($product) || !$product->active) {
                throw new \PrestaShopException('Product is invalid: ' . $idProduct);
            }

            if (!$product->available_for_order) {
                throw new \PrestaShopException('Product is not available for order: ' . $idProduct);
            }

            $availableQty = (int) \StockAvailable::getQuantityAvailableByProduct(
                $idProduct,
                $idProductAttribute,
                (int) $cart->id_shop
            );
            $canOrderOutOfStock = \Product::isAvailableWhenOutOfStock((int) $product->out_of_stock);
            if (!$canOrderOutOfStock && $availableQty < $quantity) {
                throw new \PrestaShopException('Not enough stock for reference ' . $reference . '.');
            }

            $updated = $cart->updateQty(
                $quantity,
                $idProduct,
                $idProductAttribute,
                false,
                'up',
                (int) $cart->id_address_delivery,
                new \Shop((int) $cart->id_shop),
                true
            );

            if ((int) $updated <= 0) {
                throw new \PrestaShopException('Failed to add product to cart for reference: ' . $reference);
            }
        }

        $cart->update();
    }

    private function authenticateCustomer(\Customer $customer, \Cart $cart)
    {
        $this->context->updateCustomer($customer);

        $this->context->cart = $cart;
        $this->context->cookie->id_cart = (int) $cart->id;
        $this->context->cookie->check_cgv = 0;
        $this->context->cookie->write();
    }

    private function resolveProductByReference($reference)
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return [
                'success' => false,
                'error' => 'Missing product reference.',
            ];
        }

        $row = \Db::getInstance()->getRow(
            'SELECT pa.id_product, pa.id_product_attribute
             FROM `' . _DB_PREFIX_ . 'product_attribute` pa
             INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas
                ON (pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = ' . (int) $this->context->shop->id . ')
             WHERE pa.reference = "' . pSQL($reference) . '"'
        );
        if (is_array($row) && !empty($row)) {
            return [
                'success' => true,
                'id_product' => (int) $row['id_product'],
                'id_product_attribute' => (int) $row['id_product_attribute'],
            ];
        }

        $idProduct = (int) \Product::getIdByReference($reference);
        if ($idProduct <= 0) {
            return [
                'success' => false,
                'error' => 'Product reference not found: ' . $reference . '.',
            ];
        }

        return [
            'success' => true,
            'id_product' => $idProduct,
            'id_product_attribute' => (int) \Product::getDefaultAttribute($idProduct),
        ];
    }

    private function sanitizeText($value, $maxLength)
    {
        $value = trim((string) $value);
        $value = strip_tags($value);
        $value = \Tools::substr($value, 0, (int) $maxLength);

        return pSQL($value);
    }

    private function sanitizePhone($value)
    {
        $value = trim((string) $value);
        $value = preg_replace('/[^0-9\+\s\-\.\(\)]/', '', $value);
        if (!is_string($value)) {
            return '';
        }

        return \Tools::substr($value, 0, 32);
    }

    private function sanitizeCityFromAddress($address)
    {
        $city = $this->sanitizeText($address, 64);
        if ($city === '') {
            $city = 'City';
        }

        return $city;
    }

    private function buildPostcode(\Country $country)
    {
        if (!(bool) $country->need_zip_code) {
            return '';
        }

        $format = trim((string) $country->zip_code_format);
        if ($format === '') {
            return '00000';
        }

        $postcode = strtr($format, [
            'N' => '0',
            'L' => 'A',
            'C' => (string) $country->iso_code,
        ]);
        $postcode = preg_replace('/[^A-Za-z0-9\- ]/', '', $postcode);

        if (!is_string($postcode) || $postcode === '') {
            return '00000';
        }

        return $postcode;
    }

    private function errorResponse($message, $httpCode, array $extra = [])
    {
        $response = [
            'success' => false,
            'error' => (string) $message,
            'http_code' => (int) $httpCode,
        ];

        foreach ($extra as $key => $value) {
            if ($value !== null) {
                $response[$key] = $value;
            }
        }

        return $response;
    }
}
