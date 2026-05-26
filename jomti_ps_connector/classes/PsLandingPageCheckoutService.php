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

        $normalized = $this->validatePayload($payload);
        if (!$normalized['success']) {
            return $normalized;
        }

        $data = $normalized['data'];
        if (!$this->isApiKeyValid($data['api_key'])) {
            \PsLandingPageLogger::warning('API key validation failed.', [
                'ip' => (string) $remoteIp,
                'source' => $data['meta']['source'],
                'order_id' => $data['meta']['order_id'],
            ]);

            return $this->errorResponse('Invalid API key.', 401);
        }

        try {
            $customer = $this->getOrCreateCustomer($data['customer']);
            $address = $this->getOrCreateAddress($customer, $data['customer']);
            $cart = $this->getOrCreateCart($customer, $address, $data['products']);
            $cart = $this->reloadCartOrFail((int) $cart->id, (int) $customer->id, (string) $customer->secure_key);
            $this->assertCartHasProducts($cart, 'before_checkout_url');
            $this->authenticateCustomer($customer, $cart);

            $checkoutUrl = $this->context->link->getModuleLink(
                $this->module->name,
                'success',
                [
                    'id_cart' => (int) $cart->id,
                    'key' => (string) $customer->secure_key,
                ],
                true
            );

            \PsLandingPageLogger::info('Checkout URL generated.', [
                'id_customer' => (int) $customer->id,
                'id_cart' => (int) $cart->id,
                'ip' => (string) $remoteIp,
                'source' => $data['meta']['source'],
                'order_id' => $data['meta']['order_id'],
                'lp_id' => $data['meta']['lp_id'],
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

        $apiKey = trim((string) (isset($payload['api_key']) ? $payload['api_key'] : ''));
        if ($apiKey === '') {
            return $this->errorResponse('api_key is required.', 401);
        }

        if (empty($payload['customer']) || !is_array($payload['customer'])) {
            return $this->errorResponse('Customer payload is required.', 400);
        }

        if (empty($payload['products']) || !is_array($payload['products'])) {
            return $this->errorResponse('products array is required.', 400);
        }

        $customer = [
            'firstname' => $this->sanitizeText(isset($payload['customer']['firstname']) ? $payload['customer']['firstname'] : '', 255),
            'lastname' => $this->sanitizeText(isset($payload['customer']['lastname']) ? $payload['customer']['lastname'] : '', 255),
            'email' => trim((string) (isset($payload['customer']['email']) ? $payload['customer']['email'] : '')),
            'phone' => $this->sanitizePhone(isset($payload['customer']['phone']) ? $payload['customer']['phone'] : ''),
            'address' => $this->sanitizeText(isset($payload['customer']['address']) ? $payload['customer']['address'] : '', 128),
            'is_generated_email' => false,
        ];

        if ($customer['firstname'] === '') {
            $customer['firstname'] = 'Customer';
        }

        if ($customer['lastname'] === '') {
            $customer['lastname'] = 'User';
        }

        if ($customer['email'] === '') {
            $customer['email'] = $this->generateUniqueGuestEmail();
            $customer['is_generated_email'] = true;
        }

        if (!\Validate::isEmail($customer['email'])) {
            return $this->errorResponse('Invalid customer data.', 422);
        }

        if ($customer['address'] === '') {
            $customer['address'] = 'N/A';
        }

        $rawProducts = $payload['products'];
        $products = [];
        $uniqueReferences = [];
        foreach ($rawProducts as $index => $productLine) {
            if (!is_array($productLine)) {
                return $this->errorResponse('Invalid product line at index ' . (int) $index . '.', 422);
            }

            $reference = trim((string) (isset($productLine['reference']) ? $productLine['reference'] : ''));
            $quantity = (int) (isset($productLine['quantity']) ? $productLine['quantity'] : 0);

            if ($reference === '' || $quantity <= 0) {
                return $this->errorResponse('Invalid product reference or quantity at index ' . (int) $index . '.', 422);
            }

            $uniqueReferences[] = $reference;
            $products[] = [
                'reference' => $reference,
                'quantity' => $quantity,
            ];
        }

        $resolutionMap = $this->resolveProductsByReferences($uniqueReferences);
        foreach ($products as $index => $line) {
            $resolved = isset($resolutionMap[$line['reference']]) ? $resolutionMap[$line['reference']] : null;
            if (!is_array($resolved) || empty($resolved['success'])) {
                $errorMessage = is_array($resolved) && isset($resolved['error'])
                    ? (string) $resolved['error']
                    : 'Product reference not found: ' . $line['reference'] . '.';

                return $this->errorResponse($errorMessage, 422);
            }

            if (!$resolved['success']) {
                return $this->errorResponse($resolved['error'], 422);
            }

            $idProduct = (int) $resolved['id_product'];
            $idProductAttribute = (int) $resolved['id_product_attribute'];
            $product = new \Product($idProduct, false, (int) \Configuration::get('PS_LANG_DEFAULT'), (int) $this->context->shop->id);
            if (!\Validate::isLoadedObject($product) || !$product->active) {
                return $this->errorResponse('Product not found or inactive for reference: ' . $line['reference'] . '.', 422);
            }

            $products[$index] = [
                'id_product' => $idProduct,
                'id_product_attribute' => $idProductAttribute,
                'reference' => $line['reference'],
                'quantity' => (int) $line['quantity'],
            ];
        }

        $meta = [
            'lp_id' => isset($payload['meta']['lp_id']) ? (int) $payload['meta']['lp_id'] : 0,
            'order_id' => isset($payload['meta']['order_id']) ? (int) $payload['meta']['order_id'] : 0,
            'source' => isset($payload['meta']['source']) ? $this->sanitizeText($payload['meta']['source'], 64) : 'unknown',
        ];

        return [
            'success' => true,
            'data' => [
                'api_key' => $apiKey,
                'customer' => $customer,
                'products' => $products,
                'meta' => $meta,
            ],
            'http_code' => 200,
        ];
    }

    private function isApiKeyValid($requestApiKey)
    {
        $requestApiKey = trim((string) $requestApiKey);
        $configuredApiKey = trim((string) $this->module->getApiKey());

        if ($requestApiKey === '' || $configuredApiKey === '') {
            return false;
        }

        return hash_equals($configuredApiKey, $requestApiKey);
    }

    private function getOrCreateCustomer(array $customerData)
    {
        if (empty($customerData['email']) || !\Validate::isEmail((string) $customerData['email'])) {
            $customerData['email'] = $this->generateUniqueGuestEmail($customerData['phone']);
            $customerData['is_generated_email'] = true;
        }

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
            $idCustomer = (int) \Customer::customerExists($customerData['email'], true, true);
            if ($idCustomer > 0) {
                $existingCustomer = new \Customer($idCustomer);
                if (\Validate::isLoadedObject($existingCustomer)) {
                    return $existingCustomer;
                }
            }

            throw new \PrestaShopException('Customer creation failed.');
        }

        $customer->addGroups([$customer->id_default_group]);

        \PsLandingPageLogger::info('Customer created.', [
            'id_customer' => (int) $customer->id,
            'is_generated_email' => !empty($customerData['is_generated_email']),
        ]);

        return $customer;
    }

    private function getOrCreateAddress(\Customer $customer, array $customerData)
    {
        $addressRow = \Db::getInstance()->getRow(
            'SELECT id_address
             FROM `' . _DB_PREFIX_ . 'address`
             WHERE id_customer = ' . (int) $customer->id . '
               AND deleted = 0
               AND address1 = "' . pSQL($customerData['address']) . '"
               AND phone_mobile = "' . pSQL($customerData['phone']) . '"
             ORDER BY id_address DESC'
        );

        if (is_array($addressRow) && !empty($addressRow['id_address'])) {
            $address = new \Address((int) $addressRow['id_address']);
            if (\Validate::isLoadedObject($address)) {
                return $address;
            }
        }

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
        $address->alias = 'My Address';
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

    private function getOrCreateCart(\Customer $customer, \Address $address, array $products)
    {
        $signature = $this->buildProductsSignature($products);
        $existingCartId = $this->findReusableCartId((int) $customer->id, $signature);

        if ($existingCartId > 0) {
            $cart = new \Cart($existingCartId);
            if (\Validate::isLoadedObject($cart)) {
                return $cart;
            }
        }

        $cart = $this->createCart($customer, $address);
        $this->addProductsToCart($cart, $products);

        return $cart;
    }

    private function findReusableCartId($idCustomer, $signature)
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT c.id_cart
             FROM `' . _DB_PREFIX_ . 'cart` c
             LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON (o.id_cart = c.id_cart)
             WHERE c.id_customer = ' . (int) $idCustomer . '
               AND c.id_shop = ' . (int) $this->context->shop->id . '
               AND o.id_order IS NULL
               AND c.date_add >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
             ORDER BY c.date_add DESC
             LIMIT 5'
        );

        if (!is_array($rows) || empty($rows)) {
            return 0;
        }

        foreach ($rows as $row) {
            $idCart = (int) $row['id_cart'];
            if ($idCart <= 0) {
                continue;
            }

            $cartSignature = $this->buildCartSignatureFromDatabase($idCart);
            if ($cartSignature === $signature) {
                \PsLandingPageLogger::info('Reusing existing cart for idempotent request.', ['id_cart' => $idCart]);

                return $idCart;
            }
        }

        return 0;
    }

    private function addProductsToCart(\Cart $cart, array $products)
    {
        foreach ($products as $productLine) {
            $idProduct = (int) $productLine['id_product'];
            $idProductAttribute = isset($productLine['id_product_attribute'])
                ? (int) $productLine['id_product_attribute']
                : 0;
            $reference = (string) $productLine['reference'];
            $quantity = (int) $productLine['quantity'];

            $product = new \Product($idProduct, false, (int) $cart->id_lang, (int) $cart->id_shop);
            if (!\Validate::isLoadedObject($product) || !$product->active) {
                throw new \PrestaShopException('Product is invalid: ' . $idProduct);
            }

            if (!$product->available_for_order) {
                throw new \PrestaShopException('Product is not available for order: ' . $idProduct);
            }

            if ($idProductAttribute > 0) {
                $attributeExists = (bool) \Db::getInstance()->getValue(
                    'SELECT id_product_attribute
                     FROM `' . _DB_PREFIX_ . 'product_attribute`
                     WHERE id_product_attribute = ' . (int) $idProductAttribute . '
                       AND id_product = ' . (int) $idProduct
                );

                if (!$attributeExists) {
                    $idProductAttribute = 0;
                }
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

            \PsLandingPageLogger::info('Cart product add attempt.', [
                'id_cart' => (int) $cart->id,
                'id_product' => (int) $idProduct,
                'id_product_attribute' => (int) $idProductAttribute,
                'quantity' => (int) $quantity,
                'update_qty_result' => $updated,
            ]);

            if ((int) $updated <= 0) {
                throw new \PrestaShopException('Failed to add product to cart for reference: ' . $reference);
            }

            $cartRows = \Db::getInstance()->executeS(
                'SELECT id_product, id_product_attribute, quantity
                 FROM `' . _DB_PREFIX_ . 'cart_product`
                 WHERE id_cart = ' . (int) $cart->id
            );

            \PsLandingPageLogger::info('Cart contents after product add.', [
                'id_cart' => (int) $cart->id,
                'rows' => is_array($cartRows) ? $cartRows : [],
            ]);
        }

        if (!$cart->update()) {
            throw new \PrestaShopException('Failed to persist cart after adding products.');
        }

        $cartProductCount = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'cart_product`
             WHERE id_cart = ' . (int) $cart->id
        );

        if ($cartProductCount <= 0) {
            throw new \PrestaShopException('Cart persistence check failed: no products in cart_product table.');
        }

        \PsLandingPageLogger::info('Cart persisted with products.', [
            'id_cart' => (int) $cart->id,
            'cart_product_count' => $cartProductCount,
        ]);
    }

    private function buildProductsSignature(array $products)
    {
        $parts = [];
        foreach ($products as $product) {
            $parts[] = (int) $product['id_product'] . ':' . (int) $product['id_product_attribute'] . ':' . (int) $product['quantity'];
        }

        sort($parts, SORT_STRING);

        return sha1(implode('|', $parts));
    }

    private function buildCartSignatureFromDatabase($idCart)
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT id_product, id_product_attribute, quantity
             FROM `' . _DB_PREFIX_ . 'cart_product`
             WHERE id_cart = ' . (int) $idCart
        );

        if (!is_array($rows) || empty($rows)) {
            return '';
        }

        $parts = [];
        foreach ($rows as $row) {
            $parts[] = (int) $row['id_product'] . ':' . (int) $row['id_product_attribute'] . ':' . (int) $row['quantity'];
        }

        sort($parts, SORT_STRING);

        return sha1(implode('|', $parts));
    }

    private function authenticateCustomer(\Customer $customer, \Cart $cart)
    {
        $this->context->customer = $customer;
        $this->context->updateCustomer($customer);
        $this->context->cart = $cart;
        $this->context->cookie->id_customer = (int) $customer->id;
        $this->context->cookie->id_guest = (int) $customer->id_guest;
        $this->context->cookie->customer_firstname = (string) $customer->firstname;
        $this->context->cookie->customer_lastname = (string) $customer->lastname;
        $this->context->cookie->logged = 1;
        $this->context->cookie->secure_key = (string) $customer->secure_key;
        $this->context->cookie->id_cart = (int) $cart->id;
        $this->context->cookie->check_cgv = 0;
        $this->context->cookie->write();

        \PsLandingPageLogger::info('Checkout context authenticated.', [
            'id_customer' => (int) $customer->id,
            'id_cart' => (int) $cart->id,
        ]);
    }

    public function hydrateCheckoutContext($idCart, $secureKey)
    {
        $cart = new \Cart((int) $idCart);
        if (!\Validate::isLoadedObject($cart)) {
            return [
                'success' => false,
                'error' => 'Cart not found.',
                'http_code' => 404,
            ];
        }

        if ((string) $cart->secure_key !== (string) $secureKey) {
            return [
                'success' => false,
                'error' => 'Invalid cart key.',
                'http_code' => 403,
            ];
        }

        if ((int) $cart->id_customer <= 0) {
            return [
                'success' => false,
                'error' => 'Cart has no customer.',
                'http_code' => 422,
            ];
        }

        $customer = new \Customer((int) $cart->id_customer);
        if (!\Validate::isLoadedObject($customer)) {
            return [
                'success' => false,
                'error' => 'Customer not found for cart.',
                'http_code' => 404,
            ];
        }

        $cart = $this->reloadCartOrFail((int) $cart->id, (int) $customer->id, (string) $customer->secure_key);
        $products = $cart->getProducts();
        if (empty($products)) {
            $cartFallback = new \Cart((int) $cart->id);
            if (\Validate::isLoadedObject($cartFallback)) {
                $cart = $cartFallback;
                $products = $cart->getProducts();
            }
        }

        if (empty($products)) {
            return [
                'success' => false,
                'error' => 'Cart is empty.',
                'http_code' => 422,
            ];
        }

        $this->authenticateCustomer($customer, $cart);

        return [
            'success' => true,
            'id_cart' => (int) $cart->id,
            'http_code' => 200,
        ];
    }

    private function reloadCartOrFail($idCart, $expectedCustomerId, $expectedSecureKey)
    {
        $reloadedCart = new \Cart((int) $idCart);
        if (!\Validate::isLoadedObject($reloadedCart)) {
            throw new \PrestaShopException('Failed to reload cart after creation.');
        }

        if ((int) $reloadedCart->id_customer !== (int) $expectedCustomerId) {
            throw new \PrestaShopException('Cart-customer binding mismatch.');
        }

        if ((string) $reloadedCart->secure_key !== (string) $expectedSecureKey) {
            throw new \PrestaShopException('Cart secure key mismatch.');
        }

        return $reloadedCart;
    }

    private function assertCartHasProducts(\Cart $cart, $stage)
    {
        $products = $cart->getProducts();
        if (empty($products)) {
            \PsLandingPageLogger::error('Cart has no products at checkout stage.', [
                'stage' => (string) $stage,
                'id_cart' => (int) $cart->id,
                'id_customer' => (int) $cart->id_customer,
            ]);

            throw new \PrestaShopException('Cart is empty after creation.');
        }
    }

    private function resolveProductsByReferences(array $references)
    {
        $result = [];
        $cleanedReferences = [];

        foreach ($references as $reference) {
            $clean = trim((string) $reference);
            if ($clean === '') {
                continue;
            }

            $cleanedReferences[$clean] = $clean;
        }

        if (empty($cleanedReferences)) {
            return $result;
        }

        $in = [];
        foreach ($cleanedReferences as $reference) {
            $in[] = '"' . pSQL($reference) . '"';
        }

        $rows = \Db::getInstance()->executeS(
            'SELECT pa.reference, pa.id_product, pa.id_product_attribute
             FROM `' . _DB_PREFIX_ . 'product_attribute` pa
             INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas
                ON (pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = ' . (int) $this->context->shop->id . ')
             WHERE pa.reference IN (' . implode(',', $in) . ')'
        );

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $reference = (string) $row['reference'];
                if ($reference === '' || isset($result[$reference])) {
                    continue;
                }

                $result[$reference] = [
                    'success' => true,
                    'id_product' => (int) $row['id_product'],
                    'id_product_attribute' => (int) $row['id_product_attribute'],
                ];
            }
        }

        foreach ($cleanedReferences as $reference) {
            if (isset($result[$reference])) {
                continue;
            }

            $idProduct = (int) \Product::getIdByReference($reference);
            if ($idProduct <= 0) {
                $result[$reference] = [
                    'success' => false,
                    'error' => 'Product reference not found: ' . $reference . '.',
                ];

                continue;
            }

            $result[$reference] = [
                'success' => true,
                'id_product' => $idProduct,
                'id_product_attribute' => (int) \Product::getDefaultAttribute($idProduct),
            ];
        }

        return $result;
    }

    private function generateUniqueGuestEmail($phone = '')
    {
        $attempts = 0;

        while ($attempts < 10) {
            $attempts++;
            if ($phone !== '') {
                $sanitizedPhone = preg_replace('/[^0-9]/', '', $phone);
                $email = $sanitizedPhone . '@jomti.com';
            } else {
                $email = 'guest_' . time() . '_' . random_int(1000, 9999) . '@jomti.local';
            }

            if (!\Validate::isEmail($email)) {
                continue;
            }

            $idCustomer = (int) \Customer::customerExists($email, true, true);
            if ($idCustomer <= 0) {
                return $email;
            }
        }

        throw new \PrestaShopException('Unable to generate a unique customer email.');
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
            'code' => (int) $httpCode,
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
