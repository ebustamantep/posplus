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
 * \file    posplus/class/posplussession.class.php
 * \ingroup posplus
 * \brief   Cash session (shift) of a TakePOS terminal: opening with the float of each
 *          cash box, cash put in / withdrawn, cash count with denominations, report X
 *          (close of the shift) and report Z (close of the day, locks the day through
 *          the native cash control llx_pos_cash_fence).
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/cashcontrol/class/cashcontrol.class.php';
dol_include_once('/posplus/lib/posplus.lib.php');

/**
 * Class PosplusCashSession
 */
class PosplusCashSession
{
	const STATUS_OPEN = 0;
	const STATUS_CLOSED = 1;	// Report X
	const STATUS_CLOSED_Z = 2;	// Report Z (close of the day)

	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();

	/** @var int */
	public $id = 0;
	/** @var int */
	public $entity;
	/** @var string */
	public $ref = '';
	/** @var string */
	public $terminal = '';
	/** @var int */
	public $status = 0;
	/** @var int */
	public $date_open;
	/** @var int */
	public $fk_user_open;
	/** @var int */
	public $date_start;
	/** @var int */
	public $date_close;
	/** @var int */
	public $fk_user_close;
	/** @var int */
	public $fk_cashfence;
	/** @var string */
	public $note = '';

	/** @var array<int,array<string,mixed>> Lines (cash boxes and non cash modes) */
	public $lines = array();
	/** @var array<int,array<string,mixed>> Cash put in / withdrawn */
	public $movements = array();

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
	 * Load a cash session with its lines and movements.
	 *
	 * @param int $id Id
	 * @return int    >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetch($id)
	{
		$sql = "SELECT rowid, entity, ref, terminal, status, date_open, fk_user_open, date_start, date_close, fk_user_close, fk_cashfence, note";
		$sql .= " FROM ".MAIN_DB_PREFIX."posplus_cashsession";
		$sql .= " WHERE rowid = ".((int) $id)." AND entity IN (".getEntity('posplus_cashsession').")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->ref = $obj->ref;
		$this->terminal = (string) $obj->terminal;
		$this->status = (int) $obj->status;
		$this->date_open = $this->db->jdate($obj->date_open);
		$this->fk_user_open = (int) $obj->fk_user_open;
		$this->date_start = $this->db->jdate($obj->date_start);
		$this->date_close = $this->db->jdate($obj->date_close);
		$this->fk_user_close = (int) $obj->fk_user_close;
		$this->fk_cashfence = (int) $obj->fk_cashfence;
		$this->note = (string) $obj->note;

		$this->lines = array();
		$sql = "SELECT l.rowid, l.fk_account, l.paymode, l.currency_code, l.iscash, l.opening, l.inflow, l.outflow,";
		$sql .= " l.movement_in, l.movement_out, l.expected, l.declared, l.difference, l.denominations,";
		$sql .= " ba.label as accountlabel, cp.libelle as modelabel";
		$sql .= " FROM ".MAIN_DB_PREFIX."posplus_cashsession_line as l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = l.fk_account";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement as cp ON cp.code = l.paymode AND cp.entity IN (".getEntity('c_paiement').")";
		$sql .= " WHERE l.fk_session = ".((int) $this->id);
		$sql .= " ORDER BY l.iscash DESC, l.rowid";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($l = $this->db->fetch_object($resql)) {
				$this->lines[] = array(
					'fk_account' => (int) $l->fk_account,
					'accountlabel' => (string) $l->accountlabel,
					'paymode' => (string) $l->paymode,
					'modelabel' => ($l->paymode ? posplus_payment_label($l->paymode, (string) $l->modelabel) : ''),
					'currency' => (string) $l->currency_code,
					'iscash' => (int) $l->iscash,
					'opening' => (float) $l->opening,
					'inflow' => (float) $l->inflow,
					'outflow' => (float) $l->outflow,
					'movement_in' => (float) $l->movement_in,
					'movement_out' => (float) $l->movement_out,
					'expected' => (float) $l->expected,
					'declared' => ($l->declared === null ? null : (float) $l->declared),
					'difference' => ($l->difference === null ? null : (float) $l->difference),
					'denominations' => (array) json_decode((string) $l->denominations, true),
				);
			}
		}

		$this->movements = array();
		$sql = "SELECT m.rowid, m.fk_account, m.fk_bank, m.amount, m.currency_code, m.reason, m.fk_user, m.datec, ba.label as accountlabel, u.login";
		$sql .= " FROM ".MAIN_DB_PREFIX."posplus_cashmovement as m";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = m.fk_account";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = m.fk_user";
		$sql .= " WHERE m.fk_session = ".((int) $this->id);
		$sql .= " ORDER BY m.datec, m.rowid";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($m = $this->db->fetch_object($resql)) {
				$this->movements[] = array(
					'fk_account' => (int) $m->fk_account,
					'accountlabel' => (string) $m->accountlabel,
					'fk_bank' => (int) $m->fk_bank,
					'amount' => (float) $m->amount,
					'currency' => (string) $m->currency_code,
					'reason' => (string) $m->reason,
					'login' => (string) $m->login,
					'datec' => $this->db->jdate($m->datec),
				);
			}
		}

		return 1;
	}

	/**
	 * Load the open cash session of a terminal.
	 *
	 * @param string $terminal Terminal
	 * @return int             >0 if found, 0 if none, <0 if KO
	 */
	public function fetchOpen($terminal)
	{
		global $conf;

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."posplus_cashsession";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		$sql .= " AND terminal = '".$this->db->escape($terminal)."'";
		$sql .= " AND status = ".self::STATUS_OPEN;
		$sql .= " ORDER BY rowid DESC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}
		return $this->fetch((int) $obj->rowid);
	}

	/**
	 * Cash boxes of a terminal: the open bank accounts of type "Cash" configured in TakePOS
	 * for its active payment modes (e.g. Caja USD for LIQ, Caja Bs for EFBS).
	 *
	 * @param DoliDB $db       Database handler
	 * @param string $terminal Terminal
	 * @return array<int,array{label:string,currency:string}> Indexed by bank account id
	 */
	public static function getCashBoxes($db, $terminal)
	{
		global $conf;

		$boxes = array();
		foreach (self::getTerminalModes($db, $terminal) as $mode) {
			if ($mode['iscash'] && !isset($boxes[$mode['accountid']])) {
				$boxes[$mode['accountid']] = array('label' => $mode['accountlabel'], 'currency' => $mode['currency']);
			}
		}
		return $boxes;
	}

	/**
	 * Active payment modes of a terminal with their bank account (TakePOS setup).
	 *
	 * @param DoliDB $db       Database handler
	 * @param string $terminal Terminal
	 * @return array<int,array{code:string,label:string,accountid:int,accountlabel:string,currency:string,iscash:bool}>
	 */
	public static function getTerminalModes($db, $terminal)
	{
		global $conf;

		$modes = array();
		$sql = "SELECT code, libelle as label FROM ".MAIN_DB_PREFIX."c_paiement";
		$sql .= " WHERE entity IN (".getEntity('c_paiement').") AND active = 1";
		$sql .= " ORDER BY libelle";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$accountid = posplus_get_mode_bankaccount($obj->code, $terminal);
				if ($accountid <= 0) {
					continue;
				}
				$acc = new Account($db);
				if ($acc->fetch($accountid) <= 0 || (int) $acc->clos != Account::STATUS_OPEN) {
					continue;
				}
				$modes[] = array(
					'code' => (string) $obj->code,
					'label' => posplus_payment_label($obj->code, (string) $obj->label),
					'accountid' => (int) $accountid,
					'accountlabel' => (string) $acc->label,
					'currency' => ($acc->currency_code ? $acc->currency_code : $conf->currency),
					'iscash' => ((int) $acc->type == Account::TYPE_CASH),
				);
			}
		}
		return $modes;
	}

	/**
	 * Denominations (bills and coins) to count for a currency, from the setup
	 * POSPLUS_DENOM_<CURRENCY> (comma separated values, highest first).
	 *
	 * @param string $currency Currency code
	 * @return float[]
	 */
	public static function getDenominations($currency)
	{
		$defaults = array(
			'USD' => '100,50,20,10,5,2,1',
			'EUR' => '500,200,100,50,20,10,5,2,1,0.5,0.2,0.1',
			'VEF' => '500,200,100,50,20,10,5,1,0.5',
			'VES' => '500,200,100,50,20,10,5,1,0.5',
		);
		$setup = getDolGlobalString('POSPLUS_DENOM_'.$currency, isset($defaults[$currency]) ? $defaults[$currency] : '');
		$list = array();
		foreach (explode(',', str_replace(';', ',', $setup)) as $value) {
			$value = (float) price2num(trim($value));
			if ($value > 0) {
				$list[(string) $value] = $value;
			}
		}
		rsort($list);
		return $list;
	}

	/**
	 * Total of a count by denominations.
	 *
	 * @param array<string,mixed> $denoms Map value => quantity, plus 'other' => free amount
	 * @return float
	 */
	public static function sumDenominations($denoms)
	{
		$total = 0;
		foreach ($denoms as $value => $qty) {
			if ($value === 'other') {
				$total += (float) $qty;
			} else {
				$total += (float) $value * (float) $qty;
			}
		}
		return (float) price2num($total, 'MT');
	}

	/**
	 * Open a cash session (shift) on a terminal with the float of each cash box.
	 *
	 * @param User                     $user     User
	 * @param string                   $terminal Terminal
	 * @param array<int,array<string,mixed>> $openings Map account id => denominations (value => qty, 'other' => amount)
	 * @return int                     Id if OK, <0 if KO
	 */
	public function create($user, $terminal, $openings)
	{
		global $conf, $langs;

		if (posplus_get_closed_cashcontrol($this->db, $terminal)) {
			$this->error = $langs->transnoentities('PosplusCashDayClosed');
			return -1;
		}
		$tmp = new self($this->db);
		if ($tmp->fetchOpen($terminal) > 0) {
			$this->error = $langs->transnoentities('PosplusCashSessionAlreadyOpen', $tmp->ref);
			return -1;
		}

		$now = dol_now();
		// The period counted starts at the close of the previous shift of the terminal, so no
		// payment between two shifts is lost. The period is (start, close]: the start is
		// excluded, so the first shift starts one second before its opening.
		$datestart = $now - 1;
		$sql = "SELECT MAX(date_close) as lastclose FROM ".MAIN_DB_PREFIX."posplus_cashsession";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND terminal = '".$this->db->escape($terminal)."' AND status <> ".self::STATUS_OPEN;
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql)) && !empty($obj->lastclose)) {
			$datestart = $this->db->jdate($obj->lastclose);
		}

		$this->db->begin();

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."posplus_cashsession (entity, terminal, status, date_open, fk_user_open, date_start)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($terminal)."', ".self::STATUS_OPEN.",";
		$sql .= " '".$this->db->idate($now)."', ".((int) $user->id).", '".$this->db->idate($datestart)."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX."posplus_cashsession");
		$this->ref = 'T'.$terminal.'-'.posplus_print_date($now, '%Y%m%d').'-'.$this->id;
		$this->db->query("UPDATE ".MAIN_DB_PREFIX."posplus_cashsession SET ref = '".$this->db->escape($this->ref)."' WHERE rowid = ".((int) $this->id));

		foreach (self::getCashBoxes($this->db, $terminal) as $accountid => $box) {
			$denoms = (isset($openings[$accountid]) && is_array($openings[$accountid]) ? $openings[$accountid] : array());
			$amount = self::sumDenominations($denoms);
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."posplus_cashsession_line (fk_session, fk_account, paymode, currency_code, iscash, opening, expected, denominations)";
			$sql .= " VALUES (".((int) $this->id).", ".((int) $accountid).", NULL, '".$this->db->escape($box['currency'])."', 1,";
			$sql .= " ".price2num($amount, 'MT').", ".price2num($amount, 'MT').", '".$this->db->escape(json_encode($denoms))."')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}

		$this->db->commit();
		return $this->fetch($this->id) > 0 ? $this->id : -1;
	}

	/**
	 * Put cash in (amount > 0) or withdraw cash (amount < 0) from a cash box of the open
	 * session. The bank line is recorded as LIQ (core rule for cash boxes).
	 *
	 * @param User   $user      User
	 * @param int    $accountid Cash box (bank account id)
	 * @param float  $amount    Amount in the currency of the box (signed)
	 * @param string $reason    Reason
	 * @return int              >0 if OK, <0 if KO
	 */
	public function addMovement($user, $accountid, $amount, $reason)
	{
		global $conf, $langs;

		$amount = (float) price2num($amount, 'MT');
		$boxes = self::getCashBoxes($this->db, $this->terminal);
		if ($this->status != self::STATUS_OPEN || !isset($boxes[$accountid]) || $amount == 0 || trim($reason) === '') {
			$this->error = $langs->transnoentities('ErrorBadParameters');
			return -1;
		}
		$currency = $boxes[$accountid]['currency'];

		$amountmain = null;
		if ($currency != $conf->currency) {
			require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';
			$tmp = MultiCurrency::getIdAndTxFromCode($this->db, $currency);
			$rate = (float) (isset($tmp[1]) ? $tmp[1] : 0);
			if ($rate > 0) {
				$amountmain = (float) price2num($amount / $rate, 'MT');
			}
		}

		$this->db->begin();
		$acc = new Account($this->db);
		$acc->fetch($accountid);
		$label = '('.($amount > 0 ? 'PosplusCashIn' : 'PosplusCashOut').') '.$this->ref.' - '.dol_trunc($reason, 200);
		$bankid = $acc->addline(dol_now(), 'LIQ', $label, $amount, '', 0, $user, '', '', '', null, '', $amountmain);
		if ($bankid <= 0) {
			$this->error = $acc->error;
			$this->db->rollback();
			return -1;
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."posplus_cashmovement (entity, fk_session, fk_account, fk_bank, amount, currency_code, reason, fk_user, datec)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $this->id).", ".((int) $accountid).", ".((int) $bankid).", ".price2num($amount, 'MT').",";
		$sql .= " '".$this->db->escape($currency)."', '".$this->db->escape(dol_trunc($reason, 250))."', ".((int) $user->id).", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return $this->fetch($this->id);
	}

	/**
	 * Compute the expected amounts of the session from the period start to now (or to its
	 * close): one line per cash box (float + collections - refunds + cash in - cash out)
	 * and one line per non cash payment mode and bank account (collections - refunds).
	 * Only the payments of invoices of the terminal (sales and POS returns) count.
	 *
	 * @return array<string,array<string,mixed>> Lines indexed by key
	 */
	public function computeLines()
	{
		global $conf;

		$lines = array();
		// Cash boxes: float of the opening
		foreach (self::getCashBoxes($this->db, $this->terminal) as $accountid => $box) {
			$lines['cash:'.$accountid] = array('fk_account' => $accountid, 'accountlabel' => $box['label'], 'paymode' => '', 'modelabel' => '',
				'currency' => $box['currency'], 'iscash' => 1, 'opening' => 0, 'inflow' => 0, 'outflow' => 0, 'movement_in' => 0, 'movement_out' => 0);
		}
		foreach ($this->lines as $l) {
			if ($l['iscash'] && isset($lines['cash:'.$l['fk_account']])) {
				$lines['cash:'.$l['fk_account']]['opening'] = $l['opening'];
				$lines['cash:'.$l['fk_account']]['opening_denoms'] = $l['denominations'];
			}
		}
		// Non cash modes of the terminal (listed even without movement, to declare them)
		foreach (self::getTerminalModes($this->db, $this->terminal) as $mode) {
			if (!$mode['iscash']) {
				$lines['mode:'.$mode['code'].':'.$mode['accountid']] = array('fk_account' => $mode['accountid'], 'accountlabel' => $mode['accountlabel'],
					'paymode' => $mode['code'], 'modelabel' => $mode['label'], 'currency' => $mode['currency'], 'iscash' => 0,
					'opening' => 0, 'inflow' => 0, 'outflow' => 0, 'movement_in' => 0, 'movement_out' => 0);
			}
		}

		// Payments of the period (bank lines created between the start and the close)
		$dateend = ($this->date_close ? $this->date_close : dol_now());
		$sql = "SELECT b.fk_account, cp.code, cp.libelle as label, ba.label as accountlabel, ba.currency_code, ba.courant,";
		$sql .= " SUM(CASE WHEN b.amount >= 0 THEN b.amount ELSE 0 END) as inflow,";
		$sql .= " SUM(CASE WHEN b.amount < 0 THEN -b.amount ELSE 0 END) as outflow";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
		$sql .= " JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = b.fk_account";
		$sql .= " JOIN ".MAIN_DB_PREFIX."paiement as p ON p.fk_bank = b.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement as cp ON cp.id = p.fk_paiement";
		$sql .= " WHERE p.entity IN (".getEntity('paiement').")";
		// Period (start, close]: a payment of the second of a close belongs to that shift only
		$sql .= " AND b.datec > '".$this->db->idate($this->date_start)."' AND b.datec <= '".$this->db->idate($dateend)."'";
		$sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."paiement_facture as pf";
		$sql .= "   JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = pf.fk_facture";
		$sql .= "   WHERE pf.fk_paiement = p.rowid AND f.entity IN (".getEntity('facture').") AND f.fk_statut >= 1";
		$sql .= "   AND ( (f.module_source = 'takepos' AND f.pos_source = '".$this->db->escape($this->terminal)."')";
		$sql .= "     OR (f.type = 2 AND f.module_source = 'easyback' AND f.pos_source = '".$this->db->escape($this->terminal)."') ) )";
		$sql .= " GROUP BY b.fk_account, cp.code, cp.libelle, ba.label, ba.currency_code, ba.courant";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$iscash = ((int) $obj->courant == Account::TYPE_CASH);
				$key = ($iscash ? 'cash:'.$obj->fk_account : 'mode:'.$obj->code.':'.$obj->fk_account);
				if (!isset($lines[$key])) {
					$lines[$key] = array('fk_account' => (int) $obj->fk_account, 'accountlabel' => (string) $obj->accountlabel,
						'paymode' => ($iscash ? '' : (string) $obj->code), 'modelabel' => ($iscash ? '' : posplus_payment_label($obj->code, (string) $obj->label)),
						'currency' => ($obj->currency_code ? $obj->currency_code : $conf->currency), 'iscash' => ($iscash ? 1 : 0),
						'opening' => 0, 'inflow' => 0, 'outflow' => 0, 'movement_in' => 0, 'movement_out' => 0);
				}
				$lines[$key]['inflow'] += (float) $obj->inflow;
				$lines[$key]['outflow'] += (float) $obj->outflow;
			}
		}

		// Cash put in / withdrawn during the session
		foreach ($this->movements as $m) {
			$key = 'cash:'.$m['fk_account'];
			if (isset($lines[$key])) {
				if ($m['amount'] > 0) {
					$lines[$key]['movement_in'] += $m['amount'];
				} else {
					$lines[$key]['movement_out'] += -$m['amount'];
				}
			}
		}

		foreach ($lines as $key => $l) {
			$lines[$key]['expected'] = (float) price2num($l['opening'] + $l['inflow'] - $l['outflow'] + $l['movement_in'] - $l['movement_out'], 'MT');
		}
		return $lines;
	}

	/**
	 * Close the session with the cash count: report X (close of the shift) or report Z
	 * (close of the day: also validates the native cash control of the day, which locks
	 * the terminal for the rest of the day, as TakePOS does).
	 *
	 * @param User                     $user     User
	 * @param array<string,mixed>      $declared ['cash' => [accountid => denominations], 'modes' => [key => amount]]
	 * @param bool                     $isz      True for the report Z
	 * @return int                     >0 if OK, <0 if KO
	 */
	public function close($user, $declared, $isz)
	{
		global $conf, $langs;

		$this->db->begin();
		// Lock the session row: a double submit cannot close it twice.
		$resql = $this->db->query("SELECT status FROM ".MAIN_DB_PREFIX."posplus_cashsession WHERE rowid = ".((int) $this->id)." FOR UPDATE");
		$obj = ($resql ? $this->db->fetch_object($resql) : null);
		if (!$obj || (int) $obj->status != self::STATUS_OPEN) {
			$this->error = $langs->transnoentities('PosplusCashSessionNotOpen');
			$this->db->rollback();
			return -1;
		}

		$now = dol_now();
		$this->date_close = $now;
		$lines = $this->computeLines();

		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."posplus_cashsession_line WHERE fk_session = ".((int) $this->id));
		foreach ($lines as $key => $l) {
			$denoms = null;
			if ($l['iscash']) {
				$denoms = (isset($declared['cash'][$l['fk_account']]) && is_array($declared['cash'][$l['fk_account']]) ? $declared['cash'][$l['fk_account']] : array());
				$decl = self::sumDenominations($denoms);
			} else {
				$decl = (float) price2num(isset($declared['modes'][$key]) ? $declared['modes'][$key] : 0, 'MT');
			}
			$diff = (float) price2num($decl - $l['expected'], 'MT');
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."posplus_cashsession_line";
			$sql .= " (fk_session, fk_account, paymode, currency_code, iscash, opening, inflow, outflow, movement_in, movement_out, expected, declared, difference, denominations)";
			$sql .= " VALUES (".((int) $this->id).", ".((int) $l['fk_account']).", ".($l['paymode'] !== '' ? "'".$this->db->escape($l['paymode'])."'" : "NULL").",";
			$sql .= " '".$this->db->escape($l['currency'])."', ".((int) $l['iscash']).", ".price2num($l['opening'], 'MT').",";
			$sql .= " ".price2num($l['inflow'], 'MT').", ".price2num($l['outflow'], 'MT').", ".price2num($l['movement_in'], 'MT').", ".price2num($l['movement_out'], 'MT').",";
			$sql .= " ".price2num($l['expected'], 'MT').", ".price2num($decl, 'MT').", ".price2num($diff, 'MT').",";
			$sql .= " ".($denoms !== null ? "'".$this->db->escape(json_encode($denoms))."'" : "NULL").")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}

		$fenceid = 0;
		if ($isz) {
			$fenceid = $this->closeDay($user, $lines, $declared);
			if ($fenceid < 0) {
				$this->db->rollback();
				return -1;
			}
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."posplus_cashsession SET";
		$sql .= " status = ".($isz ? self::STATUS_CLOSED_Z : self::STATUS_CLOSED).",";
		$sql .= " date_close = '".$this->db->idate($now)."',";
		$sql .= " fk_user_close = ".((int) $user->id).",";
		$sql .= " fk_cashfence = ".($fenceid > 0 ? ((int) $fenceid) : "NULL");
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return $this->fetch($this->id);
	}

	/**
	 * Close of the day (report Z): validate the native cash control of the day of the
	 * terminal (created if missing), with the amounts in the main currency. The day is
	 * stored as the local day (the one TakePOS checks to lock the payments).
	 *
	 * @param User                           $user     User
	 * @param array<string,array<string,mixed>> $lines    Computed lines
	 * @param array<string,mixed>            $declared Declared amounts
	 * @return int                           Id of the cash control, <0 if KO
	 */
	protected function closeDay($user, $lines, $declared)
	{
		global $conf;

		$cash = $cashdeclared = $card = $carddeclared = $opening = 0;
		foreach ($lines as $key => $l) {
			if ($l['currency'] != $conf->currency) {
				continue; // The native cash control only handles the main currency
			}
			if ($l['iscash']) {
				$opening += $l['opening'];
				$cash += $l['expected'];
				$cashdeclared += self::sumDenominations(isset($declared['cash'][$l['fk_account']]) ? (array) $declared['cash'][$l['fk_account']] : array());
			} else {
				$card += $l['expected'];
				$carddeclared += (float) (isset($declared['modes'][$key]) ? $declared['modes'][$key] : 0);
			}
		}

		$day = dol_getdate(dol_now()); // Same local day as the lock test of takepos/invoice.php
		$fence = new CashControl($this->db);
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pos_cash_fence";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND posnumber = '".$this->db->escape($this->terminal)."'";
		$sql .= " AND status = 0 AND date_creation >= '".$this->db->idate(dol_get_first_hour(dol_now()))."'";
		$sql .= " ORDER BY rowid DESC";
		$resql = $this->db->query($sql);
		$obj = ($resql ? $this->db->fetch_object($resql) : null);
		if ($obj) {
			$fence->fetch((int) $obj->rowid);
		} else {
			$fence->opening = price2num($opening, 'MT');
			$fence->posmodule = 'takepos';
			$fence->posnumber = $this->terminal;
			$fence->year_close = $day['year'];
			$fence->month_close = $day['mon'];
			$fence->day_close = $day['mday'];
			if ($fence->create($user) <= 0) {
				$this->error = $fence->error;
				return -1;
			}
			$fence->fetch($fence->id);
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."pos_cash_fence SET";
		$sql .= " cash = ".price2num($cash, 'MT').", card = ".price2num($card, 'MT').", cheque = 0,";
		$sql .= " cash_declared = ".price2num($cashdeclared, 'MT').", card_declared = ".price2num($carddeclared, 'MT').", cheque_declared = 0,";
		$sql .= " day_close = ".((int) $day['mday']).", month_close = ".((int) $day['mon']).", year_close = ".((int) $day['year']).",";
		$sql .= " hour_close = 23, min_close = 59, sec_close = 59";
		$sql .= " WHERE rowid = ".((int) $fence->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($fence->valid($user) < 0) {
			$this->error = $fence->error;
			return -1;
		}
		return (int) $fence->id;
	}

	/**
	 * Sales summary of the day of the terminal (for the report Z), per currency of the
	 * invoices: the invoices in the main currency are summed with their amounts in that
	 * currency, the invoices in another currency (e.g. VEF) with their amounts in that
	 * currency (multicurrency amounts). Invoices issued (validated/paid, not credit notes)
	 * and POS returns (EasyBack credit notes of the terminal).
	 *
	 * @return array<string,array{nb:int,ht:float,tva:float,ttc:float,returns:float}> Indexed by currency code
	 */
	public function getDaySummary()
	{
		global $conf;

		$day = posplus_print_date($this->date_close ? $this->date_close : dol_now(), '%Y-%m-%d');
		$start = $this->db->escape($day).' 00:00:00';
		$end = $this->db->escape($day).' 23:59:59';
		$main = $this->db->escape($conf->currency);
		// Currency of the invoice and its amounts in that currency
		$curexpr = "CASE WHEN f.multicurrency_code IS NULL OR f.multicurrency_code = '' THEN '".$main."' ELSE f.multicurrency_code END";
		$inmain = "(f.multicurrency_code IS NULL OR f.multicurrency_code = '' OR f.multicurrency_code = '".$main."')";
		$where = " FROM ".MAIN_DB_PREFIX."facture as f";
		$where .= " WHERE f.entity IN (".getEntity('facture').")";
		$where .= " AND f.pos_source = '".$this->db->escape($this->terminal)."' AND f.fk_statut IN (1, 2)";
		$where .= " AND f.datef BETWEEN '".$start."' AND '".$end."'";

		$res = array();
		$empty = array('nb' => 0, 'ht' => 0, 'tva' => 0, 'ttc' => 0, 'returns' => 0);

		$sql = "SELECT ".$curexpr." as cur, COUNT(*) as nb,";
		$sql .= " SUM(CASE WHEN ".$inmain." THEN f.total_ht ELSE f.multicurrency_total_ht END) as ht,";
		$sql .= " SUM(CASE WHEN ".$inmain." THEN f.total_tva ELSE f.multicurrency_total_tva END) as tva,";
		$sql .= " SUM(CASE WHEN ".$inmain." THEN f.total_ttc ELSE f.multicurrency_total_ttc END) as ttc";
		$sql .= $where." AND f.module_source = 'takepos' AND f.type <> 2";
		$sql .= " GROUP BY ".$curexpr;
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$res[$obj->cur] = $empty;
				$res[$obj->cur]['nb'] = (int) $obj->nb;
				$res[$obj->cur]['ht'] = (float) $obj->ht;
				$res[$obj->cur]['tva'] = (float) $obj->tva;
				$res[$obj->cur]['ttc'] = (float) $obj->ttc;
			}
		}

		$sql = "SELECT ".$curexpr." as cur,";
		$sql .= " SUM(ABS(CASE WHEN ".$inmain." THEN f.total_ttc ELSE f.multicurrency_total_ttc END)) as ttc";
		$sql .= $where." AND f.type = 2 AND f.module_source = 'easyback'";
		$sql .= " GROUP BY ".$curexpr;
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				if (!isset($res[$obj->cur])) {
					$res[$obj->cur] = $empty;
				}
				$res[$obj->cur]['returns'] = (float) $obj->ttc;
			}
		}

		// Main currency first, then the others
		uksort($res, function ($a, $b) use ($conf) {
			return ($a == $conf->currency ? -1 : ($b == $conf->currency ? 1 : strcmp($a, $b)));
		});
		return $res;
	}

	/**
	 * HTML of the cash count ticket (80 mm): per cash box the float, collections, refunds,
	 * cash in/out, expected, counted (with the denominations) and the difference; per non
	 * cash mode the expected, declared and difference; the movements; for a report Z the
	 * sales summary of the day; and the signatures.
	 *
	 * @return string
	 */
	public function getTicketHtml()
	{
		global $langs, $mysoc, $conf;

		$langs->loadLangs(array('main', 'bills', 'cashdesk', 'posplus@posplus'));
		$row = function ($left, $right = '', $class = '') {
			return '<tr'.($class ? ' class="'.$class.'"' : '').'><td class="l">'.$left.'</td><td class="r">'.$right.'</td></tr>';
		};
		$userlogin = function ($id) {
			global $db;
			$u = new User($db);
			return ($id > 0 && $u->fetch($id) > 0 ? $u->login : '');
		};

		$out = '<div class="tk">';
		$companyid = ($mysoc->tva_intra ? $mysoc->tva_intra : $mysoc->idprof1);
		$out .= '<div class="c">'.($companyid ? dol_escape_htmltag($companyid).'<br>' : '').'<b class="upper">'.dol_escape_htmltag($mysoc->name).'</b></div>';
		$title = ($this->status == self::STATUS_CLOSED_Z ? 'PosplusCashReportZ' : ($this->status == self::STATUS_CLOSED ? 'PosplusCashReportX' : 'PosplusCashReportPartial'));
		$out .= '<div class="c title upper">'.$langs->trans($title).'</div>';
		$out .= '<table>';
		$out .= $row($langs->trans('PosplusTicketCashDesk').': '.dol_escape_htmltag($this->terminal), dol_escape_htmltag($this->ref));
		// One data per line (date and user on the same line do not fit on 80 mm)
		$loginopen = $userlogin($this->fk_user_open);
		$out .= $row($langs->trans('PosplusCashOpened'), posplus_print_date($this->date_open, '%d-%m-%Y %I:%M %p'));
		$out .= $row($langs->trans('PosplusTicketOperator'), dol_escape_htmltag($loginopen));
		if ($this->date_close) {
			$out .= $row($langs->trans('PosplusCashClosed'), posplus_print_date($this->date_close, '%d-%m-%Y %I:%M %p'));
			$loginclose = $userlogin($this->fk_user_close);
			if ($loginclose !== $loginopen) {
				$out .= $row($langs->trans('PosplusCashClosedBy'), dol_escape_htmltag($loginclose));
			}
		}
		$out .= '</table>';

		$lines = ($this->status == self::STATUS_OPEN ? $this->computeLines() : $this->lines);
		$totaldiff = array();
		foreach ($lines as $l) {
			// A non cash mode without movement nor declared amount adds nothing to the ticket
			if (!$l['iscash'] && (float) $l['expected'] == 0 && (float) $l['inflow'] == 0 && (float) $l['outflow'] == 0 && empty($l['declared'])) {
				continue;
			}
			$cur = $l['currency'];
			$m = function ($amount) use ($cur) {
				return posplus_ticket_amount($amount, $cur);
			};
			$out .= '<table>';
			if ($l['iscash']) {
				$out .= $row('<b class="upper">'.dol_escape_htmltag($l['accountlabel']).'</b>', '<b>'.dol_escape_htmltag($cur).'</b>', 'sep');
				$out .= $row($langs->trans('PosplusCashFloat'), $m($l['opening']));
				$out .= $row($langs->trans('PosplusCashCollections'), $m($l['inflow']));
				if ($l['outflow'] != 0) {
					$out .= $row($langs->trans('PosplusCashRefunds'), $m(-$l['outflow']));
				}
				if ($l['movement_in'] != 0) {
					$out .= $row($langs->trans('PosplusCashIn'), $m($l['movement_in']));
				}
				if ($l['movement_out'] != 0) {
					$out .= $row($langs->trans('PosplusCashOut'), $m(-$l['movement_out']));
				}
			} else {
				$out .= $row('<b class="upper">'.dol_escape_htmltag($l['modelabel']).'</b><br><span class="small">'.dol_escape_htmltag($l['accountlabel']).'</span>', '<b>'.dol_escape_htmltag($cur).'</b>', 'sep');
				$out .= $row($langs->trans('PosplusCashCollections'), $m($l['inflow']));
				if ($l['outflow'] != 0) {
					$out .= $row($langs->trans('PosplusCashRefunds'), $m(-$l['outflow']));
				}
			}
			$out .= $row('<b>'.$langs->trans('PosplusCashExpectedShort').'</b>', '<b>'.$m($l['expected']).'</b>');
			if ($l['declared'] !== null && isset($l['declared'])) {
				$out .= $row($langs->trans($l['iscash'] ? 'PosplusCashCounted' : 'PosplusCashDeclared'), $m($l['declared']));
				if ($l['iscash'] && !empty($l['denominations'])) {
					foreach ($l['denominations'] as $value => $qty) {
						if ($value === 'other') {
							if ((float) $qty != 0) {
								$out .= $row('<span class="small">&nbsp;&nbsp;'.$langs->trans('PosplusCashOther').'</span>', '<span class="small">'.$m($qty).'</span>');
							}
						} elseif ((float) $qty > 0) {
							$out .= $row('<span class="small">&nbsp;&nbsp;'.((float) $qty).' x '.$m($value).'</span>', '<span class="small">'.$m((float) $value * (float) $qty).'</span>');
						}
					}
				}
				$label = ($l['difference'] > 0 ? 'PosplusCashSurplus' : ($l['difference'] < 0 ? 'PosplusCashShortage' : 'PosplusCashBalanced'));
				$out .= $row('<b class="upper">'.$langs->trans($label).'</b>', '<b>'.$m($l['difference']).'</b>', 'total');
				$totaldiff[$cur] = (isset($totaldiff[$cur]) ? $totaldiff[$cur] : 0) + $l['difference'];
			}
			$out .= '</table>';
		}

		if (count($this->movements) > 0) {
			$out .= '<table><tr class="sep"><td class="l" colspan="2"><b class="upper">'.$langs->trans('PosplusCashMovements').'</b></td></tr>';
			foreach ($this->movements as $mv) {
				$out .= $row('<span class="small">'.posplus_print_date($mv['datec'], '%I:%M %p').' '.dol_escape_htmltag($mv['reason']).' ('.dol_escape_htmltag($mv['login']).')</span>', '<span class="small">'.posplus_ticket_amount($mv['amount'], $mv['currency']).'</span>');
			}
			$out .= '</table>';
		}

		if (count($totaldiff) > 0) {
			$out .= '<table><tr class="sep"><td class="l" colspan="2"><b class="upper">'.$langs->trans('PosplusCashTotalDifference').'</b></td></tr>';
			foreach ($totaldiff as $cur => $diff) {
				$out .= $row(dol_escape_htmltag($cur), '<b>'.posplus_ticket_amount($diff, $cur).'</b>');
			}
			$out .= '</table>';
		}

		if ($this->status == self::STATUS_CLOSED_Z) {
			// Sales of the day per currency of the invoices (USD in $, VEF in Bs)
			$summary = $this->getDaySummary();
			if (count($summary) == 0) {
				$out .= '<table><tr class="sep"><td class="l" colspan="2"><b class="upper">'.$langs->trans('PosplusCashDaySales').'</b></td></tr>';
				$out .= $row($langs->trans('PosplusInvoicesIssued'), '0');
				$out .= '</table>';
			}
			foreach ($summary as $cur => $sum) {
				$mm = function ($amount) use ($cur) {
					return posplus_ticket_amount($amount, $cur);
				};
				$out .= '<table><tr class="sep"><td class="l" colspan="2"><b class="upper">'.$langs->trans('PosplusCashDaySalesIn', dol_escape_htmltag($cur)).'</b></td></tr>';
				$out .= $row($langs->trans('PosplusInvoicesIssued'), $sum['nb']);
				$out .= $row($langs->trans('PosplusBase'), $mm($sum['ht']));
				$out .= $row($langs->trans('PosplusVAT'), $mm($sum['tva']));
				$out .= $row($langs->trans('PosplusTotalInvoiced'), $mm($sum['ttc']));
				$out .= $row($langs->trans('PosplusReturnsTitle'), $mm(-$sum['returns']));
				$out .= $row('<b>'.$langs->trans('PosplusNetTotal').' '.dol_escape_htmltag($cur).'</b>', '<b>'.$mm($sum['ttc'] - $sum['returns']).'</b>', 'total');
				$out .= '</table>';
			}
		}

		if ($this->status != self::STATUS_OPEN) {
			$out .= '<br><br><table><tr><td class="c">______________<br>'.$langs->trans('PosplusCashSignCashier').'</td><td class="c">______________<br>'.$langs->trans('PosplusCashSignSupervisor').'</td></tr></table>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * CSS of the 80 mm tickets (same look as the sales ticket).
	 *
	 * @return string
	 */
	public static function getTicketCss()
	{
		return '<style>
.tk { font-family: "Courier New", Courier, monospace; font-size: 12px; line-height: 1.3; color: #000; max-width: 76mm; margin: 0 auto; }
.tk .c { text-align: center; }
.tk table { width: 100%; border-collapse: collapse; }
.tk td { padding: 0; vertical-align: top; }
.tk td.l { text-align: left; }
.tk td.r { text-align: right; white-space: nowrap; padding-left: 6px; }
.tk tr.sep td { border-top: 1px dashed #000; padding-top: 2px; }
.tk tr.total td { font-weight: bold; }
.tk .title { font-weight: bold; margin: 4px 0; }
.tk .small { font-size: 0.9em; }
.tk .upper { text-transform: uppercase; }
@media print {
	/* Only the ticket is printed: buttons and messages of the popup are hidden (important:
	   the popup styles set their display after this rule). */
	.noprint { display: none !important; }
	body { padding: 0 !important; margin: 0 !important; }
	@page { size: 80mm auto; margin: 2mm; }
}
</style>';
	}

	/**
	 * Label of the status.
	 *
	 * @return string
	 */
	public function getLibStatut()
	{
		global $langs;

		if ($this->status == self::STATUS_OPEN) {
			return $langs->trans('PosplusCashStatusOpen');
		}
		return $langs->trans($this->status == self::STATUS_CLOSED_Z ? 'PosplusCashStatusZ' : 'PosplusCashStatusX');
	}
}
