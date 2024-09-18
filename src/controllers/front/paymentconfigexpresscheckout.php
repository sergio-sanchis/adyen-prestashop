<?php

use Adyen\Core\BusinessLogic\CheckoutAPI\CheckoutConfig\Response\PaymentCheckoutConfigResponse;
use Adyen\Core\BusinessLogic\Domain\Checkout\PaymentRequest\Exceptions\InvalidCurrencyCode;
use Adyen\Core\Infrastructure\ORM\Exceptions\RepositoryClassException;
use Adyen\Core\Infrastructure\ServiceRegister;
use AdyenPayment\Classes\Bootstrap;
use AdyenPayment\Classes\Services\CheckoutHandler;
use AdyenPayment\Classes\Services\Integration\CustomerService;
use AdyenPayment\Classes\Utility\AdyenPrestaShopUtility;
use PrestaShop\PrestaShop\Core\Domain\Country\Exception\CountryNotFoundException;

/**
 * Class AdyenOfficialPaymentConfigExpressCheckoutModuleFrontController
 */
class AdyenOfficialPaymentConfigExpressCheckoutModuleFrontController extends ModuleFrontController
{
    /**
     * @throws RepositoryClassException
     */
    public function __construct()
    {
        parent::__construct();

        Bootstrap::init();
    }

    /**
     * @return void
     *
     * @throws InvalidCurrencyCode
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws CountryNotFoundException
     */
    public function postProcess()
    {
        $newAddress = Tools::getValue('newAddress');
        if ($newAddress) {
            $config = $this->getConfigForNewAddress($newAddress);

            header('Content-Type: application/json');
            die(json_encode(
                [
                    'amount' => $config->toArray()['amount']['value'],
                    'currency' => $config->toArray()['amount']['currency'],
                    'country' => Context::getContext()->country->iso_code
                ])
            );
        }

        $cartId = (int)Tools::getValue('cartId');
        if ($cartId !== 0) {
            $cart = new Cart($cartId);
            if (!$cart->id) {
                AdyenPrestaShopUtility::die400(['message' => 'Invalid parameters.']);
            }

            $config = CheckoutHandler::getExpressCheckoutConfig($cart);
        } else {
            $cart = $this->getCartForProduct();
            $config = CheckoutHandler::getExpressCheckoutConfig($cart);
            $cart->delete();
        }

        AdyenPrestaShopUtility::dieJson($config);
    }

    /**
     * @param array $data
     *
     * @return PaymentCheckoutConfigResponse
     *
     * @throws CountryNotFoundException
     * @throws InvalidCurrencyCode
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function getConfigForNewAddress($data)
    {
        Tools::clearAllCache();
        \ProductCore::resetStaticCache();
        Cache::clear();

        $billingAddress = json_decode($data['adyenBillingAddress'], false);
        $countryCode = $billingAddress->country;
        /** @var CustomerService $customerService */
        $customerService = ServiceRegister::getService(CustomerService::class);
        if (!$customerService->verifyIfCountryNotRestricted($countryCode, (int)$this->context->language->id)) {
            AdyenPrestaShopUtility::die400(["message" => "Invalid country code"]);
        }

        $cartId = (int)Tools::getValue('cartId');
        if ($cartId !== 0) {
            $cart = new Cart($cartId);
            if (!$cart->id) {
                AdyenPrestaShopUtility::die400(['message' => 'Invalid parameters.']);
            }

            $cart = $this->updateCartWithAddresses($data, $cart);
            $customerService->updateDeliveryAddress((int)$cart->id, (int)$cart->id_address_delivery);
            $cart->update();

            $config = CheckoutHandler::getExpressCheckoutConfig($cart);

            $address = new Address($cart->id_address_delivery);
            $address->delete();

            return $config;
        }

        $cart = $this->createEmptyCart();
        $cart = $this->updateCartWithAddresses($data, $cart);
        $cart = $this->addProductsToCart($cart);
        $customerService->updateDeliveryAddress((int)$cart->id, (int)$cart->id_address_delivery);
        $cart->update();

        $config = CheckoutHandler::getExpressCheckoutConfig($cart);

        $address = new Address($cart->id_address_delivery);
        $address->delete();
        $cart->delete();

        return $config;
    }

    /**
     * @param array $data
     * @param Cart $cart
     *
     * @return Cart
     *
     * @throws CountryNotFoundException
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function updateCartWithAddresses($data, $cart): Cart
    {
        /** @var CustomerService $customerService */
        $customerService = ServiceRegister::getService(CustomerService::class);

        $address = json_decode($data['adyenBillingAddress']);
        $address = $customerService->createAddress($address);
        $address->add();

        $cart->id_address_delivery = $address->id;
        $cart->id_address_invoice = $address->id;
        $cart->update();

        return $cart;
    }

    /**
     * @param Cart $cart
     *
     * @return Cart
     *
     */
    private function addProductsToCart(Cart $cart): Cart
    {
        $productId = (int)Tools::getValue('id_product');
        $productAttributeId = (int)Tools::getValue('id_product_attribute');
        $quantityWanted = (int)Tools::getValue('quantity_wanted');
        $customizationId = (int)Tools::getValue('id_customization');
        $cart->updateQty($quantityWanted, $productId, $productAttributeId, $customizationId);

        return $cart;
    }

    /**
     * @return Cart
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function getCartForProduct(): Cart
    {
        $cart = $this->createEmptyCart();
        $customer = new Customer((int)$this->context->customer->id);
        if($customer->id){
            $addresses = $customer->getAddresses((int)$this->context->language->id);
            if (count($addresses) > 0) {
                $cart = $this->updateCart($customer, $addresses[0]['id_address'], $addresses[0]['id_address'], $cart);
            }
        }

        return $this->addProductsToCart($cart);
    }

    /**
     * @return Cart
     * @throws PrestaShopException
     */
    private function createEmptyCart(): Cart
    {
        $cart = new Cart();
        $cart->id_currency = (int)$this->context->currency->id;
        $cart->id_lang = (int)$this->context->language->id;
        $cart->save();

        return $cart;
    }

    /**
     * @param Customer $customer
     * @param int $deliveryAddressId
     * @param int $invoiceAddressId
     * @param Cart $cart
     * @return Cart
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function updateCart(Customer $customer, int $deliveryAddressId, int $invoiceAddressId, Cart $cart): Cart
    {
        $cart->secure_key = $customer->secure_key;
        $cart->id_address_delivery = $deliveryAddressId;
        $cart->id_address_invoice = $invoiceAddressId;
        $cart->id_customer = $customer->id;
        $cart->update();

        return $cart;
    }
}
