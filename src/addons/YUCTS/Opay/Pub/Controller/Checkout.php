<?php

namespace YUCTS\Opay\Pub\Controller;

use XF\Entity\Purchasable;
use XF\Entity\PurchaseRequest;
use XF\Mvc\ParameterBag;
use XF\Pub\Controller\AbstractController;
use XF\Purchasable\AbstractPurchasable;
use YUCTS\Opay\Entity\OpayTransaction;
use YUCTS\Opay\Payment\Opay as OpayProvider;
use YUCTS\Opay\Repository\OpayTransaction as TransactionRepo;

/**
 * 中介頁：使用者按下「購買」後，由 \YUCTS\Opay\Payment\Opay::initiatePayment()
 * redirect 至此 URL (/opay-checkout/{merchant_trade_no}/)。
 *
 * 此 URL 為一般整頁載入，會回傳 Pub\View\Payment\Initiate 自訂 View
 * (純 HTML + auto-submit form)，瀏覽器即會立刻 POST 至 OPay 收銀台。
 */
class Checkout extends AbstractController
{
	public function actionIndex(ParameterBag $params)
	{
		$merchantTradeNo = (string) $params->key;
		if ($merchantTradeNo === '')
		{
			return $this->notFound();
		}

		/** @var TransactionRepo $repo */
		$repo        = $this->repository('YUCTS\Opay:OpayTransaction');
		$transaction = $repo->findByMerchantTradeNo($merchantTradeNo);

		if (!$transaction)
		{
			return $this->notFound(\XF::phrase('opay.invalid_checkout_request'));
		}

		// 僅允許 pending / failed 狀態的交易重新發起付款；
		// 已付款 / 已取消 / 已退款的訂單一律導回升級頁。
		if (!in_array($transaction->status, [OpayTransaction::STATUS_PENDING, OpayTransaction::STATUS_FAILED], true))
		{
			return $this->redirect($this->buildLink('account/upgrades'));
		}

		// 取 PurchaseRequest。先試 relation；若 relation 因型別 / 條件
		// 等原因取不到，改用直接 WHERE request_key 查詢，避免使用者卡在
		// 「無效的付款請求」訊息。
		$purchaseRequest = $transaction->PurchaseRequest;
		if (!$purchaseRequest && $transaction->request_key !== '')
		{
			$purchaseRequest = $this->em()->findOne(PurchaseRequest::class, [
				'request_key' => $transaction->request_key,
			]);
		}

		if (!$purchaseRequest)
		{
			return $this->error(
				\XF::phrase('opay.invalid_checkout_request')
				. ' [ref: ' . $merchantTradeNo . ']'
			);
		}

		$visitor = \XF::visitor();
		if ($purchaseRequest->user_id && $purchaseRequest->user_id != $visitor->user_id)
		{
			return $this->noPermission();
		}

		$purchase = $this->rebuildPurchase($purchaseRequest, $error);
		if (!$purchase)
		{
			return $this->error(
				($error ? (string) $error : (string) \XF::phrase('opay.invalid_checkout_request'))
				. ' [ref: ' . $merchantTradeNo . ']'
			);
		}

		/** @var OpayProvider $provider */
		$provider = $purchase->paymentProfile->Provider->handler;
		if (!$provider instanceof OpayProvider)
		{
			return $this->error(\XF::phrase('opay.invalid_checkout_request'));
		}

		$data = $provider->buildCheckoutFormData($purchaseRequest, $purchase);

		return $this->view(
			'YUCTS\Opay:Payment\Initiate',
			'',
			$data
		);
	}

	/**
	 * 由 PurchaseRequest 的 extra_data 重新建立 Purchase 物件。
	 *
	 * 流程：
	 *  1. 先試 handler->getPurchaseFromExtraData()
	 *     若失敗 (例如 canPurchase() 因使用者已擁有相同升級而傳 false)
	 *  2. 改以低階方式：找出 purchasable entity 並呼叫
	 *     handler->getPurchaseObject() 重建 (略過 canPurchase 限制)
	 *
	 * 「使用者已有此升級但仍進入結帳流程」的情形通常發生在：
	 *  - 站長以後台「手動成立訂單」測試後，再以同一帳號嘗試購買
	 *  - 升級已過期但仍掛在 Active 表中
	 *  - 同一帳號於不同視窗重複下單
	 *
	 *  既然使用者已主動發起 PurchaseRequest，我們應該讓他完成這次付款
	 *  (XF 的 completePurchase 對重複升級多半是冪等延長到期日)。
	 */
	protected function rebuildPurchase(PurchaseRequest $purchaseRequest, &$error = null)
	{
		$paymentProfile = $purchaseRequest->PaymentProfile;
		if (!$paymentProfile || !$paymentProfile->active)
		{
			$error = \XF::phrase('purchase_request_contains_invalid_payment_profile');
			return null;
		}

		/** @var Purchasable|null $purchasable */
		$purchasable = $this->em()->find(
			Purchasable::class,
			$purchaseRequest->purchasable_type_id
		);
		if (!$purchasable)
		{
			$error = \XF::phrase('invalid_purchase_request');
			return null;
		}

		/** @var AbstractPurchasable $handler */
		$handler = $purchasable->handler;
		$visitor = \XF::visitor();

		// 第一階段：標準流程
		$purchase = $handler->getPurchaseFromExtraData(
			$purchaseRequest->extra_data,
			$paymentProfile,
			$visitor,
			$error
		);
		if ($purchase)
		{
			return $purchase;
		}

		// 第二階段：略過 canPurchase 直接以 PurchasableEntity 重建
		try
		{
			$info = $handler->getPurchasableFromExtraData($purchaseRequest->extra_data);
		}
		catch (\Throwable $e)
		{
			$info = [];
		}
		$purchasableEntity = $info['purchasable'] ?? null;

		if ($purchasableEntity)
		{
			$error = null;
			return $handler->getPurchaseObject($paymentProfile, $purchasableEntity, $visitor);
		}

		return null;
	}
}
