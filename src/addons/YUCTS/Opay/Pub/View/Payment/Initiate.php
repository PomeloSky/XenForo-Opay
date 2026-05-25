<?php

namespace YUCTS\Opay\Pub\View\Payment;

use XF\Mvc\View;

/**
 * 自訂 View：直接輸出一個自動 submit 至 OPay 的完整 HTML 文件。
 *
 * 為何不沿用 public:payment_initiate_opay 模板？
 * - XF 的 Public 模板會被包進整套 chrome (header / nav / footer)，
 *   並由 XF.js 框架接管表單事件，可能延後或攔截 OPay 表單的自動 submit。
 * - 自訂 View::renderHtml() 跳過模板系統與 PAGE_CONTAINER，
 *   直接回傳一份「只有 OPay 表單 + 立即送出 script」的乾淨頁面，
 *   行為與官方 OpaySend::CheckOut() 生成的 HTML 完全等價。
 */
class Initiate extends View
{
	public function renderHtml()
	{
		$action = (string) ($this->params['action'] ?? '');
		$params = (array)  ($this->params['params'] ?? []);

		$hidden = '';
		foreach ($params AS $name => $value)
		{
			$hidden .= '<input type="hidden" name="'
				. htmlspecialchars((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8')
				. '" value="'
				. htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
				. '" />';
		}

		$safeAction = htmlspecialchars($action, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		$html = <<<HTML
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title>正在前往 OPay 收銀台…</title>
	<style>
		body { font-family: -apple-system, "Segoe UI", "Microsoft JhengHei", sans-serif;
			text-align: center; padding: 60px 20px; color: #444; }
		.msg { font-size: 16px; margin-bottom: 24px; }
		button { font-size: 14px; padding: 10px 24px; border: 1px solid #2a6bb0;
			background: #2a6bb0; color: #fff; border-radius: 4px; cursor: pointer; }
	</style>
</head>
<body>
	<div class="msg">正在前往 OPay 收銀台，請稍候…</div>
	<form id="OpayAutoSubmit" method="post" action="{$safeAction}" accept-charset="UTF-8">
		{$hidden}
		<noscript>
			<p>您的瀏覽器未啟用 JavaScript，請手動點擊下方按鈕完成付款。</p>
			<button type="submit">前往付款</button>
		</noscript>
	</form>
	<script>
		(function () {
			var f = document.getElementById('OpayAutoSubmit');
			if (f) { f.submit(); }
		})();
	</script>
</body>
</html>
HTML;

		// 設定 page 參數 template = '' 以跳過 PAGE_CONTAINER 包裝 (XF\Pub\App::renderPageHtml
		// 在 $params['template'] 為空字串時直接回傳 content)，避免我們的自動 submit 表單
		// 被包進整套論壇 chrome 與 XF.js 框架，確保表單能立刻送出至 OPay。
		$templater = $this->renderer->getTemplater();
		$templater->setPageParam('template', '');

		$this->response->contentType('text/html', 'UTF-8');

		return $html;
	}
}
