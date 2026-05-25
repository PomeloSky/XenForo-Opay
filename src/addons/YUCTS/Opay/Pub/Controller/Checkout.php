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

		$purchaseRequest = $transaction->PurchaseRequest;
		if (!$purchaseRequest)
		{
			return $this->notFound(\XF::phrase('opay.invalid_checkout_request'));
		}

		$visitor = \XF::visitor();
		if ($purchaseRequest->user_id && $purchaseRequest->user_id != $visitor->user_id)
		{
			return $this->noPermission();
		}

		$purchase = $this->rebuildPurchase($purchaseRequest, $error);
		if (!$purchase)
		{
			return $this->error($error ?: \XF::phrase('opay.invalid_checkout_request'));
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
	 * 由 PurchaseRequest 的 extra_data 重新建立 Purchase 物件，
	 * 與 XF 內建 \XF\Pub\Controller\PurchaseController::actionProcess() 流程一致。
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
		$handler  = $purchasable->handler;
		$purchase = $handler->getPurchaseFromExtraData(
			$purchaseRequest->extra_data,
			$paymentProfile,
			\XF::visitor(),
			$error
		);

		return $purchase ?: null;
	}
}
