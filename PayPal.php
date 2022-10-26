<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace PayPal;

use ApySecurity\Model\Role\RoleInterface;
use ApyUtilities\ApyUtilities;
use ApyUtilities\Interfaces\ApyPaymentEnabledModuleInterface;
use ApyUtilities\Interfaces\CartHelperInterface;
use ApyUtilities\Interfaces\OrderHelperInterface;
use ApyUtilities\Traits\PaymentModuleNoLoginTrait;
use Exception;
use Monolog\Logger;
use PayPal\Exception\PayPalConnectionException;
use PayPal\Model\PaypalCartQuery;
use PayPal\Model\PaypalOrder;
use PayPal\Model\PaypalOrderQuery;
use PayPal\Model\PaypalPlanifiedPaymentQuery;
use PayPal\Service\Base\PayPalBaseService;
use PayPal\Service\PayPalAgreementService;
use PayPal\Service\PayPalLoggerService;
use PayPal\Service\PayPalPaymentService;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Propel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Translation\Translator;
use Thelia\Install\Database;
use Thelia\Model\Lang;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleImageQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\Profile;
use Thelia\Model\ProfileModule;
use Thelia\Model\ProfileModuleQuery;
use Thelia\Model\ProfileQuery;
use Thelia\Module\AbstractPaymentModule;
use Thelia\Tools\URL;

class PayPal extends AbstractPaymentModule implements ApyPaymentEnabledModuleInterface
{
    use PaymentModuleNoLoginTrait;
    /** @var string */
    const DOMAIN_NAME     = 'paypal';
    const ROUTER          = 'router.paypal';
    const PAYMENT_ENABLED = 'paypal_payment_enabled';

    /**
     * The confirmation message identifier
     */
    const CONFIRMATION_MESSAGE_NAME = 'paypal_payment_confirmation';
    const RECURSIVE_MESSAGE_NAME    = 'paypal_recursive_payment_confirmation';

    const CREDIT_CARD_TYPE_VISA       = 'visa';
    const CREDIT_CARD_TYPE_MASTERCARD = 'mastercard';
    const CREDIT_CARD_TYPE_DISCOVER   = 'discover';
    const CREDIT_CARD_TYPE_AMEX       = 'amex';

    const PAYPAL_METHOD_PAYPAL            = 'paypal';
    const PAYPAL_METHOD_EXPRESS_CHECKOUT  = 'express_checkout';
    const PAYPAL_METHOD_CREDIT_CARD       = 'credit_card';
    const PAYPAL_METHOD_PLANIFIED_PAYMENT = 'planified_payment';

    const PAYPAL_PAYMENT_SERVICE_ID   = 'paypal_payment_service';
    const PAYPAL_CUSTOMER_SERVICE_ID  = 'paypal_customer_service';
    const PAYPAL_AGREEMENT_SERVICE_ID = 'paypal_agreement_service';

    const PAYMENT_STATE_APPROVED = 'approved';
    const PAYMENT_STATE_CREATED  = 'created';
    const PAYMENT_STATE_REFUSED  = 'refused';

    /**
     *  Method used by payment gateway.
     *
     *  If this method return a \Thelia\Core\HttpFoundation\Response instance, this response is send to the
     *  browser.
     *
     *  In many cases, it's necessary to send a form to the payment gateway. On your response you can return this form
     *  already completed, ready to be sent
     *
     * @param Order $order
     * @return RedirectResponse
     * @throws Exception
     */
    public function pay(Order $order)
    {
        $con = Propel::getConnection();
        $con->beginTransaction();

        try {
            /** @var PayPalPaymentService $payPalService */
            $payPalService = $this->getContainer()->get(self::PAYPAL_PAYMENT_SERVICE_ID);
            /** @var PayPalAgreementService $payPalAgreementService */
            $payPalAgreementService = $this->getContainer()->get(self::PAYPAL_AGREEMENT_SERVICE_ID);

            if (null !== $payPalCart = PaypalCartQuery::create()->findOneById($order->getCartId())) {

                if (null !== $payPalCart->getCreditCardId()) {
                    $payment = $payPalService->makePayment($order, $payPalCart->getCreditCardId());

                    //This payment method does not have a callback URL... So we have to check the payment status
                    if ($payment->getState() === PayPal::PAYMENT_STATE_APPROVED) {
                        $event = new OrderEvent($order);
                        $event->setStatus(OrderStatusQuery::getPaidStatus()->getId());
                        $this->getDispatcher()->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);
                        $response = new RedirectResponse(URL::getInstance()->absoluteUrl('/order/placed/' . $order->getId()));
                        PayPalLoggerService::log(
                            Translator::getInstance()->trans(
                                'Order payed with success with method : %method',
                                [
                                    '%method' => self::PAYPAL_METHOD_CREDIT_CARD
                                ],
                                self::DOMAIN_NAME
                            ),
                            [
                                'order_id'    => $order->getId(),
                                'customer_id' => $order->getCustomerId()
                            ],
                            Logger::INFO
                        );
                    } else {
                        $response = new RedirectResponse(URL::getInstance()->absoluteUrl('/module/paypal/cancel/' . $order->getId()));
                        PayPalLoggerService::log(
                            Translator::getInstance()->trans(
                                'Order failed with method : %method',
                                [
                                    '%method' => self::PAYPAL_METHOD_CREDIT_CARD
                                ],
                                self::DOMAIN_NAME
                            ),
                            [
                                'order_id'    => $order->getId(),
                                'customer_id' => $order->getCustomerId()
                            ],
                            Logger::CRITICAL
                        );
                    }
                } elseif (null !== $planifiedPayment = PaypalPlanifiedPaymentQuery::create()->findOneById($payPalCart->getPlanifiedPaymentId())) {
                    //Agreement Payment
                    $agreement = $payPalAgreementService->makeAgreement($order, $planifiedPayment);
                    $response  = new RedirectResponse($agreement->getApprovalLink());
                    PayPalLoggerService::log(
                        Translator::getInstance()->trans(
                            'Order created with success in PayPal with method : %method',
                            [
                                '%method' => self::PAYPAL_METHOD_PLANIFIED_PAYMENT
                            ],
                            self::DOMAIN_NAME
                        ),
                        [
                            'order_id'    => $order->getId(),
                            'customer_id' => $order->getCustomerId()
                        ],
                        Logger::INFO
                    );
                } else {
                    //Classic Payment
                    $payment  = $payPalService->makePayment($order);
                    $response = new RedirectResponse($payment->getApprovalLink());
                    PayPalLoggerService::log(
                        Translator::getInstance()->trans(
                            'Order created with success in PayPal with method : %method',
                            [
                                '%method' => self::PAYPAL_METHOD_PAYPAL
                            ],
                            self::DOMAIN_NAME
                        ),
                        [
                            'order_id'    => $order->getId(),
                            'customer_id' => $order->getCustomerId()
                        ],
                        Logger::INFO
                    );
                }
            } else {
                //Classic Payment
                $payment  = $payPalService->makePayment($order);
                $response = new RedirectResponse($payment->getApprovalLink());
                PayPalLoggerService::log(
                    Translator::getInstance()->trans(
                        'Order created with success in PayPal with method : %method',
                        [
                            '%method' => self::PAYPAL_METHOD_PAYPAL
                        ],
                        self::DOMAIN_NAME
                    ),
                    [
                        'order_id'    => $order->getId(),
                        'customer_id' => $order->getCustomerId()
                    ],
                    Logger::INFO
                );

                //Future Payment NOT OPERATIONNEL IN PAYPAL API REST YET !
                //$payment = $payPalService->makePayment($order, null, null, true);
                //$response = new RedirectResponse($payment->getApprovalLink());
            }

            $con->commit();

            return $response;
        } catch (PayPalConnectionException $e) {
            $con->rollBack();

            $message = sprintf('url : %s. data : %s. message : %s', $e->getUrl(), $e->getData(), $e->getMessage());
            PayPalLoggerService::log(
                $message,
                [
                    'customer_id' => $order->getCustomerId(),
                    'order_id'    => $order->getId()
                ],
                Logger::CRITICAL
            );
            throw $e;
        } catch (Exception $e) {
            $con->rollBack();

            PayPalLoggerService::log(
                $e->getMessage(),
                [
                    'customer_id' => $order->getCustomerId(),
                    'order_id'    => $order->getId()
                ],
                Logger::CRITICAL
            );
            throw $e;
        }
    }

    /**
     * This method is call on Payment loop.
     *
     * If you return true, the payment method will de display
     * If you return false, the payment method will not be display
     *
     * @return boolean
     * @return bool
     * @throws PropelException
     */
    public function isValidPayment()
    {
        $isValid = false;

        if (!self::isPaymentEnabled()) {
            return false;
        }

        $order_total = 0;
        if ($this->getRequest()->query->has('token')) {
            $tokenLink = $this->getRequest()->get('token');
            /** @var OrderHelperInterface $orderHelper */
            $orderHelper        = $this->getContainer()->get(OrderHelperInterface::ORDER_HELPER_SERVICE_ID);
            $apyOrderQueryClass = $orderHelper->getApyOrderQueryClassName();
            $apyOrder           = $apyOrderQueryClass::create()->findOneByLinkToken($tokenLink);

            if ($this->getRequest()->getSession()->get(ApyUtilities::ORDER_TO_PAY_ID) == $apyOrder->getOrderId()) {
                $orderHelper = $this->getContainer()->get(OrderHelperInterface::ORDER_HELPER_SERVICE_ID);
                $order_total = $orderHelper::getTotalAmount($apyOrder->getOrder());
            }
            $cartItemCount = OrderProductQuery::create()->findByOrderId($apyOrder->getOrderId())->count();
        } else {
            $cart = $this->getRequest()->getSession()->getSessionCart($this->getDispatcher());
            // Check if total order amount is within the module's limits
            /** @var CartHelperInterface $cartHelper */
            $cartHelper  = $this->container->get(CartHelperInterface::SERVICE_ID);
            $order_total = $cartHelper->getCartTotalAmount($cart, Lang::getDefaultLanguage());
            // Check cart item count
            $cartItemCount = $cart->countCartItems();
        }
        $min_amount = Paypal::getConfigValue('minimum_amount', 0);
        $max_amount = Paypal::getConfigValue('maximum_amount', 0);

        if (
            ($order_total > 0)
            &&
            ($min_amount <= 0 || $order_total >= $min_amount)
            &&
            ($max_amount <= 0 || $order_total <= $max_amount)
        ) {

            if ($cartItemCount <= Paypal::getConfigValue('cart_item_count', 9)) {
                $isValid = true;

                if (PayPalBaseService::isSandboxMode()) {
                    // In sandbox mode, check the current IP
                    $raw_ips = explode("\n", Paypal::getConfigValue('allowed_ip_list', ''));

                    $allowed_client_ips = [];

                    foreach ($raw_ips as $ip) {
                        $allowed_client_ips[] = trim($ip);
                    }

                    $client_ip = $this->getRequest()->getClientIp();

                    $isValid = in_array($client_ip, $allowed_client_ips);
                }
            }
        }

        return $isValid;
    }

    /**
     * if you want, you can manage stock in your module instead of order process.
     * Return false to decrease the stock when order status switch to pay
     *
     * @return bool
     */
    public function manageStockOnCreation()
    {
        return false;
    }


    /**
     * @param ConnectionInterface|null $con
     * @throws Exception
     */
    public function postDeactivation(ConnectionInterface $con = null): void
    {
        self::changePaymentEnabled(false);
    }

    /**
     * @param ConnectionInterface      $con
     * @param ConnectionInterface|null $con
     * @throws PropelException
     */
    public function postActivation(ConnectionInterface $con = null): void
    {
        $database = new Database($con);
        $database->insertSql(null, [__DIR__ . "/Config/create.sql"]);

        // Setup some default values at first install
        if (null === self::getConfigValue('minimum_amount', null)) {
            self::setConfigValue('minimum_amount', 0);
            self::setConfigValue('maximum_amount', 0);
            self::setConfigValue('send_payment_confirmation_message', 1);
            self::setConfigValue('cart_item_count', 999);
        }

        if (null === MessageQuery::create()->findOneByName(self::CONFIRMATION_MESSAGE_NAME)) {
            $message = new Message();

            $message
                ->setName(self::CONFIRMATION_MESSAGE_NAME)
                ->setHtmlTemplateFileName('paypal-payment-confirmation.html')
                ->setTextTemplateFileName('paypal-payment-confirmation.txt')
                ->setLocale('en_US')
                ->setTitle('Paypal payment confirmation')
                ->setSubject('Payment of order {$order_ref}')
                ->setLocale('fr_FR')
                ->setTitle('Confirmation de paiement par Paypal')
                ->setSubject('Confirmation du paiement de votre commande {$order_ref}')
                ->save();
        }

        if (null === MessageQuery::create()->findOneByName(self::RECURSIVE_MESSAGE_NAME)) {
            $message = new Message();

            $message
                ->setName(self::RECURSIVE_MESSAGE_NAME)
                ->setHtmlTemplateFileName('paypal-recursive-payment-confirmation.html')
                ->setTextTemplateFileName('paypal-recursive-payment-confirmation.txt')
                ->setLocale('en_US')
                ->setTitle('Paypal payment confirmation')
                ->setSubject('Payment of order {$order_ref}')
                ->setLocale('fr_FR')
                ->setTitle('Confirmation de paiement par Paypal')
                ->setSubject('Confirmation du paiement de votre commande {$order_ref}')
                ->save();
        }

        /* Deploy the module's image */
        $module = $this->getModuleModel();

        if (ModuleImageQuery::create()->filterByModule($module)->count() == 0) {
            $this->deployImageFolder($module, sprintf('%s/images', __DIR__), $con);
        }

        // Authorisé les utilisateurs ApyAndYou à modifier le module
        $profile = ProfileQuery::create()->findOneByCode('ApyDeveloper');
        if ($profile instanceof Profile) {
            $profileModule = ProfileModuleQuery::create()
                ->filterByProfileId($profile->getId())
                ->findOneByModuleId($this->getModuleModel()->getId());

            if (!$profileModule instanceof ProfileModule) {
                $profileModule = new ProfileModule();

                $profileModule
                    ->setProfileId($profile->getId())
                    ->setModuleId($this->getModuleModel()->getId());
            }
            $profileModule->setAccess(RoleInterface::ACCESS_VIEW_CREATE_UPDATE_DELETE)->save();
        }
    }

    public function update($currentVersion, $newVersion, ConnectionInterface $con = null): void
    {
        $finder = (new Finder())
            ->files()
            ->name('#.*?\.sql#')
            ->sortByName()
            ->in(__DIR__ . DS . 'Config' . DS . 'Update');

        $database = new Database($con);

        /** @var SplFileInfo $updateSQLFile */
        foreach ($finder as $updateSQLFile) {
            if (version_compare($currentVersion, str_replace('.sql', '', $updateSQLFile->getFilename()), '<')) {
                $database->insertSql(
                    null,
                    [
                        $updateSQLFile->getPathname()
                    ]
                );
            }
        }
    }

    /**
     * Vérifier si la checkBox pour activé le mode de payement est actif
     * @return bool
     */
    public static function isPaymentEnabled() : bool
    {
        return (bool)Paypal::getConfigValue(self::PAYMENT_ENABLED, false);
    }

    /**
     * Changer la config
     * @param bool $enabled
     */
    public static function changePaymentEnabled($enabled)
    {
        return Paypal::setConfigValue(self::PAYMENT_ENABLED, $enabled);
    }


    /**
     * @param Order $order
     * @return string
     */
    public static function getTransactionForOrder(Order $order): string
    {
        $paypalOrder = PaypalOrderQuery::create()->findOneById($order->getId());
        if ($paypalOrder instanceof PaypalOrder) {
            return $paypalOrder->getPaymentId();
        }

        return '';
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([THELIA_MODULE_DIR . ucfirst(self::getModuleCode()). "/I18n/*"])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
