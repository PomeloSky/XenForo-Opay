<?php

namespace YUCTS\Opay\Pub\View\Payment;

use XF\Mvc\View;

/**
 * 對應 \YUCTS\Opay\Pub\Controller\Result::actionIndex 的回應 View。
 *
 * 直接輸出極簡 HTML，跳過 PAGE_CONTAINER，避免被論壇 chrome 包進
 * 需要 session cookie 的版面 (SameSite cookie 政策會擋下)。
 *
 * 依 controller 推導出的 $status：
 *   - 'success'  : 立即 JS 跳轉回 /account/upgrade-purchase
 *   - 'pending'  : 略等一下再跳 (S2S 可能還在處理)
 *   - 'failed'   : 顯示失敗原因，不自動跳，讓使用者讀完
 */
class Result extends View
{
	public function renderHtml()
	{
		$redirectUrl = (string) ($this->params['redirectUrl'] ?? '/');
		$status      = (string) ($this->params['status'] ?? 'pending');
		$statusText  = (string) ($this->params['statusText'] ?? '處理中…');
		$rtnCode     = (string) ($this->params['rtnCode'] ?? '');
		$rtnMsg      = (string) ($this->params['rtnMsg'] ?? '');

		$safeUrl  = htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$safeText = htmlspecialchars($statusText, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// 成功 / pending 自動跳；failed 維持頁面讓使用者讀
		$autoRedirect = $status !== 'failed';

		$delaySec     = ($status === 'success') ? 1 : 3;
		$metaRefresh  = $autoRedirect
			? '<meta http-equiv="refresh" content="' . $delaySec . ';url=' . $safeUrl . '" />'
			: '';

		$jsRedirect = $autoRedirect
			? '<script>setTimeout(function () { window.location.replace(' . $this->jsString($redirectUrl) . '); }, '
				. ($delaySec * 1000 - 100) . ');</script>'
			: '';

		$iconColor = $status === 'success' ? '#2e8540'
			: ($status === 'failed' ? '#dc322f' : '#b58900');
		$iconChar  = $status === 'success' ? '✓'
			: ($status === 'failed' ? '✗' : '⋯');

		$rtnInfo = '';
		if ($rtnCode !== '' && $status !== 'success')
		{
			$rtnInfo = '<div class="hint">OPay 回傳代碼：<code>' . htmlspecialchars($rtnCode) . '</code>';
			if ($rtnMsg !== '')
			{
				$rtnInfo .= '<br />訊息：' . htmlspecialchars($rtnMsg);
			}
			$rtnInfo .= '</div>';
		}

		$buttonLabel = ($status === 'failed') ? '回到升級選擇頁' : '繼續';

		$html = <<<HTML
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	{$metaRefresh}
	<title>付款處理結果</title>
	<style>
		body { font-family: -apple-system, "Segoe UI", "Microsoft JhengHei", sans-serif;
			text-align: center; padding: 60px 20px; color: #444; background: #fafafa; }
		.icon { font-size: 56px; line-height: 1; color: {$iconColor}; margin-bottom: 18px; }
		.msg { font-size: 18px; margin-bottom: 14px; max-width: 600px; margin-left:auto; margin-right:auto; }
		.hint { font-size: 13px; color: #888; margin-bottom: 24px; max-width: 600px; margin-left:auto; margin-right:auto; }
		.hint code { background:#eee; padding:1px 6px; border-radius:3px; }
		a.btn { font-size: 14px; padding: 10px 24px; border: 1px solid #2a6bb0;
			background: #2a6bb0; color: #fff; border-radius: 4px;
			text-decoration: none; display: inline-block; }
	</style>
</head>
<body>
	<div class="icon">{$iconChar}</div>
	<div class="msg">{$safeText}</div>
	{$rtnInfo}
	<a class="btn" href="{$safeUrl}">{$buttonLabel}</a>
	{$jsRedirect}
</body>
</html>
HTML;

		$this->renderer->getTemplater()->setPageParam('template', '');
		$this->response->contentType('text/html', 'UTF-8');
		return $html;
	}

	protected function jsString(string $s): string
	{
		return json_encode($s, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	}
}
