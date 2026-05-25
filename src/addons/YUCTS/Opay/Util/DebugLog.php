<?php

namespace YUCTS\Opay\Util;

/**
 * 除錯模式下將 OPay 通訊內容寫入 internal_data/yucts_opay_debug.log。
 *
 * 為什麼不用 \XF::logError()：
 * logError() 會在後台「伺服器錯誤日誌」每筆都顯示為 ErrorException，
 * 對網站管理員而言極具誤導性 (以為發生了致命錯誤)。改寫到專屬檔案，
 * 既能保留診斷資料，又不會污染 XF 錯誤日誌。
 *
 * 啟用方式：後台 → 選項 → OPay 歐付寶 → 啟用除錯模式
 *
 * 檔案位置：internal_data/yucts_opay_debug.log
 * (隨 XF 部署規範，internal_data 應被 .htaccess 拒絕外部直接存取)
 */
class DebugLog
{
	public static function isEnabled(): bool
	{
		if (!class_exists(\XF::class))
		{
			return false;
		}
		try
		{
			return !empty(\XF::options()->yuctsOpayDebugMode);
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}

	public static function write(string $tag, string $message): void
	{
		if (!self::isEnabled())
		{
			return;
		}

		try
		{
			$path = self::getLogPath();
			$line = '[' . gmdate('Y-m-d H:i:s', \XF::$time ?? time()) . 'Z]'
				. ' [' . $tag . '] '
				. $message . "\n";

			// 用 LOCK_EX 避免並行寫入相互覆蓋
			@file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
		}
		catch (\Throwable $e)
		{
			// debug log 自身永遠不該拋例外影響正常流程
		}
	}

	protected static function getLogPath(): string
	{
		$root = \XF::getRootDirectory();
		return $root . \XF::$DS . 'internal_data' . \XF::$DS . 'yucts_opay_debug.log';
	}
}
