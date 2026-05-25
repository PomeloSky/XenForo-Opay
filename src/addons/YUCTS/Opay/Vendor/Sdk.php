<?php

namespace YUCTS\Opay\Vendor;

/**
 * OPay / ECPay (綠界) AIO 金流 API 輕量封裝。
 *
 * 本檔案以 PSR-4 namespace 重寫官方 PHP SDK 中本套件實際會用到的功能：
 *   1. CheckMacValue 產生 / 驗證
 *   2. 建立訂單表單 (CheckOut)
 *   3. 查詢訂單 (QueryTradeInfo)
 *   4. 信用卡關帳 / 退刷 / 取消 / 放棄 (DoAction)
 *   5. ATM / CVS 退款 (AioChargeback)
 *
 * 設計上刻意不污染全域命名空間，避免與站點上其它使用 OPay 官方 SDK
 * 的程式碼衝突；演算法完全依官方規格實作。
 *
 * @see https://www.opay.tw/Service/api_doc
 * @see https://developers.ecpay.com.tw/?p=2509 (綠界，與 OPay AIO API 共用同一規格)
 */
class Sdk
{
	public const ENC_MD5    = 0;
	public const ENC_SHA256 = 1;

	/** OPay (歐付寶) 正式環境 */
	public const HOST_OPAY_PROD  = 'https://payment.opay.tw';
	/** OPay (歐付寶) 測試環境 */
	public const HOST_OPAY_STAGE = 'https://payment-stage.opay.tw';
	/** ECPay (綠界) 正式環境 */
	public const HOST_ECPAY_PROD  = 'https://payment.ecpay.com.tw';
	/** ECPay (綠界) 測試環境 */
	public const HOST_ECPAY_STAGE = 'https://payment-stage.ecpay.com.tw';

	protected string $merchantId;
	protected string $hashKey;
	protected string $hashIv;
	protected string $apiHost;
	protected int    $encryptType;

	public function __construct(
		string $merchantId,
		string $hashKey,
		string $hashIv,
		string $apiHost,
		int    $encryptType = self::ENC_SHA256
	)
	{
		// 再次防止 user 設定時夾帶空白導致 CheckMacValue 永遠對不上 OPay
		$this->merchantId  = trim($merchantId);
		$this->hashKey     = trim($hashKey);
		$this->hashIv      = trim($hashIv);
		$this->apiHost     = rtrim(trim($apiHost), '/');
		$this->encryptType = $encryptType;
	}

	public function getEncryptType(): int
	{
		return $this->encryptType;
	}

	/**
	 * 產生 CheckMacValue。
	 *
	 * 依官方規範：參數依鍵名 (不分大小寫) 升冪排序，前後加上 HashKey / HashIV，
	 * URL Encode、轉小寫、還原 .NET 的字元差異、再以 MD5 或 SHA256 雜湊取大寫。
	 *
	 * @param array<string,scalar> $parameters
	 */
	public function generateCheckMacValue(array $parameters): string
	{
		unset($parameters['CheckMacValue']);

		uksort($parameters, 'strcasecmp');

		$raw = 'HashKey=' . $this->hashKey;
		foreach ($parameters AS $k => $v)
		{
			$raw .= '&' . $k . '=' . $v;
		}
		$raw .= '&HashIV=' . $this->hashIv;

		$rawBeforeEncode = $raw; // 為 debug log 保留

		$raw = strtolower(urlencode($raw));

		// 還原 .NET HttpUtility.UrlEncode 與 PHP 的字元編碼差異
		$replace = [
			'%2d' => '-', '%5f' => '_', '%2e' => '.',
			'%21' => '!', '%2a' => '*',
			'%28' => '(', '%29' => ')',
		];
		$raw = strtr($raw, $replace);

		$hash = ($this->encryptType === self::ENC_SHA256)
			? hash('sha256', $raw)
			: md5($raw);

		$result = strtoupper($hash);

		// 為避免把 HashKey / HashIV 完整字串寫進 log，僅保留長度+前後 2 碼遮罩
		\YUCTS\Opay\Util\DebugLog::write('check_mac',
			'algo=' . ($this->encryptType === self::ENC_SHA256 ? 'sha256' : 'md5')
			. ' hk=' . self::maskSecret($this->hashKey)
			. ' iv=' . self::maskSecret($this->hashIv)
			. ' pre_hash_len=' . strlen($raw)
			. ' result=' . $result
		);

		return $result;
	}

	protected static function maskSecret(string $s): string
	{
		$len = strlen($s);
		if ($len <= 4)
		{
			return str_repeat('*', $len) . '(' . $len . ')';
		}
		return substr($s, 0, 2) . str_repeat('*', max(0, $len - 4)) . substr($s, -2) . '(' . $len . ')';
	}

	/**
	 * 驗證自 OPay 回傳的參數中的 CheckMacValue 是否正確。
	 * 採用 hash_equals 進行常數時間比對，避免時序攻擊。
	 *
	 * @param array<string,scalar> $parameters
	 */
	public function verifyCheckMacValue(array $parameters): bool
	{
		if (empty($parameters['CheckMacValue']))
		{
			return false;
		}

		$received = (string) $parameters['CheckMacValue'];
		unset($parameters['CheckMacValue']);

		$expected = $this->generateCheckMacValue($parameters);

		return hash_equals($expected, $received);
	}

	/**
	 * 組裝送出建立訂單所需的完整參數 (含 CheckMacValue)。
	 *
	 * @param array<string,scalar> $params
	 * @return array<string,scalar>
	 */
	public function buildCheckoutParameters(array $params): array
	{
		$params['MerchantID']  = $this->merchantId;
		$params['EncryptType'] = $this->encryptType;

		$params['CheckMacValue'] = $this->generateCheckMacValue($params);
		return $params;
	}

	/**
	 * 取得「建立訂單」收銀台端點 URL。
	 */
	public function getCheckoutEndpoint(): string
	{
		return $this->apiHost . '/Cashier/AioCheckOut/V5';
	}

	/**
	 * 查詢訂單 (QueryTradeInfo)。
	 *
	 * @return array<string,string>
	 * @throws SdkException
	 */
	public function queryTradeInfo(string $merchantTradeNo): array
	{
		$params = [
			'MerchantID'      => $this->merchantId,
			'MerchantTradeNo' => $merchantTradeNo,
			'TimeStamp'       => time(),
		];
		$params['CheckMacValue'] = $this->generateCheckMacValue($params);

		$response = $this->httpPost($this->apiHost . '/Cashier/QueryTradeInfo/V5', $params);

		// 與官方 SDK 相同：先 escape 空白與 +，避免 parse_str 把 + 解成空白
		$normalized = str_replace(' ', '%20', $response);
		$normalized = str_replace('+', '%2B', $normalized);
		parse_str($normalized, $result);

		if (!is_array($result) || !$result)
		{
			throw new SdkException('OPay 查詢回應無法解析：' . substr($response, 0, 300));
		}

		// 若回應是「找不到訂單」或其它純錯誤 (沒有完整欄位)，先直接回傳，不做 CheckMacValue 比對
		if (!isset($result['MerchantID']) || !isset($result['CheckMacValue']))
		{
			return $result + ['_raw_response' => $response];
		}

		if (!$this->verifyCheckMacValue($result))
		{
			throw new SdkException(
				'查詢回應之 CheckMacValue 驗證失敗，請確認您的「HashKey / HashIV / 加密方式」與 OPay 後台設定一致。原始回應：' . substr($response, 0, 300)
			);
		}

		return $result;
	}

	/**
	 * 信用卡關帳 / 退刷 / 取消 / 放棄 (DoAction)。
	 *
	 * @param string $action one of: C (關帳), R (退刷), E (取消), N (放棄)
	 * @return array<string,string>
	 * @throws SdkException
	 */
	public function doAction(
		string $merchantTradeNo,
		string $tradeNo,
		string $action,
		int    $totalAmount
	): array
	{
		if (!in_array($action, ['C', 'R', 'E', 'N'], true))
		{
			throw new SdkException('無效的 DoAction 動作：' . $action);
		}

		$params = [
			'MerchantID'      => $this->merchantId,
			'MerchantTradeNo' => $merchantTradeNo,
			'TradeNo'         => $tradeNo,
			'Action'          => $action,
			'TotalAmount'     => $totalAmount,
			'EncryptType'     => $this->encryptType,
		];
		$params['CheckMacValue'] = $this->generateCheckMacValue($params);

		$response = $this->httpPost($this->apiHost . '/CreditDetail/DoAction', $params);
		parse_str($response, $result);

		if (!isset($result['RtnCode']) || (string) $result['RtnCode'] !== '1')
		{
			throw new SdkException(
				'OPay DoAction 失敗 #' . ($result['RtnCode'] ?? 'N/A')
				. '：' . ($result['RtnMsg'] ?? 'unknown')
			);
		}

		return $result;
	}

	/**
	 * ATM / CVS / WebATM 退款 (AioChargeback)。
	 * 注意：本 API 僅將款項自您的 OPay 餘額退回；不會回到買家帳戶。
	 * 信用卡退款請使用 doAction('R', ...)。
	 *
	 * @return array<string,string>
	 * @throws SdkException
	 */
	public function chargeback(
		string $merchantTradeNo,
		string $tradeNo,
		int    $amount,
		string $remark = ''
	): array
	{
		$params = [
			'MerchantID'             => $this->merchantId,
			'MerchantTradeNo'        => $merchantTradeNo,
			'TradeNo'                => $tradeNo,
			'ChargeBackTotalAmount'  => $amount,
			'Remark'                 => $remark,
			'EncryptType'            => $this->encryptType,
		];
		$params['CheckMacValue'] = $this->generateCheckMacValue($params);

		$response = $this->httpPost($this->apiHost . '/Cashier/AioChargeback', $params);

		if (trim($response) === '1|OK')
		{
			return ['RtnCode' => '1', 'RtnMsg' => 'OK'];
		}

		throw new SdkException('OPay 退款失敗：' . $response);
	}

	/**
	 * @param array<string,scalar> $params
	 * @throws SdkException
	 */
	protected function httpPost(string $url, array $params): string
	{
		$ch = curl_init();
		if ($ch === false)
		{
			throw new SdkException('無法初始化 cURL。');
		}

		curl_setopt_array($ch, [
			CURLOPT_URL            => $url,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query($params),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_USERAGENT      => 'YUCTS-Opay/1.0 (+https://forum.yucts.com)',
		]);

		$response = curl_exec($ch);
		if ($response === false)
		{
			$err = curl_error($ch);
			$no  = curl_errno($ch);
			curl_close($ch);
			throw new SdkException('連線 OPay 失敗 (#' . $no . ')：' . $err);
		}

		curl_close($ch);

		$dbgParams = $params;
		unset($dbgParams['CheckMacValue']);
		\YUCTS\Opay\Util\DebugLog::write('http_post',
			$url
			. ' → ' . json_encode($dbgParams, JSON_UNESCAPED_UNICODE)
			. ' | response: ' . substr((string) $response, 0, 800)
		);

		return (string) $response;
	}
}
