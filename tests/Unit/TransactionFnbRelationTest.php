<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\TransactionFnb;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionFnbRelationTest extends TestCase
{
    /**
     * Test that TransactionFnb belongsTo Transaction with correct foreign and owner keys.
     *
     * @return void
     */
    public function test_transaction_fnb_belongs_to_transaction_with_id_transaksi_key()
    {
        $fnbItem = new TransactionFnb();
        $relation = $fnbItem->transaction();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertEquals('transaction_id', $relation->getForeignKeyName());
        $this->assertEquals('id_transaksi', $relation->getOwnerKeyName());
        $this->assertStringContainsString('id_transaksi', $relation->toSql());
        $this->assertStringNotContainsString('`transactions`.`id`', $relation->toSql());
    }

    /**
     * Test that Transaction hasMany TransactionFnb with correct keys.
     *
     * @return void
     */
    public function test_transaction_has_many_transaction_fnbs()
    {
        $transaction = new Transaction();
        $relation = $transaction->transactionFnbs();

        $this->assertEquals('transaction_id', $relation->getForeignKeyName());
        $this->assertEquals('transactions.id_transaksi', $relation->getQualifiedParentKeyName());
    }
}
