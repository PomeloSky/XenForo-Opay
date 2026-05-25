<?php

namespace YUCTS\Opay\Cron;

use YUCTS\Opay\Repository\OpayTransaction as TransactionRepo;

/**
 * 每日凌晨 04:15 執行：依「交易記錄保留天數」清除 pending / failed / cancelled
 * 的舊有交易記錄。已付款 (paid) 與已退款 (refunded) 永久保留以利對帳。
 */
class Cleanup
{
	public static function run(): void
	{
		$days = (int) \XF::options()->yuctsOpayLogRetentionDays;
		if ($days < 7)
		{
			return;
		}

		/** @var TransactionRepo $repo */
		$repo = \XF::repository('YUCTS\Opay:OpayTransaction');
		$repo->pruneOldPendingTransactions($days);
	}
}
