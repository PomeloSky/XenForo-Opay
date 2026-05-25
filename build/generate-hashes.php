<?php
/**
 * 產生 src/addons/YUCTS/Opay/hashes.json
 *
 * XenForo 2.x 的「附加元件健康檢查」需要每個 addon 根目錄下有 hashes.json，
 * 內容為該 addon 內所有檔案的 SHA-256（在計算前移除 \r，使 CRLF/LF 不影響結果）。
 *
 * 規則完全比照 XenForo 內建：
 *   \XF\Service\AddOn\HashGeneratorService
 *   \XF\Util\Hash::hashTextFile
 *   \XF\Util\Json::jsonEncodePretty
 *
 * 用法：
 *   php build/generate-hashes.php
 */

declare(strict_types=1);

$repoRoot   = dirname(__DIR__);
$addOnRoot  = $repoRoot . '/src/addons/YUCTS/Opay';
$hashesPath = $addOnRoot . '/hashes.json';

if (!is_dir($addOnRoot))
{
	fwrite(STDERR, "找不到 addon 目錄：$addOnRoot\n");
	exit(1);
}

$hashes = [];

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator(
		$addOnRoot,
		FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
	),
	RecursiveIteratorIterator::LEAVES_ONLY
);

/** @var SplFileInfo $file */
foreach ($iterator AS $file)
{
	if ($file->isDir())
	{
		continue;
	}

	$name = $file->getFilename();

	// 跳過隱藏點檔（保留 .htaccess）
	if ($name !== '' && $name[0] === '.' && $name !== '.htaccess')
	{
		continue;
	}

	// 不對 hashes.json 自身做 hash
	if ($name === 'hashes.json')
	{
		continue;
	}

	$absolute = str_replace('\\', '/', $file->getPathname());
	$relative = ltrim(substr($absolute, strlen(str_replace('\\', '/', $repoRoot))), '/');

	// 與 \XF\Util\Hash::hashTextFile 行為一致：移除 \r 後再 hash
	$contents = file_get_contents($absolute);
	if ($contents === false)
	{
		fwrite(STDERR, "無法讀取：$absolute\n");
		exit(2);
	}
	$normalized = str_replace("\r", '', $contents);

	$hashes[$relative] = hash('sha256', $normalized);
}

ksort($hashes, SORT_NATURAL | SORT_FLAG_CASE);

$json = json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false)
{
	fwrite(STDERR, 'json_encode 失敗：' . json_last_error_msg() . "\n");
	exit(3);
}
// 標準化行結尾為 LF
$json = str_replace("\r", '', $json);

file_put_contents($hashesPath, $json);

echo "已產生 " . str_replace('\\', '/', $hashesPath) . PHP_EOL;
echo "共 " . count($hashes) . " 個檔案" . PHP_EOL;
