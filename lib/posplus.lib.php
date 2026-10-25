<?php
/* Copyright (C) 2026		SuperAdmin
 * Copyright (C) 2025       Frédéric France         <frederic.france@free.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    posplus/lib/posplus.lib.php
 * \ingroup posplus
 * \brief   Library files with common functions for POSPlus
 */

/**
 * Timezone used for every date/hour the module shows or prints: the timezone of the
 * OPERATOR (reported by his browser at login, $_SESSION['dol_tz_string']), not the one
 * of the hosting, which may be in another country. Falls back to the server timezone
 * when the session has no valid operator timezone (e.g. a call without login).
 *
 * @return string Value for the $tzoutput / $gm parameters of dol_print_date() / dol_mktime()
 */
function posplus_tz()
{
	if (!empty($_SESSION['dol_tz_string'])) {
		try {
			new DateTimeZone($_SESSION['dol_tz_string']);
			return 'tzuserrel';
		} catch (Exception $e) {
			dol_syslog('posplus: invalid operator timezone '.json_encode($_SESSION['dol_tz_string']), LOG_WARNING);
		}
	}
	return 'tzserver';
}

/**
 * Print a date with the operator timezone (see posplus_tz()).
 *
 * @param int|string $time   Timestamp (GMT)
 * @param string     $format Format accepted by dol_print_date()
 * @return string
 */
function posplus_print_date($time, $format = 'day')
{
	return dol_print_date($time, $format, posplus_tz());
}

/**
 * Current TakePOS terminal of the session ('' if none).
 *
 * @return string
 */
function posplus_get_terminal()
{
	return (isset($_SESSION['takeposterminal']) ? (string) $_SESSION['takeposterminal'] : '');
}

/**
 * Check that an invoice can be handled from the current POS session: a TakePOS invoice
 * of an entity of the user and of the current terminal.
 * Facture::fetch($id) does not filter on entity, so this must always be checked.
 *
 * @param Facture $invoice  Loaded invoice
 * @param string  $terminal Current terminal
 * @return bool
 */
function posplus_invoice_is_accessible($invoice, $terminal)
{
	if (empty($invoice->id) || $invoice->id <= 0) {
		return false;
	}
	if (!in_array((int) $invoice->entity, array_map('intval', explode(',', getEntity('invoice'))))) {
		return false;
	}
	if ($invoice->module_source != 'takepos') {
		return false;
	}
	if ((string) $terminal === '' || (string) $invoice->pos_source !== (string) $terminal) {
		return false;
	}
	return true;
}

/**
 * Bank account configured in TakePOS for a payment mode code of c_paiement on a
 * terminal. Same mapping as takepos/invoice.php (LIQ -> CASH, CHQ -> CHEQUE).
 *
 * @param string $paycode  Code of c_paiement (LIQ, CB, CHQ, ...)
 * @param string $terminal Terminal
 * @return int             Bank account id, or 0
 */
function posplus_get_mode_bankaccount($paycode, $terminal)
{
	$suffix = $paycode;
	if ($paycode == 'LIQ') {
		$suffix = 'CASH';
	} elseif ($paycode == 'CHQ') {
		$suffix = 'CHEQUE';
	}
	return getDolGlobalInt('CASHDESK_ID_BANKACCOUNT_'.$suffix.$terminal);
}

/**
 * Ref of the cash control that closes the current day for a terminal, or '' if the
 * period is still open. Same test as takepos/invoice.php.
 *
 * @param DoliDB $db       Database handler
 * @param string $terminal Terminal
 * @return string
 */
function posplus_get_closed_cashcontrol($db, $terminal)
{
	global $conf;

	$tmpcurrentday = dol_getdate(dol_now());

	$sql = "SELECT MIN(ref) as firstref FROM ".MAIN_DB_PREFIX."pos_cash_fence";
	$sql .= " WHERE entity = ".((int) $conf->entity);
	$sql .= " AND posnumber = ".((int) $terminal);
	$sql .= " AND year_close = ".((int) $tmpcurrentday['year']);
	$sql .= " AND (";
	$sql .= " (month_close IS NULL AND day_close IS NULL)";
	$sql .= " OR (month_close = ".((int) $tmpcurrentday['mon'])." AND day_close IS NULL)";
	$sql .= " OR (month_close = ".((int) $tmpcurrentday['mon'])." AND day_close = ".((int) $tmpcurrentday['mday']).")";
	$sql .= ")";
	$sql .= " AND status = 1";

	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj && !empty($obj->firstref)) {
			return (string) $obj->firstref;
		}
	}
	return '';
}

/**
 * Whether a bank account of a given currency can receive a payment of an invoice. The
 * core stores the payment in the bank currency only when it is the main currency or the
 * invoice currency; any other combination records a wrong amount.
 *
 * @param string $bankcurrency    Currency of the bank account
 * @param string $invoicecurrency Currency of the invoice (multicurrency_code)
 * @return bool
 */
function posplus_bank_currency_allowed($bankcurrency, $invoicecurrency)
{
	global $conf;

	if (empty($bankcurrency) || $bankcurrency == $conf->currency) {
		return true;
	}
	return (!empty($invoicecurrency) && $bankcurrency == $invoicecurrency);
}


/**
 * Foreign currencies of the payment modes of a terminal: the currencies (other than the
 * main one) of the open bank accounts configured in TakePOS for the active payment modes.
 *
 * @param DoliDB $db       Database handler
 * @param string $terminal Terminal
 * @return string[]        Currency codes (sorted)
 */
function posplus_get_terminal_foreign_currencies($db, $terminal)
{
	global $conf;

	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
	$currencies = array();
	$sql = "SELECT code FROM ".MAIN_DB_PREFIX."c_paiement";
	$sql .= " WHERE entity IN (".getEntity('c_paiement').") AND active = 1";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$accountid = posplus_get_mode_bankaccount($obj->code, $terminal);
			if ($accountid <= 0) {
				continue;
			}
			$acc = new Account($db);
			if ($acc->fetch($accountid) > 0 && (int) $acc->clos == Account::STATUS_OPEN && $acc->currency_code && $acc->currency_code != $conf->currency) {
				$currencies[$acc->currency_code] = 1;
			}
		}
	}
	$list = array_keys($currencies);
	sort($list);
	return $list;
}

/**
 * Currency (and day rate) an invoice will take at its first payment so that every payment
 * mode of the terminal ($ and Bs) can be used in any order: a draft invoice still in the
 * main currency takes the foreign currency of the terminal at the day rate, as TakePOS
 * does when the customer currency is chosen. The amounts in main currency do not change.
 * Not applied when the operator chose a customer currency in the POS (TakePOS then forces
 * that currency on every reload of the invoice).
 *
 * When the change cannot be done, 'reason' explains why, so the popup can show the modes
 * of the foreign currency disabled with a notice:
 *  - 'norate'   : the foreign currency has no exchange rate
 *  - 'multiple' : the terminal has accounts in several foreign currencies (an invoice can
 *                 only carry one besides the main one)
 *
 * @param DoliDB  $db       Database handler
 * @param Facture $invoice  Invoice (may be empty when no sale is selected)
 * @param string  $terminal Terminal
 * @return array{currency:string,rate:float,reason:string,currencies:string[]}
 */
function posplus_get_pending_invoice_currency($db, $invoice, $terminal)
{
	global $conf;

	$ret = array('currency' => '', 'rate' => 0, 'reason' => '', 'currencies' => array());
	if (!isModEnabled('multicurrency') || !empty($_SESSION['takeposcustomercurrency'])) {
		return $ret;
	}
	if (!empty($invoice->id) && ((int) $invoice->status != Facture::STATUS_DRAFT || ($invoice->multicurrency_code && $invoice->multicurrency_code != $conf->currency))) {
		return $ret;
	}
	$ret['currencies'] = posplus_get_terminal_foreign_currencies($db, $terminal);
	if (count($ret['currencies']) == 0) {
		return $ret;
	}
	if (count($ret['currencies']) > 1) {
		$ret['reason'] = 'multiple';
		return $ret;
	}
	$foreign = $ret['currencies'][0];
	require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';
	$tmp = MultiCurrency::getIdAndTxFromCode($db, $foreign);
	$rate = (float) (isset($tmp[1]) ? $tmp[1] : 0);
	if ($rate <= 0) {
		$ret['reason'] = 'norate'; // No rate: converting would record the foreign amounts at 1:1
		$ret['currencies'] = array($foreign);
		return $ret;
	}
	$ret['currency'] = $foreign;
	$ret['rate'] = $rate;
	return $ret;
}

/**
 * Whether a bank account is a cash box (type "Cash"). A payment mode is handled as cash
 * when the account configured for it in TakePOS is a cash box, whatever its code and
 * currency (e.g. LIQ in the main currency, EFBS in bolivares): no extra setup needed.
 *
 * @param DoliDB $db        Database handler
 * @param int    $accountid Bank account id
 * @return bool
 */
function posplus_bank_account_is_cash($db, $accountid)
{
	static $cache = array();

	$accountid = (int) $accountid;
	if ($accountid <= 0) {
		return false;
	}
	if (!isset($cache[$accountid])) {
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$acc = new Account($db);
		$cache[$accountid] = ($acc->fetch($accountid) > 0 && (int) $acc->type == Account::TYPE_CASH);
	}
	return $cache[$accountid];
}

/**
 * Record the bank line of a customer payment. The core only accepts the operation code
 * LIQ on a cash box (Account::addline, ErrorCashAccountAcceptsOnlyCashMoney): on a cash box
 * the bank line is recorded as LIQ while the payment keeps its own mode (EFBS...), so the
 * receipt and the reports still show the real mode. Same rule as the EasyBack reversals.
 *
 * @param DoliDB   $db        Database handler
 * @param Paiement $payment   Payment already created
 * @param User     $user      User
 * @param int      $accountid Bank account id
 * @return int                >0 if OK, <0 if KO
 */
function posplus_add_payment_to_bank($db, $payment, $user, $accountid)
{
	$paycode = $payment->paiementcode;
	if (posplus_bank_account_is_cash($db, $accountid)) {
		$payment->paiementcode = 'LIQ';
	}
	$res = $payment->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $accountid, '', '');
	$payment->paiementcode = $paycode;
	return $res;
}

/**
 * Short label of a payment mode: the translation PaymentTypeShort<CODE> when it exists,
 * otherwise the label of the dictionary (modes created by the user, e.g. EFBS, have no
 * translation and would show the raw key).
 *
 * @param string $code    Code of c_paiement
 * @param string $dblabel Label of the dictionary (optional)
 * @return string
 */
function posplus_payment_label($code, $dblabel = '')
{
	global $langs;

	$key = 'PaymentTypeShort'.$code;
	$label = $langs->trans($key);
	if ($label != $key) {
		return $label;
	}
	$key = 'PaymentType'.$code;
	$label = $langs->trans($key);
	if ($label != $key) {
		return $label;
	}
	return ($dblabel !== '' ? $dblabel : (string) $code);
}

/**
 * Return a formatted monetary amount with comma as decimal separator and dot as
 * thousands separator (e.g. 1.234,56), regardless of the installation language.
 *
 * @param float|string $amount        Amount to format
 * @param string       $currency_code Currency code (optional). If set, the symbol is added.
 * @return string
 */
function posplus_price($amount, $currency_code = '')
{
	global $langs, $conf;

	$amount = (float) $amount;
	$nbdec = 2;

	$formatted = number_format($amount, $nbdec, ',', '.');

	if ($currency_code) {
		$symbol = $langs->getCurrencySymbol($currency_code);
		if (!empty($symbol)) {
			$formatted .= ' '.$symbol;
		} elseif (!empty($conf->currency)) {
			$formatted .= ' '.$conf->currency;
		}
	}

	return $formatted;
}

/**
 * Amount for the ticket: currency symbol first, comma decimals and dot thousands
 * (e.g. "Bs 450,00", "$ 70,00", "-Bs 10,00").
 *
 * @param float|string $amount        Amount
 * @param string       $currency_code Currency code
 * @return string
 */
function posplus_ticket_amount($amount, $currency_code)
{
	global $langs;

	$amount = (float) price2num($amount, 'MT');
	$symbol = $langs->getCurrencySymbol($currency_code);
	if (empty($symbol)) {
		$symbol = $currency_code;
	}
	return ($amount < 0 ? '-' : '').$symbol.' '.number_format(abs($amount), 2, ',', '.');
}

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function posplusAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("posplus@posplus");

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/posplus/admin/setup.php", 1));
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	/*
	$head[$h][0] = dolBuildUrl(dol_buildpath("/posplus/admin/myobject_extrafields.php", 1));
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = (isset($extrafields->attributes['myobject']['label']) && is_countable($extrafields->attributes['myobject']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;

	$head[$h][0] = dolBuildUrl(dol_buildpath("/posplus/admin/myobjectline_extrafields.php", 1));
	$head[$h][1] = $langs->trans("ExtraFieldsLines");
	$nbExtrafields = (isset($extrafields->attributes['myobjectline']['label']) && is_countable($extrafields->attributes['myobjectline']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafieldsline';
	$h++;
	*/

	$head[$h][0] = dolBuildUrl(dol_buildpath("/posplus/admin/about.php", 1));
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@posplus:/posplus/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@posplus:/posplus/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'posplus@posplus');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'posplus@posplus', 'remove');

	return $head;
}
