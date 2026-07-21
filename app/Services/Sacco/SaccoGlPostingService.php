<?php

namespace App\Services\Sacco;

use App\Models\GldTransaction;
use App\Models\GlSetting;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanRepaymentSchedule;
use App\Models\SaccoTransaction;
use App\Models\StockMovement;

/**
 * Centralizes gld_transactions posting for the SACCO module — same
 * sign-only double-entry style as SalesInvoiceController::placeInvoiceTx()
 * (positive amount = debit, negative = credit, legs sharing type+trans_no
 * must net to zero). Account codes resolve from gl_settings, falling back
 * to a safe literal if unconfigured — same fallback-chain pattern used
 * throughout this codebase (see SalesInvoiceController's cogsAccount/
 * salesAccount resolution).
 */
class SaccoGlPostingService
{
    private function glSetting(): GlSetting
    {
        return GlSetting::first() ?? new GlSetting();
    }

    // Fallback literals must fit gld_transactions.account_code (varchar 15).
    private function cashAccount(): string
    {
        return $this->glSetting()->sacco_cash_account ?: 'SACCO_CASH';
    }

    private function savingsLiabilityAccount(): string
    {
        return $this->glSetting()->sacco_savings_liability_account ?: 'SACCO_SAVINGS';
    }

    private function shareCapitalAccount(): string
    {
        return $this->glSetting()->sacco_share_capital_account ?: 'SACCO_SHARES';
    }

    private function loansReceivableAccount(): string
    {
        return $this->glSetting()->sacco_loans_receivable_account ?: 'SACCO_LOANS_REC';
    }

    private function loanInterestIncomeAccount(): string
    {
        return $this->glSetting()->sacco_loan_interest_income_account ?: 'SACCO_LN_INT';
    }

    /** Member deposits cash/bank into savings — DR Cash, CR Savings Liability. */
    public function postSavingsDeposit(SaccoTransaction $txn, string $createdBy): void
    {
        $this->postPair(
            StockMovement::TYPE_SACCO_SAVINGS_DEPOSIT,
            $txn->id,
            $txn->transaction_date,
            $txn->reference ?? '',
            "Savings deposit — account #{$txn->account_id}",
            $this->cashAccount(),
            $this->savingsLiabilityAccount(),
            (float) $txn->amount,
            $createdBy,
        );
    }

    /** Member withdraws from savings — DR Savings Liability, CR Cash. */
    public function postSavingsWithdrawal(SaccoTransaction $txn, string $createdBy): void
    {
        $this->postPair(
            StockMovement::TYPE_SACCO_SAVINGS_WITHDRAWAL,
            $txn->id,
            $txn->transaction_date,
            $txn->reference ?? '',
            "Savings withdrawal — account #{$txn->account_id}",
            $this->savingsLiabilityAccount(),
            $this->cashAccount(),
            (float) $txn->amount,
            $createdBy,
        );
    }

    /** Member buys shares — DR Cash, CR Share Capital (equity). */
    public function postSharePurchase(SaccoTransaction $txn, string $createdBy): void
    {
        $this->postPair(
            StockMovement::TYPE_SACCO_SHARE_PURCHASE,
            $txn->id,
            $txn->transaction_date,
            $txn->reference ?? '',
            "Share purchase — account #{$txn->account_id}",
            $this->cashAccount(),
            $this->shareCapitalAccount(),
            (float) $txn->amount,
            $createdBy,
        );
    }

    /** Loan disbursed to member — DR Loans Receivable, CR Cash. */
    public function postLoanDisbursement(SaccoLoan $loan, string $createdBy): void
    {
        $this->postPair(
            StockMovement::TYPE_SACCO_LOAN_DISBURSEMENT,
            $loan->id,
            $loan->disbursement_date ?? now()->toDateString(),
            $loan->loan_no,
            "Loan disbursement — {$loan->loan_no}",
            $this->loansReceivableAccount(),
            $this->cashAccount(),
            (float) $loan->principal_amount,
            $createdBy,
        );
    }

    /**
     * Repayment against one schedule installment — splits into principal
     * (reduces Loans Receivable) and interest (Interest Income). The
     * counter-leg is Cash for cash/bank repayments; for milk_checkoff
     * repayments there's no literal cash movement (the amount instead
     * reduces what MilkPayslipController pays the farmer that period) — v1
     * still posts it through the same cash-account leg for simplicity, so
     * an admin who wants it distinguished can point sacco_cash_account at a
     * dedicated "Farmers Payable Clearing" GL code via Setup > GL Settings.
     */
    public function postLoanRepayment(SaccoLoanRepaymentSchedule $installment, string $paidVia, string $createdBy): void
    {
        $loan = $installment->loan;
        $trans_no = $installment->id;

        $narration = "Loan repayment — {$loan->loan_no}, installment #{$installment->installment_no} ({$paidVia})";
        $tranDate  = $installment->paid_date ?? now()->toDateString();

        if ((float) $installment->principal_due > 0) {
            GldTransaction::create([
                'trans_no'     => $trans_no,
                'type'         => StockMovement::TYPE_SACCO_LOAN_REPAYMENT,
                'tran_date'    => $tranDate,
                'account_code' => $this->cashAccount(),
                'reference'    => $loan->loan_no,
                'narration'    => $narration,
                'amount'       => (float) $installment->principal_due,
                'created_by'   => $createdBy,
            ]);
            GldTransaction::create([
                'trans_no'     => $trans_no,
                'type'         => StockMovement::TYPE_SACCO_LOAN_REPAYMENT,
                'tran_date'    => $tranDate,
                'account_code' => $this->loansReceivableAccount(),
                'reference'    => $loan->loan_no,
                'narration'    => $narration,
                'amount'       => -(float) $installment->principal_due,
                'created_by'   => $createdBy,
            ]);
        }

        if ((float) $installment->interest_due > 0) {
            GldTransaction::create([
                'trans_no'     => $trans_no,
                'type'         => StockMovement::TYPE_SACCO_LOAN_REPAYMENT,
                'tran_date'    => $tranDate,
                'account_code' => $this->cashAccount(),
                'reference'    => $loan->loan_no,
                'narration'    => $narration,
                'amount'       => (float) $installment->interest_due,
                'created_by'   => $createdBy,
            ]);
            GldTransaction::create([
                'trans_no'     => $trans_no,
                'type'         => StockMovement::TYPE_SACCO_LOAN_REPAYMENT,
                'tran_date'    => $tranDate,
                'account_code' => $this->loanInterestIncomeAccount(),
                'reference'    => $loan->loan_no,
                'narration'    => $narration,
                'amount'       => -(float) $installment->interest_due,
                'created_by'   => $createdBy,
            ]);
        }
    }

    /** One DR/CR leg pair for a simple two-account event. */
    private function postPair(
        int $type,
        int $transNo,
        string $tranDate,
        string $reference,
        string $narration,
        string $drAccount,
        string $crAccount,
        float $amount,
        string $createdBy,
    ): void {
        GldTransaction::create([
            'trans_no'     => $transNo,
            'type'         => $type,
            'tran_date'    => $tranDate,
            'account_code' => $drAccount,
            'reference'    => $reference,
            'narration'    => $narration,
            'amount'       => $amount,
            'created_by'   => $createdBy,
        ]);
        GldTransaction::create([
            'trans_no'     => $transNo,
            'type'         => $type,
            'tran_date'    => $tranDate,
            'account_code' => $crAccount,
            'reference'    => $reference,
            'narration'    => $narration,
            'amount'       => -$amount,
            'created_by'   => $createdBy,
        ]);
    }
}
