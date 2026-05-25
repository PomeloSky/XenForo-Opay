<?php

namespace YUCTS\Opay\Pub\View\Payment;

use XF\Mvc\View;

/**
 * 對應 \YUCTS\Opay\Pub\Controller\Result::actionIndex 的回應 View。
 *
 * 同 \YUCTS\Opay\Pub\View\Payment\Initiate 的設計：
 * 直接吐出極簡 HTML，避免被 PAGE_CONTAINER 包進需要 session cookie 的論壇 chrome。
 * 用 meta refresh + JS 雙保險，把使用者導回 /account/upgrade-purchase。
 */
class Result extends View
{
	public function renderHtml()
	{
		$redirectUrl = (string) ($this->params['redirectUrl'] ?? '/');
		$status      = (string) ($this->params['status'] ?? 'pending');

		$safeUrl = htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		$msg = $status === 'success'
			? '付款已收到，正在帶您回到站內…'
			: '正在帶您回到站內，請稍候…';

		$html = <<<HTML
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<meta http-equiv="refresh" content="2;url={$safeUrl}" />
	<title>付款處理中…</title>
	<style>
		body { font-family: -apple-system, "Segoe UI", "Microsoft JhengHei", sans-serif;
			text-align: center; padding: 60px 20px; color: #444; }
		.msg { font-size: 18px; margin-bottom: 16px; }
		.hint { font-size: 13px; color: #888; margin-bottom: 24px; }
		a.btn { font-size: 14px; padding: 10px 24px; border: 1px solid #2a6bb0;
			background: #2a6bb0; color: #fff; border-radius: 4px;
			text-decoration: none; display: inline-block; }
	</style>
</head>
<body>
	<div class="msg">{$msg}</div>
	<div class="hint">若 2 秒後仍未自動跳轉，請點擊下方按鈕。</div>
	<a class="btn" href="{$safeUrl}">繼續</a>
	<script>
		setTimeout(function () { window.location.replace({$this->jsString($redirectUrl)}); }, 100);
	</script>
</body>
</html>
HTML;

		$templater = $this->renderer->getTemplater();
		$templater->setPageParam('template', '');

		$this->response->contentType('text/html', 'UTF-8');
		return $html;
	}

	protected function jsString(string $s): string
	{
		return json_encode($s, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	}
}
