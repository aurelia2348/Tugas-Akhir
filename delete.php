<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['session_id'])) {
    $target_id = intval($_POST['session_id']);

    $deleted_file = 'deleted_sessions.json';
    $deleted = [];
    if (file_exists($deleted_file)) {
        $content = file_get_contents($deleted_file);
        if (!empty($content)) {
            $deleted = json_decode($content, true) ?: [];
        }
    }
    if (!in_array($target_id, $deleted)) {
        $deleted[] = $target_id;
        file_put_contents($deleted_file, json_encode($deleted));
    }
    
    $results_file = 'results.json';
    if (file_exists($results_file)) {
        $content = file_get_contents($results_file);
        if (!empty($content)) {
            $data = json_decode($content, true) ?: [];
            $filtered = [];
            foreach ($data as $user) {
                $sid = isset($user['session_id']) ? intval($user['session_id']) : 1;
                if ($sid !== $target_id) {
                    $filtered[] = $user;
                }
            }
            $filtered = array_values($filtered);
            file_put_contents($results_file, json_encode($filtered, JSON_PRETTY_PRINT));
        }
    }
}

header("Location: history.php");
exit;
