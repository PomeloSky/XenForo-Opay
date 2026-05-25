<?php

namespace YUCTS\Opay\Install\Data;

use XF\Db\Schema\Create;

class MySql
{
	/**
	 * 取得本套件需要建立的所有資料表。
	 *
	 * @return \Closure[]  table_name => function (Create $table)
	 */
	public static function getTables(): array
	{
		$tables = [];

		$tables['xf_yucts_opay_transaction'] = function (Create $table)
		{
			$table->addColumn('transaction_id', 'int')->autoIncrement();
			$table->addColumn('merchant_trade_no', 'varchar', 30)->setDefault('');
			$table->addColumn('request_key', 'varbinary', 32)->setDefault('');
			$table->addColumn('payment_profile_id', 'int')->setDefault(0);
			$table->addColumn('user_id', 'int')->setDefault(0);
			$table->addColumn('username', 'varchar', 50)->setDefault('');
			$table->addColumn('amount', 'decimal', '12,2')->setDefault(0);
			$table->addColumn('currency', 'varchar', 3)->setDefault('TWD');
			$table->addColumn('payment_method', 'varchar', 25)->setDefault('');
			$table->addColumn('payment_type', 'varchar', 50)->setDefault('');
			$table->addColumn('trade_no', 'varchar', 50)->setDefault('');
			$table->addColumn('status', 'enum')
				->values(['pending', 'paid', 'failed', 'cancelled', 'refunded'])
				->setDefault('pending');
			$table->addColumn('create_date', 'int')->setDefault(0);
			$table->addColumn('payment_date', 'int')->setDefault(0);
			$table->addColumn('last_callback_date', 'int')->setDefault(0);
			$table->addColumn('extra_info', 'mediumblob')->nullable();
			$table->addColumn('admin_note', 'text')->nullable();
			$table->addUniqueKey('merchant_trade_no');
			$table->addKey('request_key');
			$table->addKey('user_id');
			$table->addKey('status');
			$table->addKey(['status', 'create_date'], 'status_create_date');
			$table->addKey('trade_no');
		};

		return $tables;
	}
}
