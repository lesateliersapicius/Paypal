<?php

namespace PayPal\Provider;

use ApyUtilities\Provider\AbstractPaymentModuleConf;
use PayPal\PayPal;
use stdClass;

/**
 * Class PayPalModuleConfProvider
 * @package PayPal\Provider
 */
class PayPalModuleConfProvider extends AbstractPaymentModuleConf
{
    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'PayPal';
    }

    /**
     * @inheritDoc
     */
    public function getID(): string
    {
        return Paypal::getConfigValue('merchant_id', '');
    }

    /**
     * @inheritDoc
     */
    public function getActive(): bool
    {
        return (bool)PayPal::isPaymentEnabled();
    }

    /**
     * @inheritDoc
     */
    public function getData(): stdClass
    {
        return (object)[
            'clientID'     => Paypal::getConfigValue('login', ''),
            'clientSecret' => Paypal::getConfigValue('password', ''),
            'merchantID'   => Paypal::getConfigValue('merchant_id', ''),
        ];
    }
}
