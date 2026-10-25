<?php
/* Copyright (C) 2026		Edgar Bustamante	<ebustamantep@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/posplus/class/actions_posplus.class.php
 * \ingroup posplus
 * \brief   Hooks for TakePOS: cash close button and (optionally) showing the price
 *          in the customer currency under the main price of each product in the div5.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Class ActionsPosplus
 */
class ActionsPosplus extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var mixed[] Hook results.
	 */
	public $results = array();

	/**
	 * @var ?string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int Priority of hook (50 if not defined)
	 */
	public $priority = 50;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Return the conversion rate for the customer currency selected in the POS,
	 * or 0 if the feature must not be enabled.
	 *
	 * @return float The conversion rate, or 0 if disabled
	 */
	protected function getPosCurrencyRate()
	{
		global $conf;

		$sessioncurrency = (!empty($_SESSION["takeposcustomercurrency"]) ? $_SESSION["takeposcustomercurrency"] : '');
		if (!isModEnabled('multicurrency') || $sessioncurrency == '' || $sessioncurrency == $conf->currency) {
			return 0;
		}

		include_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';
		$multicurrency = new MultiCurrency($this->db);
		$multicurrency->fetch(0, $sessioncurrency);
		if (!isset($multicurrency->rate->rate) || (float) $multicurrency->rate->rate <= 0) {
			return 0;
		}

		return (float) $multicurrency->rate->rate;
	}

	/**
	 * actionButtons. Called on the TakePOS frontend to add buttons to the action bar.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param CommonObject        $object    The object
	 * @param string              $action    Current action (if set)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success (0 = add buttons, 1 = replace)
	 */
	public function actionButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$langs->load('posplus@posplus');

		if (getDolGlobalInt('POSPLUS_CASHCOUNT')) {
			// Cash count by shift (opening, cash in/out, report X / Z). It replaces the native
			// cash control of TakePOS, so its buttons ("Cash report", "Close the cash control")
			// are removed from the bar: the hook returns 1 (replace) with the native buttons
			// minus those two, followed by the POSPlus ones.
			$menus = array();
			if (!empty($parameters['menus']) && is_array($parameters['menus'])) {
				foreach ($parameters['menus'] as $menu) {
					$menuaction = (isset($menu['action']) ? (string) $menu['action'] : '');
					if (strpos($menuaction, 'CloseCashFence(') === 0 || strpos($menuaction, 'CashReport(') === 0) {
						continue;
					}
					$menus[] = $menu;
				}
			}
			$menus[] = array(
				'title' => '<span class="fa fa-cash-register paddingrightonly"></span><div class="trunc">'.$langs->trans("PosplusCashCount").'</div>',
				'action' => "PosplusCashSession('');",
			);
			$menus[] = array(
				'title' => '<span class="fa fa-exchange-alt paddingrightonly"></span><div class="trunc">'.$langs->trans("PosplusCashMovementTitle").'</div>',
				'action' => "PosplusCashSession('movement');",
			);
			$this->results = array($menus);
			return 1;
		} else {
			$this->results = array(
				array(
					array(
						'title' => '<span class="fa fa-money-bill-wave paddingrightonly"></span><div class="trunc">'.$langs->trans("PosplusCashClose").'</div>',
						'action' => 'PosplusCashClose();',
					),
				),
			);
		}

		return 0;
	}

	/**
	 * addHtmlHeader. Called into <head> by top_htmlhead (context "main"). We inject
	 * the JS needed by the cash close button and, if applicable, the multicurrency
	 * price display. Only when the page is the TakePOS frontend.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param CommonObject        $object    The object
	 * @param string              $action    Current action (if set)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success
	 */
	public function addHtmlHeader($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;

		$this->resprints = '';

		// Only for the TakePOS frontend page.
		$script = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
		if (strpos($script, '/takepos/index.php') === false) {
			return 0;
		}

		$langs->load('posplus@posplus');
		dol_include_once('/posplus/lib/posplus.lib.php');

		$today = posplus_print_date(dol_now(), '%Y-%m-%d'); // date of the operator (not of the hosting)
		$moduleurl = dol_buildpath('/posplus/cashclose.php', 1);
		$payurl = dol_buildpath('/posplus/payplus.php', 1);

		// Cash count by shift: the opening of POSPlus replaces the native one of TakePOS
		// (single currency) and opens by itself when the terminal has no open shift.
		$cashsessionjs = '';
		$terminal = posplus_get_terminal();
		if (getDolGlobalInt('POSPLUS_CASHCOUNT') && $terminal !== '') {
			dol_include_once('/posplus/class/posplussession.class.php');
			$tmpsession = new PosplusCashSession($this->db);
			$needopen = ($tmpsession->fetchOpen($terminal) == 0 && !posplus_get_closed_cashcontrol($this->db, $terminal));
			$cashsessionjs = '$(document).ready(function() {
	window.ControlCashOpening = function() { PosplusCashSession(""); };
	'.($needopen ? 'PosplusCashSession("");' : '').'
});
';
		}

		$this->resprints = '<style>
.productprice-sub {
	font-size: 1em;
	font-weight: normal;
	opacity: 0.9;
	text-align: right;
	padding-top: 1px;
	white-space: nowrap;
}
</style>
<script>
var posplusCashCloseUrl = "'.dol_escape_js($moduleurl).'";
var posplusCashCloseToken = "'.dol_escape_js(currentToken()).'";
var posplusCashCloseToday = "'.dol_escape_js($today).'";

function PosplusCashClose() {
	$.colorbox({href: posplusCashCloseUrl + "?token=" + posplusCashCloseToken + "&date=" + posplusCashCloseToday, width: "80%", height: "80%", transition: "none", iframe: "true", title: "'.dol_escape_js($langs->trans("PosplusCaisseClose")).'"});
}

// Cash count by shift (POSPlus): opening, cash in/out, report X / Z.
var posplusCashSessionUrl = "'.dol_escape_js(dol_buildpath('/posplus/cashsession.php', 1)).'";
function PosplusCashSession(action) {
	$.colorbox({href: posplusCashSessionUrl + (action ? "?action=" + encodeURIComponent(action) + "&token=" + encodeURIComponent(posplusCashCloseToken) : ""), width: "90%", height: "90%", transition: "none", iframe: "true", title: "'.dol_escape_js($langs->trans("PosplusCashCount")).'"});
}

// Replacement of the native "Payment" button by the POSPlus "Pagar" popup.
var posplusPayUrl = "'.dol_escape_js($payurl).'";
var posplusPayToken = "'.dol_escape_js(currentToken()).'";
var posplusPayTitle = "'.dol_escape_js($langs->trans("PosplusPayButton")).'";

// Called by the payplus popup once the invoice is fully paid (the payment is registered by
// POSPlus, so cash boxes of any currency work). Shows the paid invoice through the core
// (invoice link, print buttons, EasyBack return button) and prints the ticket when
// TAKEPOS_AUTO_PRINT_TICKETS is on, as takepos/invoice.php action=valid does.
var posplusInvoiceUrl = "'.dol_escape_js(DOL_URL_ROOT.'/takepos/invoice.php').'";
var posplusAutoPrint = '.(getDolGlobalString('TAKEPOS_AUTO_PRINT_TICKETS') ? 'true' : 'false').';
function PosplusAfterPayment(invoiceid) {
	$.colorbox.close();
	$("#poslines").load(posplusInvoiceUrl + "?action=history&placeid=" + encodeURIComponent(invoiceid) + "&place=" + encodeURIComponent(place) + "&token=" + encodeURIComponent(posplusPayToken), function() {
		$("#invoiceid").val("");
		if (posplusAutoPrint) {
			$("#buttonprint").first().click();
		}
	});
}

function PosplusPayPlus() {
	var invoiceid = $("#invoiceid").val();
	$.colorbox({href: posplusPayUrl + "?place=" + place + "&invoiceid=" + invoiceid + "&token=" + posplusPayToken, width: "80%", height: "90%", transition: "none", iframe: "true", title: posplusPayTitle});
}

'.$cashsessionjs.'
$(document).ready(function() {
	// The native button has onclick="CloseBill();". We point it to our popup, relabel it
	// and style it green so the operator easily spots the payment action.
	var btn = $("button[onclick*=\"CloseBill\"]");
	if (btn.length) {
		btn.attr("onclick", "PosplusPayPlus();");
		btn.html("<span class=\"far fa-money-bill-alt paddingrightonly\"></span><div class=\"trunc\">" + posplusPayTitle + "</div>");
		btn.css("background-color", "#28a745");
		btn.css("color", "#ffffff");
		btn.css("border-color", "#1e7e34");
	}
});
</script>';

		// Multicurrency price display (optional)
		$rate = $this->getPosCurrencyRate();
		if ($rate > 0) {
			$sessioncurrency = $_SESSION["takeposcustomercurrency"];
			$symbol = dol_escape_js($langs->getCurrencySymbol($sessioncurrency));

			$this->resprints .= '<script>
var posplusMulticurrencyRate = '.((float) $rate).';
var posplusMulticurrencyCode = "'.dol_escape_js($sessioncurrency).'";
var posplusMulticurrencySymbol = "'.$symbol.'";

function posplusFormatCurrency(amount) {
	if (isNaN(amount)) { return ""; }

	var decCount = 2;
	var nb = Number(amount).toFixed(decCount);
	var parts = nb.split(".");
	var intPart = parts[0];
	var decPart = parts.length > 1 ? parts[1] : "";

	// Thousands separator: dot.
	intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ".");

	// Decimal separator: comma.
	var out = intPart + (decPart ? "," + decPart : "");
	var sym = posplusMulticurrencySymbol || posplusMulticurrencyCode;
	return out + " " + sym;
}

function posplusAppendSecondaryPrice(containerId, price) {
	var el = document.getElementById(containerId);
	if (!el) { return; }

	// Avoid duplicates if the element is filled several times (e.g. re-render).
	if (el.querySelector(".productprice-sub")) { return; }

	var converted = price * posplusMulticurrencyRate;
	var sub = document.createElement("div");
	sub.className = "productprice-sub";
	sub.textContent = posplusFormatCurrency(converted);
	el.appendChild(sub);
}
</script>';
		}

		// Show/hide the invoice discount button, enable/disable the modify price and line
		// discount buttons, and hide the split-sale and free-zone buttons.
		// DESCUENTO_FACTURA = 1 -> show the invoice discount button, 0 -> hide it.
		// MODIFICAR_PRECIO = 1 -> price modifiable, 0 -> show it but disabled.
		// DESCUENTO_LINEA = 1 -> line discount enabled, 0 -> show it but disabled.
		// VENTA_DIVIDIDA = 1 -> show split sale button, 0 -> hide it.
		// PRODUCTO_LIBRE = 1 -> show free zone button, 0 -> hide it.
		$showdiscount = getDolGlobalInt('DESCUENTO_FACTURA', 0);
		$enableprice = getDolGlobalInt('MODIFICAR_PRECIO', 0);
		$enablelinediscount = getDolGlobalInt('DESCUENTO_LINEA', 0);
		$showsplit = getDolGlobalInt('VENTA_DIVIDIDA', 0);
		$showfreezone = getDolGlobalInt('PRODUCTO_LIBRE', 0);

		$this->resprints .= '<script>
$(document).ready(function() {
	// Invoice discount button (added by core in the action menu with Reduction()).
	var discountAfter = '.(($showdiscount) ? 'true' : 'false').';
	if (!discountAfter) {
		$("button[onclick*=\"Reduction\"]").hide();
	}

	function disableBtn(sel, enabled) {
		var btn = $(sel);
		if (btn.length) {
			if (enabled) {
				btn.prop("disabled", false);
				btn.css("opacity", "");
			} else {
				btn.prop("disabled", true);
				btn.css("opacity", "0.4");
			}
		}
	}

	// Modify price button (id="price" in the numpad).
	disableBtn("#price", '.(($enableprice) ? 'true' : 'false').');

	// Line discount button (id="reduction" in the numpad).
	disableBtn("#reduction", '.(($enablelinediscount) ? 'true' : 'false').');

	// Split sale button (core action Split()).
	var splitAfter = '.(($showsplit) ? 'true' : 'false').';
	if (!splitAfter) {
		$("button[onclick*=\"Split()\"]").hide();
	}

	// Free zone (free product) button (core action FreeZone()).
	var freezoneAfter = '.(($showfreezone) ? 'true' : 'false').';
	if (!freezoneAfter) {
		$("button[onclick*=\"FreeZone()\"]").hide();
	}
});
</script>';

		return 0;
	}

	/**
	 * completeJSProductDisplay. Called for each product displayed in the div5
	 * (context "takeposfrontend"). $this->resprints is output as raw JS inside the
	 * product display loop, right after the main price is rendered. We append the
	 * price converted to the customer currency.
	 *
	 * @param array<string,mixed> $parameters Hook metadata ('caller' is 'loadProducts' or 'search2')
	 * @param CommonObject        $object    The object
	 * @param string              $action    Current action (if set)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success
	 */
	public function completeJSProductDisplay($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;

		$this->resprints = '';

		if (!isset($parameters['caller'])) {
			return 0;
		}

		$rate = $this->getPosCurrencyRate();
		if ($rate <= 0) {
			return 0;
		}

		// In category loading the div index is 'ishow' and the data index is 'idata';
		// in search both are 'i'. We reuse them so the hook works in both cases.
		$divIndex = ($parameters['caller'] == 'search2') ? 'i' : 'ishow';
		$dataIndex = ($parameters['caller'] == 'search2') ? 'i' : 'idata';

		$priceField = (getDolGlobalInt('TAKEPOS_CHANGE_PRICE_HT') ? 'price' : 'price_ttc');

		$this->resprints = '
		if (typeof '.$dataIndex.' !== "undefined" && data['.$dataIndex.'] && data['.$dataIndex.']["'.$priceField.'"] !== undefined) {
			posplusAppendSecondaryPrice("proprice"+'.$divIndex.', parseFloat(data['.$dataIndex.']["'.$priceField.'"]));
		}';

		return 0;
	}

	/**
	 * completeTakePosInvoiceHeader. Called on the TakePOS invoice header (context
	 * "takeposinvoice"). We rewrite the customer block (#customerandsales) so it
	 * shows the full customer name and, on a second line, the intracommunity VAT
	 * number, without touching the core file.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param CommonObject        $object    The invoice object
	 * @param string              $action    Current action (if set)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success
	 */
	public function completeTakePosInvoiceHeader($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $conf, $user;

		$this->resprints = '';

		if (!is_object($object) || empty($object->element) || $object->element != 'facture') {
			return 0;
		}

		// Load the module translation file so the intra label is translated.
		$langs->load('posplus@posplus');

		// Only if a real customer is set (not the generic POS thirdparty).
		$constforcompanyid = 'CASHDESK_ID_THIRDPARTY'.(empty($_SESSION['takeposterminal']) ? '1' : $_SESSION['takeposterminal']);
		$genericid = getDolGlobalInt($constforcompanyid);
		if (empty($object->socid) || (int) $object->socid == (int) $genericid) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($db);
		if ($soc->fetch($object->socid) <= 0) {
			return 0;
		}

		$name = $soc->name;
		$intra = trim($soc->tva_intra);
		// Native translation for the local tax id (CI/RIF in es_VE). The key is the native
		// ProfId1ES from the es_VE companies.lang file (do not add a new custom key).
		$intralabel = $langs->transnoentities('ProfId1ES');

		// The customer data is user input: it is passed as JSON safe inside <script> (HEX
		// flags) and inserted with .text(), never as HTML.
		$jsonflags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		$this->resprints = '<script>
	$(document).ready(function() {
		var cs = $("#customerandsales");
		if (!cs.length) { return; }
		var pname = '.json_encode((string) $name, $jsonflags).';
		var pintra = '.json_encode((string) $intra, $jsonflags).';
		var pintralabel = '.json_encode((string) $intralabel, $jsonflags).';
		var link = $(\'<a class="valignmiddle" id="customer" onclick="Customer();" style="line-height:1.15; display:inline-block;"></a>\');
		var body = $("<span></span>").append($("<b></b>").text(pname));
		if (pintra) {
			body.append("<br>").append($(\'<span style="font-size:0.8em; opacity:0.85;"></span>\').text(pintralabel + ": " + pintra));
		}
		link.append(\'<span class="fas fa-building paddingrightonly"></span>\').append(body);
		cs.empty().append(link);
	});
	</script>';

		return 0;
	}

	/**
	 * doActions. Called by takepos/invoice.php (context "takeposinvoice") before any
	 * action. Enforces on the SERVER the TakePOS buttons disabled in the POSPlus setup:
	 * hiding them in the browser is not enough, the URL can still be called.
	 *
	 * @param array<string,mixed> $parameters  Hook metadata
	 * @param CommonObject        $object      The invoice
	 * @param string              $action      Current action (set to '' when refused)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$contexts = explode(':', isset($parameters['context']) ? $parameters['context'] : '');
		if (!in_array('takeposinvoice', $contexts) || empty($action)) {
			return 0;
		}

		$refused = false;
		if ($action == 'updateprice' && !getDolGlobalInt('MODIFICAR_PRECIO')) {
			$refused = true;
		} elseif ($action == 'updatereduction' && !getDolGlobalInt('DESCUENTO_LINEA')) {
			$refused = true;
		} elseif ($action == 'update_reduction_global' && !getDolGlobalInt('DESCUENTO_FACTURA')) {
			$refused = true;
		} elseif ($action == 'freezone') {
			// The invoice discount "by amount" (takepos/reduction.php) is a free zone line
			// with a negative amount: it depends on the invoice discount setting.
			if ((float) GETPOST('number', 'alpha') < 0) {
				$refused = !getDolGlobalInt('DESCUENTO_FACTURA');
			} else {
				$refused = !getDolGlobalInt('PRODUCTO_LIBRE');
			}
		}

		if ($refused) {
			$langs->load('posplus@posplus');
			dol_syslog('posplus: action '.$action.' refused by POSPlus setup', LOG_WARNING);
			dol_htmloutput_errors($langs->trans('PosplusErrorActionDisabled'), array(), 1);
			$action = '';
		}

		return 0;
	}

	/**
	 * TakeposReceipt. Called on the TakePOS receipt page (context "takeposfrontend",
	 * hook TakeposReceipt). The ticket is always rendered here, with the layout of a
	 * fiscal receipt for an 80 mm printer:
	 *  - header with the company tax id, name and address; customer (tax id, name, town,
	 *    phone), operator, cash desk and sale condition (cash / credit);
	 *  - invoice number, date and hour (hour of the operator, not of the hosting);
	 *  - lines "qty x unit price" + description with the tax mark (E)/(G)/(R) and the
	 *    amount without tax; subtotal, tax breakdown (exempt, base and tax per rate),
	 *    payments in the currency of the box/account where they went, and total;
	 *  - all the data coming from users is escaped.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param CommonObject        $object    The invoice object
	 * @param string              $action    Current action (if set)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success
	 */
	public function TakeposReceipt($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $conf, $mysoc, $nojs, $user;

		$this->resprints = '';

		if (!is_object($object) || empty($object->element) || $object->element != 'facture') {
			return 0;
		}

		dol_include_once('/posplus/lib/posplus.lib.php');
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$langs->loadLangs(array("main", "bills", "cashdesk", "companies", "posplus@posplus"));

		$isspecimen = (GETPOST('specimen') || !empty($object->specimen) || empty($object->id));
		$facid = ($isspecimen ? 0 : (int) $object->id);
		$gift = GETPOSTINT('gift');
		$showamounts = ($gift != 1);
		$terminal = (empty($_SESSION['takeposterminal']) ? '' : $_SESSION['takeposterminal']);

		// Currency of the amounts: the invoice currency when it is a foreign one (e.g. Bs),
		// otherwise the main currency.
		$cur = $conf->currency;
		$usemc = (isModEnabled('multicurrency') && !empty($object->multicurrency_code) && $object->multicurrency_code != $conf->currency && (float) $object->multicurrency_tx > 0);
		if ($usemc) {
			$cur = $object->multicurrency_code;
		}
		$amt = function ($main, $foreign) use ($usemc) {
			return ($usemc ? (float) $foreign : (float) $main);
		};
		$money = function ($amount) use ($cur) {
			return posplus_ticket_amount($amount, $cur);
		};
		// Row "label ........ value"
		$row = function ($left, $right = '', $class = '') {
			return '<tr'.($class ? ' class="'.$class.'"' : '').'><td class="l">'.$left.'</td><td class="r">'.$right.'</td></tr>';
		};

		$out = '<style>
.tk { font-family: "Courier New", Courier, monospace; font-size: 12px; line-height: 1.3; color: #000; max-width: 76mm; margin: 0 auto; }
.tk .c { text-align: center; }
.tk table { width: 100%; border-collapse: collapse; }
.tk td { padding: 0; vertical-align: top; }
.tk td.l { text-align: left; }
.tk td.r { text-align: right; white-space: nowrap; padding-left: 6px; }
.tk tr.sep td, .tk .sep { border-top: 1px dashed #000; padding-top: 2px; }
.tk tr.total td { font-weight: bold; font-size: 1.15em; }
.tk .title { font-weight: bold; margin-top: 4px; }
.tk .small { font-size: 0.9em; }
.tk .upper { text-transform: uppercase; }
</style>';
		$out .= '<div class="tk">';

		// ---- Company header ----
		$companyid = ($mysoc->tva_intra ? $mysoc->tva_intra : $mysoc->idprof1);
		$out .= '<div class="c"><br>';
		if ($companyid) {
			$out .= dol_escape_htmltag($companyid).'<br>';
		}
		$out .= '<b class="upper">'.dol_escape_htmltag($mysoc->name).'</b><br>';
		if ($mysoc->address) {
			$out .= nl2br(dol_escape_htmltag($mysoc->address)).'<br>';
		}
		$townstate = trim($mysoc->town.($mysoc->state ? ' - '.$mysoc->state : ''), ' -');
		if ($townstate) {
			$out .= dol_escape_htmltag($townstate).'<br>';
		}
		if ($isspecimen) {
			$out .= '!!! SPECIMEN !!!<br>';
		}
		$out .= '</div>';

		// Header free text (setup of the admin, HTML allowed as in the core)
		$constFreeText = 'TAKEPOS_HEADER'.(empty($terminal) ? '0' : $terminal);
		if (getDolGlobalString('TAKEPOS_HEADER') || getDolGlobalString($constFreeText)) {
			$newfreetext = '';
			$substitutionarray = getCommonSubstitutionArray($langs);
			complete_substitutions_array($substitutionarray, $langs, $object);
			if (getDolGlobalString('TAKEPOS_HEADER')) {
				$newfreetext .= make_substitutions(getDolGlobalString('TAKEPOS_HEADER'), $substitutionarray);
			}
			if (getDolGlobalString($constFreeText)) {
				$newfreetext .= make_substitutions(getDolGlobalString($constFreeText), $substitutionarray);
			}
			$out .= '<div class="c">'.nl2br($newfreetext).'</div>';
		}

		// ---- Customer, operator, cash desk, sale condition ----
		$out .= '<div>';
		$genericid = getDolGlobalInt('CASHDESK_ID_THIRDPARTY'.$terminal);
		$soc = new Societe($db);
		if (!$isspecimen && $object->socid > 0 && $object->socid != $genericid && $soc->fetch($object->socid) > 0) {
			$socid = ($soc->tva_intra ? $soc->tva_intra : $soc->idprof1);
			if ($socid) {
				$out .= $langs->trans('PosplusTicketCustomerId').': '.dol_escape_htmltag($socid).'<br>';
			}
			$out .= $langs->trans('PosplusTicketCustomerName').': '.dol_escape_htmltag($soc->name).'<br>';
			if ($soc->town) {
				$out .= '<span class="upper">'.dol_escape_htmltag($soc->town).'</span><br>';
			}
		} else {
			$soc = null;
		}
		$operator = '';
		$authorid = (!empty($object->user_creation_id) ? (int) $object->user_creation_id : (int) $user->id);
		if ($authorid > 0) {
			$tmpuser = new User($db);
			if ($tmpuser->fetch($authorid) > 0) {
				$operator = $tmpuser->login;
			}
		}
		$phoneandop = '';
		if ($soc && $soc->phone) {
			$phoneandop .= $langs->trans('PosplusTicketPhone').': '.dol_escape_htmltag($soc->phone).' ';
		}
		if ($operator) {
			$phoneandop .= $langs->trans('PosplusTicketOperator').': '.dol_escape_htmltag($operator);
		}
		if ($phoneandop) {
			$out .= $phoneandop.'<br>';
		}
		$out .= $langs->trans('PosplusTicketCashDesk').': '.($isspecimen ? '99' : dol_escape_htmltag($object->pos_source ? $object->pos_source : 'Backoffice')).'<br>';
		$remain = ($isspecimen ? 0 : (float) price2num($object->getRemainToPay(), 'MT'));
		$out .= '<span class="upper">'.$langs->trans($remain > 0 && $object->status != $object::STATUS_DRAFT ? 'PosplusTicketCreditSale' : 'PosplusTicketCashSale').'</span>';
		$out .= '</div>';

		// ---- Document title, number, date and hour ----
		// Name of the document: the one configured in TakePOS (TAKEPOS_RECEIPT_NAME, e.g.
		// "NOTA DE DESPACHO"), printed once, with the number.
		$docname = (getDolGlobalString('TAKEPOS_RECEIPT_NAME') ? dol_escape_htmltag(getDolGlobalString('TAKEPOS_RECEIPT_NAME')) : $langs->trans('PosplusTicketInvoice'));
		$out .= '<table class="title">';
		$out .= $row('<span class="upper">'.$docname.':</span>', ($isspecimen ? '99999' : dol_escape_htmltag($object->ref)));
		// Date of the invoice: calendar day stored by the core (DATE column), printed as is.
		// Hour of the sale: time of the last payment (date_valid is a DATE column, without
		// hour), else creation time of the invoice; in the timezone of the operator.
		$datetxt = ($object->date ? dol_print_date($object->date, '%d-%m-%Y', 'tzserver') : posplus_print_date(dol_now(), '%d-%m-%Y'));
		$saletime = 0;
		if ($facid) {
			$resql = $db->query("SELECT MAX(p.datep) as datep FROM ".MAIN_DB_PREFIX."paiement_facture as pf JOIN ".MAIN_DB_PREFIX."paiement as p ON p.rowid = pf.fk_paiement WHERE pf.fk_facture = ".((int) $facid));
			if ($resql && ($obj = $db->fetch_object($resql)) && !empty($obj->datep)) {
				$saletime = $db->jdate($obj->datep);
			}
		}
		if (empty($saletime)) {
			$saletime = (!empty($object->date_creation) ? $object->date_creation : dol_now());
		}
		$hourtxt = posplus_print_date($saletime, '%I:%M %p');
		$out .= $row('<span class="upper">'.$langs->trans('PosplusTicketDate').'</span>: '.$datetxt, '<span class="upper">'.$langs->trans('PosplusTicketHour').'</span>: '.$hourtxt);
		$out .= '</table>';

		// Print counter (the core does it after the hook, so it must be done here)
		if (!$isspecimen && $object->status == $object::STATUS_CLOSED) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."facture SET pos_print_counter = pos_print_counter + 1";
			$sql .= " WHERE rowid = ".((int) $object->id);
			$db->query($sql);
			$object->pos_print_counter += 1;
		}
		if ($object->status == $object::STATUS_DRAFT) {
			$out .= '<div class="c"><b>*** '.strtoupper($langs->trans("TemporaryReceipt")).' ***</b></div>';
		} elseif ($object->pos_print_counter >= 2) {
			$out .= '<div class="c"><b>*** '.$langs->transnoentities("DUPLICATA");
			if (getDolGlobalString('TAKEPOS_SHOW_PRINT_COUNTER_ON_RECEIPT')) {
				$out .= ' (no '.((int) $object->pos_print_counter - 1).')';
			}
			$out .= ' ***</b><br>'.$langs->trans('DateOfPrinting').': '.posplus_print_date(dol_now(), '%d-%m-%Y %I:%M %p').'</div>';
		}

		// ---- Tax marks: E exempt, G general (highest rate), R reduced (other rates) ----
		$rates = array();
		foreach ($object->lines as $line) {
			if ((float) $line->tva_tx > 0) {
				$rates[(string) (float) $line->tva_tx] = (float) $line->tva_tx;
			}
		}
		arsort($rates);
		$taxmark = array('0' => 'E');
		$first = true;
		foreach ($rates as $key => $rate) {
			$taxmark[$key] = ($first ? 'G' : 'R');
			$first = false;
		}

		// ---- Lines ----
		$out .= '<table>';
		$firstline = true;
		if ($action == 'without_details') {
			$qty = GETPOSTINT('qty') > 0 ? GETPOSTINT('qty') : 1;
			$out .= $row($qty.'x '.dol_escape_htmltag(GETPOST('label', 'alphanohtml')), ($showamounts ? $money($amt($object->total_ht, $object->multicurrency_total_ht)) : ''), 'sep');
		} else {
			foreach ($object->lines as $line) {
				$desc = (!empty($line->product_label) ? $line->product_label : $line->desc);
				$desc = dol_escape_htmltag(dol_string_nohtmltag((string) $desc));
				$mark = (isset($taxmark[(string) (float) $line->tva_tx]) ? $taxmark[(string) (float) $line->tva_tx] : 'G');
				$qtyline = price2num($line->qty, 'MS').'x';
				if ($showamounts) {
					$qtyline .= ' '.$money($amt($line->subprice, $line->multicurrency_subprice));
				}
				$out .= $row($qtyline, '', ($firstline ? 'sep' : ''));
				if ($showamounts && (float) $line->remise_percent > 0) {
					$out .= $row($langs->trans('PosplusTicketDiscount').' '.price2num($line->remise_percent, 'MU').'%');
				}
				$out .= $row('<span class="upper">'.$desc.'</span> ('.$mark.')', ($showamounts ? $money($amt($line->total_ht, $line->multicurrency_total_ht)) : ''));
				$firstline = false;
			}
		}

		if ($showamounts) {
			// ---- Subtotal and tax breakdown ----
			$out .= $row('<span class="upper">'.$langs->trans('PosplusTicketSubtotal').'</span>', $money($amt($object->total_ht, $object->multicurrency_total_ht)), 'sep');
			$bases = array();
			$taxes = array();
			foreach ($object->lines as $line) {
				$key = (string) (float) $line->tva_tx;
				$bases[$key] = (isset($bases[$key]) ? $bases[$key] : 0) + $amt($line->total_ht, $line->multicurrency_total_ht);
				$taxes[$key] = (isset($taxes[$key]) ? $taxes[$key] : 0) + $amt($line->total_tva, $line->multicurrency_total_tva);
			}
			ksort($bases, SORT_NUMERIC); // Exempt first, then each rate (as a fiscal receipt)
			$firsttax = true;
			foreach ($bases as $key => $base) {
				if ((float) $key == 0) {
					$out .= $row('<span class="upper">'.$langs->trans('PosplusTicketExempt').'</span>', $money($base), ($firsttax ? 'sep' : ''));
				} else {
					$ratetxt = $taxmark[$key].number_format((float) $key, 2, ',', '.').'%';
					$out .= $row('<span class="upper">'.$langs->trans('PosplusTicketBase').'</span> '.$ratetxt, $money($base), ($firsttax ? 'sep' : ''));
					$out .= $row('<span class="upper">'.$langs->trans('PosplusTicketVat').'</span> '.$ratetxt, $money($taxes[$key]));
				}
				$firsttax = false;
			}
			if (price2num($object->total_localtax1, 'MU')) {
				$out .= $row($langs->trans('TotalLT1'), $money($amt($object->total_localtax1, $object->total_localtax1)));
			}
			if (price2num($object->total_localtax2, 'MU')) {
				$out .= $row($langs->trans('TotalLT2'), $money($amt($object->total_localtax2, $object->total_localtax2)));
			}

			// ---- Payments: each one in the currency of the box/account where it went ----
			if ($facid) {
				$sql = "SELECT p.pos_change as pos_change, pf.amount as amount, pf.multicurrency_amount, f.multicurrency_code,";
				$sql .= " cp.code, cp.libelle as label, ba.currency_code as bankcurrency";
				$sql .= " FROM ".MAIN_DB_PREFIX."paiement_facture as pf";
				$sql .= " JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = pf.fk_facture";
				$sql .= " JOIN ".MAIN_DB_PREFIX."paiement as p ON p.rowid = pf.fk_paiement";
				$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement as cp ON p.fk_paiement = cp.id";
				$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank as b ON b.rowid = p.fk_bank";
				$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = b.fk_account";
				$sql .= " WHERE pf.fk_facture = ".((int) $facid);
				$sql .= " ORDER BY p.datep, p.rowid";
				$resql = $db->query($sql);
				if ($resql) {
					$firstpay = true;
					while ($obj = $db->fetch_object($resql)) {
						$bankcurrency = (!empty($obj->bankcurrency) ? $obj->bankcurrency : $conf->currency);
						if ($bankcurrency != $conf->currency && $bankcurrency == $obj->multicurrency_code) {
							$payamount = (float) $obj->multicurrency_amount;
							$paycur = $bankcurrency;
						} else {
							$payamount = (float) $obj->amount;
							$paycur = $conf->currency;
						}
						// pos_change: change given back in the main currency (cash LIQ only)
						$haschange = ($obj->pos_change > 0 && $paycur == $conf->currency);
						if ($haschange) {
							$payamount += (float) $obj->pos_change; // Amount received
						}
						$out .= $row(dol_escape_htmltag(posplus_payment_label($obj->code, (string) $obj->label)), posplus_ticket_amount($payamount, $paycur), ($firstpay ? 'sep' : ''));
						if ($haschange) {
							$out .= $row($langs->trans('Change'), posplus_ticket_amount($obj->pos_change, $paycur));
						}
						$firstpay = false;
					}
				}
			}

			// ---- Total ----
			$out .= $row('<span class="upper">'.$langs->trans('PosplusTicketTotal').'</span>', $money($amt($object->total_ttc, $object->multicurrency_total_ttc)), 'sep total');
		}
		$out .= '</table>';

		// Footer free text (setup of the admin)
		$constFreeText = 'TAKEPOS_FOOTER'.(empty($terminal) ? '0' : $terminal);
		if (getDolGlobalString('TAKEPOS_FOOTER') || getDolGlobalString($constFreeText)) {
			$newfreetext = '';
			$substitutionarray = getCommonSubstitutionArray($langs);
			complete_substitutions_array($substitutionarray, $langs, $object);
			if (getDolGlobalString($constFreeText)) {
				$newfreetext .= make_substitutions(getDolGlobalString($constFreeText), $substitutionarray);
			}
			if (getDolGlobalString('TAKEPOS_FOOTER')) {
				$newfreetext .= make_substitutions(getDolGlobalString('TAKEPOS_FOOTER'), $substitutionarray);
			}
			$out .= '<div class="c sep">'.$newfreetext.'</div>';
		}

		$out .= '</div>';
		$out .= "<br>\n<!-- Need a margin to avoid that the print of the ticket is cut by the browser -->";

		// Auto print
		if (!GETPOST('forcenoautoopen') && !$isspecimen && empty($nojs) && $facid) {
			$out .= '<script type="text/javascript">window.print();</script>';
		}

		$out .= '</body></html>';

		$this->resprints = $out;
		return 0;
	}
}
