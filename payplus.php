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
 * \file    posplus/payplus.php
 * \ingroup posplus
 * \brief   Payment popup for TakePOS that replaces the native "Payment" button.
 *          Allows receiving payments in different currencies according to the
 *          currency of each bank account (multicurrency), with the equivalent
 *          in the main company currency.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1'); // No need to renew token (popup loaded in an iframe)
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1'); // No need to load and show top/left menu
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1'); // Do not load HTML output functions (no header/footer)
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 * @var Societe $mysoc
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';
require_once __DIR__.'/lib/posplus.lib.php';

// Translations
$langs->loadLangs(array('posplus@posplus', 'bills', 'cashdesk', 'banks'));

// Security
if (!isModEnabled('posplus')) {
	accessforbidden("Module posplus not enabled");
}
if (!$user->hasRight('takepos', 'run')) {
	accessforbidden();
}

$place = (GETPOST('place', 'aZ09') ? GETPOST('place', 'aZ09') : '0'); // $place is id of table for Bar or Restaurant
$invoiceid = GETPOSTINT('invoiceid');
$terminal = posplus_get_terminal();

$invoice = new Facture($db);
if ($invoiceid > 0) {
	$ret = $invoice->fetch($invoiceid);
} else {
	$ret = $invoice->fetch(0, '(PROV-POS'.$terminal.'-'.$place.')');
}
// Only a TakePOS invoice of this terminal and of an entity of the user (fetch by id
// does not filter on entity).
if ($ret <= 0 || !posplus_invoice_is_accessible($invoice, $terminal)) {
	$invoice = new Facture($db);
	$invoiceid = 0;
} else {
	$invoiceid = $invoice->id;
}

// If no invoice is found (e.g. empty sale), still render the payment modes so the
// modal is usable; the amounts will be 0 until an invoice is selected.
$invoicenotfound = (empty($invoice->id) || $invoice->id <= 0);
$invoicecurrency = (!$invoicenotfound && $invoice->multicurrency_code ? $invoice->multicurrency_code : $conf->currency);

// Determine the conversion rate for the customer currency (if any).
$sessioncurrency = (!empty($_SESSION["takeposcustomercurrency"]) ? $_SESSION["takeposcustomercurrency"] : '');
$multicurrencyrate = 0;
if (isModEnabled('multicurrency') && $sessioncurrency != '' && $sessioncurrency != $conf->currency) {
	$currencyrateobj = new MultiCurrency($db);
	$currencyrateobj->fetch(0, $sessioncurrency);
	if (isset($currencyrateobj->rate->rate) && (float) $currencyrateobj->rate->rate > 0) {
		$multicurrencyrate = (float) $currencyrateobj->rate->rate;
	}
}

// The invoice may carry its own multicurrency tx (foreign-per-main rate). Only used
// for display: the server converts the amounts with the rate stored on the invoice.
$invoicerate = (float) $invoice->multicurrency_tx;
if ($invoicecurrency == $conf->currency) {
	$invoicerate = 1;
}
// A draft still in the main currency takes the foreign currency of the terminal at its first
// payment (see payplus_ajax.php): its payment modes are offered from the start.
$pending = posplus_get_pending_invoice_currency($db, $invoice, $terminal);
if ($pending['currency'] !== '') {
	$invoicecurrency = $pending['currency'];
	$invoicerate = $pending['rate'];
}

// List of valid payment modes and their bank account.
// Replicate takepos/pay.php: a mode is shown if it has a configured bank account,
// or (when the bank module is not enabled) if it is CASH or CB.
$arrayOfPaymentModes = array();

$sql = "SELECT code, libelle as label FROM ".MAIN_DB_PREFIX."c_paiement";
$sql .= " WHERE entity IN (".getEntity('c_paiement').")";
$sql .= " AND active = 1";
$sql .= " ORDER BY libelle";
$resql = $db->query($sql);

if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$bankaccount = posplus_get_mode_bankaccount($obj->code, $terminal);
		if ($bankaccount > 0 && isModEnabled('bank')) {
			$acc = new Account($db);
			if ($acc->fetch($bankaccount) <= 0 || (int) $acc->clos != Account::STATUS_OPEN) {
				continue;
			}
			$acccurrency = ($acc->currency_code ? $acc->currency_code : $conf->currency);
			// A bank account in a currency that is neither the main one nor the invoice one
			// cannot receive this payment (the core would record a wrong amount): the mode is
			// shown disabled with the reason, so the operator knows what to do.
			$disabledreason = '';
			if (!posplus_bank_currency_allowed($acccurrency, $invoicecurrency)) {
				if ($pending['reason'] == 'norate') {
					$disabledreason = $langs->trans('PosplusModeNoRate', $acccurrency);
				} elseif ($pending['reason'] == 'multiple') {
					$disabledreason = $langs->trans('PosplusModeMultiCurrency', implode(', ', $pending['currencies']));
				} else {
					$disabledreason = $langs->trans('PosplusErrorBankCurrency', $acccurrency, $invoicecurrency);
				}
			}
			$arrayOfPaymentModes[] = array(
				'code' => $obj->code,
				'label' => $obj->label,
				'accountid' => $bankaccount,
				'currency' => $acccurrency,
				'disabled' => $disabledreason,
			);
		}
		if (!isModEnabled('bank') && ($obj->code == 'LIQ' || $obj->code == 'CB')) {
			$arrayOfPaymentModes[] = array(
				'code' => $obj->code,
				'label' => $obj->label,
				'accountid' => 0,
				'currency' => $conf->currency,
				'disabled' => '',
			);
		}
	}
}

$remaintopay = 0;
$totaltopay = 0;
if (!$invoicenotfound) {
	$remaintopay = $invoice->getRemainToPay();
	$totaltopay = $invoice->total_ttc;
}

// Helper to format an amount in a currency (comma decimals, dot thousands).
function posplus_fmt_amount($amount, $currency_code)
{
	global $langs;
	$amount = (float) $amount;
	$nbdec = 2;
	$formatted = number_format($amount, $nbdec, ',', '.');
	if ($currency_code) {
		$symbol = $langs->getCurrencySymbol($currency_code);
		if (!empty($symbol)) {
			$formatted .= ' '.$symbol;
		} else {
			$formatted .= ' '.$currency_code;
		}
	}
	return $formatted;
}

$maincurrency = $conf->currency;
$rowcount = count($arrayOfPaymentModes);

// Multicurrency feature active only when a customer currency differs from the main currency.
$multicurrencyactive = (isModEnabled('multicurrency') && $sessioncurrency != '' && $sessioncurrency != $maincurrency && $multicurrencyrate > 0);
$showdelayed = getDolGlobalInt('TAKEPOS_DELAYED_PAYMENT');

// Build the list of payment modes to inject in JS: code, account, currency and rate.
$jsmodes = array();
$i = 0;
foreach ($arrayOfPaymentModes as $mode) {
	$i++;
	$cur = $mode['currency'];
	// Only allowed foreign account: the invoice currency, converted with the invoice rate.
	$rate = (($cur != $maincurrency && $invoicerate > 0) ? $invoicerate : 1);
	$jsmodes[] = array(
		'code' => $mode['code'],
		'label' => posplus_payment_label($mode['code'], $mode['label']),
		'accountid' => (int) $mode['accountid'],
		'currency' => $cur,
		'rate' => (float) $rate,
		'disabled' => $mode['disabled'],
	);
}
$rowcount = count($jsmodes);

// With the cash count by shift, no payment can be taken without an open shift.
$nocashsession = false;
if (getDolGlobalInt('POSPLUS_CASHCOUNT')) {
	require_once __DIR__.'/class/posplussession.class.php';
	$cashsession = new PosplusCashSession($db);
	if ($cashsession->fetchOpen($terminal) <= 0) {
		$nocashsession = true;
		foreach ($jsmodes as $k => $m) {
			$jsmodes[$k]['disabled'] = $langs->trans('PosplusCashNoOpenSession');
		}
	}
}

// Debug helper (only when constant POSPLUS_DEBUG is set to 1)
if (getDolGlobalInt('POSPLUS_DEBUG')) {
	print '<!-- DEBUG payplus: terminal='.dol_escape_htmltag($terminal).' sessionterm='.dol_escape_htmltag(isset($_SESSION['takeposterminal']) ? $_SESSION['takeposterminal'] : 'NULL').' rowcount='.$rowcount.' bank_enabled='.(isModEnabled('bank') ? '1' : '0').' c_paiement_entity='.dol_escape_htmltag(getEntity('c_paiement')).' multicurrencyactive='.($multicurrencyactive ? '1' : '0').' sessioncurrency='.dol_escape_htmltag($sessioncurrency).' delayed='.($showdelayed ? '1' : '0').' -->';
}
?>
<!DOCTYPE html>
<html>
<head>
<style>
body { background-color: #fff; padding: 10px; font-size: 14px; font-family: Arial, Helvetica, sans-serif; }
#payplusbox { max-width: 700px; margin: auto; }
.payplus-total { font-size: 1.2em; font-weight: bold; margin-bottom: 8px; }
.payplus-remain { margin-bottom: 8px; }
.payplus-eq { font-size: 0.9em; opacity: 0.85; }
.payplus-amounts { border: 1px solid #ddd; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; background-color: #fafafa; }
.payplus-amounts .fields { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
.payplus-amounts .field { display: flex; flex-direction: column; }
.payplus-amounts .field label { font-size: 0.85em; opacity: 0.75; margin-bottom: 2px; }
.payplus-amounts .field input[type="text"] { width: 160px; text-align: right; padding: 5px 8px; font-size: 1.1em; }
.payplus-rate-hint { font-size: 0.85em; opacity: 0.75; margin-top: 6px; }
.payplus-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
.payplus-grid .btn-mode { font-size: 1.05em; padding: 10px 8px; cursor: pointer; }
.payplus-btn-delayed { font-size: 1.05em; padding: 10px 8px; margin-bottom: 12px; width: 100%; cursor: pointer; }
.payplus-btn-close { margin-top: 12px; }
.payplus-note { font-size: 0.9em; opacity: 0.8; margin-top: 6px; }
.payplus-grid .btn-mode-off { cursor: not-allowed; opacity: 0.45; }
.payplus-warning { font-size: 0.9em; color: #8a5300; background-color: #fff4e0; border: 1px solid #f0c36d; border-radius: 4px; padding: 6px 8px; margin-bottom: 8px; }
</style>
<script type="text/javascript" src="<?php echo DOL_URL_ROOT; ?>/includes/jquery/js/jquery.js"></script>
</head>
<body>
<div id="payplusbox">

<?php
if ($invoicenotfound) {
	print '<div class="payplus-remain error">'.$langs->trans('PosplusErrorNoInvoice').'</div>';
}

// Total header (main currency + customer currency equivalent, like pay.php)
print '<div class="payplus-total">'.$langs->trans('TotalTTC').': <span id="posplus_total_main">'.posplus_fmt_amount($totaltopay, $maincurrency).'</span>';
if ($multicurrencyactive) {
	print ' &nbsp; <span class="payplus-eq">(<span id="posplus_total_ses">'.posplus_fmt_amount($totaltopay * $multicurrencyrate, $sessioncurrency).'</span>)</span>';
}
print '</div>';

// Remain to pay (updated dynamically after each partial payment)
print '<div class="payplus-remain">'.$langs->trans('RemainToPay').': <span id="posplus_remain_main">'.posplus_fmt_amount($remaintopay, $maincurrency).'</span>';
if ($multicurrencyactive) {
	print ' &nbsp; <span class="payplus-eq">(<span id="posplus_remain_ses">'.posplus_fmt_amount($remaintopay * $multicurrencyrate, $sessioncurrency).'</span>)</span>';
}
print '</div>';

// Global amount inputs (local + customer session currency)
print '<div class="payplus-amounts">';
print '<div class="fields">';
print '<div class="field"><label>'.$langs->trans('PosplusLocalCurrency').' ('.$maincurrency.')</label>';
print '<input type="text" id="posplus_local" class="posplus-local" value="'.posplus_fmt_amount($remaintopay, '').'">';
print '</div>';
if ($multicurrencyactive) {
	print '<div class="field"><label>'.$langs->trans('PosplusSessionCurrency').' ('.$sessioncurrency.')</label>';
	print '<input type="text" id="posplus_ses" class="posplus-session" value="'.posplus_fmt_amount($remaintopay * $multicurrencyrate, '').'">';
	print '</div>';
}
print '</div>';
if ($multicurrencyactive) {
	print '<div class="payplus-rate-hint">1 '.dol_escape_htmltag($maincurrency).' = '.posplus_fmt_amount($multicurrencyrate, '').' '.dol_escape_htmltag($sessioncurrency).'</div>';
}
print '</div>';

// Payment mode buttons grid
print '<div class="payplus-grid" id="posplus_modes_grid">';
$idx = 0;
foreach ($jsmodes as $m) {
	$label = $m['label'];
	if ($m['currency'] != $maincurrency) {
		$label .= ' ('.$m['currency'].')';
	}
	if ($m['disabled'] !== '') {
		// Mode not usable for this invoice: shown disabled, the reason is listed below.
		print '<button type="button" class="btn-mode btn-mode-off" disabled title="'.dol_escape_htmltag($m['disabled']).'">'.dol_escape_htmltag($label).'</button>';
	} else {
		print '<button type="button" class="btn-mode" onclick="posplusPayMode('.$idx.')">'.dol_escape_htmltag($label).'</button>';
	}
	$idx++;
}
if ($i == 0) {
	print '<div class="payplus-note">'.$langs->trans('NoPaimementModesDefined').'</div>';
}
print '</div>';
// Reasons of the disabled modes (once each)
$disabledreasons = array();
foreach ($jsmodes as $m) {
	if ($m['disabled'] !== '') {
		$disabledreasons[$m['disabled']] = 1;
	}
}
foreach (array_keys($disabledreasons) as $reason) {
	print '<div class="payplus-warning">'.dol_escape_htmltag($reason).'</div>';
}

// Deferred payment (credit sale)
if ($showdelayed) {
	print '<button type="button" class="payplus-btn-delayed" onclick="posplusDelayed()">'.$langs->trans('PosplusDelayed').'</button>';
}

print '<div class="payplus-note" id="posplus_paid_note"></div>';

print '<div class="payplus-btn-close">';
print '<button type="button" onclick="parent.$.colorbox.close();">'.$langs->trans('Close').'</button>';
print '</div>';
?>

</div>

<script type="text/javascript">
	var posplusRemainToPay = <?php echo (float) $remaintopay; ?>;
	var posplusTotalTtc = <?php echo (float) $totaltopay; ?>;
	var posplusPlace = <?php echo json_encode($place); ?>;
	var posplusToken = <?php echo json_encode(currentToken()); ?>;
	var posplusInvoiceId = <?php echo (int) $invoiceid; ?>;
	var posplusMainCurrency = <?php echo json_encode($maincurrency); ?>;
	var posplusMulticurrencyRate = <?php echo (float) ($multicurrencyactive ? $multicurrencyrate : 0); ?>;
	var posplusSessionCurrency = <?php echo json_encode($multicurrencyactive ? $sessioncurrency : ''); ?>;
	var posplusDelayedEnabled = <?php echo ($showdelayed ? '1' : '0'); ?>;
	var posplusModes = <?php echo json_encode($jsmodes); ?>;

	// Parse an amount string ("1.234,56" or "1234.56") into a float.
	function posplusParseAmount(str) {
		if (!str) { return 0; }
		str = String(str).replace(/\s/g, '');
		if (str.indexOf(',') > -1 && str.indexOf('.') > -1) {
			str = str.replace(/\./g, '').replace(',', '.');
		} else if (str.indexOf(',') > -1) {
			str = str.replace(',', '.');
		}
		var v = parseFloat(str);
		return isNaN(v) ? 0 : v;
	}

	// Format a float in "1.234,56".
	function posplusFormatAmount(amount) {
		var nb = Number(amount).toFixed(2);
		var parts = nb.split('.');
		var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
		return intPart + ',' + (parts[1] || '00');
	}

	// Update the remain header (main + session) after a partial payment.
	function posplusSetRemain(remainMain) {
		posplusRemainToPay = remainMain;
		var mainEl = document.getElementById('posplus_remain_main');
		if (mainEl) { mainEl.textContent = posplusFormatAmount(remainMain) + ' ' + posplusMainCurrency; }
		var sesEl = document.getElementById('posplus_remain_ses');
		if (sesEl && posplusMulticurrencyRate > 0) {
			sesEl.textContent = posplusFormatAmount(remainMain * posplusMulticurrencyRate) + ' ' + posplusSessionCurrency;
		}
	}

	// Reload the global inputs with a remaining amount (in main currency).
	function posplusReloadInputs(remainMain) {
		var local = document.getElementById('posplus_local');
		if (local) { local.value = remainMain > 0 ? posplusFormatAmount(remainMain) : ''; }
		posplusSyncSessionFromLocal();
	}

	// Sync session input from local input.
	function posplusSyncSessionFromLocal() {
		if (posplusMulticurrencyRate <= 0) { return; }
		var local = document.getElementById('posplus_local');
		var ses = document.getElementById('posplus_ses');
		if (!local || !ses) { return; }
		var main = posplusParseAmount(local.value);
		ses.value = main > 0 ? posplusFormatAmount(main * posplusMulticurrencyRate) : '';
	}

	// Sync local input from session input.
	function posplusSyncLocalFromSession() {
		if (posplusMulticurrencyRate <= 0) { return; }
		var local = document.getElementById('posplus_local');
		var ses = document.getElementById('posplus_ses');
		if (!local || !ses) { return; }
		var amtSes = posplusParseAmount(ses.value);
		var main = amtSes / posplusMulticurrencyRate;
		local.value = main > 0 ? posplusFormatAmount(main) : '';
	}

	// Attach input handlers.
	document.addEventListener('DOMContentLoaded', function() {
		var local = document.getElementById('posplus_local');
		if (local) { local.addEventListener('input', function() { posplusSyncSessionFromLocal(); }); }
		var ses = document.getElementById('posplus_ses');
		if (ses) { ses.addEventListener('input', function() { posplusSyncLocalFromSession(); }); }
	});

	// Register a payment for a given mode using the global local amount.
	function posplusPayMode(modeIdx) {
		var local = document.getElementById('posplus_local');
		if (!local) { return; }
		var amountMain = posplusParseAmount(local.value);
		if (amountMain <= 0) {
			alert(<?php echo json_encode($langs->trans('TotalAmountEmpty')); ?>);
			return;
		}

		var mode = posplusModes[modeIdx];
		if (!mode) { return; }

		// Change given back (cash LIQ in the main currency only, as the core).
		var excess = 0;
		if (String(mode.code) === 'LIQ' && mode.currency === posplusMainCurrency && amountMain > posplusRemainToPay) {
			excess = amountMain - posplusRemainToPay;
		}

		// A sale with a negative total is a refund: the core turns it into a credit note,
		// so it keeps going through takepos/invoice.php?action=valid.
		if (posplusTotalTtc < 0) {
			var loadurl = <?php echo json_encode(DOL_URL_ROOT.'/takepos/invoice.php'); ?> + '?action=valid&pay=' + encodeURIComponent(mode.code) + '&amount=' + encodeURIComponent(amountMain) + '&excess=' + encodeURIComponent(excess) + '&accountid=' + encodeURIComponent(mode.accountid) + '&place=' + encodeURIComponent(posplusPlace) + '&invoiceid=' + encodeURIComponent(posplusInvoiceId) + '&token=' + encodeURIComponent(posplusToken);
			parent.$("#poslines").load(loadurl, function() {
				parent.$('#invoiceid').val("");
				parent.$.colorbox.close();
			});
			return;
		}

		// Partial or complete payment: registered by POSPlus (the core cannot record a cash
		// payment of another mode than LIQ into a cash box). The amount is sent in the main
		// currency; the server converts it with the rate stored on the invoice.
		var url = <?php echo json_encode(dol_buildpath('/posplus/payplus_ajax.php', 1)); ?>;
		var data = {
			token: posplusToken,
			action: 'addpayment',
			place: posplusPlace,
			invoiceid: posplusInvoiceId,
			pay: mode.code,
			amount: amountMain,
			excess: excess,
			accountid: mode.accountid
		};

		posplusSendPayment(url, data);
	}

	// Disable the action buttons while a request is running (avoid double payments).
	function posplusBusy(busy) {
		var btns = document.querySelectorAll('#posplus_modes_grid button:not(.btn-mode-off), .payplus-btn-delayed');
		for (var k = 0; k < btns.length; k++) { btns[k].disabled = busy; }
	}

	// Deferred (credit) sale: validate the invoice without registering a payment.
	// We delegate to the core by reloading #poslines through invoice.php?action=valid
	// with pay=delayed, exactly as takepos/pay.php does for the "Reported" button.
	function posplusDelayed() {
		var loadurl = <?php echo json_encode(DOL_URL_ROOT.'/takepos/invoice.php'); ?> + '?action=valid&pay=delayed&place=' + encodeURIComponent(posplusPlace) + '&invoiceid=' + encodeURIComponent(posplusInvoiceId) + '&token=' + encodeURIComponent(posplusToken);
		parent.$("#poslines").load(loadurl, function() {
			parent.$('#invoiceid').val("");
			parent.$.colorbox.close();
		});
	}

	// Send a single payment to the endpoint and handle remain / close.
	function posplusSendPayment(url, data) {
		posplusBusy(true);
		$.post(url, data, function(resp) {
			posplusBusy(false);
			if (resp && resp.error) {
				alert(resp.error);
				return;
			}
			if (!resp || typeof resp.remaintopay === 'undefined') {
				alert(<?php echo json_encode($langs->trans('Error')); ?>);
				return;
			}

			var remain = resp.remaintopay;
			if (remain > 0) {
				// Partial payment: keep the popup open, update the remain and reload the inputs.
				posplusSetRemain(remain);
				posplusReloadInputs(remain);
				var note = document.getElementById('posplus_paid_note');
				if (note) {
					note.textContent = <?php echo json_encode($langs->trans('PosplusPaidPartial')); ?> + ' ' + posplusFormatAmount(remain) + ' ' + posplusMainCurrency;
				}
			} else {
				// Payment complete: the POS shows the paid invoice and prints the ticket.
				if (typeof parent.PosplusAfterPayment === 'function') {
					parent.PosplusAfterPayment(resp.invoiceid);
				} else {
					parent.$("#poslines").load("invoice.php?place=" + encodeURIComponent(posplusPlace) + "&token=" + encodeURIComponent(posplusToken));
					parent.$('#invoiceid').val("");
					parent.$.colorbox.close();
				}
			}
		}, 'json').fail(function() {
			posplusBusy(false);
			alert(<?php echo json_encode($langs->trans('Error')); ?>);
		});
	}
</script>
</body>
</html>
<?php
$db->close();
