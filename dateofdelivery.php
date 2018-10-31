<?php
/**
 * 2007-2018 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2018 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Class DateOfDelivery
 */
class DateOfDelivery extends Module
{
    private $_html = '';

    /**
     * DateOfDelivery constructor.
     *
     * @throws PrestaShopDatabaseException
     */
    public function __construct()
    {
        $this->name = 'dateofdelivery';
        $this->tab = 'shipping_logistics';
        $this->version = '2.1.0';
        $this->author = 'PrestaShop';
        $this->need_instance = 0;

        $this->controllers = ['ajax'];
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('Date of delivery', [], 'Modules.Dateofdelivery.Admin');
        $this->description = $this->trans('Displays an approximate date of delivery', [], 'Modules.Dateofdelivery.Admin');
        $this->ps_versions_compliancy = ['min' => '1.7.1.0', 'max' => _PS_VERSION_];
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function install()
    {
        if (!parent::install()
            || !$this->registerHook('displayBeforeCarrier')
            || !$this->registerHook('orderDetailDisplayed')
            || !$this->registerHook('actionCarrierUpdate')
            || !$this->registerHook('displayPDFInvoice')
        ) {
            return false;
        }

        if (!Db::getInstance()->execute('
		CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'dateofdelivery_carrier_rule` (
			`id_carrier_rule` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`id_carrier` INT NOT NULL,
			`minimal_time` INT NOT NULL,
			`maximal_time` INT NOT NULL,
			`delivery_saturday` TINYINT(1) NOT NULL,
			`delivery_sunday` TINYINT(1) NOT NULL
		) ENGINE ='._MYSQL_ENGINE_.';
		')) {
            return false;
        }

        Configuration::updateValue('DOD_EXTRA_TIME_PRODUCT_OOS', 0);
        Configuration::updateValue('DOD_EXTRA_TIME_PREPARATION', 1);
        Configuration::updateValue('DOD_PREPARATION_SATURDAY', 1);
        Configuration::updateValue('DOD_PREPARATION_SUNDAY', 1);
        Configuration::updateValue('DOD_DATE_FORMAT', 'l j F Y');

        return true;
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function uninstall()
    {
        Configuration::deleteByName('DOD_EXTRA_TIME_PRODUCT_OOS');
        Configuration::deleteByName('DOD_EXTRA_TIME_PREPARATION');
        Configuration::deleteByName('DOD_PREPARATION_SATURDAY');
        Configuration::deleteByName('DOD_PREPARATION_SUNDAY');
        Configuration::deleteByName('DOD_DATE_FORMAT');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'dateofdelivery_carrier_rule`');

        return parent::uninstall();
    }

    /**
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws PrestaShopExceptionCore
     * @throws SmartyException
     */
    public function getContent()
    {
        $this->_html .= '';

        $this->_postProcess();
        if (Tools::isSubmit('addCarrierRule') || (Tools::isSubmit('updatedateofdelivery') && Tools::isSubmit('id_carrier_rule'))) {
            $this->_html .= $this->renderAddForm();
        } else {
            $this->_html .= $this->renderList();
            $this->_html .= $this->renderForm();
        }

        return $this->_html;
    }

    /**
     * @param $params
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function hookActionCarrierUpdate($params)
    {
        $new_carrier = $params['carrier'];

        Db::getInstance()->execute('UPDATE `'._DB_PREFIX_.'dateofdelivery_carrier_rule` SET `id_carrier` = '.(int) $new_carrier->id.' WHERE `id_carrier` = '.(int) $params['id_carrier']);
    }

    /**
     * @param $params
     *
     * @return bool|string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function hookDisplayBeforeCarrier($params)
    {
        /** @var Cart $cart */
        $cart = $params['cart'];
        $idCarrier = $cart->id_carrier;
        $dateFrom = $dateTo = '';
        foreach ($cart->getProducts(false) as $product) {

            $oos = false;
            if (StockAvailable::getQuantityAvailableByProduct($product['id_product'], ($product['id_product_attribute'] ? (int) $product['id_product_attribute'] : null), (int) $this->context->shop->id) <= 0) {
                $oos = true;
            }

            $availableDate = Product::getAvailableDate($product['id_product'], ($product['id_product_attribute'] ? (int) $product['id_product_attribute'] : null));

            $dateRange = $this->_getDatesOfDelivery($idCarrier, $oos, $availableDate);

            if ($dateRange && (is_null($dateFrom) || $dateFrom < $dateRange[0][1])) {
                $dateFrom = $dateRange[0][1];
            }

            if ($dateRange && (is_null($dateTo) || $dateTo < $dateRange[1][1])) {
                $dateTo = $dateRange[1][1];
            }
        }

        $this->smarty->assign([
            'datesDelivery' => ($dateFrom && $dateTo) ? ['from' => $dateFrom, 'to' => $dateTo] : null,
            'idCarrier'     => $idCarrier,
        ]);

        return $this->fetch("module:{$this->name}/views/templates/hook/before_carrier.tpl");
    }

    /**
     * @param array $params
     *
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function hookOrderDetailDisplayed($params)
    {
        $oos = false; // For out of stock management
        foreach ($params['order']->getProducts() as $product) {
            if ($product['product_quantity_in_stock'] < 1) {
                $oos = true;
            }
        }

        $datesDelivery = $this->_getDatesOfDelivery((int) ($params['order']->id_carrier), $oos, $params['order']->date_add);

        if (!is_array($datesDelivery) || !count($datesDelivery)) {
            return '';
        }

        $this->smarty->assign('datesDelivery', $datesDelivery);

        return $this->fetch("module:{$this->name}/views/templates/hook/order_detail.tpl");
    }

    /**
     * Displays the delivery dates on the invoice
     *
     * @param array $params contains an instance of OrderInvoice
     *
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function hookDisplayPDFInvoice($params)
    {
        $orderInvoice = $params['object'];
        if (!($orderInvoice instanceof OrderInvoice)) {
            return '';
        }

        $order = new Order((int) $orderInvoice->id_order);

        $oos = false; // For out of stock management
        foreach ($order->getProducts() as $product) {
            if ($product['product_quantity_in_stock'] < 1) {
                $oos = true;
            }
        }

        $idCarrier = (int) OrderInvoice::getCarrierId($orderInvoice->id);
        $return = '';
        if (($dates_delivery = $this->_getDatesOfDelivery($idCarrier, $oos, $orderInvoice->date_add)) && isset($dates_delivery[0][0]) && isset($dates_delivery[1][0])) {
            $return = sprintf($this->trans('Approximate date of delivery is between %1$s and %2$s', [], 'Modules.Dateofdelivery.Shop'), $dates_delivery[0][0], $dates_delivery[1][0]);
        }

        return $return;
    }

    /**
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws PrestaShopExceptionCore
     */
    protected function _postProcess()
    {
        if (Tools::isSubmit('saturdaystatusdateofdelivery') && $idCarrierRule = Tools::getValue('id_carrier_rule')) {
            if ($this->_updateSaturdayStatus($idCarrierRule)) {
                Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&conf=4');
            } else {
                Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&conf=1');
            }
        }

        if (Tools::isSubmit('sundaystatusdateofdelivery') && $idCarrierRule = Tools::getValue('id_carrier_rule')) {
            if ($this->_updateSundayStatus($idCarrierRule)) {
                Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&conf=4');
            } else {
                Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&conf=1');
            }
        }

        $errors = [];
        if (Tools::isSubmit('submitMoreOptions')) {
            if (Tools::getValue('date_format') == '' OR !Validate::isCleanHtml(Tools::getValue('date_format'))) {
                $errors[] = $this->trans('Date format is invalid', [], 'Modules.Dateofdelivery.Admin');
            }

            if (!count($errors)) {
                Configuration::updateValue('DOD_EXTRA_TIME_PRODUCT_OOS', (int) Tools::getValue('extra_time_product_oos'));
                Configuration::updateValue('DOD_EXTRA_TIME_PREPARATION', (int) Tools::getValue('extra_time_preparation'));
                Configuration::updateValue('DOD_PREPARATION_SATURDAY', (int) Tools::getValue('preparation_day_preparation_saturday'));
                Configuration::updateValue('DOD_PREPARATION_SUNDAY', (int) Tools::getValue('preparation_day_preparation_sunday'));
                Configuration::updateValue('DOD_DATE_FORMAT', Tools::getValue('date_format'));
                $this->_html .= $this->displayConfirmation($this->trans('Settings are updated', [], 'Modules.Dateofdelivery.Admin'));
            } else {
                $this->_html .= $this->displayError(implode('<br />', $errors));
            }
        }

        if (Tools::isSubmit('submitCarrierRule')) {
            if (!Validate::isUnsignedInt(Tools::getValue('minimal_time'))) {
                $errors[] = $this->trans('Minimum time is invalid', [], 'Modules.Dateofdelivery.Admin');
            }
            if (!Validate::isUnsignedInt(Tools::getValue('maximal_time'))) {
                $errors[] = $this->trans('Maximum time is invalid', [], 'Modules.Dateofdelivery.Admin');
            }
            if (($carrier = new Carrier((int) Tools::getValue('id_carrier'))) AND !Validate::isLoadedObject($carrier)) {
                $errors[] = $this->trans('Carrier is invalid', [], 'Modules.Dateofdelivery.Admin');
            }
            if ($this->_isAlreadyDefinedForCarrier((int) ($carrier->id), (int) (Tools::getValue('id_carrier_rule', 0)))) {
                $errors[] = $this->trans('This rule has already been defined for this carrier.', [], 'Modules.Dateofdelivery.Admin');
            }

            if (!count($errors)) {
                if (Tools::isSubmit('addCarrierRule')) {
                    if (Db::getInstance()->execute('
					INSERT INTO `'._DB_PREFIX_.'dateofdelivery_carrier_rule`(`id_carrier`, `minimal_time`, `maximal_time`, `delivery_saturday`, `delivery_sunday`)
					VALUES ('.(int) $carrier->id.', '.(int) Tools::getValue('minimal_time').', '.(int) Tools::getValue('maximal_time').', '.(int) Tools::isSubmit('preparation_day_delivery_saturday').', '.(int) Tools::isSubmit('preparation_day_delivery_sunday').')
					')) {
                        Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&confirmAddCarrierRule');
                    } else {
                        $this->_html .= $this->displayError($this->trans('An error occurred on adding of carrier rule.', [], 'Modules.Dateofdelivery.Admin'));
                    }
                } else {
                    if (Db::getInstance()->execute('
					UPDATE `'._DB_PREFIX_.'dateofdelivery_carrier_rule`
					SET `id_carrier` = '.(int) $carrier->id.', `minimal_time` = '.(int) Tools::getValue('minimal_time').', `maximal_time` = '.(int) Tools::getValue('maximal_time').', `delivery_saturday` = '.(int) Tools::isSubmit('preparation_day_delivery_saturday').', `delivery_sunday` = '.(int) Tools::isSubmit('preparation_day_delivery_sunday').'
					WHERE `id_carrier_rule` = '.(int) Tools::getValue('id_carrier_rule')
                    )) {
                        Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&confirmupdatedateofdelivery');
                    } else {
                        $this->_html .= $this->displayError($this->trans('An error occurred on updating of carrier rule.', [], 'Modules.Dateofdelivery.Admin'));
                    }
                }

            } else {
                $this->_html .= $this->displayError(implode('<br />', $errors));
            }
        }

        if (Tools::isSubmit('deletedateofdelivery') && Tools::isSubmit('id_carrier_rule') && (int) Tools::getValue('id_carrier_rule') && $this->_isCarrierRuleExists((int) Tools::getValue('id_carrier_rule'))) {
            $this->_deleteByIdCarrierRule((int) Tools::getValue('id_carrier_rule'));
            $this->_html .= $this->displayConfirmation($this->trans('Carrier rule deleted successfully', [], 'Modules.Dateofdelivery.Admin'));
        }

        if (Tools::isSubmit('confirmAddCarrierRule')) {
            $this->_html = $this->displayConfirmation($this->trans('Carrier rule added successfully', [], 'Modules.Dateofdelivery.Admin'));
        }

        if (Tools::isSubmit('confirmupdatedateofdelivery')) {
            $this->_html = $this->displayConfirmation($this->trans('Carrier rule updated successfully', [], 'Modules.Dateofdelivery.Admin'));
        }
    }

    /**
     * @return array|false|mysqli_result|null|PDOStatement|resource
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function _getCarrierRulesWithCarrierName()
    {
        return Db::getInstance()->executeS('
		SELECT *
		FROM `'._DB_PREFIX_.'dateofdelivery_carrier_rule` dcr
		LEFT JOIN `'._DB_PREFIX_.'carrier` c ON (c.`id_carrier` = dcr.`id_carrier`)
		');
    }

    /**
     * @param int $idCarrierRule
     *
     * @return array|bool|null|object
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function _getCarrierRule($idCarrierRule)
    {
        if (!(int) $idCarrierRule) {
            return false;
        }

        return Db::getInstance()->getRow('
		SELECT *
		FROM `'._DB_PREFIX_.'dateofdelivery_carrier_rule`
		WHERE `id_carrier_rule` = '.(int) $idCarrierRule
        );
    }

    /**
     * @param int $idCarrier
     *
     * @return array|bool|null|object
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function _getCarrierRuleWithIdCarrier($idCarrier)
    {
        if (!(int) $idCarrier) {
            return false;
        }

        return Db::getInstance()->getRow('
		SELECT *
		FROM `'._DB_PREFIX_.'dateofdelivery_carrier_rule`
		WHERE `id_carrier` = '.(int) $idCarrier
        );
    }

    /**
     * @param int $idCarrierRule
     *
     * @return bool
     * @throws PrestaShopException
     */
    protected function _isCarrierRuleExists($idCarrierRule)
    {
        if (!(int) $idCarrierRule) {
            return false;
        }

        return (bool) Db::getInstance()->getValue('
		SELECT COUNT(*)
		FROM `'._DB_PREFIX_.'dateofdelivery_carrier_rule`
		WHERE `id_carrier_rule` = '.(int) $idCarrierRule
        );
    }

    /**
     * @param int $idCarrierRule
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function _deleteByIdCarrierRule($idCarrierRule)
    {
        if (!(int) $idCarrierRule) {
            return false;
        }

        return Db::getInstance()->execute('
		DELETE FROM `'._DB_PREFIX_.'dateofdelivery_carrier_rule`
		WHERE `id_carrier_rule` = '.(int) $idCarrierRule
        );
    }

    /**
     * @param int $idCarrier
     * @param int $idCarrierRule
     *
     * @return bool
     * @throws PrestaShopException
     */
    protected function _isAlreadyDefinedForCarrier($idCarrier, $idCarrierRule = 0)
    {
        if (!(int) $idCarrier) {
            return false;
        }

        return (bool) Db::getInstance()->getValue('
		SELECT COUNT(*)
		FROM `'._DB_PREFIX_.'dateofdelivery_carrier_rule`
		WHERE `id_carrier` = '.(int) ($idCarrier).'
		'.((int) $idCarrierRule != 0 ? 'AND `id_carrier_rule` != '.(int) $idCarrierRule : ''));
    }

    protected function _updateSaturdayStatus($id_carrier_rule)
    {
        if (!$this->_isCarrierRuleExists($id_carrier_rule)) {
            return false;
        }

        $select = 'SELECT delivery_saturday FROM `'._DB_PREFIX_.'dateofdelivery_carrier_rule`
						WHERE `id_carrier_rule` = '.(int) $id_carrier_rule;
        $old_value = (bool) Db::getInstance()->getValue($select);
        $sql = 'UPDATE `'._DB_PREFIX_.'dateofdelivery_carrier_rule` SET
						`delivery_saturday` = '.(int) !$old_value.'
						WHERE `id_carrier_rule` = '.(int) $id_carrier_rule;

        return Db::getInstance()->execute($sql);
    }

    /**
     * @param $idCarrierRule
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function _updateSundayStatus($idCarrierRule)
    {
        if (!$this->_isCarrierRuleExists($idCarrierRule)) {
            return false;
        }

        $select = 'SELECT delivery_sunday FROM `'._DB_PREFIX_.'dateofdelivery_carrier_rule`
						WHERE `id_carrier_rule` = '.(int) $idCarrierRule;
        $oldValue = (bool) Db::getInstance()->getValue($select);
        $sql = 'UPDATE `'._DB_PREFIX_.'dateofdelivery_carrier_rule` SET
						`delivery_sunday` = '.(int) !$oldValue.'
						WHERE `id_carrier_rule` = '.(int) $idCarrierRule;

        return Db::getInstance()->execute($sql);
    }

    /**
     * @param int    $id_carrier
     * @param bool   $productOos
     * @param string $date
     *
     * @return array|bool returns the min & max delivery date
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function _getDatesOfDelivery($id_carrier, $productOos = false, $date = null)
    {
        if (!(int) $id_carrier) {
            return false;
        }
        $carrierRule = $this->_getCarrierRuleWithIdCarrier((int) $id_carrier);
        if (empty($carrierRule)) {
            return false;
        }

        if ($date != null && Validate::isDate($date) && strtotime($date) > time()) {
            $dateNow = strtotime($date);
        } else {
            $dateNow = time();
        } // Date on timestamp format
        if ($productOos) {
            $dateNow += Configuration::get('DOD_EXTRA_TIME_PRODUCT_OOS') * 24 * 3600;
        }
        if (!Configuration::get('DOD_PREPARATION_SATURDAY') && date('l', $dateNow) == 'Saturday') {
            $dateNow += 24 * 3600;
        }
        if (!Configuration::get('DOD_PREPARATION_SUNDAY') && date('l', $dateNow) == 'Sunday') {
            $dateNow += 24 * 3600;
        }

        $dateMinimalTime = $dateNow + ($carrierRule['minimal_time'] * 24 * 3600) + (Configuration::get('DOD_EXTRA_TIME_PREPARATION') * 24 * 3600);
        $dateMaximalTime = $dateNow + ($carrierRule['maximal_time'] * 24 * 3600) + (Configuration::get('DOD_EXTRA_TIME_PREPARATION') * 24 * 3600);

        if (!$carrierRule['delivery_saturday'] && date('l', $dateMinimalTime) == 'Saturday') {
            $dateMinimalTime += 24 * 3600;
            $dateMaximalTime += 24 * 3600;
        }
        if (!$carrierRule['delivery_saturday'] && date('l', $dateMaximalTime) == 'Saturday') {
            $dateMaximalTime += 24 * 3600;
        }

        if (!$carrierRule['delivery_sunday'] && date('l', $dateMinimalTime) == 'Sunday') {
            $dateMinimalTime += 24 * 3600;
            $dateMaximalTime += 24 * 3600;
        }
        if (!$carrierRule['delivery_sunday'] && date('l', $dateMaximalTime) == 'Sunday') {
            $dateMaximalTime += 24 * 3600;
        }

        /*

        // Do not remove this commentary, it's usefull to allow translations of months and days in the translator tool

        $this->l('Sunday');
        $this->l('Monday');
        $this->l('Tuesday');
        $this->l('Wednesday');
        $this->l('Thursday');
        $this->l('Friday');
        $this->l('Saturday');

        $this->l('January');
        $this->l('February');
        $this->l('March');
        $this->l('April');
        $this->l('May');
        $this->l('June');
        $this->l('July');
        $this->l('August');
        $this->l('September');
        $this->l('October');
        $this->l('November');
        $this->l('December');
        */

        $dateMinimalString = '';
        $dateMaximalString = '';
        $dateFormat = preg_split('/([a-z])/Ui', Configuration::get('DOD_DATE_FORMAT'), null, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($dateFormat as $elmt) {
            if ($elmt == 'l' || $elmt == 'F') {
                $dateMinimalString .= $this->l(date($elmt, $dateMinimalTime));
                $dateMaximalString .= $this->l(date($elmt, $dateMaximalTime));
            } elseif (preg_match('/[a-z]/Ui', $elmt)) {
                $dateMinimalString .= date($elmt, $dateMinimalTime);
                $dateMaximalString .= date($elmt, $dateMaximalTime);
            } else {
                $dateMinimalString .= $elmt;
                $dateMaximalString .= $elmt;
            }
        }

        return [
            [
                $dateMinimalString,
                $dateMinimalTime,
            ],
            [
                $dateMaximalString,
                $dateMaximalTime,
            ],
        ];
    }

    /**
     * @return string
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function renderForm()
    {
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Settings', [], 'Admin.Global'),
                    'icon'  => 'icon-cogs',
                ],
                'input'  => [
                    [
                        'type'   => 'text',
                        'label'  => $this->trans('Extra time when a product is out of stock', [], 'Modules.Dateofdelivery.Admin'),
                        'name'   => 'extra_time_product_oos',
                        'suffix' => $this->trans('day(s)', [], 'Modules.Dateofdelivery.Admin'),
                    ],
                    [
                        'type'   => 'text',
                        'label'  => $this->trans('Extra time for preparation of the order', [], 'Modules.Dateofdelivery.Admin'),
                        'name'   => 'extra_time_preparation',
                        'suffix' => $this->trans('day(s)', [], 'Modules.Dateofdelivery.Admin'),
                    ],
                    [
                        'type'   => 'checkbox',
                        'label'  => $this->trans('Preparation option', [], 'Modules.Dateofdelivery.Admin'),
                        'name'   => 'preparation_day',
                        'values' => [
                            'id'    => 'id',
                            'name'  => 'name',
                            'query' => [
                                [
                                    'id'   => 'preparation_saturday',
                                    'name' => $this->trans('Saturday preparation', [], 'Modules.Dateofdelivery.Admin'),
                                    'val'  => 1,
                                ],
                                [
                                    'id'   => 'preparation_sunday',
                                    'name' => $this->trans('Sunday preparation', [], 'Modules.Dateofdelivery.Admin'),
                                    'val'  => 1,
                                ],
                            ],
                        ],
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->trans('Date format:', [], 'Modules.Dateofdelivery.Admin'),
                        'name'  => 'date_format',
                        'desc'  => $this->trans('You can see all parameters available at: %site%',
                            [
                                '%site%' => '<a href="http://www.php.net/manual/en/function.date.php">http://www.php.net/manual/en/function.date.php</a>',
                            ], 'Modules.Dateofdelivery.Admin'),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMoreOptions';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages'    => $this->context->controller->getLanguages(),
            'id_language'  => $this->context->language->id,
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    /**
     * @return string
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function renderAddForm()
    {
        $carriers = Carrier::getCarriers($this->context->language->id, true, false, false, null, Carrier::ALL_CARRIERS);

        foreach ($carriers as $key => $val) {
            $carriers[$key]['name'] = (!$val['name'] ? Configuration::get('PS_SHOP_NAME') : $val['name']);
        }

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Settings', [], 'Admin.Global'),
                    'icon'  => 'icon-cogs',
                ],
                'input'  => [
                    [
                        'type'    => 'select',
                        'label'   => $this->trans('Carrier :', [], 'Modules.Dateofdelivery.Admin'),
                        'name'    => 'id_carrier',
                        'options' => [
                            'query' => $carriers,
                            'id'    => 'id_carrier',
                            'name'  => 'name',
                        ],
                    ],
                    [
                        'type'   => 'text',
                        'label'  => $this->trans('Delivery between', [], 'Modules.Dateofdelivery.Admin'),
                        'name'   => 'minimal_time',
                        'suffix' => $this->trans('day(s)', [], 'Modules.Dateofdelivery.Admin'),
                    ],
                    [
                        'type'   => 'text',
                        'label'  => '',
                        'name'   => 'maximal_time',
                        'suffix' => $this->trans('day(s)', [], 'Modules.Dateofdelivery.Admin'),
                    ],
                    [
                        'type'   => 'checkbox',
                        'label'  => $this->trans('Delivery option', [], 'Modules.Dateofdelivery.Admin'),
                        'name'   => 'preparation_day',
                        'values' => [
                            'id'    => 'id',
                            'name'  => 'name',
                            'query' => [
                                [
                                    'id'   => 'delivery_saturday',
                                    'name' => $this->trans('Delivery on Saturday', [], 'Modules.Dateofdelivery.Admin'),
                                    'val'  => 1,
                                ],
                                [
                                    'id'   => 'delivery_sunday',
                                    'name' => $this->trans('Delivery on Sunday', [], 'Modules.Dateofdelivery.Admin'),
                                    'val'  => 1,
                                ],
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                    'class' => 'btn btn-default pull-right',
                    'name'  => 'submitCarrierRule',
                ],
            ],
        ];

        if (Tools::getValue('id_carrier_rule') && $this->_isCarrierRuleExists(Tools::getValue('id_carrier_rule'))) {
            $fieldsForm['form']['input'][] = ['type' => 'hidden', 'name' => 'id_carrier_rule'];
        }

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];

        $helper->identifier = $this->identifier;

        if (Tools::getValue('id_carrier_rule')) {
            $helper->submit_action = 'updatedateofdelivery';
        } else {
            $helper->submit_action = 'addCarrierRule';
        }
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getCarrierRuleFieldsValues(),
            'languages'    => $this->context->controller->getLanguages(),
            'id_language'  => $this->context->language->id,
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    /**
     * @return array
     */
    public function getConfigFieldsValues()
    {
        return [
            'extra_time_product_oos'               => Tools::getValue('extra_time_product_oos', Configuration::get('DOD_EXTRA_TIME_PRODUCT_OOS')),
            'extra_time_preparation'               => Tools::getValue('extra_time_preparation', Configuration::get('DOD_EXTRA_TIME_PREPARATION')),
            'preparation_day_preparation_saturday' => Tools::getValue('preparation_day_preparation_saturday', Configuration::get('DOD_PREPARATION_SATURDAY')),
            'preparation_day_preparation_sunday'   => Tools::getValue('preparation_day_preparation_sunday', Configuration::get('DOD_PREPARATION_SUNDAY')),
            'date_format'                          => Tools::getValue('date_format', Configuration::get('DOD_DATE_FORMAT')),
            'id_carrier'                           => Tools::getValue('id_carrier'),
        ];
    }

    /**
     * @return array
     *
     * @throws PrestaShopException
     */
    public function getCarrierRuleFieldsValues()
    {
        $fields = [
            'id_carrier_rule'   => Tools::getValue('id_carrier_rule'),
            'id_carrier'        => Tools::getValue('id_carrier'),
            'minimal_time'      => Tools::getValue('minimal_time'),
            'maximal_time'      => Tools::getValue('maximal_time'),
            'delivery_saturday' => Tools::getValue('delivery_saturday'),
            'delivery_sunday'   => Tools::getValue('delivery_sunday'),
        ];

        if (Tools::isSubmit('updatedateofdelivery') && $this->_isCarrierRuleExists(Tools::getValue('id_carrier_rule'))) {
            $carrierRule = $this->_getCarrierRule(Tools::getValue('id_carrier_rule'));

            $fields['id_carrier_rule'] = Tools::getValue('id_carrier_rule', $carrierRule['id_carrier_rule']);
            $fields['id_carrier'] = Tools::getValue('id_carrier', $carrierRule['id_carrier']);
            $fields['minimal_time'] = Tools::getValue('minimal_time', $carrierRule['minimal_time']);
            $fields['maximal_time'] = Tools::getValue('maximal_time', $carrierRule['maximal_time']);
            $fields['preparation_day_delivery_saturday'] = Tools::getValue('preparation_day_delivery_saturday', $carrierRule['delivery_saturday']);
            $fields['preparation_day_delivery_sunday'] = Tools::getValue('preparation_day_delivery_sunday', $carrierRule['delivery_sunday']);
        }

        return $fields;
    }

    /**
     * @return string
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function renderList()
    {
        $addUrl = $this->context->link->getAdminLink('AdminModules').'&configure='.$this->name.'&addCarrierRule=1';

        $fields_list = [
            'name'              => [
                'title' => $this->trans('Name of carrier', [], 'Modules.Dateofdelivery.Admin'),
                'type'  => 'text',
            ],
            'delivery_between'  => [
                'title' => $this->trans('Delivery between', [], 'Modules.Dateofdelivery.Admin'),
                'type'  => 'text',
            ],
            'delivery_saturday' => [
                'title'  => $this->trans('Saturday delivery', [], 'Modules.Dateofdelivery.Admin'),
                'type'   => 'bool',
                'align'  => 'center',
                'active' => 'saturdaystatus',
            ],
            'delivery_sunday'   => [
                'title'  => $this->trans('Sunday delivery', [], 'Modules.Dateofdelivery.Admin'),
                'type'   => 'bool',
                'align'  => 'center',
                'active' => 'sundaystatus',
            ],
        ];
        $list = $this->_getCarrierRulesWithCarrierName();

        foreach ($list as $key => $val) {
            if (!$val['name']) {
                $list[$key]['name'] = Configuration::get('PS_SHOP_NAME');
            }
            $list[$key]['delivery_between'] = sprintf($this->trans('%1$d day(s) and %2$d day(s)', [], 'Modules.Dateofdelivery.Admin'), $val['minimal_time'], $val['maximal_time']);
        }

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = true;
        $helper->identifier = 'id_carrier_rule';
        $helper->actions = ['edit', 'delete'];
        $helper->show_toolbar = false;

        $helper->title = $this->trans('Link list', [], 'Modules.Dateofdelivery.Admin');
        $helper->table = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        $this->context->smarty->assign(['add_url' => $addUrl]);

        return $this->fetch("module:{$this->name}/views/templates/hook/button.tpl")
            .$helper->generateList($list, $fields_list)
            .$this->fetch("module:{$this->name}/views/templates/hook/button.tpl");
    }
}
