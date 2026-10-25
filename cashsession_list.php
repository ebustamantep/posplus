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
 * \file    posplus/cashsession_list.php
 * \ingroup posplus
 * \brief   History of the cash counts (shifts) of the TakePOS terminals, with the
 *          differences per currency.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
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
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/lib/posplus.lib.php';
require_once __DIR__.'/class/posplussession.class.php';

$langs->loadLangs(array('posplus@posplus', 'cashdesk', 'banks'));

if (!isModEnabled('posplus') || !getDolGlobalInt('POSPLUS_CASHCOUNT')) {
	accessforbidden();
}
if (!$user->hasRight('posplus', 'arqueo', 'read')) {
	accessforbidden();
}

$search_terminal = GETPOST('search_terminal', 'aZ09');
$search_status = GETPOST('search_status', 'intcomma');
$search_datestart = dol_mktime(0, 0, 0, GETPOSTINT('search_datestartmonth'), GETPOSTINT('search_datestartday'), GETPOSTINT('search_datestartyear'), posplus_tz());
$search_dateend = dol_mktime(23, 59, 59, GETPOSTINT('search_dateendmonth'), GETPOSTINT('search_dateendday'), GETPOSTINT('search_dateendyear'), posplus_tz());
if (GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter_x', 'alpha')) {
	$search_terminal = '';
	$search_status = '';
	$search_datestart = '';
	$search_dateend = '';
}
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = max(0, GETPOSTINT('page'));
$offset = $limit * $page;

$form = new Form($db);

$sql = "SELECT s.rowid, s.ref, s.terminal, s.status, s.date_open, s.date_close, uo.login as loginopen, uc.login as loginclose";
$sql .= " FROM ".MAIN_DB_PREFIX."posplus_cashsession as s";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as uo ON uo.rowid = s.fk_user_open";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as uc ON uc.rowid = s.fk_user_close";
$sql .= " WHERE s.entity IN (".getEntity('posplus_cashsession').")";
if ($search_terminal !== '') {
	$sql .= " AND s.terminal = '".$db->escape($search_terminal)."'";
}
if ($search_status !== '' && $search_status >= 0) {
	$sql .= " AND s.status = ".((int) $search_status);
}
if ($search_datestart) {
	$sql .= " AND s.date_open >= '".$db->idate($search_datestart)."'";
}
if ($search_dateend) {
	$sql .= " AND s.date_open <= '".$db->idate($search_dateend)."'";
}
$sql .= " ORDER BY s.date_open DESC, s.rowid DESC";
$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$num = $db->num_rows($resql);

llxHeader('', $langs->trans('PosplusCashCountHistory'), '', '', 0, 0, '', '', '', 'mod-posplus page-cashsession-list');

$param = '&search_terminal='.urlencode($search_terminal).'&search_status='.urlencode((string) $search_status);
if ($search_datestart) {
	$param .= '&search_datestartday='.GETPOSTINT('search_datestartday').'&search_datestartmonth='.GETPOSTINT('search_datestartmonth').'&search_datestartyear='.GETPOSTINT('search_datestartyear');
}
if ($search_dateend) {
	$param .= '&search_dateendday='.GETPOSTINT('search_dateendday').'&search_dateendmonth='.GETPOSTINT('search_dateendmonth').'&search_dateendyear='.GETPOSTINT('search_dateendyear');
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print_barre_liste($langs->trans('PosplusCashCountHistory'), $page, $_SERVER['PHP_SELF'], $param, '', '', '', $num, -1, 'fa-cash-register', 0, '', '', $limit);

print '<div class="div-table-responsive"><table class="tagtable liste">';
// Filters
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"><input type="text" class="maxwidth50" name="search_terminal" value="'.dol_escape_htmltag($search_terminal).'"></td>';
print '<td class="liste_titre">'.$form->selectDate($search_datestart ? $search_datestart : -1, 'search_datestart', 0, 0, 1, '', 1, 0).' '.$form->selectDate($search_dateend ? $search_dateend : -1, 'search_dateend', 0, 0, 1, '', 1, 0).'</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre">'.$form->selectarray('search_status', array('0' => $langs->trans('PosplusCashStatusOpen'), '1' => $langs->trans('PosplusCashStatusX'), '2' => $langs->trans('PosplusCashStatusZ')), $search_status, 1).'</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center">'.$form->showFilterButtons().'</td>';
print '</tr>';
// Titles
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('PosplusTicketCashDesk').'</th>';
print '<th>'.$langs->trans('PosplusCashOpened').'</th>';
print '<th>'.$langs->trans('PosplusCashClosed').'</th>';
print '<th>'.$langs->trans('Status').'</th>';
print '<th class="right">'.$langs->trans('PosplusCashTotalDifference').'</th>';
print '<th></th>';
print '</tr>';

$i = 0;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	$i++;
	// Differences per currency
	$diffs = array();
	$resd = $db->query("SELECT currency_code, SUM(difference) as diff FROM ".MAIN_DB_PREFIX."posplus_cashsession_line WHERE fk_session = ".((int) $obj->rowid)." AND difference IS NOT NULL GROUP BY currency_code");
	if ($resd) {
		while ($d = $db->fetch_object($resd)) {
			$value = (float) price2num($d->diff, 'MT');
			$diffs[] = '<span class="'.($value < 0 ? 'error' : ($value > 0 ? 'warning' : 'opacitymedium')).'">'.dol_escape_htmltag(posplus_ticket_amount($value, $d->currency_code)).'</span>';
		}
	}
	$status = ((int) $obj->status == 0 ? 'PosplusCashStatusOpen' : ((int) $obj->status == 2 ? 'PosplusCashStatusZ' : 'PosplusCashStatusX'));
	$url = dol_buildpath('/posplus/cashsession_card.php', 1).'?id='.((int) $obj->rowid);
	print '<tr class="oddeven">';
	print '<td class="nowraponall"><a href="'.$url.'">'.img_picto('', 'fa-cash-register', 'class="pictofixedwidth"').dol_escape_htmltag($obj->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag($obj->terminal).'</td>';
	print '<td class="nowraponall">'.posplus_print_date($db->jdate($obj->date_open), '%d-%m-%Y %I:%M %p').' <span class="opacitymedium">'.dol_escape_htmltag($obj->loginopen).'</span></td>';
	print '<td class="nowraponall">'.($obj->date_close ? posplus_print_date($db->jdate($obj->date_close), '%d-%m-%Y %I:%M %p').' <span class="opacitymedium">'.dol_escape_htmltag($obj->loginclose).'</span>' : '').'</td>';
	print '<td>'.$langs->trans($status).'</td>';
	print '<td class="right nowraponall">'.implode('<br>', $diffs).'</td>';
	print '<td></td>';
	print '</tr>';
}
if ($num == 0) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
print '</table></div>';
print '</form>';

llxFooter();
$db->close();
