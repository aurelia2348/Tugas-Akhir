<?php
header('Content-Type: application/json');

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['id'])) {
    echo json_encode(["status" => "error", "message" => "Invalid data"]);
    exit;
}

$file = 'results.json';
$currentData = [];

if (file_exists($file)) {
    $fileData = file_get_contents($file);
    if (!empty($fileData)) {
        $decoded = json_decode($fileData, true);
        if (is_array($decoded)) {
            $currentData = $decoded;
        }
    }
}

$currentData[] = $data;

if (file_put_contents($file, json_encode($currentData, JSON_PRETTY_PRINT))) {
    echo json_encode(["status" => "success", "message" => "Data saved"]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to write file"]);
}
