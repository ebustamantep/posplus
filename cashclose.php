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
 * \file    posplus/cashclose.php
 * \ingroup posplus
 * \brief   Cash close report for TakePOS. Shows a date selector, invoice totals
 *          (issued count, total TTC, base HT, VAT) and payment breakdown by mode.
 */

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
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once __DIR__.'/lib/posplus.lib.php';

// Translations
$langs->loadLangs(array('posplus@posplus', 'bills', 'cashdesk'));

// Security
if (!isModEnabled('posplus')) {
	accessforbidden("Module posplus not enabled");
}
if (!$user->hasRight('takepos', 'run')) {
	accessforbidden();
}

/*
 * View
 */

$form = new Form($db);

$conf->dol_hide_topmenu = 1;
$selfurl = dol_buildpath('/posplus/cashclose.php', 1);
$conf->dol_hide_leftmenu = 1;

$terminal = posplus_get_terminal();

// Hours (today, date of printing) use the timezone of the operator, not the hosting one.
$defaultdate = posplus_print_date(dol_now(), '%Y-%m-%d');
$selecteddate = GETPOST('date', 'aZ09', 1);
if (empty($selecteddate)) {
	$selecteddate = $defaultdate;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selecteddate)) {
	$selecteddate = $defaultdate;
}

// Build the day range (full date range)
$year = (int) substr($selecteddate, 0, 4);
$month = (int) substr($selecteddate, 5, 2);
$day = (int) substr($selecteddate, 8, 2);
$datestart = dol_mktime(0, 0, 0, $month, $day, $year, posplus_tz());
$dateend = dol_mktime(23, 59, 59, $month, $day, $year, posplus_tz());
if ($datestart === '' || $dateend === '') {
	$selecteddate = $defaultdate;
	$datestart = dol_mktime(0, 0, 0, (int) substr($selecteddate, 5, 2), (int) substr($selecteddate, 8, 2), (int) substr($selecteddate, 0, 4), posplus_tz());
	$dateend = dol_mktime(23, 59, 59, (int) substr($selecteddate, 5, 2), (int) substr($selecteddate, 8, 2), (int) substr($selecteddate, 0, 4), posplus_tz());
}
// f.datef and b.dateo are DATE columns (calendar day as stored by the core): they are
// compared with the selected calendar day as is, without any timezone conversion.
$sqldaystart = $db->escape($selecteddate).' 00:00:00';
$sqldayend = $db->escape($selecteddate).' 23:59:59';

print '<style>
body { background-color: #fff; padding: 10px; font-size: 14px; }
#cashclosebox { max-width: 700px; margin: auto; }
table.posclose { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
table.posclose th, table.posclose td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
table.posclose th { background-color: #f0f0f0; }
table.posclose td.right, table.posclose th.right { text-align: right; }
.posclose-title { font-size: 1.4em; font-weight: bold; margin-bottom: 10px; }
.posclose-subtitle { margin-bottom: 15px; opacity: 0.8; }
.posclose-company { font-size: 1.3em; font-weight: bold; text-align: center; margin-bottom: 10px; }
.posclose-total { font-weight: bold; }
.posclose-btn { margin-bottom: 15px; }
.posclose-date { margin-bottom: 15px; }

/* Printing on a 80mm thermal receipt printer */
@media print {
	@page { size: 80mm auto; margin: 2mm; }
	html, body {
		width: 76mm;               /* printable width of 80mm roll (minus margins) */
		margin: 0; padding: 0;
		font-family: "Courier New", monospace;
		font-size: 9px;
		line-height: 1.25;
		color: #000;
	}
	#cashclosebox { max-width: 76mm; margin: 0; }
	table.posclose { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
	table.posclose th, table.posclose td {
		border: none; padding: 1px 0; text-align: left; font-size: 9px;
	}
	.posclose-title { font-size: 12px; text-align: center; }
	.posclose-subtitle { font-size: 9px; opacity: 1; margin-bottom: 4px; }
	.posclose-company { font-size: 12px; text-align: center; margin-bottom: 4px; }
	.posclose-btn, .posclose-date { display: none; }
}
</style>';

print '<div id="cashclosebox">';

// Header shown both on screen and on the printed ticket.
// Force dd/mm/yyyy (day/month/year) independent of the installation language.
$selecteddate_dmy = posplus_print_date($datestart, '%d/%m/%Y');
$printdate = posplus_print_date(dol_now(), '%d/%m/%Y %I:%M %p');

print '<div class="posclose-company">'.dol_escape_htmltag($mysoc->name).'</div>';
print '<div class="posclose-title">'.$langs->trans('PosplusCashClose').'</div>';

// Terminal
$terminalname = getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$terminal);
if ($terminalname == 'TAKEPOS_TERMINAL_NAME_'.$terminal) {
	$terminalname = '';
}
if ($terminalname && strpos($terminalname, (string) $terminal) !== false) {
	$termlabel = $terminalname;
} else {
	$termlabel = $terminal.($terminalname ? ' - '.$terminalname : '');
}
print '<div class="posclose-subtitle">'.$langs->trans('PosplusTerminal').': '.dol_escape_htmltag($termlabel).'</div>';

// Date + printing date (single line, no duplication)
print '<div class="posclose-subtitle">'.$langs->trans('PosplusCashCloseDate').': '.$selecteddate_dmy.' &nbsp;&nbsp; '.$langs->trans('DateOfPrinting').': '.$printdate.'</div>';

// Print button (hidden when printing)
print '<div class="posclose-btn"><button type="button" onclick="window.print()">'.$langs->trans('Print').'</button></div>';

// Date selector: native date input with calendar popup. Reloads when the date changes.
print '<div class="posclose-date">';
print '<input type="date" value="'.dol_escape_htmltag($selecteddate).'" onchange="location.href=\''.dol_escape_js($selfurl).'?date=\'+this.value">';
print '</div>';

if ($terminal == '') {
	print '<div class="error">'.$langs->trans('PosplusNoData').'</div>';
} else {
	// Every amount of an invoice is shown in the currency of the invoice: the invoices in the
	// main currency with their amounts, the invoices in another currency (e.g. VEF) with their
	// multicurrency amounts (as the report Z).
	$maincur = $db->escape($conf->currency);
	$sqlcurexpr = "CASE WHEN f.multicurrency_code IS NULL OR f.multicurrency_code = '' THEN '".$maincur."' ELSE f.multicurrency_code END";
	$sqlinmain = "(f.multicurrency_code IS NULL OR f.multicurrency_code = '' OR f.multicurrency_code = '".$maincur."')";

	// Invoices totals per currency: only validated/paid invoices (1, 2). Drafts and abandoned (3) are out.
	$sql = "SELECT ".$sqlcurexpr." AS cur, COUNT(*) AS nb,";
	$sql .= " COALESCE(SUM(CASE WHEN ".$sqlinmain." THEN f.total_ht ELSE f.multicurrency_total_ht END), 0) AS ht,";
	$sql .= " COALESCE(SUM(CASE WHEN ".$sqlinmain." THEN f.total_tva ELSE f.multicurrency_total_tva END), 0) AS tva,";
	$sql .= " COALESCE(SUM(CASE WHEN ".$sqlinmain." THEN f.total_ttc ELSE f.multicurrency_total_ttc END), 0) AS ttc";
	$sql .= " FROM ".MAIN_DB_PREFIX."facture AS f";
	$sql .= " WHERE f.entity IN (".getEntity('facture').")";
	$sql .= " AND f.module_source = 'takepos'";
	$sql .= " AND f.pos_source = '".$db->escape($terminal)."'";
	$sql .= " AND f.fk_statut IN (1, 2)";
	$sql .= " AND f.type != 2"; // Credit notes are accounted in their own section
	$sql .= " AND f.datef BETWEEN '".$sqldaystart."' AND '".$sqldayend."'";
	$sql .= " GROUP BY ".$sqlcurexpr;

	// Per currency: nb, ht, tva, ttc, returns, pending
	$bycur = array();
	$emptycur = array('nb' => 0, 'ht' => 0, 'tva' => 0, 'ttc' => 0, 'returns' => 0, 'pending' => 0);
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$bycur[$obj->cur] = $emptycur;
			$bycur[$obj->cur]['nb'] = (int) $obj->nb;
			$bycur[$obj->cur]['ht'] = (float) $obj->ht;
			$bycur[$obj->cur]['tva'] = (float) $obj->tva;
			$bycur[$obj->cur]['ttc'] = (float) $obj->ttc;
		}
		$db->free($resql);
	}

	// Payment breakdown based on what the bank account registered: llx_bank.amount is the
	// amount in the account currency and llx_bank.amount_main_currency the equivalent in the
	// main company currency. Grouped by payment mode of the PAYMENT (p.fk_paiement: on a cash
	// box the bank line is always LIQ, core rule) and by bank account, and split into inflows
	// (collections) and outflows (refunds/reversals).
	$sql2 = "SELECT cp.code AS code, cp.libelle AS label, ba.rowid AS accountid, ba.label AS accountlabel,";
	$sql2 .= " ba.courant AS accounttype, ba.currency_code AS cur,";
	$sql2 .= " COUNT(*) AS nb,";
	$sql2 .= " COALESCE(SUM(CASE WHEN b.amount >= 0 THEN b.amount ELSE 0 END), 0) AS in_cur,";
	$sql2 .= " COALESCE(SUM(CASE WHEN b.amount < 0 THEN -b.amount ELSE 0 END), 0) AS out_cur,";
	$sql2 .= " COALESCE(SUM(CASE WHEN b.amount >= 0 THEN b.amount_main_currency ELSE 0 END), 0) AS in_main,";
	$sql2 .= " COALESCE(SUM(CASE WHEN b.amount < 0 THEN -b.amount_main_currency ELSE 0 END), 0) AS out_main";
	$sql2 .= " FROM ".MAIN_DB_PREFIX."bank AS b";
	$sql2 .= " JOIN ".MAIN_DB_PREFIX."bank_account AS ba ON ba.rowid = b.fk_account";
	$sql2 .= " JOIN ".MAIN_DB_PREFIX."paiement AS p ON p.fk_bank = b.rowid";
	$sql2 .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement AS cp ON cp.id = p.fk_paiement";
	$sql2 .= " WHERE p.entity IN (".getEntity('paiement').")";
	// Sales of this terminal plus the returns made from this POS (credit notes marked at
	// creation by EasyBack with module_source='easyback' + pos_source=<terminal>), so the
	// refund nets in its original payment mode. Returns made from the backoffice are not
	// part of the POS transactions and stay out of the close.
	// EXISTS (not a JOIN) so a bank line is counted once even if its payment covers
	// several invoices. The money really moved, so any non draft invoice counts.
	$sql2 .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."paiement_facture AS pf";
	$sql2 .= "   JOIN ".MAIN_DB_PREFIX."facture AS f ON f.rowid = pf.fk_facture";
	$sql2 .= "   WHERE pf.fk_paiement = p.rowid";
	$sql2 .= "   AND f.entity IN (".getEntity('facture').")";
	$sql2 .= "   AND f.fk_statut >= 1";
	$sql2 .= "   AND ( (f.module_source = 'takepos' AND f.pos_source = '".$db->escape($terminal)."')";
	$sql2 .= "     OR (f.type = 2 AND f.module_source = 'easyback' AND f.pos_source = '".$db->escape($terminal)."') ) )";
	$sql2 .= " AND b.dateo BETWEEN '".$sqldaystart."' AND '".$sqldayend."'";
	$sql2 .= " GROUP BY cp.code, cp.libelle, ba.rowid, ba.label, ba.courant, ba.currency_code";
	$sql2 .= " ORDER BY cp.libelle, ba.label";

	$payments = array();
	$resql2 = $db->query($sql2);
	if ($resql2) {
		while ($obj2 = $db->fetch_object($resql2)) {
			$incur = (float) $obj2->in_cur;
			$outcur = (float) $obj2->out_cur;
			$inmain = (float) $obj2->in_main;
			$outmain = (float) $obj2->out_main;
			$payments[] = array(
				'code' => $obj2->code,
				'label' => posplus_payment_label((string) $obj2->code, (string) $obj2->label),
				'accountid' => (int) $obj2->accountid,
				'accountlabel' => $obj2->accountlabel,
				'iscash' => ((int) $obj2->accounttype == Account::TYPE_CASH),
				'cur' => ($obj2->cur ? $obj2->cur : $conf->currency),
				'nb' => (int) $obj2->nb,
				'in_cur' => $incur,
				'out_cur' => $outcur,
				'total_cur' => price2num($incur - $outcur, 'MT'),
				'in_main' => $inmain,
				'out_main' => $outmain,
				'total_main' => price2num($inmain - $outmain, 'MT'),
			);
		}
		$db->free($resql2);
	}

	// Returns of the day: credit notes created from the POS. EasyBack marks them at
	// creation with module_source='easyback' and pos_source=<terminal>, so returns made
	// from the backoffice are NOT counted here (they are not POS transactions).
	$sql4 = "SELECT fcn.rowid, fcn.ref, f.ref AS origin_ref, s.nom AS client, s.tva_intra,";
	$sql4 .= " ".str_replace('f.', 'fcn.', $sqlcurexpr)." AS cur,";
	$sql4 .= " ABS(CASE WHEN ".str_replace('f.', 'fcn.', $sqlinmain)." THEN fcn.total_ttc ELSE fcn.multicurrency_total_ttc END) AS ttc";
	$sql4 .= " FROM ".MAIN_DB_PREFIX."facture AS fcn";
	$sql4 .= " LEFT JOIN ".MAIN_DB_PREFIX."facture AS f ON f.rowid = fcn.fk_facture_source";
	$sql4 .= " LEFT JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = fcn.fk_soc";
	$sql4 .= " WHERE fcn.entity IN (".getEntity('facture').")";
	$sql4 .= " AND fcn.type = 2";
	$sql4 .= " AND fcn.fk_statut IN (1, 2)";
	$sql4 .= " AND fcn.module_source = 'easyback'";
	$sql4 .= " AND fcn.pos_source = '".$db->escape($terminal)."'";
	$sql4 .= " AND fcn.datef BETWEEN '".$sqldaystart."' AND '".$sqldayend."'";
	$sql4 .= " ORDER BY fcn.ref";

	$returns = array();
	$resql4 = $db->query($sql4);
	if ($resql4) {
		while ($obj4 = $db->fetch_object($resql4)) {
			$returns[] = array(
				'ref' => $obj4->ref,
				'origin_ref' => $obj4->origin_ref,
				'client' => $obj4->client,
				'intra' => $obj4->tva_intra,
				'cur' => $obj4->cur,
				'ttc' => (float) $obj4->ttc,
			);
			if (!isset($bycur[$obj4->cur])) {
				$bycur[$obj4->cur] = $emptycur;
			}
			$bycur[$obj4->cur]['returns'] += (float) $obj4->ttc;
		}
		$db->free($resql4);
	}

	// Main currency first, then the others
	uksort($bycur, function ($a, $b) use ($conf) {
		return ($a == $conf->currency ? -1 : ($b == $conf->currency ? 1 : strcmp($a, $b)));
	});

	// Section: invoices issued, one column per currency of the invoices
	print '<table class="posclose">';
	print '<tr><th>'.$langs->trans('PosplusInvoicesIssued').'</th>';
	foreach (array_keys($bycur) as $cur) {
		print '<th class="right">'.dol_escape_htmltag($cur).'</th>';
	}
	print '</tr>';
	if (count($bycur) == 0) {
		print '<tr><td>'.$langs->trans('PosplusInvoicesIssued').'</td><td class="right">0</td></tr>';
	} else {
		$rows = array('nb' => 'PosplusInvoicesIssued', 'ht' => 'PosplusBase', 'tva' => 'PosplusVAT', 'ttc' => 'PosplusTotalInvoiced');
		foreach ($rows as $field => $label) {
			print '<tr'.($field == 'ttc' ? ' class="posclose-total"' : '').'><td>'.$langs->trans($label).'</td>';
			foreach ($bycur as $cur => $vals) {
				print '<td class="right">'.($field == 'nb' ? (int) $vals['nb'] : posplus_price($vals[$field], $cur)).'</td>';
			}
			print '</tr>';
		}
	}
	print '</table>';

	// Section: returns of the day
	print '<table class="posclose">';
	print '<tr><th colspan="4">'.$langs->trans('PosplusReturnsTitle').'</th></tr>';
	if (count($returns) > 0) {
		print '<tr><th>'.$langs->trans('PosplusReturnsRef').'</th><th>'.$langs->trans('PosplusReturnsOrigin').'</th><th>'.$langs->trans('PosplusReturnsCustomer').'</th><th class="right">'.$langs->trans('PosplusReturnsAmount').'</th></tr>';
		foreach ($returns as $ret) {
			$retclient = $ret['client'];
			if (!empty($ret['intra'])) {
				$retclient .= ' ('.$langs->transnoentities('ProfId1ES').': '.$ret['intra'].')';
			}
			if (empty($retclient)) {
				$retclient = '-';
			}
			print '<tr><td>'.dol_escape_htmltag($ret['ref']).'</td><td>'.dol_escape_htmltag($ret['origin_ref']).'</td><td>'.dol_escape_htmltag($retclient).'</td><td class="right">'.posplus_price($ret['ttc'], $ret['cur']).'</td></tr>';
		}
		foreach ($bycur as $cur => $vals) {
			if ($vals['returns'] != 0) {
				print '<tr class="posclose-total"><td colspan="3">'.$langs->trans('PosplusReturnsTitle').' '.dol_escape_htmltag($cur).'</td><td class="right">'.posplus_price($vals['returns'], $cur).'</td></tr>';
			}
		}
	} else {
		print '<tr><td colspan="4">'.$langs->trans('PosplusNoData').'</td></tr>';
	}
	print '</table>';

	// Net total (sales - returns) shown after the returns so the report reads in order:
	// invoiced, returns, net, then how it was paid.
	print '<table class="posclose">';
	foreach ($bycur as $cur => $vals) {
		print '<tr class="posclose-total"><td>'.$langs->trans('PosplusNetTotal').' '.dol_escape_htmltag($cur).'</td><td class="right">'.posplus_price($vals['ttc'] - $vals['returns'], $cur).'</td></tr>';
	}
	if (count($bycur) == 0) {
		print '<tr class="posclose-total"><td>'.$langs->trans('PosplusNetTotal').'</td><td class="right">'.posplus_price(0, $conf->currency).'</td></tr>';
	}
	print '</table>';

	// Section title: payment breakdown
	print '<table class="posclose">';
	print '<tr><th colspan="5">'.$langs->trans('PosplusPaymentsBreakdown').'</th></tr>';
	if (count($payments) > 0) {
		print '<tr><th>'.$langs->trans('PosplusPaymentMode').'</th><th>'.$langs->trans('PosplusCurrency').'</th><th class="right">'.$langs->trans('PosplusInflows').'</th><th class="right">'.$langs->trans('PosplusOutflows').'</th><th class="right">'.$langs->trans('Total').'</th></tr>';
		foreach ($payments as $pay) {
			$curlabel = ($pay['cur'] !== '' && $pay['cur'] !== null ? $pay['cur'] : '');
			// Inflows / outflows / total as registered in the bank account currency
			$inpos = ($curlabel ? posplus_price($pay['in_cur'], $curlabel) : posplus_price($pay['in_cur']).' '.$curlabel);
			$outpos = ($curlabel ? posplus_price($pay['out_cur'], $curlabel) : posplus_price($pay['out_cur']).' '.$curlabel);
			$totpos = ($curlabel ? posplus_price($pay['total_cur'], $curlabel) : posplus_price($pay['total_cur']).' '.$curlabel);
			print '<tr><td>'.dol_escape_htmltag($pay['label']).'<br><span class="opacitymedium">'.dol_escape_htmltag($pay['accountlabel']).'</span></td><td>'.($curlabel ? dol_escape_htmltag($curlabel) : '-').'</td><td class="right">'.$inpos.'</td><td class="right">'.$outpos.'</td><td class="right">'.$totpos.'</td></tr>';
			// Equivalent in the main company currency (only when the account currency differs)
			if ($curlabel !== '' && $curlabel != $conf->currency) {
				print '<tr><td></td><td>'.$conf->currency.'</td><td class="right">'.posplus_price($pay['in_main'], $conf->currency).'</td><td class="right">'.posplus_price($pay['out_main'], $conf->currency).'</td><td class="right">'.posplus_price($pay['total_main'], $conf->currency).'</td></tr>';
			}
		}
	} else {
		print '<tr><td colspan="5">'.$langs->trans('PosplusNoData').'</td></tr>';
	}
	print '</table>';

	// Section: collected per currency. What entered in each currency, whatever the payment
	// mode (cash, card, mobile payment...): a mixed payment ($ + Bs) adds each part to its
	// currency. Collections minus refunds of the day, in the currency of the accounts.
	$collected = array();
	foreach ($payments as $pay) {
		if (!isset($collected[$pay['cur']])) {
			$collected[$pay['cur']] = array('in' => 0, 'out' => 0);
		}
		$collected[$pay['cur']]['in'] += $pay['in_cur'];
		$collected[$pay['cur']]['out'] += $pay['out_cur'];
	}
	uksort($collected, function ($a, $b) use ($conf) {
		return ($a == $conf->currency ? -1 : ($b == $conf->currency ? 1 : strcmp($a, $b)));
	});
	print '<table class="posclose">';
	print '<tr><th colspan="4">'.$langs->trans('PosplusCollectedByCurrency').'</th></tr>';
	if (count($collected) > 0) {
		print '<tr><th>'.$langs->trans('PosplusCurrency').'</th><th class="right">'.$langs->trans('PosplusInflows').'</th><th class="right">'.$langs->trans('PosplusOutflows').'</th><th class="right">'.$langs->trans('Total').'</th></tr>';
		foreach ($collected as $cur => $vals) {
			print '<tr><td>'.dol_escape_htmltag($cur).'</td><td class="right">'.posplus_price($vals['in'], $cur).'</td><td class="right">'.posplus_price($vals['out'], $cur).'</td>';
			print '<td class="right posclose-total">'.posplus_price(price2num($vals['in'] - $vals['out'], 'MT'), $cur).'</td></tr>';
		}
	} else {
		print '<tr><td colspan="4">'.$langs->trans('PosplusNoData').'</td></tr>';
	}
	print '</table>';

	// Section: cash per currency. What must physically be in each cash box (bank account of
	// type "Cash"), whatever the payment mode used (LIQ, EFBS...): collections minus
	// refunds of the day, in the currency of the box.
	$cashboxes = array();
	foreach ($payments as $pay) {
		if (empty($pay['iscash'])) {
			continue;
		}
		$key = $pay['accountid'];
		if (!isset($cashboxes[$key])) {
			$cashboxes[$key] = array('label' => $pay['accountlabel'], 'cur' => $pay['cur'], 'in' => 0, 'out' => 0);
		}
		$cashboxes[$key]['in'] += $pay['in_cur'];
		$cashboxes[$key]['out'] += $pay['out_cur'];
	}
	print '<table class="posclose">';
	print '<tr><th colspan="4">'.$langs->trans('PosplusCashByCurrency').'</th></tr>';
	if (count($cashboxes) > 0) {
		print '<tr><th>'.$langs->trans('PosplusCashBox').'</th><th class="right">'.$langs->trans('PosplusInflows').'</th><th class="right">'.$langs->trans('PosplusOutflows').'</th><th class="right">'.$langs->trans('PosplusCashExpected').'</th></tr>';
		foreach ($cashboxes as $box) {
			print '<tr><td>'.dol_escape_htmltag($box['label']).' ('.dol_escape_htmltag($box['cur']).')</td>';
			print '<td class="right">'.posplus_price($box['in'], $box['cur']).'</td>';
			print '<td class="right">'.posplus_price($box['out'], $box['cur']).'</td>';
			print '<td class="right posclose-total">'.posplus_price(price2num($box['in'] - $box['out'], 'MT'), $box['cur']).'</td></tr>';
		}
	} else {
		print '<tr><td colspan="4">'.$langs->trans('PosplusNoData').'</td></tr>';
	}
	print '</table>';

	// Section: pending to collect (validated, not yet paid invoices with a remaining balance)
	// Pending = total_ttc - payments - credit notes/deposits applied (same as getRemainToPay()).
	// Only unpaid invoices of this terminal (fk_statut = 1), of the day, not credit notes.
	// Subqueries (no GROUP BY/HAVING on an alias) so the query also runs on PostgreSQL.
	$sql3 = "SELECT f.rowid, f.ref, s.nom AS client, s.tva_intra, ".$sqlcurexpr." AS cur,";
	$sql3 .= " CASE WHEN ".$sqlinmain." THEN f.total_ttc ELSE f.multicurrency_total_ttc END AS total_ttc,";
	$sql3 .= " (SELECT COALESCE(SUM(CASE WHEN ".$sqlinmain." THEN pf.amount ELSE pf.multicurrency_amount END), 0) FROM ".MAIN_DB_PREFIX."paiement_facture AS pf WHERE pf.fk_facture = f.rowid) AS paid,";
	$sql3 .= " (SELECT COALESCE(SUM(CASE WHEN ".$sqlinmain." THEN re.amount_ttc ELSE re.multicurrency_amount_ttc END), 0) FROM ".MAIN_DB_PREFIX."societe_remise_except AS re WHERE re.fk_facture = f.rowid) AS credits";
	$sql3 .= " FROM ".MAIN_DB_PREFIX."facture AS f";
	$sql3 .= " LEFT JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = f.fk_soc";
	$sql3 .= " WHERE f.entity IN (".getEntity('facture').")";
	$sql3 .= " AND f.module_source = 'takepos'";
	$sql3 .= " AND f.pos_source = '".$db->escape($terminal)."'";
	$sql3 .= " AND f.fk_statut = 1";
	$sql3 .= " AND f.type <> 2";
	$sql3 .= " AND f.datef BETWEEN '".$sqldaystart."' AND '".$sqldayend."'";
	$sql3 .= " ORDER BY f.ref";

	$pending = array();
	$resql3 = $db->query($sql3);
	if ($resql3) {
		while ($obj3 = $db->fetch_object($resql3)) {
			$pendingamount = (float) price2num((float) $obj3->total_ttc - (float) $obj3->paid - (float) $obj3->credits, 'MT');
			if ($pendingamount <= 0) {
				continue;
			}
			$pending[] = array(
				'rowid' => (int) $obj3->rowid,
				'ref' => $obj3->ref,
				'total_ttc' => (float) $obj3->total_ttc,
				'client' => $obj3->client,
				'intra' => $obj3->tva_intra,
				'paid' => (float) $obj3->paid,
				'pending' => $pendingamount,
				'cur' => $obj3->cur,
			);
		}
		$db->free($resql3);
	}

	print '<table class="posclose">';
	print '<tr><th colspan="3">'.$langs->trans('PosplusPendingTitle').'</th></tr>';
	if (count($pending) > 0) {
		print '<tr><th>'.$langs->trans('PosplusPendingInvoice').'</th><th>'.$langs->trans('PosplusPendingCustomer').'</th><th class="right">'.$langs->trans('PosplusPendingAmount').'</th></tr>';
		$totalpending = array();
		foreach ($pending as $p) {
			$totalpending[$p['cur']] = (isset($totalpending[$p['cur']]) ? $totalpending[$p['cur']] : 0) + $p['pending'];
			$clientlabel = $p['client'];
			if (!empty($p['intra'])) {
				$clientlabel .= ' ('.$langs->transnoentities('ProfId1ES').': '.$p['intra'].')';
			}
			if (empty($clientlabel)) {
				$clientlabel = '-';
			}
			print '<tr><td>'.dol_escape_htmltag($p['ref']).'</td><td>'.dol_escape_htmltag($clientlabel).'</td><td class="right">'.posplus_price($p['pending'], $p['cur']).'</td></tr>';
		}
		foreach ($totalpending as $cur => $amount) {
			print '<tr class="posclose-total"><td colspan="2">'.$langs->trans('PosplusPendingTitle').' '.dol_escape_htmltag($cur).'</td><td class="right">'.posplus_price($amount, $cur).'</td></tr>';
		}
	} else {
		print '<tr><td colspan="3">'.$langs->trans('PosplusNoData').'</td></tr>';
	}
	print '</table>';
}

print '</div>';
$db->close();
