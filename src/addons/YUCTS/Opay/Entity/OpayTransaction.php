<?php

namespace YUCTS\Opay\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * OPay 訂單 (交易) 紀錄。
 *
 * 用於記錄每一筆由站內發起的 OPay 付款請求，與 XF 內建的 xf_payment_provider_log
 * 互為對應 (以 request_key 連結)。後台訂單管理畫面即以本資料表為主資料源，
 * 並可進行手動成立 / 取消 / 退刷等管理操作。
 *
 * COLUMNS
 * @property int|null $transaction_id
 * @property string $merchant_trade_no
 * @property string $request_key
 * @property int $payment_profile_id
 * @property int $user_id
 * @property string $username
 * @property float $amount
 * @property string $currency
 * @property string $payment_method
 * @property string $payment_type
 * @property string $trade_no
 * @property string $status
 * @property int $create_date
 * @property int $payment_date
 * @property int $last_callback_date
 * @property array|null $extra_info
 * @property string|null $admin_note
 *
 * RELATIONS
 * @property-read \XF\Entity\User|null $User
 * @property-read \XF\Entity\PaymentProfile|null $PaymentProfile
 * @property-read \XF\Entity\PurchaseRequest|null $PurchaseRequest
 */
class OpayTransaction extends Entity
{
	public const STATUS_PENDING   = 'pending';
	public const STATUS_PAID      = 'paid';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_REFUNDED  = 'refunded';

	public static function getStructure(Structure $structure)
	{
		$structure->table      = 'xf_yucts_opay_transaction';
		$structure->shortName  = 'YUCTS\Opay:OpayTransaction';
		$structure->primaryKey = 'transaction_id';
		$structure->columns    = [
			'transaction_id'     => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
			'merchant_trade_no'  => ['type' => self::STR, 'maxLength' => 30, 'required' => true],
			'request_key'        => ['type' => self::BINARY, 'maxLength' => 32, 'default' => ''],
			'payment_profile_id' => ['type' => self::UINT, 'default' => 0],
			'user_id'            => ['type' => self::UINT, 'default' => 0],
			'username'           => ['type' => self::STR, 'maxLength' => 50, 'default' => ''],
			'amount'             => ['type' => self::FLOAT, 'default' => 0],
			'currency'           => ['type' => self::STR, 'maxLength' => 3, 'default' => 'TWD'],
			'payment_method'     => ['type' => self::STR, 'maxLength' => 25, 'default' => ''],
			'payment_type'       => ['type' => self::STR, 'maxLength' => 50, 'default' => ''],
			'trade_no'           => ['type' => self::STR, 'maxLength' => 50, 'default' => ''],
			'status'             => [
				'type'         => self::STR,
				'default'      => self::STATUS_PENDING,
				'allowedValues' => [
					self::STATUS_PENDING,
					self::STATUS_PAID,
					self::STATUS_FAILED,
					self::STATUS_CANCELLED,
					self::STATUS_REFUNDED,
				],
			],
			'create_date'        => ['type' => self::UINT, 'default' => \XF::$time],
			'payment_date'       => ['type' => self::UINT, 'default' => 0],
			'last_callback_date' => ['type' => self::UINT, 'default' => 0],
			'extra_info'         => ['type' => self::JSON_ARRAY, 'default' => [], 'nullable' => true],
			'admin_note'         => ['type' => self::STR, 'default' => null, 'nullable' => true],
		];
		$structure->getters    = [
			'status_phrase' => true,
		];
		$structure->relations  = [
			'User' => [
				'entity'     => 'XF:User',
				'type'       => self::TO_ONE,
				'conditions' => 'user_id',
				'primary'    => true,
			],
			'PaymentProfile' => [
				'entity'     => 'XF:PaymentProfile',
				'type'       => self::TO_ONE,
				'conditions' => 'payment_profile_id',
				'primary'    => true,
			],
			'PurchaseRequest' => [
				'entity'     => 'XF:PurchaseRequest',
				'type'       => self::TO_ONE,
				'conditions' => [['request_key', '=', '$request_key']],
				'primary'    => true,
			],
		];

		return $structure;
	}

	public function getStatusPhrase(): \XF\Phrase
	{
		return \XF::phrase('opay.status.' . $this->status);
	}

	public function canManuallyComplete(): bool
	{
		return in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED], true);
	}

	public function canManuallyCancel(): bool
	{
		return in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED], true);
	}

	public function canRefund(): bool
	{
		return $this->status === self::STATUS_PAID
			&& $this->payment_method === 'Credit'
			&& $this->trade_no !== '';
	}
}
