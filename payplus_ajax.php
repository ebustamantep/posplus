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
 *          The payment itself (validation, stock, payment, closing, as takepos/invoice.php
 *          action=valid) is done by PosplusPayment; the popup then shows/prints the ticket
 *          through the core.
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
require_once __DIR__.'/lib/posplus.lib.php';
require_once __DIR__.'/class/pospluspayment.class.php';

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

// Payment as sent by the popup. The amount is in the currency of the box/account of the mode
// ('currency'): in the main currency, or in the invoice currency for an account of that
// currency (e.g. Bs). The conversion is done with the rate stored on the invoice, never with a
// rate sent by the browser. 'excess' is the cash received above the remain to pay (change given
// back), in the main currency. 'num_payment' is the reference (confirmation number), stored in
// num_paiement and in the bank line.
$data = array(
	'pay' => GETPOST('pay', 'aZ09'),
	'amount' => GETPOSTFLOAT('amount'),
	'currency' => GETPOST('currency', 'aZ09'),
	'excess' => GETPOSTFLOAT('excess'),
	'accountid' => GETPOSTINT('accountid'),
	'num_payment' => dol_trunc(trim(GETPOST('num_payment', 'alphanohtml')), 50, 'right', 'UTF-8', 1),
);

$pospayment = new PosplusPayment($db);
$result = $pospayment->addPayment($user, $invoice, $terminal, $data);

if ($result === false) {
	print json_encode(array('error' => $pospayment->error ? $pospayment->error : $langs->transnoentities('Error')));
} else {
	print json_encode(array(
		'success' => true,
		'remaintopay' => $result['remaintopay'],
		'remaintopay_mc' => $result['remaintopay_mc'],
		'currency_mc' => $result['currency_mc'],
		'invoiceid' => $result['invoiceid'],
		'paid' => $result['paid'],
	));
}
$db->close();
