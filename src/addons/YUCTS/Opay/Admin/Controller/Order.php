<?php

namespace YUCTS\Opay\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\Exception as ReplyException;
use XF\Payment\CallbackState;
use XF\Repository\PaymentRepository;
use YUCTS\Opay\Entity\OpayTransaction;
use YUCTS\Opay\Payment\Opay as OpayProvider;
use YUCTS\Opay\Repository\OpayTransaction as TransactionRepo;
use YUCTS\Opay\Vendor\SdkException;

/**
 * 後台「OPay 訂單管理」控制器。
 *
 * 提供：
 *   - 訂單列表 (含搜尋、分頁)
 *   - 訂單詳情頁
 *   - 手動成立訂單 (觸發 XF 升級開通流程)
 *   - 手動取消訂單
 *   - 申請 OPay 退款 (信用卡 / ATM / CVS)
 *   - 即時向 OPay 查詢訂單狀態
 *   - 交易記錄頁 (顯示 xf_payment_provider_log 中 provider_id=opay 的記錄)
 */
class Order extends AbstractController
{
	protected const PER_PAGE = 25;

	protected function preDispatchController($action, ParameterBag $params)
	{
		$this->assertAdminPermission('yuctsOpayManage');
	}

	// =============================================================
	// 列表
	// =============================================================

	public function actionIndex()
	{
		$page    = $this->filterPage();
		$perPage = self::PER_PAGE;

		$filters = $this->filter([
			'status'   => 'str',
			'username' => 'str',
			'mtn'      => 'str',
		]);

		/** @var TransactionRepo $repo */
		$repo   = $this->repository('YUCTS\Opay:OpayTransaction');
		$finder = $repo->findTransactionsForList()
			->with(['User', 'PaymentProfile']);

		if ($filters['status'] !== '')
		{
			$finder->where('status', $filters['status']);
		}
		if ($filters['username'] !== '')
		{
			$finder->where('username', 'LIKE', $finder->escapeLike($filters['username'], '%?%'));
		}
		if ($filters['mtn'] !== '')
		{
			$finder->where('merchant_trade_no', 'LIKE', $finder->escapeLike($filters['mtn'], '%?%'));
		}

		$totalTransactions = $finder->total();
		$transactions = $finder
			->limitByPage($page, $perPage)
			->fetch();

		$viewParams = [
			'transactions' => $transactions,
			'page'         => $page,
			'perPage'      => $perPage,
			'total'        => $totalTransactions,
			'filters'      => $filters,
			'statuses'     => $this->getStatusOptions(),
		];
		return $this->view(
			'YUCTS\Opay:Order\Listing',
			'yucts_opay_order_list',
			$viewParams
		);
	}

	// =============================================================
	// 檢視 / 編輯單一訂單
	// =============================================================

	public function actionView(ParameterBag $params)
	{
		$transaction = $this->assertTransactionExists((int) $params->transaction_id);

		$logs = $this->finder('XF:PaymentProviderLog')
			->where('purchase_request_key', $transaction->request_key)
			->order('log_date', 'desc')
			->limit(50)
			->fetch();

		// 由付款設定檔判斷此筆交易實際使用的 API host + 對應 OPay/ECPay 廠商後台
		$diag = $this->buildTransactionDiagnostics($transaction);

		$viewParams = [
			'transaction' => $transaction,
			'logs'        => $logs,
			'diag'        => $diag,
		];
		return $this->view(
			'YUCTS\Opay:Order\View',
			'yucts_opay_order_view',
			$viewParams
		);
	}

	/**
	 * 取得本筆交易的診斷資訊：
	 *  - api_host         : 實際送出付款請求的 API 主機
	 *  - vendor_url       : 對應的廠商後台首頁 (供管理員快速登入)
	 *  - vendor_label     : 廠商後台名稱
	 *  - service_provider : opay / ecpay
	 *  - environment      : production / stage
	 *  - encrypt_type     : sha256 / md5
	 *
	 * @return array<string,string>
	 */
	protected function buildTransactionDiagnostics(OpayTransaction $transaction): array
	{
		$opts = [];
		if ($transaction->PaymentProfile)
		{
			$opts = (array) $transaction->PaymentProfile->options;
		}

		$provider = $opts['service_provider'] ?? 'opay';
		$env      = $opts['environment'] ?? 'stage';
		$enc      = $opts['encrypt_type'] ?? 'sha256';

		if ($provider === 'ecpay')
		{
			$apiHost    = ($env === 'production') ? 'https://payment.ecpay.com.tw' : 'https://payment-stage.ecpay.com.tw';
			$vendorUrl  = ($env === 'production') ? 'https://vendor.ecpay.com.tw/' : 'https://vendor-stage.ecpay.com.tw/';
			$vendorLbl  = 'ECPay 綠界廠商後台';
		}
		else
		{
			$apiHost    = ($env === 'production') ? 'https://payment.opay.tw' : 'https://payment-stage.opay.tw';
			$vendorUrl  = ($env === 'production') ? 'https://vendor.opay.tw/' : 'https://vendor-stage.opay.tw/';
			$vendorLbl  = 'OPay 歐付寶廠商後台';
		}

		return [
			'api_host'         => $apiHost,
			'vendor_url'       => $vendorUrl,
			'vendor_label'     => $vendorLbl,
			'service_provider' => $provider,
			'environment'      => $env,
			'encrypt_type'     => $enc,
		];
	}

	public function actionEdit(ParameterBag $params)
	{
		$transaction = $this->assertTransactionExists((int) $params->transaction_id);

		if ($this->isPost())
		{
			$input = $this->filter([
				'admin_note' => 'str',
			]);
			$transaction->admin_note = $input['admin_note'] !== '' ? $input['admin_note'] : null;
			$transaction->save();

			return $this->redirect(
				$this->buildLink('opay/orders/view', $transaction),
				\XF::phrase('changes_saved')
			);
		}

		$viewParams = [
			'transaction' => $transaction,
		];
		return $this->view(
			'YUCTS\Opay:Order\Edit',
			'yucts_opay_order_edit',
			$viewParams
		);
	}

	// =============================================================
	// 手動成立 / 取消 / 退款 / 查詢
	// =============================================================

	public function actionComplete(ParameterBag $params)
	{
		$transaction = $this->assertTransactionExists((int) $params->transaction_id);

		if (!$transaction->canManuallyComplete())
		{
			return $this->error('此訂單目前狀態 (' . $transaction->status . ') 不允許手動成立。');
		}

		if (!$this->isPost())
		{
			$viewParams = [
				'transaction' => $transaction,
				'action'      => 'complete',
				'actionUrl'   => $this->buildLink('opay/orders/complete', $transaction),
				'confirm'     => \XF::phrase('opay.complete_confirm'),
			];
			return $this->view(
				'YUCTS\Opay:Order\Action',
				'yucts_opay_order_action',
				$viewParams
			);
		}

		$note = $this->filter('note', 'str');
		$this->manuallyCompleteTransaction($transaction, $note);

		return $this->redirect(
			$this->buildLink('opay/orders/view', $transaction),
			'訂單已手動成立，相關升級 / 商品已開通。'
		);
	}

	public function actionCancel(ParameterBag $params)
	{
		$transaction = $this->assertTransactionExists((int) $params->transaction_id);

		if (!$transaction->canManuallyCancel())
		{
			return $this->error('此訂單目前狀態 (' . $transaction->status . ') 不允許手動取消。');
		}

		if (!$this->isPost())
		{
			$viewParams = [
				'transaction' => $transaction,
				'action'      => 'cancel',
				'actionUrl'   => $this->buildLink('opay/orders/cancel', $transaction),
				'confirm'     => \XF::phrase('opay.cancel_confirm'),
			];
			return $this->view(
				'YUCTS\Opay:Order\Action',
				'yucts_opay_order_action',
				$viewParams
			);
		}

		$note = $this->filter('note', 'str');
		$this->manuallyCancelTransaction($transaction, $note);

		return $this->redirect(
			$this->buildLink('opay/orders/view', $transaction),
			'訂單已手動取消。'
		);
	}

	public function actionRefund(ParameterBag $params)
	{
		$transaction = $this->assertTransactionExists((int) $params->transaction_id);

		if (!$transaction->canRefund())
		{
			return $this->error('此訂單不符合退款條件 (僅已付款且具 TradeNo 的信用卡訂單可使用退刷；其它付款方式請以 OPay 後台對帳並另行匯款)。');
		}

		if (!$this->isPost())
		{
			$viewParams = [
				'transaction' => $transaction,
				'action'      => 'refund',
				'actionUrl'   => $this->buildLink('opay/orders/refund', $transaction),
				'confirm'     => \XF::phrase('opay.refund_confirm'),
			];
			return $this->view(
				'YUCTS\Opay:Order\Action',
				'yucts_opay_order_action',
				$viewParams
			);
		}

		try
		{
			$provider = $this->getOpayProvider();
			$result   = $provider->refundTransaction($transaction);
		}
		catch (SdkException $e)
		{
			return $this->error('OPay 退款失敗：' . $e->getMessage());
		}

		$transaction->status = OpayTransaction::STATUS_REFUNDED;
		$extra = (array) ($transaction->extra_info ?: []);
		$extra['refund_result'] = $result;
		$transaction->extra_info = $extra;
		$transaction->save();

		$this->writeLog(
			$transaction,
			'cancel',
			'後台退款 (' . \XF::visitor()->username . ')',
			$result
		);

		return $this->redirect(
			$this->buildLink('opay/orders/view', $transaction),
			\XF::phrase('opay.refund_requested')
		);
	}

	public function actionQuery(ParameterBag $params)
	{
		$transaction = $this->assertTransactionExists((int) $params->transaction_id);

		try
		{
			$provider = $this->getOpayProvider();
			$result   = $provider->queryTransaction($transaction);
		}
		catch (SdkException $e)
		{
			return $this->error('OPay 查詢失敗：' . $e->getMessage());
		}

		$extra = (array) ($transaction->extra_info ?: []);
		$extra['last_query'] = $result;
		$transaction->extra_info = $extra;

		// 若 OPay 已返回 TradeNo 但本機 transaction 還沒有，補上以利後續搜尋
		if (!empty($result['TradeNo']) && empty($transaction->trade_no))
		{
			$transaction->trade_no = (string) $result['TradeNo'];
		}
		// 若 OPay 已標記為已付款，但本機尚為 pending (例如 S2S callback 漏接)
		// 一併同步狀態
		if (
			isset($result['TradeStatus']) && (string) $result['TradeStatus'] === '1'
			&& $transaction->status === OpayTransaction::STATUS_PENDING
		)
		{
			$transaction->status = OpayTransaction::STATUS_PAID;
			if (empty($transaction->payment_date))
			{
				$transaction->payment_date = \XF::$time;
			}
		}

		$transaction->save();

		$rows           = [];
		$fieldLabels    = $this->getOpayFieldLabels();
		$tradeStatusMap = $this->getOpayTradeStatusMap();
		foreach ($result AS $key => $value)
		{
			$valueLabel = '';
			if ($key === 'TradeStatus' && $value !== '' && isset($tradeStatusMap[$value]))
			{
				$valueLabel = $tradeStatusMap[$value];
			}
			$rows[] = [
				'key'         => $key,
				'labelZh'     => $fieldLabels[$key] ?? '',
				'value'       => $value,
				'valueLabel'  => $valueLabel,
			];
		}

		$viewParams = [
			'transaction' => $transaction,
			'rows'        => $rows,
		];
		return $this->view(
			'YUCTS\Opay:Order\Query',
			'yucts_opay_order_query',
			$viewParams
		);
	}

	/**
	 * OPay 查詢回應中常見欄位的中文標籤。
	 * 沒列在這裡的欄位，模板會直接顯示原始英文。
	 *
	 * @return array<string,string>
	 */
	protected function getOpayFieldLabels(): array
	{
		return [
			'MerchantID'           => '特店編號',
			'MerchantTradeNo'      => '商店訂單編號',
			'StoreID'              => '分店代號',
			'TradeNo'              => 'OPay 交易序號',
			'TradeAmt'             => '交易金額',
			'PaymentDate'          => '付款日期',
			'PaymentType'          => '付款方式',
			'HandlingCharge'       => 'OPay 手續費',
			'PaymentTypeChargeFee' => '交易手續費',
			'TradeDate'            => '訂單成立時間',
			'TradeStatus'          => '訂單狀態',
			'ItemName'             => '商品名稱',
			'CheckMacValue'        => '檢查碼',
			'RtnCode'              => '回傳代碼',
			'RtnMsg'               => '回傳訊息',
			'TradeDesc'            => '交易描述',
			'CustomField1'         => '自訂欄位 1',
			'CustomField2'         => '自訂欄位 2',
			'CustomField3'         => '自訂欄位 3',
			'CustomField4'         => '自訂欄位 4',
			'BankCode'             => 'ATM 銀行代碼',
			'vAccount'             => 'ATM 虛擬帳號',
			'ExpireDate'           => '繳費期限',
			'PaymentNo'            => '超商代碼',
			'Barcode1'             => '超商條碼 1',
			'Barcode2'             => '超商條碼 2',
			'Barcode3'             => '超商條碼 3',
		];
	}

	/**
	 * TradeStatus 代碼的中文說明。
	 *
	 * @return array<string,string>
	 */
	protected function getOpayTradeStatusMap(): array
	{
		return [
			'0'        => '尚未付款',
			'1'        => '已付款',
			'2'        => '已取號待繳費',
			'10100073' => '已取得超商繳費代碼，等待繳費',
			'10200047' => '查無此訂單 (OPay 端尚未建立)',
		];
	}

	// =============================================================
	// 交易記錄頁
	// =============================================================

	public function actionLog()
	{
		$page = $this->filterPage();
		$perPage = self::PER_PAGE;

		$finder = $this->finder('XF:PaymentProviderLog')
			->where('provider_id', 'opay')
			->order('log_date', 'desc');

		$total = $finder->total();
		$logs  = $finder->limitByPage($page, $perPage)->fetch();

		$viewParams = [
			'logs'    => $logs,
			'page'    => $page,
			'perPage' => $perPage,
			'total'   => $total,
		];
		return $this->view(
			'YUCTS\Opay:Order\Logs',
			'yucts_opay_order_logs',
			$viewParams
		);
	}

	// =============================================================
	// 內部工具
	// =============================================================

	/**
	 * 將指定的 OPayTransaction 手動標記為已付款，並驅動 XF 的標準
	 * 「completeTransaction」流程，使對應的 user_upgrade / 其它 purchasable
	 * 能正常開通。
	 *
	 * @throws ReplyException
	 */
	protected function manuallyCompleteTransaction(OpayTransaction $transaction, string $note): void
	{
		$purchaseRequest = $transaction->PurchaseRequest;
		if (!$purchaseRequest)
		{
			throw $this->exception($this->error(
				'找不到對應的 XF PurchaseRequest，無法觸發升級開通流程。'
			));
		}

		$transaction->status       = OpayTransaction::STATUS_PAID;
		$transaction->payment_date = \XF::$time;
		$extra = (array) ($transaction->extra_info ?: []);
		$extra['manual_complete'] = [
			'by'   => \XF::visitor()->username,
			'time' => \XF::$time,
			'note' => $note,
		];
		$transaction->extra_info = $extra;
		$transaction->save();

		// 透過官方 AbstractProvider 流程觸發 purchasable 完成
		$provider = $this->getOpayProvider();

		$state                   = new CallbackState();
		$state->requestKey       = $purchaseRequest->request_key;
		$state->transactionId    = $transaction->trade_no !== '' ? $transaction->trade_no : ('MANUAL-' . $transaction->merchant_trade_no);
		$state->paymentResult    = CallbackState::PAYMENT_RECEIVED;
		$state->logType          = 'payment';
		$state->logMessage       = (string) \XF::phrase('opay.order_completed_manually', [
			'user' => \XF::visitor()->username,
			'note' => $note ?: '(無)',
		]);
		$state->logDetails       = [
			'manual'             => true,
			'by'                 => \XF::visitor()->username,
			'note'               => $note,
			'transaction_id'     => $transaction->transaction_id,
			'merchant_trade_no'  => $transaction->merchant_trade_no,
		];

		try
		{
			$provider->completeTransaction($state);
			$provider->log($state);
		}
		catch (\Exception $e)
		{
			\XF::logException($e);
			throw $this->exception($this->error(
				'手動成立流程中發生錯誤：' . $e->getMessage()
			));
		}
	}

	protected function manuallyCancelTransaction(OpayTransaction $transaction, string $note): void
	{
		$transaction->status = OpayTransaction::STATUS_CANCELLED;
		$extra = (array) ($transaction->extra_info ?: []);
		$extra['manual_cancel'] = [
			'by'   => \XF::visitor()->username,
			'time' => \XF::$time,
			'note' => $note,
		];
		$transaction->extra_info = $extra;
		$transaction->save();

		$this->writeLog(
			$transaction,
			'cancel',
			(string) \XF::phrase('opay.order_cancelled_manually', [
				'user' => \XF::visitor()->username,
				'note' => $note ?: '(無)',
			]),
			[
				'manual'            => true,
				'by'                => \XF::visitor()->username,
				'note'              => $note,
				'merchant_trade_no' => $transaction->merchant_trade_no,
			]
		);
	}

	protected function writeLog(OpayTransaction $transaction, string $type, string $message, array $details): void
	{
		/** @var PaymentRepository $paymentRepo */
		$paymentRepo = $this->repository('XF:Payment');
		$paymentRepo->logCallback(
			$transaction->request_key,
			'opay',
			$transaction->trade_no !== '' ? $transaction->trade_no : ('MANUAL-' . $transaction->merchant_trade_no),
			$type,
			$message,
			$details,
			null
		);
	}

	protected function getOpayProvider(): OpayProvider
	{
		/** @var \XF\Entity\PaymentProvider|null $provider */
		$provider = $this->em()->find('XF:PaymentProvider', 'opay');
		if (!$provider || !$provider->handler instanceof OpayProvider)
		{
			throw $this->exception($this->error(
				'OPay 付款服務商未正確安裝，請至「附加元件」中重新升級本套件。'
			));
		}
		return $provider->handler;
	}

	protected function assertTransactionExists(int $id): OpayTransaction
	{
		/** @var OpayTransaction|null $transaction */
		$transaction = $this->em()->find('YUCTS\Opay:OpayTransaction', $id, ['User', 'PaymentProfile']);
		if (!$transaction)
		{
			throw $this->exception($this->notFound('找不到此 OPay 訂單。'));
		}
		return $transaction;
	}

	protected function getStatusOptions(): array
	{
		return [
			''          => \XF::phrase('all'),
			'pending'   => \XF::phrase('opay_status.pending'),
			'paid'      => \XF::phrase('opay_status.paid'),
			'failed'    => \XF::phrase('opay_status.failed'),
			'cancelled' => \XF::phrase('opay_status.cancelled'),
			'refunded'  => \XF::phrase('opay_status.refunded'),
		];
	}
}
