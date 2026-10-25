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
 * \file    posplus/cashsession_card.php
 * \ingroup posplus
 * \brief   Detail of a cash count (shift) of a TakePOS terminal, with the ticket of the
 *          count and its reprint.
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

require_once __DIR__.'/lib/posplus.lib.php';
require_once __DIR__.'/class/posplussession.class.php';

$langs->loadLangs(array('posplus@posplus', 'cashdesk', 'banks'));

if (!isModEnabled('posplus') || !getDolGlobalInt('POSPLUS_CASHCOUNT')) {
	accessforbidden();
}
if (!$user->hasRight('posplus', 'arqueo', 'read')) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$object = new PosplusCashSession($db);
if ($object->fetch($id) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}

// Print view: only the ticket
if (GETPOSTINT('print')) {
	top_httphead();
	print '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.dol_escape_htmltag($object->ref).'</title>'.PosplusCashSession::getTicketCss().'</head><body>';
	print $object->getTicketHtml();
	print '<script>window.print();</script></body></html>';
	$db->close();
	exit;
}

llxHeader('', $object->ref, '', '', 0, 0, '', '', '', 'mod-posplus page-cashsession-card');

$linkback = '<a href="'.dol_buildpath('/posplus/cashsession_list.php', 1).'">'.$langs->trans('BackToList').'</a>';
print load_fiche_titre($langs->trans('PosplusCashCount').' '.dol_escape_htmltag($object->ref), $linkback, 'fa-cash-register');

print '<div class="tabsAction">';
print '<a class="butAction" target="_blank" href="'.dol_buildpath('/posplus/cashsession_card.php', 1).'?id='.((int) $object->id).'&print=1">'.$langs->trans('Print').'</a>';
print '</div>';

print PosplusCashSession::getTicketCss();
print '<div style="background:#fff; border:1px solid #ddd; padding:12px; max-width: 90mm;">'.$object->getTicketHtml().'</div>';

llxFooter();
$db->close();
