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
 * Whether a payment must carry its reference (confirmation number of the transfer, mobile
 * payment, card voucher...). Every payment needs it except cash: a mode whose account is a
 * cash box (LIQ, EFBS...) or, without the bank module, LIQ. Option POSPLUS_PAYMENT_REF_REQUIRED
 * (on when not yet defined, so it applies after replacing the folder).
 *
 * @param DoliDB $db        Database handler
 * @param string $paycode   Code of c_paiement
 * @param int    $accountid Bank account id of the mode (0 without the bank module)
 * @return bool
 */
function posplus_mode_needs_reference($db, $paycode, $accountid)
{
	if (!getDolGlobalInt('POSPLUS_PAYMENT_REF_REQUIRED', 1)) {
		return false;
	}
	if (isModEnabled('bank') && $accountid > 0) {
		return !posplus_bank_account_is_cash($db, $accountid);
	}
	return ($paycode != 'LIQ');
}

/**
 * Code of the cash payment mode of a foreign currency. TakePOS only offers the accounts of
 * type Cash to a mode whose code starts with LIQ (takepos/admin/terminal.php), so a cash mode
 * must be named LIQ...: LIQBS for the bolivar (VES, VEF, VED), LIQ + ISO code otherwise.
 *
 * @param string $currency Currency code
 * @return string
 */
function posplus_foreign_cash_code($currency)
{
	return (in_array($currency, array('VES', 'VEF', 'VED')) ? 'LIQBS' : 'LIQ'.$currency);
}

/**
 * Configure the cash in the foreign currencies of multicurrency so a cash box in that
 * currency can be used in TakePOS without manual setup (run at module activation and from
 * the setup page). For each foreign currency:
 * - the cash box (account of type Cash) of that currency; for the bolivar it is created when
 *   missing, other currencies are only handled when they already have one;
 * - the cash payment mode LIQBS / LIQ<ISO>: a mode whose code does not start with LIQ but is
 *   assigned to that cash box in a terminal (e.g. EFBS) is renamed (payments keep its id),
 *   otherwise the mode is created;
 * - each terminal without an account for that mode gets the cash box. An assignment already
 *   made is never changed.
 * Idempotent. With $dryrun nothing is written and the messages describe what would be done.
 *
 * @param DoliDB $db     Database handler
 * @param User   $user   User
 * @param bool   $dryrun True to only check
 * @return array<int,array{type:string,msg:string}> Messages (type info, warning or error)
 */
function posplus_setup_foreign_cash($db, $user, $dryrun = false)
{
	global $conf, $langs, $mysoc;

	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
	$langs->loadLangs(array('posplus@posplus', 'banks'));

	$out = array();
	if (!isModEnabled('multicurrency') || !isModEnabled('bank')) {
		return $out;
	}
	$nbterminals = max(1, getDolGlobalInt('TAKEPOS_NUM_TERMINALS', 1));

	$currencies = array();
	$sql = "SELECT code FROM ".MAIN_DB_PREFIX."multicurrency";
	$sql .= " WHERE entity IN (".getEntity('multicurrency').")";
	$sql .= " AND code <> '".$db->escape($conf->currency)."'";
	$sql .= " ORDER BY code";
	$resql = $db->query($sql);
	if (!$resql) {
		return array(array('type' => 'error', 'msg' => $db->lasterror()));
	}
	while ($obj = $db->fetch_object($resql)) {
		$currencies[] = (string) $obj->code;
	}

	foreach ($currencies as $currency) {
		$isbs = in_array($currency, array('VES', 'VEF', 'VED'));
		$paycode = posplus_foreign_cash_code($currency);
		$label = ($isbs ? $langs->transnoentities('PosplusFxCashLabelBs') : $langs->transnoentities('PosplusFxCashLabel', $currency));

		// Open cash boxes in this currency
		$cashboxes = array();
		$sql = "SELECT rowid, ref FROM ".MAIN_DB_PREFIX."bank_account";
		$sql .= " WHERE entity IN (".getEntity('bank_account').")";
		$sql .= " AND courant = ".((int) Account::TYPE_CASH)." AND clos = ".((int) Account::STATUS_OPEN);
		$sql .= " AND currency_code = '".$db->escape($currency)."'";
		$sql .= " ORDER BY rowid";
		$resql = $db->query($sql);
		if (!$resql) {
			$out[] = array('type' => 'error', 'msg' => $db->lasterror());
			continue;
		}
		while ($obj = $db->fetch_object($resql)) {
			$cashboxes[(int) $obj->rowid] = (string) $obj->ref;
		}
		if (empty($cashboxes)) {
			if (!$isbs) {
				continue; // A foreign currency without cash box is not received in cash
			}
			$ref = 'CAJA'.$currency;
			if ($dryrun) {
				$out[] = array('type' => 'warning', 'msg' => $langs->transnoentities('PosplusFxCashBoxToCreate', $currency, $label));
				$cashboxes[0] = $ref;
			} else {
				// Ref and label must be unique in the entity (a closed box may already use them)
				$n = 1;
				$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."bank_account WHERE entity = ".((int) $conf->entity);
				while (($res = $db->query($sql." AND ref = '".$db->escape($ref)."'")) && ($obj = $db->fetch_object($res)) && $obj->nb > 0) {
					$n++;
					$ref = 'CAJA'.$currency.$n;
				}
				$n = 1;
				$acclabel = $label;
				while (($res = $db->query($sql." AND label = '".$db->escape($acclabel)."'")) && ($obj = $db->fetch_object($res)) && $obj->nb > 0) {
					$n++;
					$acclabel = $label.' '.$n;
				}
				$acc = new Account($db);
				$acc->ref = $ref;
				$acc->label = $acclabel;
				$acc->type = Account::TYPE_CASH;
				$acc->status = Account::STATUS_OPEN;
				$acc->currency_code = $currency;
				$acc->country_id = (int) $mysoc->country_id;
				$acc->date_solde = dol_now();
				$acc->rappro = 0;
				if ($acc->create($user) <= 0) {
					$out[] = array('type' => 'error', 'msg' => $langs->transnoentities('PosplusFxError', $acclabel, $acc->error));
					continue;
				}
				$cashboxes[(int) $acc->id] = $ref;
				$out[] = array('type' => 'info', 'msg' => $langs->transnoentities('PosplusFxCashBoxCreated', $ref, $acclabel));
			}
		}

		// Cash payment mode of the currency
		$modeexists = false;
		$oldcode = '';
		$sql = "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement";
		$sql .= " WHERE entity IN (".getEntity('c_paiement').") AND code = '".$db->escape($paycode)."'";
		$resql = $db->query($sql);
		if ($resql && $db->fetch_object($resql)) {
			$modeexists = true;
		}
		if (!$modeexists) {
			// A mode not named LIQ... but assigned to one of these cash boxes (e.g. EFBS): the
			// terminal setup of TakePOS would lose its account at the next save.
			$sql = "SELECT id, code FROM ".MAIN_DB_PREFIX."c_paiement";
			$sql .= " WHERE entity IN (".getEntity('c_paiement').") AND code NOT LIKE 'LIQ%'";
			$sql .= " ORDER BY id";
			$resql = $db->query($sql);
			while ($resql && $oldcode === '' && ($obj = $db->fetch_object($resql))) {
				for ($t = 1; $t <= $nbterminals; $t++) {
					$accountid = posplus_get_mode_bankaccount($obj->code, (string) $t);
					if ($accountid > 0 && isset($cashboxes[$accountid])) {
						$oldcode = (string) $obj->code;
						break;
					}
				}
			}
			if ($oldcode !== '' && in_array($oldcode, array('CB', 'CHQ'))) {
				$oldcode = ''; // Core modes are never renamed
			}
			if ($dryrun) {
				if ($oldcode !== '') {
					$out[] = array('type' => 'warning', 'msg' => $langs->transnoentities('PosplusFxModeToRename', $oldcode, $paycode));
				} else {
					$out[] = array('type' => 'warning', 'msg' => $langs->transnoentities('PosplusFxModeToCreate', $paycode, $label));
				}
			} elseif ($oldcode !== '') {
				$db->begin();
				$ok = $db->query("UPDATE ".MAIN_DB_PREFIX."c_paiement SET code = '".$db->escape($paycode)."' WHERE entity IN (".getEntity('c_paiement').") AND code = '".$db->escape($oldcode)."'");
				for ($t = 1; $ok && $t <= $nbterminals; $t++) {
					$accountid = getDolGlobalInt('CASHDESK_ID_BANKACCOUNT_'.$oldcode.$t);
					if ($accountid > 0) {
						$ok = (dolibarr_set_const($db, 'CASHDESK_ID_BANKACCOUNT_'.$paycode.$t, (string) $accountid, 'chaine', 0, '', $conf->entity) > 0
							&& dolibarr_del_const($db, 'CASHDESK_ID_BANKACCOUNT_'.$oldcode.$t, $conf->entity) >= 0);
					}
				}
				if ($ok) {
					$db->commit();
					$out[] = array('type' => 'info', 'msg' => $langs->transnoentities('PosplusFxModeRenamed', $oldcode, $paycode));
				} else {
					$db->rollback();
					$out[] = array('type' => 'error', 'msg' => $langs->transnoentities('PosplusFxError', $oldcode, $db->lasterror()));
					continue;
				}
			} else {
				// Same insert as the dictionary page (admin/dict.php): free id, then on
				// PostgreSQL the sequence is moved after it.
				$newid = 0;
				$resql = $db->query("SELECT MAX(id) as maxid FROM ".MAIN_DB_PREFIX."c_paiement");
				if ($resql && ($obj = $db->fetch_object($resql))) {
					$newid = ((int) $obj->maxid) + 1;
				}
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_paiement (id, entity, code, libelle, type, active, position)";
				$sql .= " VALUES (".((int) $newid).", ".((int) $conf->entity).", '".$db->escape($paycode)."', '".$db->escape($label)."', 2, 1, 0)";
				if ($newid <= 0 || !$db->query($sql)) {
					$out[] = array('type' => 'error', 'msg' => $langs->transnoentities('PosplusFxError', $paycode, $db->lasterror()));
					continue;
				}
				if ($db->type == 'pgsql') {
					$db->query("SELECT setval(pg_get_serial_sequence('".$db->escape(MAIN_DB_PREFIX."c_paiement")."', 'id'), GREATEST((SELECT MAX(id) FROM ".MAIN_DB_PREFIX."c_paiement), 1))");
				}
				$out[] = array('type' => 'info', 'msg' => $langs->transnoentities('PosplusFxModeCreated', $paycode, $label));
			}
		}

		// Terminals without an account for the mode get the cash box (only when there is one)
		if (count($cashboxes) > 1) {
			$missing = false;
			for ($t = 1; $t <= $nbterminals; $t++) {
				if (posplus_get_mode_bankaccount($paycode, (string) $t) <= 0) {
					$missing = true;
				}
			}
			if ($missing) {
				$out[] = array('type' => 'warning', 'msg' => $langs->transnoentities('PosplusFxSeveralCashBoxes', $currency, $paycode));
			}
			continue;
		}
		$accountid = (int) key($cashboxes);
		$accountref = reset($cashboxes);
		for ($t = 1; $t <= $nbterminals; $t++) {
			if (posplus_get_mode_bankaccount($paycode, (string) $t) > 0) {
				continue;
			}
			if ($dryrun && $oldcode !== '' && posplus_get_mode_bankaccount($oldcode, (string) $t) > 0) {
				continue; // Comes with the rename
			}
			if ($dryrun) {
				$out[] = array('type' => 'warning', 'msg' => $langs->transnoentities('PosplusFxTerminalToAssign', $t, $paycode, $accountref));
			} elseif (dolibarr_set_const($db, 'CASHDESK_ID_BANKACCOUNT_'.$paycode.$t, (string) $accountid, 'chaine', 0, '', $conf->entity) > 0) {
				$out[] = array('type' => 'info', 'msg' => $langs->transnoentities('PosplusFxTerminalAssigned', $t, $paycode, $accountref));
			} else {
				$out[] = array('type' => 'error', 'msg' => $langs->transnoentities('PosplusFxError', $paycode, $db->lasterror()));
			}
		}
	}

	return $out;
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
