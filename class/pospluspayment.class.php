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
 * \file    posplus/class/pospluspayment.class.php
 * \ingroup posplus
 * \brief   Registration of a payment on a TakePOS invoice (validation, stock, payment in the
 *          currency of the box, bank line, closing). Shared by the payplus popup endpoint and
 *          by any other flow that must pay a POS invoice with the same rules.
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once __DIR__.'/../lib/posplus.lib.php';

/**
 * Payment of a TakePOS invoice by POSPlus.
 */
class PosplusPayment
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Last error (translated)
	 */
	public $error = '';

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
	 * Normalize a payment code sent by TakePOS ('cash', 'card', 'cheque') to a c_paiement code.
	 *
	 * @param string $pay Payment code
	 * @return string c_paiement code
	 */
	public static function normalizePayCode($pay)
	{
		if ($pay == 'cash') {
			return 'LIQ';
		} elseif ($pay == 'card') {
			return 'CB';
		} elseif ($pay == 'cheque') {
			return 'CHQ';
		}
		return (string) $pay;
	}

	/**
	 * Register one payment (partial, or the one that completes the invoice) of a TakePOS invoice.
	 * A draft invoice is validated first (numbering, stock), as takepos/invoice.php action=valid.
	 * The amounts are converted with the rate stored on the invoice, never with a rate given by
	 * the caller. Everything is done in one transaction: on error nothing is recorded.
	 *
	 * The caller must have checked the rights of the user and that the invoice is a TakePOS
	 * invoice of the terminal (posplus_invoice_is_accessible).
	 *
	 * @param User                $user     User recording the payment
	 * @param Facture             $invoice  The invoice (fetched)
	 * @param string              $terminal TakePOS terminal
	 * @param array<string,mixed> $data     Payment:
	 *                                      'pay'         payment code (cash/card/cheque or c_paiement code),
	 *                                      'amount'      amount in the currency of the box/account,
	 *                                      'currency'    currency of that amount ('' = not checked),
	 *                                      'excess'      cash received above the remain (main currency),
	 *                                      'accountid'   account forced by the operator (if TakePOS allows it),
	 *                                      'num_payment' reference of the payment,
	 *                                      'datepaye'    date of the payment (default now)
	 * @return array{remaintopay:float,remaintopay_mc:float,currency_mc:string,invoiceid:int,paid:int}|false
	 *         Result, or false on error ($this->error)
	 */
	public function addPayment($user, $invoice, $terminal, $data)
	{
		global $conf, $langs;

		$langs->loadLangs(array('posplus@posplus', 'bills', 'cashdesk', 'banks', 'errors'));
		$this->error = '';
		$db = $this->db;

		$amountofpayment = (float) price2num(isset($data['amount']) ? $data['amount'] : 0, 'MT');
		$amountcurrency = (isset($data['currency']) ? (string) $data['currency'] : '');
		$excess = (float) price2num(isset($data['excess']) ? $data['excess'] : 0, 'MT');
		$accountid = (isset($data['accountid']) ? (int) $data['accountid'] : 0);
		$numpayment = (isset($data['num_payment']) ? (string) $data['num_payment'] : '');
		$datepaye = (!empty($data['datepaye']) ? $data['datepaye'] : dol_now());

		if ((int) $invoice->type != Facture::TYPE_STANDARD) {
			return $this->fail($langs->transnoentities('ErrorBadParameters'));
		}

		// The day must not be closed for this terminal (same test as takepos/invoice.php).
		$refcashcontrol = posplus_get_closed_cashcontrol($db, $terminal);
		if ($refcashcontrol) {
			return $this->fail($langs->transnoentities('ACashControlHasBeenclosedForCurrentDay', $refcashcontrol));
		}

		// With the cash count by shift, a payment needs an open shift on the terminal.
		if (getDolGlobalInt('POSPLUS_CASHCOUNT')) {
			require_once __DIR__.'/posplussession.class.php';
			$cashsession = new PosplusCashSession($db);
			if ($cashsession->fetchOpen($terminal) <= 0) {
				return $this->fail($langs->transnoentities('PosplusCashNoOpenSession'));
			}
		}

		if ($amountofpayment <= 0) {
			return $this->fail($langs->transnoentities('TotalAmountEmpty'));
		}

		$paycode = self::normalizePayCode(isset($data['pay']) ? $data['pay'] : '');

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
			return $this->fail($langs->transnoentities('ErrorBadParameters'));
		}

		// Bank account: the one configured in TakePOS for this mode and terminal. The value
		// given by the operator is only accepted when TakePOS allows forcing the account.
		$bankaccount = posplus_get_mode_bankaccount($paycode, $terminal);
		if (getDolGlobalString('TAKEPOS_CAN_FORCE_BANK_ACCOUNT_DURING_PAYMENT') && $accountid > 0) {
			$bankaccount = $accountid;
		}

		$bankcurrency = $conf->currency;
		if (isModEnabled('bank')) {
			if ($bankaccount <= 0) {
				return $this->fail($langs->transnoentities('ErrorFieldRequired', $langs->transnoentitiesnoconv('BankAccount')));
			}
			$acc = new Account($db);
			// Account::fetch() filters on the entities of the user.
			if ($acc->fetch($bankaccount) <= 0 || (int) $acc->clos != Account::STATUS_OPEN) {
				return $this->fail($langs->transnoentities('ErrorBadParameters').' ('.$langs->transnoentitiesnoconv('BankAccount').')');
			}
			$bankcurrency = ($acc->currency_code ? $acc->currency_code : $conf->currency);
		} else {
			// Without the bank module the native flow only allows cash and card.
			if ($paycode != 'LIQ' && $paycode != 'CB') {
				return $this->fail($langs->transnoentities('ErrorBadParameters'));
			}
			$bankaccount = 0;
		}

		// Every payment but cash must carry its reference.
		if ($numpayment === '' && posplus_mode_needs_reference($db, $paycode, $bankaccount)) {
			return $this->fail($langs->transnoentities('PosplusRefRequired'));
		}

		$error = 0;
		$errormsg = '';
		$remaintopay = 0;

		$db->begin();

		// Lock the invoice row until the end of the transaction, so two simultaneous requests
		// (double click, two tabs) cannot both pay the same remain to pay.
		$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."facture WHERE rowid = ".((int) $invoice->id)." FOR UPDATE");
		if (!$resql || $invoice->fetch($invoice->id) <= 0) {
			$errormsg = $db->lasterror();
			$db->rollback();
			return $this->fail($errormsg);
		}

		$invoice->oldcopy = dol_clone($invoice, 2);

		// First payment of an invoice still in the main currency: it takes the foreign currency
		// of the terminal at the day rate (amounts in main currency unchanged), so the payment
		// modes in $ and in Bs can be mixed in any order.
		$pending = posplus_get_pending_invoice_currency($db, $invoice, $terminal);
		if ($pending['currency'] !== '') {
			if ($invoice->setMulticurrencyCode($pending['currency']) < 0 || $invoice->fetch($invoice->id) <= 0) {
				$errormsg = ($invoice->error ? $invoice->error : $langs->transnoentities('Error'));
				$db->rollback();
				return $this->fail($errormsg);
			}
		}

		if ($invoice->status == Facture::STATUS_DRAFT) {
			if (!$this->validateInvoice($user, $invoice, $terminal)) {
				$error++;
				$errormsg = $this->error;
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
		// An amount in the foreign currency worth less than 0.01 in the main currency would
		// record a bank line without any amount on the invoice.
		if (!$error && $payinforeign && (float) price2num($amountofpayment / (float) $invoice->multicurrency_tx, 'MT') <= 0) {
			$error++;
			$errormsg = $langs->transnoentities('TotalAmountEmpty');
		}

		if (!$error) {
			$payment = new Paiement($db);
			$payment->datepaye = $datepaye;
			$payment->fk_account = $bankaccount;
			if ($payinforeign) {
				// Amount in the invoice currency, as received in the box (e.g. Bs), never more
				// than the remain to pay in that currency: Paiement::create() converts it to the
				// main currency with the invoice rate and the bank line keeps the exact amount.
				$remaintopaymc = (float) price2num($invoice->getRemainToPay(1), 'MT');
				if ($remaintopaymc <= 0) {
					$remaintopaymc = (float) price2num($remaintopay * (float) $invoice->multicurrency_tx, 'MT');
				}
				$payment->multicurrency_amounts[$invoice->id] = price2num(min($amountofpayment, $remaintopaymc), 'MT');
				$payment->multicurrency_code[$invoice->id] = $invoicecurrency;
				$payment->multicurrency_tx[$invoice->id] = (float) $invoice->multicurrency_tx;
			} else {
				// Amount in main currency, never more than the remain to pay. multicurrency_amounts
				// is left empty (as takepos/invoice.php does): Paiement::create() converts it with
				// the invoice rate.
				$payment->amounts[$invoice->id] = price2num(min($amountofpayment, $remaintopay), 'MT');
			}
			// Change given back: as the core, only for LIQ in the main currency (a change in
			// another currency is not supported by the core: the amount is just capped to the
			// remain to pay).
			if ($paycode == 'LIQ' && $bankcurrency == $conf->currency && $amountofpayment > $remaintopay) {
				$payment->pos_change = price2num($excess > 0 ? $excess : $amountofpayment - $remaintopay, 'MT');
			}
			$payment->paiementid = $paiementid;
			$payment->paiementcode = $paycode;
			$payment->num_payment = $numpayment;

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
			// currency can keep a cent of rounding when the other one is fully paid: the invoice
			// is paid when either remain is zero.
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

		if ($error) {
			$db->rollback();
			return $this->fail($errormsg ? $errormsg : $langs->transnoentities('Error'));
		}
		$db->commit();

		return array(
			'remaintopay' => $remaintopay,
			'remaintopay_mc' => $remaintopaymc,
			'currency_mc' => $invoicecurrency,
			'invoiceid' => (int) $invoice->id,
			'paid' => $paid,
		);
	}

	/**
	 * Validate a draft TakePOS invoice (same logic as takepos/invoice.php action=valid):
	 * numbering and stock movement in the warehouse of the terminal. Must be called inside the
	 * transaction of the caller (it does not commit nor roll back).
	 *
	 * @param User    $user     User
	 * @param Facture $invoice  Draft invoice
	 * @param string  $terminal TakePOS terminal
	 * @return bool True if OK, false on error ($this->error)
	 */
	public function validateInvoice($user, $invoice, $terminal)
	{
		global $conf, $langs;

		$db = $this->db;

		if (count($invoice->lines) == 0) {
			$this->error = $langs->transnoentities('NoLinesToBill', 'TakePos');
			return false;
		}

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
			$langs->load("admin");
			$this->error = ($invoice->error == 'NotConfigured' ? $langs->transnoentities("NotConfigured").' (TakePos numbering module)' : $invoice->error);
			return false;
		}

		if (isModEnabled('stock') && isModEnabled('productbatch') && $allowstockchange) {
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
					$this->error = $mouvP->error;
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Set the error and return false.
	 *
	 * @param string $msg Error message
	 * @return false
	 */
	private function fail($msg)
	{
		$this->error = (string) $msg;
		return false;
	}
}
