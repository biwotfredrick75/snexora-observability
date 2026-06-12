<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single gateway for all GL postings.
 * Every insert to gld_transactions MUST go through post() so balance is
 * enforced at the point of write, not just checked after the fact.
 *
 * Contract: SUM(amount) for a given (type, trans_no) batch must equal zero.
 * Positive amount = debit, negative amount = credit.
 */
class GlPostingService
{
    private const BALANCE_TOLERANCE = 0.005;

    /**
     * Validate that entries balance then insert them.
     *
     * @param array $lines  Rows ready for gld_transactions (must all share the same type+trans_no)
     * @throws RuntimeException if entries don't balance
     */
    public static function post(array $lines): void
    {
        if (empty($lines)) return;

        self::assertBalanced($lines);

        DB::table('gld_transactions')->insert($lines);
    }

    /**
     * Assert entries sum to zero. Safe to call before a DB::transaction insert too.
     *
     * @throws RuntimeException
     */
    public static function assertBalanced(array $lines): void
    {
        $sum = array_sum(array_column($lines, 'amount'));

        if (abs($sum) > self::BALANCE_TOLERANCE) {
            $type    = $lines[0]['type']     ?? '?';
            $transNo = $lines[0]['trans_no'] ?? '?';
            throw new RuntimeException(
                sprintf(
                    'GL imbalance detected for type=%s trans_no=%s: entries sum to %.4f (must be zero). '
                    . 'Debits must equal credits.',
                    $type, $transNo, $sum
                )
            );
        }
    }

    /**
     * Build a standard GL line array — keeps all callers consistent.
     */
    public static function line(
        int     $type,
        int     $transNo,
        string  $tranDate,
        string  $accountCode,
        float   $amount,
        string  $reference  = '',
        ?string $narration  = null,
        string  $createdBy  = 'system',
        ?int    $dimensionId  = null,
        ?int    $dimension2Id = null,
    ): array {
        return [
            'type'          => $type,
            'trans_no'      => $transNo,
            'tran_date'     => $tranDate,
            'account_code'  => $accountCode,
            'amount'        => $amount,
            'reference'     => $reference,
            'narration'     => $narration,
            'created_by'    => $createdBy,
            'dimension_id'  => $dimensionId,
            'dimension2_id' => $dimension2Id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ];
    }
}
