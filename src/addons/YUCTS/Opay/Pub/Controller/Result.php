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

		$rtnCode = (string) $this->filter('RtnCode', 'str');
		$status  = ($rtnCode === '1') ? 'success' : 'pending';

		// 統一導回 XF 內建的 /account/upgrade-purchase 頁；
		// 它會根據 xf_purchase_request 與已開通的升級顯示適當訊息。
		$redirectUrl = $this->buildLink('canonical:account/upgrade-purchase');

		$viewParams = [
			'redirectUrl' => $redirectUrl,
			'transaction' => $transaction,
			'status'      => $status,
		];

		return $this->view(
			'YUCTS\Opay:Payment\Result',
			'payment_return_opay',
			$viewParams
		);
	}
}
