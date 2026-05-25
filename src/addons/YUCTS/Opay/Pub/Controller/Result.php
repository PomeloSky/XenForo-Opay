<?php

namespace YUCTS\Opay\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Pub\Controller\AbstractController;
use YUCTS\Opay\Repository\OpayTransaction as TransactionRepo;

/**
 * OPay 付款完成後，使用者瀏覽器被導向的「公開」回傳頁。
 * 對應 OPay 的 OrderResultURL。
 *
 * 為什麼需要這個控制器：
 *   OPay 在使用者完成付款後，會以 POST 方式 (302/303) 把瀏覽器導向
 *   OrderResultURL。這是「跨站」POST 導向，瀏覽器套用 SameSite=Lax
 *   政策時不會帶 XF 的 session cookie，使用者會被當成未登入。
 *
 *   若 OrderResultURL 指向 /account/upgrade-purchase 這類需要登入的頁，
 *   使用者完成付款回站後會看到「請先登入」(即便他原本就有登入)。
 *
 *   解法：OrderResultURL 改指向本路由 /opay-return/{merchant_trade_no}/，
 *   不要求 cookies / 不需要登入；接到 POST 後，回傳一張極簡 HTML 頁，
 *   以 JS + meta refresh 雙保險跳轉至 /account/upgrade-purchase。
 *   因為這次跳轉是「同源 GET」，cookies 會正常送出，使用者保持登入。
 *
 *   付款結果以「ReturnURL (server-to-server callback)」為準；
 *   本頁不負責更新付款狀態。
 *
 * 名稱說明：本來想用 Return 命名，但 PHP 將 'return' 列為保留字
 * (class Return 在新版 PHP 會直接 parse error)，因此改用 Result。
 */
class Result extends AbstractController
{
	/**
	 * OPay 的跨站 POST 沒有 XF csrf token，跳過 CSRF 檢查。
	 *
	 * 注意：XF 沒有 getCsrfActions / getCsrfSkipActions 之類的設定點，
	 * 唯一正確的做法是直接 override checkCsrfIfNeeded() 為 no-op。
	 * (見 \XF\Mvc\Controller::preDispatch() 與 checkCsrfIfNeeded())
	 */
	public function checkCsrfIfNeeded($action, ParameterBag $params)
	{
		// 故意空 — 接受 OPay 來的跨站 POST
	}

	/**
	 * 公開頁，不能要求登入 (cookie 通常被 SameSite=Lax 擋下)。
	 * 我們也不需要 XF 的 IP/ban/policy 檢查，因為這頁的請求實際上是
	 * 「OPay 把使用者瀏覽器導回我們」，並非「使用者主動瀏覽論壇」。
	 */
	protected function preDispatchType($action, ParameterBag $params)
	{
		// 故意空 — 跳過所有 XF 預設的 viewing / login / TFA 檢查
	}

	public function actionIndex(ParameterBag $params)
	{
		$key = (string) $params->key;

		/** @var TransactionRepo $repo */
		$repo        = $this->repository('YUCTS\Opay:OpayTransaction');
		$transaction = $key === '' ? null : $repo->findByMerchantTradeNo($key);

		// OPay 用 POST 把交易結果一併帶回。盡可能解析以給使用者正確的訊息，
		// 但實際的「付款完成」判定仍以 server-to-server callback 為準。
		$payload = (array) $this->request()->getInput();

		$rtnCode = (string) ($payload['RtnCode'] ?? '');
		$rtnMsg  = (string) ($payload['RtnMsg']  ?? '');
		$tradeNo = (string) ($payload['TradeNo'] ?? '');

		// 驗證 CheckMacValue (若 transaction 存在且有 payload 可驗)。
		// 若 OPay 未 POST (例如 OPay 自己錯誤頁 auto redirect)、或 payload
		// 為空、或驗證失敗，視為「處理中」，仍導回升級頁；S2S callback
		// 一旦進來，狀態會由 webhook 真正更新。
		$macOk = false;
		if ($payload && $transaction && $transaction->PaymentProfile)
		{
			try
			{
				/** @var \YUCTS\Opay\Payment\Opay $handler */
				$handler = $transaction->PaymentProfile->Provider->handler ?? null;
				if ($handler && method_exists($handler, 'verifyReturnPayload'))
				{
					$macOk = $handler->verifyReturnPayload($transaction->PaymentProfile, $payload);
				}
			}
			catch (\Throwable $e) { /* 不影響跳轉 */ }
		}

		\YUCTS\Opay\Util\DebugLog::write('return_url',
			'key=' . $key
			. ' rtn=' . $rtnCode
			. ' msg=' . $rtnMsg
			. ' trade_no=' . $tradeNo
			. ' mac=' . ($macOk ? 'ok' : 'skip/fail')
		);

		// OPay 明確回報失敗 (RtnCode 非空且非 1) 且 CheckMacValue 驗證通過：
		// 自動把訂單狀態標記為 failed，並把 OPay 給的訊息寫入交易記錄，
		// 避免後台一直停在「待付款」。
		if (
			$transaction
			&& $macOk
			&& $rtnCode !== ''
			&& $rtnCode !== '1'
			&& $transaction->status === \YUCTS\Opay\Entity\OpayTransaction::STATUS_PENDING
		)
		{
			$transaction->status = \YUCTS\Opay\Entity\OpayTransaction::STATUS_FAILED;
			$extra = (array) ($transaction->extra_info ?: []);
			$extra['return_failure'] = [
				'time'     => \XF::$time,
				'rtn_code' => $rtnCode,
				'rtn_msg'  => $rtnMsg,
				'payload'  => $this->filterSensitive($payload),
			];
			$transaction->extra_info = $extra;
			$transaction->save();
		}

		// 依 RtnCode 推導畫面要顯示的狀態
		if ($rtnCode === '1' && $macOk)
		{
			$status     = 'success';
			$statusText = '付款已收到，正在帶您回到站內…';
		}
		else if ($rtnCode === '1')
		{
			// OPay 說成功但 CheckMacValue 對不上 / 缺資料 — 不能直接相信
			$status     = 'pending';
			$statusText = 'OPay 回報成功，但驗證未通過；系統將以 server-to-server 通知為準。正在帶您回到站內…';
		}
		else if ($rtnCode !== '' && $rtnCode !== '1')
		{
			$status     = 'failed';
			$statusText = '付款未完成 (#' . htmlspecialchars($rtnCode) . ')';
			if ($rtnMsg !== '')
			{
				$statusText .= '：' . htmlspecialchars($rtnMsg);
			}
		}
		else
		{
			$status     = 'pending';
			$statusText = '正在帶您回到站內，請稍候…';
		}

		// 成功 / 處理中 → 導回升級頁；失敗 → 導回升級選擇頁
		$redirectUrl = ($status === 'failed')
			? $this->buildLink('canonical:account/upgrades')
			: $this->buildLink('canonical:account/upgrade-purchase');

		$viewParams = [
			'redirectUrl' => $redirectUrl,
			'transaction' => $transaction,
			'status'      => $status,
			'statusText'  => $statusText,
			'rtnCode'     => $rtnCode,
			'rtnMsg'      => $rtnMsg,
		];

		return $this->view(
			'YUCTS\Opay:Payment\Result',
			'payment_return_opay',
			$viewParams
		);
	}

	/**
	 * 寫入訂單 extra_info 前移除 CheckMacValue 等不該長期保留的欄位。
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	protected function filterSensitive(array $payload): array
	{
		unset($payload['CheckMacValue']);
		return $payload;
	}
}
