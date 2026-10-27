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
 * \file    posplus/payplus_ajax.php
 * \ingroup posplus
 * \brief   AJAX endpoint used by the payplus.php popup to register a payment (partial
 *          or the one that completes the invoice) of a TakePOS invoice, possibly into a
 *          bank account or a cash box of another currency.
 *          Replicates takepos/invoice.php action=valid (validation, stock, payment,
 *          closing); the popup then shows/prints the ticket through the core.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
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
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once __DIR__.'/lib/posplus.lib.php';

// Translations
$langs->loadLangs(array('posplus@posplus', 'bills', 'cashdesk', 'banks', 'errors'));

header('Content-Type: application/json; charset=UTF-8');

/**
 * Print a JSON error and stop.
 *
 * @param string $msg Error message
 * @return never
 */
function posplus_ajax_fail($msg)
{
	global $db;
	print json_encode(array('error' => $msg));
	$db->close();
	exit;
}

// Security
if (!isModEnabled('posplus')) {
	posplus_ajax_fail('Module posplus not enabled');
}
// CSRF: this endpoint registers payments and bank movements, so the token is always
// mandatory (do not depend on MAIN_SECURITY_CSRF_WITH_TOKEN).
$token = GETPOST('token', 'aZ09');
if (empty($token) || $token !== currentToken()) {
	posplus_ajax_fail($langs->transnoentities('PosplusErrorInvalidToken'));
}
if (!$user->hasRight('takepos', 'run') || !$user->hasRight('facture', 'creer')) {
	posplus_ajax_fail($langs->transnoentities('NotEnoughPermissions'));
}
if (GETPOST('action', 'aZ09') != 'addpayment') {
	posplus_ajax_fail('Invalid action');
}

$terminal = posplus_get_terminal();
if ($terminal === '') {
	posplus_ajax_fail($langs->transnoentities('PosplusErrorNoTerminal'));
}

$place = (GETPOST('place', 'aZ09') ? GETPOST('place', 'aZ09') : '0');
$invoiceid = GETPOSTINT('invoiceid');
$pay = GETPOST('pay', 'aZ09');
// Amount in the currency of the box/account of the mode (GETPOST 'currency'): in the main
// currency, or in the invoice currency for an account of that currency (e.g. Bs). The
// conversion is done with the rate stored on the invoice, never with a rate sent by the browser.
$amountofpayment = (float) price2num(GETPOSTFLOAT('amount'), 'MT');
$amountcurrency = GETPOST('currency', 'aZ09');
// Cash received above the remain to pay (change given back), in the main currency.
$excess = (float) price2num(GETPOSTFLOAT('excess'), 'MT');
$accountid = GETPOSTINT('accountid');

// Load the invoice (only a TakePOS invoice of this terminal and of an entity of the user)
$invoice = new Facture($db);
if ($invoiceid > 0) {
	$ret = $invoice->fetch($invoiceid);
} else {
	$ret = $invoice->fetch(0, '(PROV-POS'.$terminal.'-'.$place.')');
}
if ($ret <= 0 || !posplus_invoice_is_accessible($invoice, $terminal)) {
	posplus_ajax_fail($langs->transnoentities('ErrorRecordNotFound'));
}
if ((int) $invoice->type != Facture::TYPE_STANDARD) {
	posplus_ajax_fail($langs->transnoentities('ErrorBadParameters'));
}

// The day must not be closed for this terminal (same test as takepos/invoice.php).
$refcashcontrol = posplus_get_closed_cashcontrol($db, $terminal);
if ($refcashcontrol) {
	posplus_ajax_fail($langs->transnoentities('ACashControlHasBeenclosedForCurrentDay', $refcashcontrol));
}

// With the cash count by shift, a payment needs an open shift on the terminal.
if (getDolGlobalInt('POSPLUS_CASHCOUNT')) {
	require_once __DIR__.'/class/posplussession.class.php';
	$cashsession = new PosplusCashSession($db);
	if ($cashsession->fetchOpen($terminal) <= 0) {
		posplus_ajax_fail($langs->transnoentities('PosplusCashNoOpenSession'));
	}
}

if ($amountofpayment <= 0) {
	posplus_ajax_fail($langs->transnoentities('TotalAmountEmpty'));
}

// Normalize the payment code to a c_paiement code.
$paycode = $pay;
if ($pay == 'cash') {
	$paycode = 'LIQ';
} elseif ($pay == 'card') {
	$paycode = 'CB';
} elseif ($pay == 'cheque') {
	$paycode = 'CHQ';
}

// Id of the payment mode (must be an active mode of c_paiement).
$paiementid = 0;
if ($paycode) {
	$sql = "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement";
	$sql .= " WHERE entity IN (".getEntity('c_paiement').")";
	$sql .= " AND active = 1";
	$sql .= " AND code = '".$db->escape($paycode)."'";
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj) {
			$paiementid = (int) $obj->id;
		}
	}
}
if ($paiementid <= 0) {
	posplus_ajax_fail($langs->transnoentities('ErrorBadParameters'));
}

// Bank account: the one configured in TakePOS for this mode and terminal. The value sent
// by the browser is only accepted when TakePOS allows forcing the account.
$bankaccount = posplus_get_mode_bankaccount($paycode, $terminal);
if (getDolGlobalString('TAKEPOS_CAN_FORCE_BANK_ACCOUNT_DURING_PAYMENT') && $accountid > 0) {
	$bankaccount = $accountid;
}

$bankcurrency = $conf->currency;
if (isModEnabled('bank')) {
	if ($bankaccount <= 0) {
		posplus_ajax_fail($langs->transnoentities('ErrorFieldRequired', $langs->transnoentitiesnoconv('BankAccount')));
	}
	$acc = new Account($db);
	// Account::fetch() filters on the entities of the user.
	if ($acc->fetch($bankaccount) <= 0 || (int) $acc->clos != Account::STATUS_OPEN) {
		posplus_ajax_fail($langs->transnoentities('ErrorBadParameters').' ('.$langs->transnoentitiesnoconv('BankAccount').')');
	}
	$bankcurrency = ($acc->currency_code ? $acc->currency_code : $conf->currency);
} else {
	// Without the bank module the native flow only allows cash and card.
	if ($paycode != 'LIQ' && $paycode != 'CB') {
		posplus_ajax_fail($langs->transnoentities('ErrorBadParameters'));
	}
	$bankaccount = 0;
}

$error = 0;
$errormsg = '';
$remaintopay = 0;

$db->begin();

// Lock the invoice row until the end of the transaction, so two simultaneous requests
// (double click, two tabs) cannot both pay the same remain to pay.
$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."facture WHERE rowid = ".((int) $invoice->id)." FOR UPDATE");
if (!$resql || $invoice->fetch($invoice->id) <= 0) {
	$db->rollback();
	posplus_ajax_fail($db->lasterror());
}

$invoice->oldcopy = dol_clone($invoice, 2);

// First payment of an invoice still in the main currency: it takes the foreign currency of
// the terminal at the day rate (amounts in main currency unchanged), so the payment modes in
// $ and in Bs can be mixed in any order.
$pending = posplus_get_pending_invoice_currency($db, $invoice, $terminal);
if ($pending['currency'] !== '') {
	if ($invoice->setMulticurrencyCode($pending['currency']) < 0 || $invoice->fetch($invoice->id) <= 0) {
		$db->rollback();
		posplus_ajax_fail($invoice->error ? $invoice->error : $langs->transnoentities('Error'));
	}
}

if ($invoice->status == Facture::STATUS_DRAFT) {
	if (count($invoice->lines) == 0) {
		$error++;
		$errormsg = $langs->transnoentities('NoLinesToBill', 'TakePos');
	} else {
		// Validate the draft invoice (same logic as takepos/invoice.php action=valid).
		$allowstockchange = (getDolGlobalString('CASHDESK_NO_DECREASE_STOCK'.$terminal) != "1");
		if (isModEnabled('stock') && !isModEnabled('productbatch') && $allowstockchange) {
			$savconst = getDolGlobalString('STOCK_CALCULATE_ON_BILL');
			$conf->global->STOCK_CALCULATE_ON_BILL = 1; // Force stock change during invoice validation
			$res = $invoice->validate($user, '', getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$terminal), 0, 0);
			$conf->global->STOCK_CALCULATE_ON_BILL = $savconst;
		} else {
			$res = $invoice->validate($user);
		}
		if ($res < 0) {
			$error++;
			$langs->load("admin");
			$errormsg = ($invoice->error == 'NotConfigured' ? $langs->transnoentities("NotConfigured").' (TakePos numbering module)' : $invoice->error);
		} elseif (isModEnabled('stock') && isModEnabled('productbatch') && $allowstockchange) {
			// With lots/serials the stock is moved line by line (same as takepos/invoice.php).
			require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
			require_once DOL_DOCUMENT_ROOT.'/product/class/productbatch.class.php';
			$inventorycode = dol_print_date(dol_now(), 'dayhourlog');
			$labeltakeposmovement = 'TakePOS - '.$langs->transnoentities("Invoice").' '.$invoice->ref;
			foreach ($invoice->lines as $line) {
				if (empty($line->fk_product)) {
					continue;
				}
				$warehouseid = ($line->fk_warehouse ? $line->fk_warehouse : getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$terminal));
				$mouvP = new MouvementStock($db);
				$mouvP->setOrigin($invoice->element, $invoice->id);
				if ($line->batch != '' && $warehouseid > 0) {
					$prod_batch = new Productbatch($db);
					$prod_batch->find(0, '', '', $line->batch, $warehouseid, (int) $line->fk_product);
					$resmove = $mouvP->livraison($user, $line->fk_product, $warehouseid, $line->qty, $line->price, $labeltakeposmovement, '', '', '', $prod_batch->batch, $prod_batch->id, $inventorycode);
				} else {
					$resmove = $mouvP->livraison($user, $line->fk_product, $warehouseid, $line->qty, $line->price, $labeltakeposmovement, '', '', '', '', 0, $inventorycode);
				}
				if ($resmove < 0) {
					$error++;
					$errormsg = $mouvP->error;
					break;
				}
			}
		}
	}
} elseif ($invoice->status != Facture::STATUS_VALIDATED) {
	// Paid, abandoned...: nothing to pay.
	$error++;
	$errormsg = $langs->transnoentities('InvoiceIsAlreadyValidated', 'TakePos');
}

// The bank account must be in the main currency or in the invoice currency: any other
// combination would be recorded by the core with a wrong amount.
$invoicecurrency = ($invoice->multicurrency_code ? $invoice->multicurrency_code : $conf->currency);
// Payment received in the foreign currency of the invoice (account in that currency)
$payinforeign = ($bankcurrency != $conf->currency && $bankcurrency == $invoicecurrency && (float) $invoice->multicurrency_tx > 0);

if (!$error) {
	$remaintopay = (float) price2num($invoice->getRemainToPay(), 'MT');
	if ($remaintopay <= 0) {
		$error++;
		$errormsg = $langs->transnoentities('InvoiceIsAlreadyValidated', 'TakePos');
	}
}

if (!$error && !posplus_bank_currency_allowed($bankcurrency, $invoicecurrency)) {
	$error++;
	$errormsg = $langs->transnoentities('PosplusErrorBankCurrency', $bankcurrency, $invoicecurrency);
}
// The amount must be in the currency of the account of the mode (the popup sends it so)
if (!$error && $amountcurrency !== '' && $amountcurrency != $bankcurrency) {
	$error++;
	$errormsg = $langs->transnoentities('ErrorBadParameters').' ('.$langs->transnoentitiesnoconv('Currency').')';
}
// An amount in the foreign currency worth less than 0.01 in the main currency would record a
// bank line without any amount on the invoice.
if (!$error && $payinforeign && (float) price2num($amountofpayment / (float) $invoice->multicurrency_tx, 'MT') <= 0) {
	$error++;
	$errormsg = $langs->transnoentities('TotalAmountEmpty');
}

if (!$error) {
	$payment = new Paiement($db);
	$payment->datepaye = dol_now();
	$payment->fk_account = $bankaccount;
	if ($payinforeign) {
		// Amount in the invoice currency, as received in the box (e.g. Bs), never more than the
		// remain to pay in that currency: Paiement::create() converts it to the main currency
		// with the invoice rate and the bank line keeps the exact amount received.
		$remaintopaymc = (float) price2num($invoice->getRemainToPay(1), 'MT');
		if ($remaintopaymc <= 0) {
			$remaintopaymc = (float) price2num($remaintopay * (float) $invoice->multicurrency_tx, 'MT');
		}
		$payment->multicurrency_amounts[$invoice->id] = price2num(min($amountofpayment, $remaintopaymc), 'MT');
		$payment->multicurrency_code[$invoice->id] = $invoicecurrency;
		$payment->multicurrency_tx[$invoice->id] = (float) $invoice->multicurrency_tx;
	} else {
		// Amount in main currency, never more than the remain to pay. multicurrency_amounts is
		// left empty (as takepos/invoice.php does): Paiement::create() converts it with the
		// invoice rate.
		$payment->amounts[$invoice->id] = price2num(min($amountofpayment, $remaintopay), 'MT');
	}
	// Change given back: as the core, only for LIQ in the main currency (a change in another
	// currency is not supported by the core: the amount is just capped to the remain to pay).
	if ($paycode == 'LIQ' && $bankcurrency == $conf->currency && $amountofpayment > $remaintopay) {
		$payment->pos_change = price2num($excess > 0 ? $excess : $amountofpayment - $remaintopay, 'MT');
	}
	$payment->paiementid = $paiementid;
	$payment->paiementcode = $paycode;
	$payment->num_payment = '';

	$res = $payment->create($user); // This records the payment and regenerates the PDF
	if ($res < 0) {
		$error++;
		$errormsg = $payment->error;
	} elseif ($bankaccount > 0) {
		// On a cash box the bank line is recorded as LIQ (core rule), the payment keeps its mode.
		$res = posplus_add_payment_to_bank($db, $payment, $user, $bankaccount);
		if ($res < 0) {
			$error++;
			$errormsg = $payment->error;
		}
	}
}

$remaintopaymc = 0;
$paid = 0;
if (!$error) {
	// Close the invoice if fully paid. With a foreign invoice currency, the remain in one
	// currency can keep a cent of rounding when the other one is fully paid: the invoice is
	// paid when either remain is zero.
	$remaintopay = (float) price2num($invoice->getRemainToPay(), 'MT');
	$usemc = ($invoicecurrency != $conf->currency && (float) $invoice->multicurrency_tx > 0);
	$remaintopaymc = ($usemc ? (float) price2num($invoice->getRemainToPay(1), 'MT') : $remaintopay);
	if ($remaintopay <= 0 || $remaintopaymc <= 0) {
		$paid = 1;
		if ($invoice->setPaid($user) < 0 || $invoice->setPaymentMethods($paiementid) < 0) {
			$error++;
			$errormsg = $invoice->error;
		}
		$remaintopay = 0;
		$remaintopaymc = 0;
	}
}
if (!$error) {
	$db->commit();
} else {
	$db->rollback();
}

if ($error) {
	print json_encode(array('error' => $errormsg ? $errormsg : $langs->transnoentities('Error')));
} else {
	print json_encode(array(
		'success' => true,
		'remaintopay' => $remaintopay,
		'remaintopay_mc' => $remaintopaymc,
		'currency_mc' => $invoicecurrency,
		'invoiceid' => (int) $invoice->id,
		'paid' => $paid,
	));
}
$db->close();
