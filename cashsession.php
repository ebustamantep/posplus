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
 * \file    posplus/cashsession.php
 * \ingroup posplus
 * \brief   Cash count of the TakePOS terminal (popup): opening of the shift with the float
 *          of each cash box, cash in / withdrawals, cash count by denominations (blind
 *          if configured), report X (close of the shift), report Z (close of the day)
 *          and the 80 mm ticket of the count.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1'); // Popup of the POS: keep the token of the POS page
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
 * @var Translate $langs
 * @var User $user
 * @var Societe $mysoc
 */

require_once __DIR__.'/lib/posplus.lib.php';
require_once __DIR__.'/class/posplussession.class.php';

$langs->loadLangs(array('posplus@posplus', 'bills', 'cashdesk', 'banks', 'errors'));

// Security
if (!isModEnabled('posplus') || !getDolGlobalInt('POSPLUS_CASHCOUNT')) {
	accessforbidden('Module posplus or its cash count is not enabled');
}
if (!$user->hasRight('takepos', 'run')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$type = (GETPOST('type', 'aZ09') == 'z' ? 'z' : 'x');
$terminal = posplus_get_terminal();
$canz = $user->hasRight('posplus', 'arqueo', 'closez');
$blind = getDolGlobalInt('POSPLUS_CASHCOUNT_BLIND');
$selfurl = dol_buildpath('/posplus/cashsession.php', 1);

if ($terminal === '') {
	accessforbidden($langs->trans('PosplusErrorNoTerminal'));
}

/**
 * Read the counted denominations of a cash box from the form (qty_<account>_<index>
 * for each denomination of the currency, plus other_<account>).
 *
 * @param int    $accountid Bank account id
 * @param string $currency  Currency
 * @return array<string,float> Map value => quantity, plus 'other' => amount
 */
function posplus_read_denominations($accountid, $currency)
{
	$denoms = array();
	foreach (PosplusCashSession::getDenominations($currency) as $idx => $value) {
		$qty = (float) price2num(GETPOST('qty_'.$accountid.'_'.$idx, 'alpha'), 'MS');
		if ($qty > 0) {
			$denoms[(string) $value] = $qty;
		}
	}
	$other = (float) price2num(GETPOST('other_'.$accountid, 'alpha'), 'MT');
	if ($other != 0) {
		$denoms['other'] = $other;
	}
	return $denoms;
}

$session = new PosplusCashSession($db);
$hasopen = ($session->fetchOpen($terminal) > 0);
$dayclosed = posplus_get_closed_cashcontrol($db, $terminal);
$errors = array();
$messages = array();

/*
 * Actions (POST + token)
 */

if ($action == 'open' && $_SERVER['REQUEST_METHOD'] == 'POST') {
	$openings = array();
	foreach (PosplusCashSession::getCashBoxes($db, $terminal) as $accountid => $box) {
		$openings[$accountid] = posplus_read_denominations($accountid, $box['currency']);
	}
	if ($session->create($user, $terminal, $openings) > 0) {
		$hasopen = true;
		$messages[] = $langs->trans('PosplusCashSessionOpened', $session->ref);
		$action = 'opened';
	} else {
		$errors[] = $session->error;
		$action = '';
	}
}

if ($action == 'addmovement' && $_SERVER['REQUEST_METHOD'] == 'POST' && $hasopen) {
	$accountid = GETPOSTINT('accountid');
	$amount = (float) price2num(GETPOST('amount', 'alpha'), 'MT');
	$sign = (GETPOST('direction', 'aZ09') == 'out' ? -1 : 1);
	if ($amount <= 0 || trim(GETPOST('reason', 'alphanohtml')) === '') {
		$errors[] = $langs->trans('PosplusCashMovementRequired');
		$action = 'movement';
	} elseif ($session->addMovement($user, $accountid, $sign * $amount, GETPOST('reason', 'alphanohtml')) > 0) {
		$messages[] = $langs->trans('PosplusCashMovementSaved');
		$action = '';
	} else {
		$errors[] = $session->error;
		$action = 'movement';
	}
}

if ($action == 'close' && $_SERVER['REQUEST_METHOD'] == 'POST' && $hasopen) {
	if ($type == 'z' && !$canz) {
		accessforbidden();
	}
	$declared = array('cash' => array(), 'modes' => array());
	foreach ($session->computeLines() as $key => $l) {
		if ($l['iscash']) {
			$declared['cash'][$l['fk_account']] = posplus_read_denominations($l['fk_account'], $l['currency']);
		} else {
			$declared['modes'][$key] = (float) price2num(GETPOST('decl_'.md5($key), 'alpha'), 'MT');
		}
	}
	if ($session->close($user, $declared, ($type == 'z')) > 0) {
		header('Location: '.$selfurl.'?action=ticket&id='.((int) $session->id).'&token='.currentToken());
		exit;
	}
	$errors[] = $session->error;
	$action = 'count';
}

if ($action == 'ticket') {
	$ticket = new PosplusCashSession($db);
	if ($ticket->fetch($id) <= 0 || ($ticket->terminal !== $terminal && !$user->hasRight('posplus', 'arqueo', 'read'))) {
		accessforbidden();
	}
}

/*
 * View
 */

/**
 * Denomination grid of a cash box (quantity per bill/coin + other amount + live total).
 *
 * @param int                $accountid Bank account id
 * @param array{label:string,currency:string} $box Cash box
 * @param array<string,mixed> $prefill  Previous denominations
 * @return string
 */
function posplus_denomination_grid($accountid, $box, $prefill = array())
{
	global $langs;

	$cur = $box['currency'];
	$out = '<div class="pp-box" data-account="'.((int) $accountid).'" data-currency="'.dol_escape_htmltag($cur).'">';
	$out .= '<div class="pp-boxtitle">'.dol_escape_htmltag($box['label']).' <span class="pp-cur">'.dol_escape_htmltag($cur).'</span></div>';
	$out .= '<table class="pp-denoms">';
	foreach (PosplusCashSession::getDenominations($cur) as $idx => $value) {
		$qty = (isset($prefill[(string) $value]) ? (float) $prefill[(string) $value] : '');
		$out .= '<tr><td class="pp-dval">'.dol_escape_htmltag(posplus_ticket_amount($value, $cur)).'</td>';
		$out .= '<td>x <input type="number" min="0" step="1" inputmode="numeric" class="pp-qty" data-value="'.((float) $value).'" name="qty_'.((int) $accountid).'_'.$idx.'" value="'.dol_escape_htmltag((string) $qty).'"></td>';
		$out .= '<td class="pp-sub"></td></tr>';
	}
	$other = (isset($prefill['other']) ? (float) $prefill['other'] : '');
	$out .= '<tr><td class="pp-dval">'.$langs->trans('PosplusCashOther').'</td><td colspan="2"><input type="text" inputmode="decimal" class="pp-other" name="other_'.((int) $accountid).'" value="'.dol_escape_htmltag((string) $other).'" placeholder="0,00"></td></tr>';
	$out .= '</table>';
	$out .= '<div class="pp-total">'.$langs->trans('Total').': <span class="pp-boxtotal">0,00</span> '.dol_escape_htmltag($cur).'</div>';
	$out .= '</div>';
	return $out;
}

/**
 * Amount input of a cash box (opening float: a single amount, no denominations).
 *
 * @param int                $accountid Bank account id
 * @param array{label:string,currency:string} $box Cash box
 * @return string
 */
function posplus_amount_box($accountid, $box)
{
	$cur = $box['currency'];
	$out = '<div class="pp-box">';
	$out .= '<div class="pp-boxtitle">'.dol_escape_htmltag($box['label']).' <span class="pp-cur">'.dol_escape_htmltag($cur).'</span></div>';
	$out .= '<input type="text" inputmode="decimal" class="pp-amount" name="other_'.((int) $accountid).'" placeholder="0,00" autocomplete="off"> '.dol_escape_htmltag($cur);
	$out .= '</div>';
	return $out;
}

top_httphead();
print '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
print '<title>'.$langs->trans('PosplusCashCount').'</title>';
print PosplusCashSession::getTicketCss();
print '<style>
body { background-color: #fff; color: #222; padding: 10px; font-size: 14px; font-family: Arial, Helvetica, sans-serif; }
#ppbox { max-width: 760px; margin: auto; }
h2 { font-size: 1.25em; margin: 0 0 10px 0; }
.pp-msg { background: #e8f5e9; border: 1px solid #a5d6a7; padding: 6px 8px; border-radius: 4px; margin-bottom: 8px; }
.pp-err { background: #fdecea; border: 1px solid #f5c2c0; padding: 6px 8px; border-radius: 4px; margin-bottom: 8px; color: #8a1c1c; }
.pp-warn { background: #fff4e0; border: 1px solid #f0c36d; padding: 6px 8px; border-radius: 4px; margin-bottom: 8px; color: #8a5300; }
.pp-boxes { display: flex; flex-wrap: wrap; gap: 12px; }
.pp-box { border: 1px solid #ddd; border-radius: 6px; padding: 8px 10px; flex: 1 1 300px; background: #fafafa; }
.pp-boxtitle { font-weight: bold; margin-bottom: 6px; }
.pp-cur { font-weight: normal; opacity: 0.7; }
.pp-denoms td { padding: 2px 4px; }
.pp-dval { text-align: right; white-space: nowrap; }
.pp-qty { width: 70px; text-align: right; padding: 4px; font-size: 1em; }
.pp-other { width: 120px; text-align: right; padding: 4px; font-size: 1em; }
.pp-amount { width: 180px; text-align: right; padding: 8px; font-size: 1.3em; }
.pp-sub { text-align: right; opacity: 0.7; white-space: nowrap; }
.pp-total { margin-top: 6px; font-weight: bold; text-align: right; }
.pp-actions { margin-top: 12px; display: flex; flex-wrap: wrap; gap: 8px; }
.pp-actions button, .pp-actions a.pp-btn { font-size: 1em; padding: 10px 14px; cursor: pointer; border: 1px solid #bbb; border-radius: 4px; background: #f2f2f2; color: #222; text-decoration: none; }
.pp-actions .pp-primary { background: #28a745; border-color: #1e7e34; color: #fff; }
.pp-actions .pp-danger { background: #c0392b; border-color: #962d22; color: #fff; }
table.pp-list { width: 100%; border-collapse: collapse; margin-top: 8px; }
table.pp-list th, table.pp-list td { border-bottom: 1px solid #e3e3e3; padding: 4px 6px; text-align: left; }
table.pp-list td.r, table.pp-list th.r { text-align: right; }
.pp-field { margin-bottom: 8px; }
.pp-field label { display: block; font-size: 0.9em; opacity: 0.75; }
.pp-field input, .pp-field select { font-size: 1em; padding: 6px; min-width: 220px; }
</style>';
print '</head><body><div id="ppbox">';

foreach ($messages as $msg) {
	print '<div class="pp-msg noprint">'.dol_escape_htmltag($msg).'</div>';
}
foreach ($errors as $err) {
	print '<div class="pp-err noprint">'.dol_escape_htmltag($langs->trans($err)).'</div>';
}

$token = currentToken();

if ($action == 'ticket') {
	// ---- Ticket of a closed (or open) session ----
	print '<div class="pp-actions noprint"><button type="button" class="pp-primary" onclick="window.print()">'.$langs->trans('Print').'</button>';
	print '<a class="pp-btn" href="'.$selfurl.'">'.$langs->trans('PosplusCashBack').'</a></div><br class="noprint">';
	print $ticket->getTicketHtml();
} elseif ($dayclosed && !$hasopen) {
	// ---- Day already closed (report Z) ----
	print '<h2>'.$langs->trans('PosplusCashCount').'</h2>';
	print '<div class="pp-warn">'.$langs->trans('PosplusCashDayClosed').'</div>';
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."posplus_cashsession WHERE entity = ".((int) $conf->entity)." AND terminal = '".$db->escape($terminal)."' AND status <> 0 ORDER BY rowid DESC";
	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		print '<div class="pp-actions"><a class="pp-btn" href="'.$selfurl.'?action=ticket&id='.((int) $obj->rowid).'&token='.$token.'">'.$langs->trans('PosplusCashLastTicket').'</a></div>';
	}
} elseif (!$hasopen) {
	// ---- Opening of the shift: float of each cash box ----
	print '<h2>'.$langs->trans('PosplusCashOpenTitle').'</h2>';
	print '<p>'.$langs->trans('PosplusCashOpenHelp').'</p>';
	$boxes = PosplusCashSession::getCashBoxes($db, $terminal);
	if (count($boxes) == 0) {
		print '<div class="pp-warn">'.$langs->trans('PosplusCashNoBoxes').'</div>';
	}
	print '<form method="POST" action="'.$selfurl.'">';
	print '<input type="hidden" name="token" value="'.$token.'"><input type="hidden" name="action" value="open">';
	print '<div class="pp-boxes">';
	foreach ($boxes as $accountid => $box) {
		print posplus_amount_box($accountid, $box);
	}
	print '</div>';
	print '<div class="pp-actions"><button type="submit" class="pp-primary">'.$langs->trans('PosplusCashOpenButton').'</button>';
	print '<button type="button" onclick="parent.$.colorbox.close();">'.$langs->trans('Close').'</button></div>';
	print '</form>';
} elseif ($action == 'movement') {
	// ---- Cash in / withdrawal ----
	print '<h2>'.$langs->trans('PosplusCashMovementTitle').' - '.dol_escape_htmltag($session->ref).'</h2>';
	print '<form method="POST" action="'.$selfurl.'">';
	print '<input type="hidden" name="token" value="'.$token.'"><input type="hidden" name="action" value="addmovement">';
	print '<div class="pp-field"><label>'.$langs->trans('PosplusCashMovementType').'</label><select name="direction">';
	print '<option value="out">'.$langs->trans('PosplusCashOut').'</option><option value="in">'.$langs->trans('PosplusCashIn').'</option></select></div>';
	print '<div class="pp-field"><label>'.$langs->trans('PosplusCashBox').'</label><select name="accountid">';
	foreach (PosplusCashSession::getCashBoxes($db, $terminal) as $accountid => $box) {
		print '<option value="'.((int) $accountid).'">'.dol_escape_htmltag($box['label'].' ('.$box['currency'].')').'</option>';
	}
	print '</select></div>';
	print '<div class="pp-field"><label>'.$langs->trans('Amount').'</label><input type="text" inputmode="decimal" name="amount" required></div>';
	print '<div class="pp-field"><label>'.$langs->trans('PosplusCashReason').'</label><input type="text" name="reason" maxlength="200" required></div>';
	print '<div class="pp-actions"><button type="submit" class="pp-primary">'.$langs->trans('Save').'</button>';
	print '<a class="pp-btn" href="'.$selfurl.'">'.$langs->trans('Cancel').'</a></div>';
	print '</form>';
} elseif ($action == 'count') {
	// ---- Cash count (report X or Z) ----
	if ($type == 'z' && !$canz) {
		accessforbidden();
	}
	print '<h2>'.$langs->trans($type == 'z' ? 'PosplusCashCountZ' : 'PosplusCashCountX').' - '.dol_escape_htmltag($session->ref).'</h2>';
	if ($blind) {
		print '<div class="pp-warn">'.$langs->trans('PosplusCashBlindHelp').'</div>';
	}
	if ($type == 'z') {
		print '<div class="pp-warn">'.$langs->trans('PosplusCashZHelp').'</div>';
	}
	$lines = $session->computeLines();
	print '<form method="POST" action="'.$selfurl.'" onsubmit="return confirm('.dol_escape_htmltag(json_encode($langs->transnoentities('PosplusCashConfirmClose'))).');">';
	print '<input type="hidden" name="token" value="'.$token.'"><input type="hidden" name="action" value="close"><input type="hidden" name="type" value="'.$type.'">';
	print '<div class="pp-boxes">';
	foreach ($lines as $key => $l) {
		if ($l['iscash']) {
			print posplus_denomination_grid($l['fk_account'], array('label' => $l['accountlabel'], 'currency' => $l['currency']));
		}
	}
	print '</div>';
	$hasmodes = false;
	foreach ($lines as $l) {
		$hasmodes = $hasmodes || !$l['iscash'];
	}
	if ($hasmodes) {
		print '<table class="pp-list"><tr><th>'.$langs->trans('PosplusPaymentMode').'</th>';
		if (!$blind) {
			print '<th class="r">'.$langs->trans('PosplusCashExpectedShort').'</th>';
		}
		print '<th class="r">'.$langs->trans('PosplusCashDeclared').'</th></tr>';
		foreach ($lines as $key => $l) {
			if ($l['iscash']) {
				continue;
			}
			print '<tr><td>'.dol_escape_htmltag($l['modelabel']).' <span class="pp-cur">('.dol_escape_htmltag($l['accountlabel'].' - '.$l['currency']).')</span></td>';
			if (!$blind) {
				print '<td class="r">'.dol_escape_htmltag(posplus_ticket_amount($l['expected'], $l['currency'])).'</td>';
			}
			print '<td class="r"><input type="text" inputmode="decimal" class="pp-other" name="decl_'.md5($key).'" placeholder="0,00"></td></tr>';
		}
		print '</table>';
	}
	if (!$blind) {
		print '<table class="pp-list"><tr><th>'.$langs->trans('PosplusCashBox').'</th><th class="r">'.$langs->trans('PosplusCashExpectedShort').'</th></tr>';
		foreach ($lines as $l) {
			if ($l['iscash']) {
				print '<tr><td>'.dol_escape_htmltag($l['accountlabel']).'</td><td class="r">'.dol_escape_htmltag(posplus_ticket_amount($l['expected'], $l['currency'])).'</td></tr>';
			}
		}
		print '</table>';
	}
	print '<div class="pp-actions"><button type="submit" class="'.($type == 'z' ? 'pp-danger' : 'pp-primary').'">'.$langs->trans($type == 'z' ? 'PosplusCashCloseZ' : 'PosplusCashCloseX').'</button>';
	print '<a class="pp-btn" href="'.$selfurl.'">'.$langs->trans('Cancel').'</a></div>';
	print '</form>';
} else {
	// ---- Open session: summary and actions ----
	print '<h2>'.$langs->trans('PosplusCashCount').' - '.dol_escape_htmltag($session->ref).'</h2>';
	$opener = new User($db);
	$opener->fetch($session->fk_user_open);
	print '<p>'.$langs->trans('PosplusCashOpenedBy', posplus_print_date($session->date_open, '%d-%m-%Y %I:%M %p'), dol_escape_htmltag($opener->login)).'</p>';
	print '<div class="pp-actions">';
	print '<a class="pp-btn" href="'.$selfurl.'?action=movement&token='.$token.'">'.$langs->trans('PosplusCashMovementTitle').'</a>';
	print '<a class="pp-btn pp-primary" href="'.$selfurl.'?action=count&type=x&token='.$token.'">'.$langs->trans('PosplusCashCountX').'</a>';
	if ($canz) {
		print '<a class="pp-btn pp-danger" href="'.$selfurl.'?action=count&type=z&token='.$token.'">'.$langs->trans('PosplusCashCountZ').'</a>';
	}
	print '<a class="pp-btn" href="'.dol_buildpath('/posplus/cashclose.php', 1).'?date='.urlencode(posplus_print_date(dol_now(), '%Y-%m-%d')).'">'.$langs->trans('PosplusCashDaySales').'</a>';
	print '<button type="button" onclick="parent.$.colorbox.close();">'.$langs->trans('Close').'</button>';
	print '</div>';

	// Floats and, if the count is not blind, what the boxes should contain now
	print '<table class="pp-list"><tr><th>'.$langs->trans('PosplusCashBox').'</th><th class="r">'.$langs->trans('PosplusCashFloat').'</th>';
	if (!$blind) {
		print '<th class="r">'.$langs->trans('PosplusCashExpectedShort').'</th>';
	}
	print '</tr>';
	foreach ($session->computeLines() as $l) {
		if (!$l['iscash']) {
			continue;
		}
		print '<tr><td>'.dol_escape_htmltag($l['accountlabel']).'</td><td class="r">'.dol_escape_htmltag(posplus_ticket_amount($l['opening'], $l['currency'])).'</td>';
		if (!$blind) {
			print '<td class="r">'.dol_escape_htmltag(posplus_ticket_amount($l['expected'], $l['currency'])).'</td>';
		}
		print '</tr>';
	}
	print '</table>';

	if (count($session->movements) > 0) {
		print '<table class="pp-list"><tr><th colspan="3">'.$langs->trans('PosplusCashMovements').'</th></tr>';
		foreach ($session->movements as $mv) {
			print '<tr><td>'.posplus_print_date($mv['datec'], '%I:%M %p').' - '.dol_escape_htmltag($mv['login']).'</td><td>'.dol_escape_htmltag($mv['reason']).'</td>';
			print '<td class="r">'.dol_escape_htmltag(posplus_ticket_amount($mv['amount'], $mv['currency'])).'</td></tr>';
		}
		print '</table>';
	}
}

// Live totals of the denomination grids
print '<script>
(function() {
	function parseAmount(str) {
		str = String(str || "").replace(/\s/g, "");
		if (str.indexOf(",") > -1 && str.indexOf(".") > -1) { str = str.replace(/\./g, "").replace(",", "."); }
		else if (str.indexOf(",") > -1) { str = str.replace(",", "."); }
		var v = parseFloat(str); return isNaN(v) ? 0 : v;
	}
	function fmt(v) {
		var parts = Number(v).toFixed(2).split(".");
		return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".") + "," + parts[1];
	}
	function refresh(box) {
		var total = 0;
		box.querySelectorAll(".pp-qty").forEach(function(inp) {
			var sub = (parseFloat(inp.value) || 0) * parseFloat(inp.getAttribute("data-value"));
			total += sub;
			var cell = inp.parentNode.nextElementSibling;
			if (cell) { cell.textContent = sub ? fmt(sub) : ""; }
		});
		box.querySelectorAll(".pp-other").forEach(function(inp) { total += parseAmount(inp.value); });
		var t = box.querySelector(".pp-boxtotal");
		if (t) { t.textContent = fmt(total); }
	}
	document.querySelectorAll(".pp-box").forEach(function(box) {
		box.addEventListener("input", function() { refresh(box); });
		refresh(box);
	});
})();
</script>';

print '</div></body></html>';
$db->close();
