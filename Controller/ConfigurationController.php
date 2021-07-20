<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia	                                                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : info@thelia.net                                                      */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/

namespace PayPal\Controller;

use Exception;
use PayPal\Form\ConfigurationForm;
use PayPal\PayPal;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Thelia;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Tools\URL;
use Thelia\Tools\Version\Version;

/**
 * Class ConfigurePaypal
 * @package Paypal\Controller
 */
class ConfigurationController extends BaseAdminController
{
    /*
     * Checks paypal.configure || paypal.configure.sandbox form and save config into json file
     */
    /**
     * @return mixed|\Symfony\Component\HttpFoundation\Response|Response
     */
    public function configureAction()
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'Paypal', AccessManager::UPDATE)) {
            return $response;
        }

        $configurationForm = $this->createForm(ConfigurationForm::FORM_NAME);

        try {
            $form = $this->validateForm($configurationForm, "POST");

            // Get the form field values
            $data = $form->getData();

            $oldStatus = Paypal::isPaymentEnabled();

            foreach ($data as $name => $value) {
                if (is_array($value)) {
                    $value = implode(';', $value);
                }

                Paypal::setConfigValue($name, $value);
            }

            // Forcé certains valeurs
            PayPal::setConfigValue('send_payment_confirmation_message', false);
            PayPal::setConfigValue('method_express_checkout', false);
            PayPal::setConfigValue('method_credit_card', false);
            PayPal::setConfigValue('method_planified_payment', false);
            PayPal::setConfigValue('send_payment_confirmation_message', false);
            PayPal::setConfigValue('send_recursive_message', false);

            // histoire de ne pas devoir aller en BDD pour éplucher la request, on regarde si le statut du mode de
            // paiement a changé ou non, pour l'indiquer directement dans le message
            $statusLog = '';
            if ($oldStatus !== $data[Paypal::PAYMENT_ENABLED]) {
                $statusLog = $data[Paypal::PAYMENT_ENABLED] ? ' (Payment actived)' : ' (Payment deactived)';
            }
            // Log configuration modification
            $this->adminLogAppend(
                AdminResources::UPDATE,
                AccessManager::UPDATE,
                'PayPal configuration updated' . $statusLog
            );

            if ($this->getRequest()->get('save_mode') == 'stay') {
                // If we have to stay on the same page, redisplay the configuration page/
                $url = '/admin/module/Paypal';
            } else {
                // If we have to close the page, go back to the module back-office page.
                $url = '/admin/modules';
            }

            return $this->generateRedirect(URL::getInstance()->absoluteUrl($url));
        } catch (FormValidationException $ex) {
            $error_msg = $this->createStandardFormValidationErrorMessage($ex);
        } catch (Exception $ex) {
            $error_msg = $ex->getMessage();
        }

        $this->setupFormErrorContext(
            $this->getTranslator()->trans("Paypal configuration", [], PayPal::DOMAIN_NAME),
            $error_msg,
            $configurationForm,
            $ex
        );

        // Before 2.2, the errored form is not stored in session
        if (Version::test(Thelia::THELIA_VERSION, '2.2', false, "<")) {
            return $this->render('module-configure', ['module_code' => PayPal::getModuleCode()]);
        } else {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/module/PayPal'));
        }
    }

    /**
     * @return Response
     */
    public function renderConfigureAction()
    {
        return $this->render('module-configure', ['module_code' => PayPal::getModuleCode()]);
    }

    /**
     * @return Response
     */
    public function logAction()
    {
        return $this->render('paypal/paypal-log');
    }
}
