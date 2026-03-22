<?php

namespace App\Services\Inventory;

use App\Models\GldTransaction;

class GldTransactionService
{
    /**
     * Write a pair of balanced GL entries (debit + credit) for one amount.
     *
     * @param  int    $transNo      Source document ID
     * @param  int    $type         Transaction type (same codes as StockMovement)
     * @param  string $date         Transaction date (Y-m-d)
     * @param  string $debitAccount  GL account to debit  (+amount)
     * @param  string $creditAccount GL account to credit (-amount)
     * @param  float  $amount        Absolute monetary amount
     * @param  string $reference     Document reference string
     * @param  string $narration     Description / memo
     * @param  string $createdBy     User ID
     * @param  array  $extra         Optional: dimension_id, dimension2_id
     */
    public static function doubleEntry(
        int    $transNo,
        int    $type,
        string $date,
        string $debitAccount,
        string $creditAccount,
        float  $amount,
        string $reference  = '',
        string $narration  = '',
        string $createdBy  = '',
        array  $extra      = []
    ): void {
        $shared = array_merge([
            'trans_no'   => $transNo,
            'type'       => $type,
            'tran_date'  => $date,
            'reference'  => $reference,
            'narration'  => $narration,
            'created_by' => $createdBy,
        ], array_intersect_key($extra, array_flip(['dimension_id', 'dimension2_id'])));

        // DEBIT  — positive amount
        GldTransaction::create(array_merge($shared, [
            'account_code' => $debitAccount,
            'amount'       => abs($amount),
        ]));

        // CREDIT — negative amount
        GldTransaction::create(array_merge($shared, [
            'account_code' => $creditAccount,
            'amount'       => -abs($amount),
        ]));
    }
}
