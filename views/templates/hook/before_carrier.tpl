{*
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
*}
<p id="dateofdelivery">
{if !empty($datesDelivery)}
    {l s='Approximate date of delivery with this carrier is between' d='Modules.Dateofdelivery.Shop'}
    <span id="dateofdelivery_min" data-id-carrier="{$idCarrier}" data-date="{$datesDelivery.from}">{$datesDelivery.from|date_format:'l, d F Y'}</span> {l s='and' d='Modules.Dateofdelivery.Shop'} <span id="dateofdelivery_max" data-id-carrier="" data-date="{$datesDelivery.to}">{$datesDelivery.to|date_format:'l, d F Y'}</span> <sup>*</sup>
    <br/>
    <span style="font-size:10px;margin:0;padding:0;"><sup>*</sup> {l s='with direct payment methods (e.g. credit card)' d='Modules.Dateofdelivery.Shop'}</span>
{/if}
</p>

{if empty($dateofdeliveryAjax)}
  <script type="text/javascript">
    (function initDateofdeliveryModuleBeforeCarrier() {
      if (typeof $ === 'undefined' || typeof prestashop === 'undefined') {
        return setTimeout(function () {
          initDateofdeliveryModuleBeforeCarrier.apply(null, arguments);
        }, 100);
      }

      function refreshBeforeCarrier() {
        $('#dateofdelivery').html('');
        $.get('{url entity='module' name='dateofdelivery' controller='ajax'}', function (data) {
          if (data && data.success) {
            $('#dateofdelivery').html(data.html);
          }
        });
      }

      prestashop.on('changedCheckoutStep', refreshBeforeCarrier);
      prestashop.on('updatedDeliveryForm', refreshBeforeCarrier);
    }());
  </script>
{/if}
