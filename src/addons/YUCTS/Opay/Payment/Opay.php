<?php

namespace YUCTS\Opay\Payment;

use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Http\Request;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;
use YUCTS\Opay\Entity\OpayTransaction;
use YUCTS\Opay\Repository\OpayTransaction as TransactionRepo;
use YUCTS\Opay\Util\DebugLog;
use YUCTS\Opay\Vendor\Sdk;
use YUCTS\Opay\Vendor\SdkException;

/**
 * OPay 歐付寶 / ECPay 綠界 (AIO) 付款服務提供者。
 *
 * 完整實作 XenForo 2.3 \XF\Payment\AbstractProvider，
 * 並透過 \YUCTS\Opay\Vendor\Sdk 與 OPay AIO 平台互動。
 */
class Opay extends AbstractProvider
{
	public function getTitle(): string
	{
		return \XF::phrase('payment_provider.opay');
	}

	// =================================================================
	// 後台設定頁
	// =================================================================

	public function renderConfig(PaymentProfile $profile): string
	{
		$data = [
			'profile' => $profile,
		];
		return \XF::app()->templater()->renderTemplate(
			'admin:payment_profile_opay',
			$data
		);
	}

	public function verifyConfig(array &$options, &$errors = []): bool
	{
		$merchantId = trim($options['merchant_id'] ?? '');
		$hashKey    = trim($options['hash_key'] ?? '');
		$hashIv     = trim($options['hash_iv'] ?? '');

		if ($merchantId === '' || strlen($merchantId) > 10)
		{
			$errors[] = '請填寫合法的 MerchantID (1~10 字元)。';
		}
		if ($hashKey === '')
		{
			$errors[] = '請填寫 HashKey。';
		}
		if ($hashIv === '')
		{
			$errors[] = '請填寫 HashIV。';
		}

		$provider = $options['service_provider'] ?? 'opay';
		if (!in_array($provider, ['opay', 'ecpay'], true))
		{
			$errors[] = '請選擇正確的服務商。';
		}

		$env = $options['environment'] ?? 'stage';
		if (!in_array($env, ['stage', 'production'], true))
		{
			$errors[] = '請選擇正確的環境。';
		}

		$encryptType = $options['encrypt_type'] ?? 'sha256';
		if (!in_array($encryptType, ['sha256', 'md5'], true))
		{
			$errors[] = '請選擇正確的 CheckMacValue 加密方式。';
		}
		$options['encrypt_type'] = $encryptType;

		$methods = (array) ($options['payment_methods'] ?? []);
		$valid   = ['Credit', 'WebATM', 'ATM', 'CVS'];
		$methods = array_values(array_intersect($valid, $methods));
		if (!$methods)
		{
			$methods = ['Credit'];
		}
		$options['payment_methods'] = $methods;

		$atmExpire = (int) ($options['atm_expire_date'] ?? 3);
		if ($atmExpire < 1 || $atmExpire > 60)
		{
			$atmExpire = 3;
		}
		$options['atm_expire_date'] = $atmExpire;

		$cvsExpire = (int) ($options['cvs_expire_minutes'] ?? 10080);
		if ($cvsExpire < 60 || $cvsExpire > 43200)
		{
			$cvsExpire = 10080;
		}
		$options['cvs_expire_minutes'] = $cvsExpire;

		return empty($errors);
	}

	// =================================================================
	// 發起付款
	// =================================================================

	public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
	{
		// 不直接回傳含 OPay 表單的 View。原因：
		// XenForo 的 /account/upgrades 「購買」按鈕是以 AJAX 提交 (_xfResponseType=json)，
		// 若我們回傳 View，XF AJAX 處理器只會把它當 overlay 內容處理，無法觸發瀏覽器整頁
		// 跳轉至 OPay。實務上會看到使用者被導回 OrderResultURL (/account/upgrade-purchase)
		// 而沒有真正前往 OPay。
		//
		// 解法：先把訂單寫入 (pending)，然後 redirect 至我們站內的中介頁
		// /opay-checkout/{merchant_trade_no}/，由 XF AJAX 接到 redirect 後負責瀏覽器
		// 整頁跳轉。中介頁 (Pub\Controller\Checkout) 再重新組裝參數並輸出
		// 自動 submit 至 OPay 的純 HTML 表單。
		$transaction = $this->createOrUpdateTransaction($purchaseRequest, $purchase);

		$url = $controller->buildLink('opay-checkout', ['key' => $transaction->merchant_trade_no]);

		DebugLog::write('initiatePayment',
			'redirect → ' . $url
			. ' (merchant_trade_no=' . $transaction->merchant_trade_no
			. ', amount=' . $purchaseRequest->cost_amount . ')'
		);

		return $controller->redirect($url);
	}

	/**
	 * 由 Pub\Controller\Checkout 呼叫，產生送往 OPay 的完整表單參數
	 * (含 MerchantID / EncryptType / CheckMacValue) 與 action URL。
	 *
	 * @return array{action: string, params: array<string,scalar>}
	 */
	public function buildCheckoutFormData(PurchaseRequest $purchaseRequest, Purchase $purchase): array
	{
		$paymentProfile = $purchase->paymentProfile;
		$sdk            = $this->buildSdk($paymentProfile);

		$transaction = $this->createOrUpdateTransaction($purchaseRequest, $purchase);

		$methods       = (array) ($paymentProfile->options['payment_methods'] ?? ['Credit']);
		$choosePayment = count($methods) === 1 ? reset($methods) : 'ALL';
		$ignorePayment = '';
		if ($choosePayment === 'ALL')
		{
			$all       = ['Credit', 'WebATM', 'ATM', 'CVS', 'BARCODE', 'ApplePay', 'TWQR'];
			$ignore    = array_values(array_diff($all, $methods));
			$ignorePayment = implode('#', $ignore);
		}

		$itemName  = $this->buildItemName($purchase);
		$tradeDesc = $this->sanitizeTradeDesc($purchase->title ?: $purchase->purchasableTitle ?: 'Purchase');
		$amount    = (int) round((float) $purchaseRequest->cost_amount);

		// OrderResultURL 必須是「不需要登入」的公開頁，因為 OPay 是用跨站 POST
		// 把瀏覽器導回，SameSite=Lax 政策會擋下 XF session cookie。
		// 把它指到我們自己的 /opay-return/{key}/，再以同源 JS / meta refresh 跳回
		// /account/upgrade-purchase；那次跳轉是同源 GET，cookies 會正常帶出。
		$resultUrl = \XF::app()->router('public')->buildLink(
			'canonical:opay-return',
			['key' => $transaction->merchant_trade_no]
		);

		$params = [
			'MerchantTradeNo'   => $transaction->merchant_trade_no,
			'MerchantTradeDate' => gmdate('Y/m/d H:i:s', \XF::$time + 28800),
			'PaymentType'       => 'aio',
			'TotalAmount'       => $amount,
			'TradeDesc'         => $tradeDesc,
			'ItemName'          => $itemName,
			'ReturnURL'         => $this->getCallbackUrl(),
			'ClientBackURL'     => $purchase->cancelUrl,
			'OrderResultURL'    => $resultUrl,
			'ChoosePayment'     => $choosePayment,
			'NeedExtraPaidInfo' => 'N',
			'EncryptType'       => $sdk->getEncryptType(),
		];

		if ($ignorePayment !== '')
		{
			$params['IgnorePayment'] = $ignorePayment;
		}
		if ($choosePayment === 'ATM' || in_array('ATM', $methods, true))
		{
			$params['ExpireDate'] = (int) ($paymentProfile->options['atm_expire_date'] ?? 3);
		}
		if ($choosePayment === 'CVS' || in_array('CVS', $methods, true))
		{
			$params['StoreExpireDate'] = (int) ($paymentProfile->options['cvs_expire_minutes'] ?? 10080);
		}

		$params = $sdk->buildCheckoutParameters($params);
		$action = $sdk->getCheckoutEndpoint();

		$debug = $params;
		unset($debug['CheckMacValue']);
		DebugLog::write('buildCheckoutFormData',
			$action . ' ' . json_encode($debug, JSON_UNESCAPED_UNICODE)
		);

		return ['action' => $action, 'params' => $params];
	}

	/**
	 * 建立 / 更新對應的 OpayTransaction 紀錄。
	 */
	protected function createOrUpdateTransaction(PurchaseRequest $purchaseRequest, Purchase $purchase): OpayTransaction
	{
		/** @var TransactionRepo $repo */
		$repo = \XF::repository('YUCTS\Opay:OpayTransaction');

		$merchantTradeNo = $repo->generateMerchantTradeNo($purchaseRequest->request_key);

		$transaction = $repo->findByMerchantTradeNo($merchantTradeNo);
		if (!$transaction)
		{
			/** @var OpayTransaction $transaction */
			$transaction = \XF::em()->create('YUCTS\Opay:OpayTransaction');
			$transaction->merchant_trade_no  = $merchantTradeNo;
			$transaction->request_key        = $purchaseRequest->request_key;
			$transaction->payment_profile_id = (int) $purchaseRequest->payment_profile_id;
			$transaction->user_id            = (int) $purchaseRequest->user_id;
			$transaction->username           = $purchase->purchaser->username ?? '';
			$transaction->amount             = (float) $purchaseRequest->cost_amount;
			$transaction->currency           = $purchaseRequest->cost_currency;
			$transaction->status             = OpayTransaction::STATUS_PENDING;
			$transaction->save();
		}

		return $transaction;
	}

	protected function buildItemName(Purchase $purchase): string
	{
		$title    = $purchase->purchasableTitle ?: $purchase->title ?: 'Purchase';
		// 移除 OPay ItemName 不接受的字元：# 為分隔符；換行 / Tab 會破壞表單
		$title    = preg_replace('/[#\r\n\t]+/u', ' ', $title);
		$title    = trim(preg_replace('/\s+/u', ' ', $title));
		$cost     = (int) round((float) $purchase->cost);
		$currency = $purchase->currency ?: 'TWD';
		$itemName = sprintf('#%s %d %s x 1', $this->truncateUtf8($title, 150), $cost, $currency);
		return $this->truncateUtf8($itemName, 200);
	}

	/**
	 * 將 TradeDesc 清理為 OPay 可接受的純文字 (≤ 200 字元、無控制字元)。
	 * 注意：「不要」對此值 urlencode；表單提交時瀏覽器會自動 url-encode 一次，
	 * 多餘的 urlencode 會造成顯示出 %XX 字串以及 CheckMacValue 不必要的歧異。
	 */
	protected function sanitizeTradeDesc(string $s): string
	{
		$s = preg_replace('/[\r\n\t]+/u', ' ', $s);
		$s = trim(preg_replace('/\s+/u', ' ', $s));
		return $this->truncateUtf8($s, 200);
	}

	/**
	 * 安全截斷 UTF-8 字串。
	 */
	protected function truncateUtf8(string $s, int $maxChars): string
	{
		if (mb_strlen($s, 'UTF-8') <= $maxChars)
		{
			return $s;
		}
		return mb_substr($s, 0, $maxChars, 'UTF-8');
	}

	// =================================================================
	// Callback 處理
	// =================================================================

	public function setupCallback(Request $request): CallbackState
	{
		$state = new CallbackState();

		$input = $request->getInput();
		// 同時相容 GET (OrderResultURL) 與 POST (ReturnURL) 帶回的參數
		if (!$input)
		{
			$input = $_REQUEST;
		}

		$state->inputRaw = $input;

		$merchantTradeNo = (string) ($input['MerchantTradeNo'] ?? '');
		$state->merchantTradeNo = $merchantTradeNo;
		$state->transactionId   = (string) ($input['TradeNo'] ?? $merchantTradeNo);

		/** @var TransactionRepo $repo */
		$repo = \XF::repository('YUCTS\Opay:OpayTransaction');
		$transaction = $merchantTradeNo === '' ? null : $repo->findByMerchantTradeNo($merchantTradeNo);
		if ($transaction)
		{
			$state->opayTransaction = $transaction;
			$state->requestKey      = $transaction->request_key;
		}

		return $state;
	}

	public function validateCallback(CallbackState $state): bool
	{
		$input = (array) $state->inputRaw;
		if (!$input)
		{
			$state->logType    = 'error';
			$state->logMessage = '回傳資料為空。';
			$state->httpCode   = 400;
			return false;
		}

		$paymentProfile = $state->getPaymentProfile();
		if (!$paymentProfile)
		{
			$state->logType    = 'error';
			$state->logMessage = '找不到對應的付款設定檔。';
			$state->httpCode   = 404;
			return false;
		}

		$sdk = $this->buildSdk($paymentProfile);

		if (!$sdk->verifyCheckMacValue($input))
		{
			$state->logType    = 'error';
			$state->logMessage = (string) \XF::phrase('opay.invalid_check_mac_value');
			$state->httpCode   = 400;
			return false;
		}

		return true;
	}

	public function validateTransaction(CallbackState $state): bool
	{
		$input  = (array) $state->inputRaw;
		$rtnCode = (string) ($input['RtnCode'] ?? '');

		// 僅針對「成功」的回呼避免重複處理；其餘狀態 (例如 ATM 取號通知 RtnCode=2)
		// 不應視為「已處理過的付款」而 skip，因此回傳 true。
		if ($rtnCode !== '1')
		{
			return true;
		}

		return parent::validateTransaction($state);
	}

	public function validateCost(CallbackState $state): bool
	{
		$purchaseRequest = $state->getPurchaseRequest();
		if (!$purchaseRequest)
		{
			$state->logType    = 'error';
			$state->logMessage = '找不到對應的購買請求。';
			$state->httpCode   = 404;
			return false;
		}

		$expected = (int) round((float) $purchaseRequest->cost_amount);
		$paid     = (int) ($state->inputRaw['TradeAmt'] ?? 0);
		if ($paid !== $expected)
		{
			$state->logType    = 'error';
			$state->logMessage = '金額不符 (預期 ' . $expected . '，收到 ' . $paid . ')。';
			$state->httpCode   = 400;
			return false;
		}

		return true;
	}

	public function getPaymentResult(CallbackState $state): void
	{
		$input   = (array) $state->inputRaw;
		$rtnCode = (string) ($input['RtnCode'] ?? '');

		switch ($rtnCode)
		{
			case '1':   // 訂單成立 (信用卡 / WebATM / TopUpUsed) 或 ATM/CVS 已繳費
				$state->paymentResult = CallbackState::PAYMENT_RECEIVED;
				break;

			case '2':   // ATM 取號成功 / CVS 取號成功 — 尚未繳費，不算付款收到
				$state->logType    = 'info';
				$state->logMessage = '已取得繳費代碼 / 虛擬帳號，等待買家繳費。';
				break;

			case '10100073':  // CVS / 條碼取號成功
				$state->logType    = 'info';
				$state->logMessage = '已取得繳費代碼，等待買家繳費。';
				break;

			default:
				$state->logType    = 'info';
				$state->logMessage = 'OPay 回傳 RtnCode=' . $rtnCode . ': ' . ($input['RtnMsg'] ?? '');
				break;
		}

		$this->syncTransactionFromCallback($state);
	}

	/**
	 * 將 OPay 回傳的內容同步寫回 xf_yucts_opay_transaction。
	 */
	protected function syncTransactionFromCallback(CallbackState $state): void
	{
		$input = (array) $state->inputRaw;

		/** @var TransactionRepo $repo */
		$repo = \XF::repository('YUCTS\Opay:OpayTransaction');
		$transaction = $repo->findByMerchantTradeNo((string) ($input['MerchantTradeNo'] ?? ''));
		if (!$transaction)
		{
			return;
		}

		$transaction->trade_no            = (string) ($input['TradeNo'] ?? $transaction->trade_no);
		$transaction->payment_type        = (string) ($input['PaymentType'] ?? $transaction->payment_type);
		$transaction->last_callback_date  = \XF::$time;

		$extra = (array) ($transaction->extra_info ?: []);
		// 移除敏感欄位，避免日誌中留下無謂資料
		unset($input['CheckMacValue']);
		$extra['last_callback']           = $input;
		$transaction->extra_info          = $extra;

		$rtnCode = (string) ($input['RtnCode'] ?? '');
		if ($rtnCode === '1')
		{
			$transaction->status       = OpayTransaction::STATUS_PAID;
			$transaction->payment_date = \XF::$time;
		}

		$transaction->save();
	}

	public function prepareLogData(CallbackState $state): void
	{
		$input = (array) $state->inputRaw;
		unset($input['CheckMacValue']);
		$state->logDetails = $input;
	}

	// =================================================================
	// 取消 / 退款 (給後台呼叫使用)
	// =================================================================

	/**
	 * 由後台 OrderController 呼叫。依付款方式決定呼叫 DoAction(R) 或 Chargeback。
	 *
	 * @throws SdkException
	 */
	public function refundTransaction(OpayTransaction $transaction): array
	{
		if (!$transaction->PaymentProfile)
		{
			throw new SdkException('找不到對應的付款設定檔。');
		}

		$sdk    = $this->buildSdk($transaction->PaymentProfile);
		$amount = (int) round((float) $transaction->amount);
		$mtn    = $transaction->merchant_trade_no;
		$tn     = $transaction->trade_no;

		if ($tn === '')
		{
			throw new SdkException('此訂單尚未取得 OPay 交易序號 (TradeNo)，無法退款。');
		}

		if ($transaction->payment_method === 'Credit' || strpos((string) $transaction->payment_type, 'Credit') !== false)
		{
			// 信用卡：退刷 (若已關帳會自動轉退款)
			return $sdk->doAction($mtn, $tn, 'R', $amount);
		}

		// 其餘 (ATM / CVS / WebATM)：使用 AioChargeback
		return $sdk->chargeback($mtn, $tn, $amount, '後台退款');
	}

	/**
	 * 查詢 OPay 訂單目前狀態。
	 *
	 * @throws SdkException
	 */
	public function queryTransaction(OpayTransaction $transaction): array
	{
		if (!$transaction->PaymentProfile)
		{
			throw new SdkException('找不到對應的付款設定檔。');
		}

		$sdk = $this->buildSdk($transaction->PaymentProfile);
		return $sdk->queryTradeInfo($transaction->merchant_trade_no);
	}

	// =================================================================
	// 工具
	// =================================================================

	protected function buildSdk(PaymentProfile $profile): Sdk
	{
		$options  = (array) $profile->options;
		$provider = $options['service_provider'] ?? 'opay';
		$env      = $options['environment'] ?? 'stage';
		$enc      = ($options['encrypt_type'] ?? 'sha256') === 'md5' ? Sdk::ENC_MD5 : Sdk::ENC_SHA256;

		if ($provider === 'ecpay')
		{
			$host = $env === 'production' ? Sdk::HOST_ECPAY_PROD : Sdk::HOST_ECPAY_STAGE;
		}
		else
		{
			$host = $env === 'production' ? Sdk::HOST_OPAY_PROD : Sdk::HOST_OPAY_STAGE;
		}

		return new Sdk(
			(string) ($options['merchant_id'] ?? ''),
			(string) ($options['hash_key'] ?? ''),
			(string) ($options['hash_iv'] ?? ''),
			$host,
			$enc
		);
	}

	public function verifyCurrency(PaymentProfile $paymentProfile, $currencyCode): bool
	{
		return $currencyCode === 'TWD';
	}

	public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING): bool
	{
		// 本版本暫不支援定期定額 (信用卡 PeriodAmount)；如有需求請使用「使用者升級」的單次付款。
		$result = self::ERR_NO_RECURRING;
		return false;
	}
}
