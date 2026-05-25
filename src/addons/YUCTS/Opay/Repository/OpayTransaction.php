<?php

namespace YUCTS\Opay\Repository;

use XF\Mvc\Entity\Repository;
use YUCTS\Opay\Entity\OpayTransaction as TransactionEntity;
use YUCTS\Opay\Finder\OpayTransaction as TransactionFinder;

class OpayTransaction extends Repository
{
	public function findTransactionsForList(): TransactionFinder
	{
		/** @var TransactionFinder $finder */
		$finder = $this->finder('YUCTS\Opay:OpayTransaction')
			->setDefaultOrder('create_date', 'desc');
		return $finder;
	}

	public function findByMerchantTradeNo(string $merchantTradeNo): ?TransactionEntity
	{
		/** @var TransactionEntity|null $entity */
		$entity = $this->finder('YUCTS\Opay:OpayTransaction')
			->where('merchant_trade_no', $merchantTradeNo)
			->fetchOne();
		return $entity;
	}

	public function findByRequestKey(string $requestKey): ?TransactionEntity
	{
		if ($requestKey === '')
		{
			return null;
		}

		/** @var TransactionEntity|null $entity */
		$entity = $this->finder('YUCTS\Opay:OpayTransaction')
			->where('request_key', $requestKey)
			->fetchOne();
		return $entity;
	}

	/**
	 * 將 OPay 平台用的廠商交易編號 (最長 20 字元) 由 XF 的 request_key 推導。
	 * 為避開 OPay 的長度限制，我們使用「YS + 13 碼 hex」共 15 字元，
	 * 既不會超過 20 字元，又可由 request_key 反查。
	 */
	public function generateMerchantTradeNo(string $requestKey): string
	{
		$hash = substr(strtoupper(sha1($requestKey)), 0, 13);
		return 'YS' . $hash;
	}

	/**
	 * 清除超過保留天數的歷史交易記錄 (保留 paid / refunded 以利對帳)。
	 */
	public function pruneOldPendingTransactions(int $retentionDays): int
	{
		$cutoff = \XF::$time - ($retentionDays * 86400);

		return $this->db()->delete(
			'xf_yucts_opay_transaction',
			'create_date < ? AND status IN (?, ?, ?)',
			[
				$cutoff,
				TransactionEntity::STATUS_PENDING,
				TransactionEntity::STATUS_FAILED,
				TransactionEntity::STATUS_CANCELLED,
			]
		);
	}
}
