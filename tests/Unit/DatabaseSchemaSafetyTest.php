<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Location;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\TransactionFnb;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSchemaSafetyTest extends TestCase
{
    /**
     * Test locations table structure and columns.
     */
    public function test_schema_has_locations_table_with_expected_columns()
    {
        $this->assertTrue(Schema::hasTable('locations'), 'locations table must exist');
        $expectedColumns = ['id', 'name', 'code', 'type', 'device_id', 'is_active', 'created_at', 'updated_at'];
        foreach ($expectedColumns as $col) {
            $this->assertTrue(Schema::hasColumn('locations', $col), "locations table missing column: {$col}");
        }
    }

    /**
     * Test additive session_token column on transactions table.
     */
    public function test_schema_has_transactions_session_token_column()
    {
        $this->assertTrue(Schema::hasColumn('transactions', 'session_token'), 'transactions table must have session_token column');
    }

    /**
     * Test additive is_fnb_staff column on users table.
     */
    public function test_schema_has_users_is_fnb_staff_column()
    {
        $this->assertTrue(Schema::hasColumn('users', 'is_fnb_staff'), 'users table must have is_fnb_staff column');
    }

    /**
     * Test fnb_orders and fnb_order_items tables structure and columns.
     */
    public function test_schema_has_fnb_orders_and_items_tables_with_expected_columns()
    {
        $this->assertTrue(Schema::hasTable('fnb_orders'), 'fnb_orders table must exist');
        $expectedOrderColumns = [
            'id', 'order_number', 'session_token', 'source', 'location_id',
            'transaction_id', 'customer_name', 'billing_name', 'status',
            'payment_status', 'requires_upfront_payment', 'notes', 'accepted_at',
            'cancelled_at', 'cancelled_by_user_id', 'cancelled_reason',
            'created_by_user_id', 'created_at', 'updated_at',
        ];
        foreach ($expectedOrderColumns as $col) {
            $this->assertTrue(Schema::hasColumn('fnb_orders', $col), "fnb_orders table missing column: {$col}");
        }

        $this->assertTrue(Schema::hasTable('fnb_order_items'), 'fnb_order_items table must exist');
        $expectedItemColumns = [
            'id', 'fnb_order_id', 'fnb_id', 'qty', 'unit_price_beli',
            'unit_price_jual', 'subtotal', 'payment_status', 'notes',
            'created_at', 'updated_at',
        ];
        foreach ($expectedItemColumns as $col) {
            $this->assertTrue(Schema::hasColumn('fnb_order_items', $col), "fnb_order_items table missing column: {$col}");
        }
    }

    /**
     * Test invoices and invoice_items tables structure and columns.
     */
    public function test_schema_has_invoices_and_items_tables_with_expected_columns()
    {
        $this->assertTrue(Schema::hasTable('invoices'), 'invoices table must exist');
        $expectedInvoiceColumns = [
            'id', 'invoice_number', 'transaction_id', 'invoice_type', 'subtotal',
            'discount', 'final_total', 'amount_paid', 'change_amount',
            'payment_method', 'cashier_user_id', 'paid_at', 'created_at', 'updated_at',
        ];
        foreach ($expectedInvoiceColumns as $col) {
            $this->assertTrue(Schema::hasColumn('invoices', $col), "invoices table missing column: {$col}");
        }

        $this->assertTrue(Schema::hasTable('invoice_items'), 'invoice_items table must exist');
        $expectedItemColumns = [
            'id', 'invoice_id', 'item_type', 'fnb_id', 'fnb_order_item_id',
            'transaction_fnb_id', 'name', 'qty', 'unit_price_beli',
            'unit_price_jual', 'subtotal', 'created_at', 'updated_at',
        ];
        foreach ($expectedItemColumns as $col) {
            $this->assertTrue(Schema::hasColumn('invoice_items', $col), "invoice_items table missing column: {$col}");
        }
    }

    /**
     * Test that inserting duplicate fnb_order_item_id in invoice_items throws QueryException.
     * This proves DB-level double-invoicing prevention!
     */
    public function test_database_prevents_duplicate_invoice_items_for_fnb_order_item()
    {
        // 1. Create cashier user
        $cashier = User::create([
            'name' => 'Cashier Test',
            'email' => 'cashier_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role' => 'kasir',
        ]);

        // 2. Create Location
        $location = Location::create([
            'name' => 'Station Test',
            'code' => 'ST-TEST-' . uniqid(),
            'type' => 'station',
            'is_active' => true,
        ]);

        // 3. Create FNB
        $fnb = Fnb::create([
            'nama' => 'Teh Botol Test',
            'kategori' => 'Minuman',
            'harga_beli' => 3000,
            'harga_jual' => 5000,
            'stok' => 10,
            'satuan' => 'botol',
        ]);

        // 4. Create FnbOrder & FnbOrderItem
        $order = FnbOrder::create([
            'order_number' => 'ORD-' . strtoupper(uniqid()),
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $location->id,
            'customer_name' => 'Customer A',
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'unpaid',
        ]);

        $orderItem = FnbOrderItem::create([
            'fnb_order_id' => $order->id,
            'fnb_id' => $fnb->id,
            'qty' => 1,
            'unit_price_beli' => 3000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
            'payment_status' => 'unpaid',
        ]);

        // 5. Create First Invoice & InvoiceItem
        $invoice1 = Invoice::create([
            'invoice_number' => 'INV-' . strtoupper(uniqid()),
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
            'subtotal' => 5000,
            'discount' => 0,
            'final_total' => 5000,
            'amount_paid' => 5000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $cashier->id,
            'paid_at' => now(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice1->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $fnb->id,
            'fnb_order_item_id' => $orderItem->id,
            'name' => $fnb->nama,
            'qty' => 1,
            'unit_price_beli' => 3000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
        ]);

        // 6. Create Second Invoice and attempt to attach the SAME fnb_order_item_id -> must throw QueryException
        $invoice2 = Invoice::create([
            'invoice_number' => 'INV-' . strtoupper(uniqid()),
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
            'subtotal' => 5000,
            'discount' => 0,
            'final_total' => 5000,
            'amount_paid' => 5000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $cashier->id,
            'paid_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        InvoiceItem::create([
            'invoice_id' => $invoice2->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $fnb->id,
            'fnb_order_item_id' => $orderItem->id, // Duplicate reference!
            'name' => $fnb->nama,
            'qty' => 1,
            'unit_price_beli' => 3000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
        ]);
    }

    /**
     * Test that inserting duplicate transaction_fnb_id in invoice_items throws QueryException.
     * This proves DB-level double-invoicing prevention for legacy transaction F&B items!
     */
    public function test_database_prevents_duplicate_invoice_items_for_transaction_fnb()
    {
        // 1. Create cashier user
        $cashier = User::create([
            'name' => 'Cashier Test 2',
            'email' => 'cashier_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role' => 'kasir',
        ]);

        // 2. Create FNB
        $fnb = Fnb::create([
            'nama' => 'Kopi Test',
            'kategori' => 'Minuman',
            'harga_beli' => 4000,
            'harga_jual' => 6000,
            'stok' => 10,
            'satuan' => 'cangkir',
        ]);

        // 3. Create Transaction & TransactionFnb
        $transaction = Transaction::create([
            'nama' => 'Player 1',
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '11:00',
            'harga' => 10000,
            'total' => 16000,
            'status' => 'aktif',
            'status_transaksi' => 'berjalan',
            'tipe_transaksi' => 'prepaid',
        ]);

        $txFnb = TransactionFnb::create([
            'transaction_id' => $transaction->id_transaksi,
            'fnb_id' => $fnb->id,
            'qty' => 1,
            'harga_beli' => 4000,
            'harga_jual' => 6000,
        ]);

        // 4. Create First Invoice & InvoiceItem
        $invoice1 = Invoice::create([
            'invoice_number' => 'INV-' . strtoupper(uniqid()),
            'invoice_type' => Invoice::TYPE_GAME_AND_FNB,
            'subtotal' => 16000,
            'discount' => 0,
            'final_total' => 16000,
            'amount_paid' => 20000,
            'change_amount' => 4000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $cashier->id,
            'paid_at' => now(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice1->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $fnb->id,
            'transaction_fnb_id' => $txFnb->id,
            'name' => $fnb->nama,
            'qty' => 1,
            'unit_price_beli' => 4000,
            'unit_price_jual' => 6000,
            'subtotal' => 6000,
        ]);

        // 5. Create Second Invoice and attempt to attach duplicate transaction_fnb_id -> must throw QueryException
        $invoice2 = Invoice::create([
            'invoice_number' => 'INV-' . strtoupper(uniqid()),
            'invoice_type' => Invoice::TYPE_GAME_AND_FNB,
            'subtotal' => 16000,
            'discount' => 0,
            'final_total' => 16000,
            'amount_paid' => 20000,
            'change_amount' => 4000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $cashier->id,
            'paid_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        InvoiceItem::create([
            'invoice_id' => $invoice2->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $fnb->id,
            'transaction_fnb_id' => $txFnb->id, // Duplicate reference!
            'name' => $fnb->nama,
            'qty' => 1,
            'unit_price_beli' => 4000,
            'unit_price_jual' => 6000,
            'subtotal' => 6000,
        ]);
    }

    /**
     * Test Location model fillable, casts and relations.
     */
    public function test_location_model_fillable_casts_and_relations()
    {
        $location = new Location();
        $this->assertEquals(['name', 'code', 'type', 'device_id', 'is_active'], $location->getFillable());
        $this->assertEquals('boolean', $location->getCasts()['is_active'] ?? null);

        $deviceRelation = $location->device();
        $this->assertInstanceOf(BelongsTo::class, $deviceRelation);
        $this->assertEquals('device_id', $deviceRelation->getForeignKeyName());

        $fnbOrdersRelation = $location->fnbOrders();
        $this->assertInstanceOf(HasMany::class, $fnbOrdersRelation);
        $this->assertEquals('location_id', $fnbOrdersRelation->getForeignKeyName());
    }

    /**
     * Test FnbOrder and FnbOrderItem models.
     */
    public function test_fnb_order_and_item_models_fillable_casts_and_relations()
    {
        $this->assertSame('QR', FnbOrder::SOURCE_QR);
        $this->assertSame('CASHIER', FnbOrder::SOURCE_CASHIER);
        $this->assertSame('FNB_STAFF', FnbOrder::SOURCE_FNB_STAFF);

        $this->assertSame('PENDING', FnbOrder::STATUS_PENDING);
        $this->assertSame('ACCEPTED', FnbOrder::STATUS_ACCEPTED);
        $this->assertSame('PREPARING', FnbOrder::STATUS_PREPARING);
        $this->assertSame('READY', FnbOrder::STATUS_READY);
        $this->assertSame('DELIVERED', FnbOrder::STATUS_DELIVERED);
        $this->assertSame('CANCELLED', FnbOrder::STATUS_CANCELLED);
        $this->assertSame('EXPIRED', FnbOrder::STATUS_EXPIRED);

        $order = new FnbOrder();
        $expectedFillable = [
            'order_number', 'session_token', 'source', 'location_id',
            'transaction_id', 'customer_name', 'billing_name', 'status',
            'payment_status', 'requires_upfront_payment', 'notes', 'accepted_at',
            'cancelled_at', 'cancelled_by_user_id', 'cancelled_reason',
            'created_by_user_id',
        ];
        $this->assertEquals($expectedFillable, $order->getFillable());
        $this->assertEquals('boolean', $order->getCasts()['requires_upfront_payment'] ?? null);
        $this->assertEquals('datetime', $order->getCasts()['accepted_at'] ?? null);
        $this->assertEquals('datetime', $order->getCasts()['cancelled_at'] ?? null);

        $this->assertInstanceOf(BelongsTo::class, $order->location());
        $this->assertInstanceOf(BelongsTo::class, $order->transaction());
        $this->assertEquals('id_transaksi', $order->transaction()->getOwnerKeyName());
        $this->assertInstanceOf(HasMany::class, $order->items());
        $this->assertInstanceOf(BelongsTo::class, $order->createdByUser());
        $this->assertInstanceOf(BelongsTo::class, $order->cancelledByUser());

        $item = new FnbOrderItem();
        $this->assertInstanceOf(BelongsTo::class, $item->order());
        $this->assertInstanceOf(BelongsTo::class, $item->fnb());
        $this->assertInstanceOf(HasOne::class, $item->invoiceItem());
    }

    /**
     * Test Invoice and InvoiceItem models.
     */
    public function test_invoice_and_item_models_fillable_casts_and_relations()
    {
        $this->assertSame('GAME_AND_FNB', Invoice::TYPE_GAME_AND_FNB);
        $this->assertSame('GAME_ONLY', Invoice::TYPE_GAME_ONLY);
        $this->assertSame('FNB_STANDALONE', Invoice::TYPE_FNB_STANDALONE);

        $this->assertSame('GAME_RENTAL', InvoiceItem::ITEM_TYPE_GAME_RENTAL);
        $this->assertSame('FNB_ITEM', InvoiceItem::ITEM_TYPE_FNB_ITEM);

        $invoice = new Invoice();
        $this->assertEquals('datetime', $invoice->getCasts()['paid_at'] ?? null);
        $this->assertInstanceOf(BelongsTo::class, $invoice->transaction());
        $this->assertEquals('id_transaksi', $invoice->transaction()->getOwnerKeyName());
        $this->assertInstanceOf(BelongsTo::class, $invoice->cashierUser());
        $this->assertInstanceOf(HasMany::class, $invoice->items());

        $item = new InvoiceItem();
        $this->assertInstanceOf(BelongsTo::class, $item->invoice());
        $this->assertInstanceOf(BelongsTo::class, $item->fnb());
        $this->assertInstanceOf(BelongsTo::class, $item->orderItem());
        $this->assertInstanceOf(BelongsTo::class, $item->transactionFnb());
    }

    /**
     * Test User model is_fnb_staff attribute and isFnbStaff() RBAC method.
     */
    public function test_user_model_fnb_staff_fillable_casts_and_rbac_helper()
    {
        $user = new User();
        $this->assertContains('is_fnb_staff', $user->getFillable());
        $this->assertEquals('boolean', $user->getCasts()['is_fnb_staff'] ?? null);

        // Kasir with is_fnb_staff = true -> true
        $fnbStaff = new User(['role' => 'kasir', 'is_fnb_staff' => true]);
        $this->assertTrue($fnbStaff->isFnbStaff());

        // Kasir with is_fnb_staff = false -> false
        $normalKasir = new User(['role' => 'kasir', 'is_fnb_staff' => false]);
        $this->assertFalse($normalKasir->isFnbStaff());

        // Admin with is_fnb_staff = true -> false (only kasir role is considered F&B staff per requirement)
        $admin = new User(['role' => 'admin', 'is_fnb_staff' => true]);
        $this->assertFalse($admin->isFnbStaff());

        // Owner with is_fnb_staff = true -> false
        $owner = new User(['role' => 'owner', 'is_fnb_staff' => true]);
        $this->assertFalse($owner->isFnbStaff());
    }

    /**
     * Test Transaction model session_token fillable and new relations.
     */
    public function test_transaction_model_session_token_and_relations()
    {
        $transaction = new Transaction();
        $this->assertContains('session_token', $transaction->getFillable());

        $fnbOrdersRelation = $transaction->fnbOrders();
        $this->assertInstanceOf(HasMany::class, $fnbOrdersRelation);
        $this->assertEquals('transaction_id', $fnbOrdersRelation->getForeignKeyName());
        $this->assertEquals('transactions.id_transaksi', $fnbOrdersRelation->getQualifiedParentKeyName());

        $invoicesRelation = $transaction->invoices();
        $this->assertInstanceOf(HasMany::class, $invoicesRelation);
        $this->assertEquals('transaction_id', $invoicesRelation->getForeignKeyName());
        $this->assertEquals('transactions.id_transaksi', $invoicesRelation->getQualifiedParentKeyName());
    }
}
