<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$studentParam = $_GET['student'] ?? null;
$csvFile = __DIR__ . '/data.csv';

if (is_string($studentParam) && trim($studentParam) !== '') {
	$sanitizedStudent = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($studentParam));
	$sanitizedStudent = trim((string) $sanitizedStudent, '_');

	if ($sanitizedStudent !== '') {
		$csvFile = __DIR__ . '/data_' . $sanitizedStudent . '.csv';
	}
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];

if ($method === 'POST') {
	$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
	$rawBody = file_get_contents('php://input');

	if ($contentType !== 'application/json') {
		http_response_code(415);
		echo json_encode([
			'success' => false,
			'message' => 'POST requests must use Content-Type: application/json.',
		]);
		exit;
	}

	if ($rawBody === false || trim($rawBody) === '') {
		http_response_code(400);
		echo json_encode([
			'success' => false,
			'message' => 'JSON body is empty.',
		]);
		exit;
	}

	$decoded = json_decode($rawBody, true);
	if (!is_array($decoded)) {
		http_response_code(400);
		echo json_encode([
			'success' => false,
			'message' => 'Invalid JSON body. Expected a JSON object.',
		]);
		exit;
	}

	$input = $decoded;
} else {
	$input = $_GET;
}

if (empty($input)) {
	http_response_code(400);
	echo json_encode([
		'success' => false,
		'message' => 'No input data provided.',
	]);
	exit;
}

$normalizeValue = static function ($value): string {
	if (is_array($value)) {
		return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	if (is_bool($value)) {
		return $value ? 'true' : 'false';
	}

	if ($value === null) {
		return '';
	}

	return (string) $value;
};

$incomingKeys = array_keys($input);
$existingHeader = [];
$rows = [];

if (file_exists($csvFile) && filesize($csvFile) > 0) {
	$readHandle = fopen($csvFile, 'rb');
	if ($readHandle === false) {
		http_response_code(500);
		echo json_encode([
			'success' => false,
			'message' => 'Unable to read existing CSV file.',
		]);
		exit;
	}

	$existingHeader = fgetcsv($readHandle) ?: [];
	while (($row = fgetcsv($readHandle)) !== false) {
		$rows[] = $row;
	}
	fclose($readHandle);
}

$header = $existingHeader;
foreach ($incomingKeys as $key) {
	if (!in_array($key, $header, true)) {
		$header[] = $key;
	}
}

$newRow = [];
foreach ($header as $column) {
	$newRow[] = array_key_exists($column, $input) ? $normalizeValue($input[$column]) : '';
}

$writeHandle = fopen($csvFile, 'wb');
if ($writeHandle === false) {
	http_response_code(500);
	echo json_encode([
		'success' => false,
		'message' => 'Unable to write CSV file.',
	]);
	exit;
}

if (fputcsv($writeHandle, $header) === false) {
	fclose($writeHandle);
	http_response_code(500);
	echo json_encode([
		'success' => false,
		'message' => 'Unable to write CSV header.',
	]);
	exit;
}

foreach ($rows as $row) {
	$mappedRow = [];
	foreach ($header as $index => $column) {
		$mappedRow[] = $row[$index] ?? '';
	}

	if (fputcsv($writeHandle, $mappedRow) === false) {
		fclose($writeHandle);
		http_response_code(500);
		echo json_encode([
			'success' => false,
			'message' => 'Unable to rewrite existing CSV rows.',
		]);
		exit;
	}
}

if (fputcsv($writeHandle, $newRow) === false) {
	fclose($writeHandle);
	http_response_code(500);
	echo json_encode([
		'success' => false,
		'message' => 'Unable to append new CSV row.',
	]);
	exit;
}

fclose($writeHandle);

echo json_encode([
	'success' => true,
	'message' => 'Data stored successfully.',
	'columns' => $header,
]);

