<?php
date_default_timezone_set('Asia/Jakarta');
$file = 'results.json';
$data = [];
if (file_exists($file)) {
    $content = file_get_contents($file);
    if (!empty($content)) {
        $data = json_decode($content, true) ?: [];
    }
}

$deleted_sessions = [];
if (file_exists('deleted_sessions.json')) {
    $content = file_get_contents('deleted_sessions.json');
    if (!empty($content)) $deleted_sessions = json_decode($content, true) ?: [];
}

$sessions = [];
foreach ($data as $user) {
    $s_id = isset($user['session_id']) ? $user['session_id'] : 1;
    if (in_array($s_id, $deleted_sessions)) continue;
    
    if (!isset($sessions[$s_id])) {
        $sessions[$s_id] = [
            'id' => $s_id,
            'name' => 'Vaksinasi ' . $s_id,
            'arrivals' => []
        ];
    }
    
    foreach ($user['history'] as $h) {
        if ($h['stage'] == 1 && isset($h['masuk_queue'])) {
            $sessions[$s_id]['arrivals'][] = $h['masuk_queue'];
            break;
        }
    }
}

$valid_sessions = array_filter($sessions, function($s) {
    return count($s['arrivals']) > 0;
});

krsort($valid_sessions);

$last_two_sessions = array_slice($valid_sessions, 0, 2, true);
$last_two_sessions = array_reverse($last_two_sessions, true);

$buckets_def = [
    '08:00:00-08:30:00', '08:30:00-09:00:00',
    '09:00:00-09:30:00', '09:30:00-10:00:00',
    '10:00:00-10:30:00', '10:30:00-11:00:00',
    '11:00:00-11:30:00', '11:30:00-12:00:00',
    '12:00:00-12:30:00', '12:30:00-13:00:00'
];

$table_data = [];
$max_count = 0;
$months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'];

foreach ($last_two_sessions as $s) {
    if (empty($s['arrivals'])) continue;
    
    $min_ts = (int)(min($s['arrivals']) / 1000);
    $m = (int)date('n', $min_ts) - 1;
    $en_months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $m_en = (int)date('n', $min_ts) - 1;
    $date_str = date('j ', $min_ts) . $en_months[$m_en] . date(' Y', $min_ts);
    
    $session_buckets = array_fill_keys($buckets_def, 0);
    $has_data_in_range = false;

    foreach ($s['arrivals'] as $ts_ms) {
        $ts = (int)($ts_ms / 1000);
        $h = (int)date('H', $ts);
        $m = (int)date('i', $ts);
        
        $bucket_key = '';
        if ($h == 8 && $m < 30) $bucket_key = '08:00:00-08:30:00';
        else if ($h == 8 && $m >= 30) $bucket_key = '08:30:00-09:00:00';
        else if ($h == 9 && $m < 30) $bucket_key = '09:00:00-09:30:00';
        else if ($h == 9 && $m >= 30) $bucket_key = '09:30:00-10:00:00';
        else if ($h == 10 && $m < 30) $bucket_key = '10:00:00-10:30:00';
        else if ($h == 10 && $m >= 30) $bucket_key = '10:30:00-11:00:00';
        else if ($h == 11 && $m < 30) $bucket_key = '11:00:00-11:30:00';
        else if ($h == 11 && $m >= 30) $bucket_key = '11:30:00-12:00:00';
        else if ($h == 12 && $m < 30) $bucket_key = '12:00:00-12:30:00';
        else if ($h == 12 && $m >= 30) $bucket_key = '12:30:00-13:00:00';
        
        if ($bucket_key) {
            $session_buckets[$bucket_key]++;
            $has_data_in_range = true;
        }
    }
    
    foreach ($session_buckets as $b => $count) {
        $table_data[] = [
            'date' => $date_str,
            'time' => $b,
            'count' => $count,
            'session_name' => $s['name']
        ];
        if ($count > $max_count) {
            $max_count = $count;
        }
    }
}

$hw_buckets = array_slice($buckets_def, 0, 5);
$Y1 = []; $Y2 = [];

$sessions_keys = array_keys($last_two_sessions);
$s1_key = $sessions_keys[0] ?? null;
$s2_key = $sessions_keys[1] ?? null;
$can_forecast = false;
$hw_forecast = [];
$hw_p1_name = '';
$hw_p2_name = '';

if ($s1_key !== null && $s2_key !== null) {
    $hw_p1_name = $last_two_sessions[$s1_key]['name'] ?? 'P1';
    $hw_p2_name = $last_two_sessions[$s2_key]['name'] ?? 'P2';
    
    $p1_map = []; $p2_map = [];
    foreach ($table_data as $row) {
        if (in_array($row['time'], $hw_buckets)) {
            if ($row['session_name'] == $last_two_sessions[$s1_key]['name']) $p1_map[$row['time']] = $row['count'];
            elseif ($row['session_name'] == $last_two_sessions[$s2_key]['name']) $p2_map[$row['time']] = $row['count'];
        }
    }
    foreach ($hw_buckets as $b) {
        $Y1[] = $p1_map[$b] ?? 0;
        $Y2[] = $p2_map[$b] ?? 0;
    }
    
    if (count($Y1) == 5 && count($Y2) == 5) {
        $can_forecast = true;
        
        $L5 = array_sum($Y1) / 5;
        if ($L5 == 0) $L5 = 1;

        $S_init = [];
        foreach ($Y1 as $y) $S_init[] = $y / $L5;

        $T5 = 0;
        for ($i = 0; $i < 5; $i++) {
            $T5 += (($Y2[$i] - $Y1[$i]) / 5) / 5;
        }
        
        $calc_hw = function($alpha, $beta, $gamma) use ($Y2, $L5, $T5, $S_init) {
            $L_prev = $L5;
            $T_prev = $T5;
            $S_prev = $S_init;
            $S_new = [];
            $sse = 0;
            for ($i = 0; $i < 5; $i++) {
                $y_actual = $Y2[$i];
                $F = ($L_prev + $T_prev) * $S_prev[$i];
                $sse += pow($y_actual - $F, 2);
                
                $L_t = $alpha * ($y_actual / $S_prev[$i]) + (1 - $alpha) * ($L_prev + $T_prev);
                $T_t = $beta * ($L_t - $L_prev) + (1 - $beta) * $T_prev;
                $S_t = $gamma * ($y_actual / $L_t) + (1 - $gamma) * $S_prev[$i];
                
                $S_new[] = $S_t;
                $L_prev = $L_t;
                $T_prev = $T_t;
            }
            return [
                'rmse' => sqrt($sse / 5),
                'L_last' => $L_prev,
                'T_last' => $T_prev,
                'S_last' => $S_new
            ];
        };
        
        $best_rmse = INF;
        $excel_a = 0.0;
        $excel_b = 0.008585854;
        $excel_g = 0.232778029;
        
        $best_model = $calc_hw($excel_a, $excel_b, $excel_g);
        $best_rmse = $best_model['rmse'];
        $best_params = ['a'=>$excel_a, 'b'=>$excel_b, 'g'=>$excel_g];

        for ($a = 0.0; $a <= 1.0; $a += 0.05) {
            for ($b = 0.0; $b <= 1.0; $b += 0.05) {
                for ($g = 0.0; $g <= 1.0; $g += 0.05) {
                    $r = $calc_hw($a, $b, $g);
                    if ($r['rmse'] < $best_rmse - 1e-7) {
                        $best_rmse = $r['rmse'];
                        $best_model = $r;
                        $best_params = ['a'=>$a, 'b'=>$b, 'g'=>$g];
                    }
                }
            }
        }
        
        $a_start = max(0.0, $best_params['a'] - 0.05); $a_end = min(1.0, $best_params['a'] + 0.05);
        $b_start = max(0.0, $best_params['b'] - 0.05); $b_end = min(1.0, $best_params['b'] + 0.05);
        $g_start = max(0.0, $best_params['g'] - 0.05); $g_end = min(1.0, $best_params['g'] + 0.05);
        for ($a = $a_start; $a <= $a_end; $a += 0.01) {
            for ($b = $b_start; $b <= $b_end; $b += 0.01) {
                for ($g = $g_start; $g <= $g_end; $g += 0.01) {
                    $r = $calc_hw($a, $b, $g);
                    if ($r['rmse'] < $best_rmse - 1e-7) {
                        $best_rmse = $r['rmse'];
                        $best_model = $r;
                        $best_params = ['a'=>$a, 'b'=>$b, 'g'=>$g];
                    }
                }
            }
        }
        
        $hw_total_forecast = 0;
        for ($h = 1; $h <= 5; $h++) {
            $f_raw = ($best_model['L_last'] + $h * $best_model['T_last']) * $best_model['S_last'][$h - 1];
            $f_val = (int)ceil($f_raw);
            if ($f_val < 0) $f_val = 0;
            $hw_forecast[] = $f_val;
            $hw_total_forecast += $f_val;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QueueFlow Pro - Analysis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <style>
        .top-header-bar { height: 64px; background: rgba(255,255,255,0.92); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; padding: 0 1.75rem; gap: 1rem; flex-shrink: 0; }
        .th-page-label { font-size: 0.9rem; font-weight: 600; color: #64748b; flex: 1; }
        .th-icon-btn { width: 38px; height: 38px; border: none; background: transparent; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 1.1rem; cursor: pointer; transition: all 0.2s; flex-shrink: 0; }
        .th-icon-btn:hover { background: #f1f5f9; color: #4f46e5; }
        .th-divider { width: 1px; height: 30px; background: #e2e8f0; margin: 0 0.35rem; flex-shrink: 0; }
        .th-user-area { display: flex; align-items: center; gap: 0.6rem; cursor: pointer; padding: 0.3rem 0.65rem; border-radius: 12px; transition: background 0.2s; }
        .th-user-area:hover { background: #f1f5f9; }
        .th-user-info { text-align: right; line-height: 1.25; }
        .th-user-name { font-size: 0.82rem; font-weight: 700; color: #0f172a; }
        .th-user-role { font-size: 0.6rem; font-weight: 600; color: #94a3b8; letter-spacing: 0.8px; text-transform: uppercase; }
        .th-avatar { width: 36px; height: 36px; border-radius: 10px; object-fit: cover; border: 2px solid #e0e7ff; flex-shrink: 0; }
        .th-avatar-fallback { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, #6366f1, #4f46e5); display: flex; align-items: center; justify-content: center; color: white; font-size: 0.9rem; font-weight: 700; flex-shrink: 0; }
        
        .analysis-content { padding: 1.75rem 2rem; max-width: 1200px; margin: 0 auto; }
        .a-page-title { font-size: 1.65rem; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; margin-bottom: 0.35rem; }
        .a-page-subtitle { color: #6366f1; font-size: 0.83rem; font-weight: 500; max-width: 600px; line-height: 1.5; margin-bottom: 2rem;}
        
        .h-breadcrumb { font-size: 0.78rem; margin-bottom: 0.85rem; }
        .h-breadcrumb a { color: #6366f1; text-decoration: none; font-weight: 500; }
        .h-breadcrumb a:hover { text-decoration: underline; }
        .h-breadcrumb .sep { margin: 0 0.4rem; color: #94a3b8; }
        .h-breadcrumb .current { color: #6366f1; font-weight: 600; }
        .h-page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
        .h-page-title { font-size: 1.65rem; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; margin-bottom: 0.35rem; }
        .h-page-subtitle { color: #6366f1; font-size: 0.83rem; font-weight: 500; max-width: 600px; line-height: 1.5; }
        
        .analysis-card { background: white; border-radius: 18px; box-shadow: 0 4px 20px rgba(99,102,241,0.06), 0 1px 3px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; padding: 2rem; margin-bottom: 1.5rem; }
        
        .hw-analysis-container { opacity: 0; transform: translateY(20px); transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1); display: none; }
        .hw-analysis-container.show { display: block; opacity: 1; transform: translateY(0); }
        
        .custom-table { width: 100%; border-collapse: collapse; }
        .custom-table thead tr { background: #f8fafc; }
        .custom-table th { padding: 0.8rem 1.2rem; font-size: 0.67rem; font-weight: 700; letter-spacing: 1px; color: #64748b; text-transform: uppercase; border-bottom: 1px solid #f1f5f9; white-space: nowrap; }
        .custom-table tbody tr { border-bottom: 1px solid #f8fafc; transition: background 0.15s; }
        .custom-table tbody tr:last-child { border-bottom: none; }
        .custom-table tbody tr:hover { background: #fafbff; }
        .custom-table td { padding: 0.95rem 1.2rem; vertical-align: middle; font-size: 0.9rem; color: #334155; }
        
        .forecast-table { border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; border-collapse: separate; border-spacing: 0; }
        .forecast-table thead th { background: #f8fafc; color: #475569; border-bottom: 2px solid #e2e8f0; border-right: 1px solid #e2e8f0; }
        .forecast-table tbody td { border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; }
        .forecast-table th:last-child, .forecast-table td:last-child { border-right: none; }
        .forecast-table tbody tr:last-child td { border-bottom: none; }
        
        .peak-row { background: #fef2f2 !important; font-weight: 700; }
        .peak-row td { color: #dc2626 !important; }
        .peak-badge { display: inline-block; background: #ef4444; color: white; font-size: 0.7rem; padding: 0.15rem 0.5rem; border-radius: 12px; margin-left: 8px; letter-spacing: 0.5px; text-transform: uppercase; }

        .insight-box { background: #f0fdf4; border: 1px solid #bbf7d0; padding: 1.5rem; border-radius: 12px; margin-top: 1.5rem; display: flex; gap: 1rem; align-items: flex-start; }
        .insight-icon { font-size: 1.5rem; color: #16a34a; }
        .insight-text h6 { font-weight: 800; color: #166534; margin-bottom: 0.4rem; font-size: 0.95rem; }
        .insight-text p { margin-bottom: 0; font-size: 0.85rem; color: #15803d; line-height: 1.5; }
    </style>
</head>
<body class="bg-app">
<div class="d-flex vw-100 vh-100 overflow-hidden">
    
    <aside class="sidebar bg-white border-end d-flex flex-column flex-shrink-0">
        <div class="p-4 d-flex align-items-center gap-3">
            <div class="brand-icon text-white rounded-3 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                <i class="bi bi-bar-chart-fill" style="font-size: 1.1rem;"></i>
            </div>
            <h5 class="fw-bold mb-0 text-dark" style="line-height: 1.2; letter-spacing: -0.5px;">QueueFlow<span class="text-primary">.</span><br><span class="fs-6 fw-semibold text-secondary">Pro Simulation</span></h5>
        </div>
        <nav class="nav flex-column gap-2 px-3 fw-semibold mt-2">
            <a href="index.php" class="nav-link text-secondary rounded-1 d-flex align-items-center gap-3 py-3 px-3"><i class="bi bi-grid-1x2-fill"></i> DASHBOARD</a>
            <a href="history.php" class="nav-link text-secondary rounded-1 d-flex align-items-center gap-3 py-3 px-3"><i class="bi bi-clock-history"></i> HISTORY</a>
            <a href="analysis.php" class="nav-link active rounded-1 d-flex align-items-center gap-3 py-3 px-3"><i class="bi bi-bar-chart-line-fill"></i> ANALYSIS</a>
        </nav>
        <div class="mt-auto border-top mx-3 mb-3 pt-3">
            <a href="index.php" class="btn btn-primary w-100 fw-bold d-flex align-items-center justify-content-center gap-2 mb-4 py-2"><i class="bi bi-plus-lg"></i> New Simulation</a>
            <a href="#" class="nav-link text-secondary rounded-1 d-flex align-items-center gap-3 px-3 py-2 fw-semibold"><i class="bi bi-gear-fill"></i> SETTINGS</a>
        </div>
    </aside>

    <div class="d-flex flex-column flex-grow-1 overflow-hidden">
        <div class="top-header-bar">
            <div class="th-page-label">Analisis Data - Distribusi Kedatangan</div>
            <div class="d-flex align-items-center gap-1">
                <button class="th-icon-btn"><i class="bi bi-bell"></i></button>
                <div class="th-divider"></div>
                <div class="th-user-area">
                    <div class="th-user-info">
                        <div class="th-user-name">Admin Utama</div>
                        <div class="th-user-role">Administrator</div>
                    </div>
                    <img src="admin_avatar.png" alt="Admin" class="th-avatar" onerror="this.outerHTML='<div class=&quot;th-avatar-fallback&quot;>A</div>'">
                </div>
            </div>
        </div>
        <main class="flex-grow-1 overflow-auto bg-app">
            <div class="analysis-content" style="max-width: 100%;">
                
                <div class="h-breadcrumb">
                    <a href="index.php">Dashboard</a> <span class="sep">›</span> <span class="current">Analisis Data</span>
                </div>
                
                <div class="h-page-header">
                    <div>
                        <h1 class="h-page-title">Analisis Data (Holt-Winters Forecasting)</h1>
                        <p class="h-page-subtitle">Peramalan kedatangan pasien menggunakan Model Multiplicative berdasarkan optimisasi error (RMSE).</p>
                    </div>
                </div>

                <div class="row g-4 mb-5">
                    <div class="<?php echo $can_forecast ? 'col-xl-7' : 'col-12'; ?>">
                        <div class="analysis-card h-100 mb-0">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div style="width: 42px; height: 42px; border-radius: 12px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #64748b; flex-shrink: 0;">
                                        <i class="bi bi-clock-history" style="font-size: 1.3rem;"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-bold mb-1" style="color: #1e293b; font-size: 1.1rem; letter-spacing: -0.3px;">Distribusi Kedatangan</h6>
                                        <p class="text-secondary mb-0" style="font-size: 0.75rem;">Tabel jumlah kedatangan pasien pada interval 30 menit dari 2 sesi terakhir.</p>
                                    </div>
                                </div>
                                <?php if ($can_forecast && !empty($table_data)): ?>
                                <div class="d-flex align-items-center gap-2">
                                    <button id="btnRestartAnalysis" class="btn btn-outline-secondary fw-bold px-3 py-2 d-none align-items-center gap-2" style="border-radius: 10px; transition: all 0.3s; font-size: 0.85rem;" title="Ulangi Analisis">
                                        <i class="bi bi-arrow-counterclockwise fs-5" style="margin-right: -2px;"></i>
                                    </button>
                                    <button id="btnStartAnalysis" class="btn btn-primary fw-bold px-4 py-2 d-inline-flex align-items-center gap-2" style="border-radius: 10px; transition: all 0.3s; font-size: 0.85rem;">
                                        <i class="bi bi-play-fill fs-5" style="margin-right: -4px;"></i> Mulai Analisis Holt-Winters
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                    <?php if (empty($table_data)): ?>
                        <div class="text-center text-secondary py-5">
                            <i class="bi bi-inbox fs-1"></i>
                            <p class="mt-3">Belum ada data kedatangan pasien yang tercatat pada sesi mana pun.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="custom-table">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Waktu</th>
                                        <th>Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $all_counts = array_column($table_data, 'count');
                                    $unique_counts = array_unique($all_counts);
                                    rsort($unique_counts);
                                    $peak_threshold = isset($unique_counts[1]) ? $unique_counts[1] : (isset($unique_counts[0]) ? $unique_counts[0] : 0);
                                    if ($peak_threshold < 5 && isset($unique_counts[0])) $peak_threshold = $unique_counts[0];

                                    $peak_info = [];
                                    foreach ($table_data as $row): 
                                        $is_peak = ($row['count'] >= $peak_threshold && $row['count'] > 0);
                                        if ($is_peak) {
                                            $peak_info[] = "{$row['time']} ({$row['date']})";
                                        }
                                    ?>
                                    <tr class="<?php echo $is_peak ? 'peak-row' : ''; ?>">
                                        <td><?php echo htmlspecialchars($row['date']); ?></td>
                                        <td><?php echo htmlspecialchars($row['time']); ?></td>
                                        <td>
                                            <?php echo $row['count']; ?>
                                            <?php if ($is_peak): ?>
                                                <span class="peak-badge">Peak Hour</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        


                    <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($can_forecast): ?>
                    <div class="col-xl-5 d-flex flex-column gap-4" id="emptyStateContainer">
                        <div class="analysis-card mb-0 d-flex flex-column align-items-center justify-content-center text-center" style="border: 2px dashed #cbd5e1; background: #f8fafc; height: 350px;">
                            <i class="bi bi-bar-chart-line" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                            <h6 class="fw-bold text-secondary mb-2" style="color: #94a3b8 !important;">Menunggu Grafik Prediksi</h6>
                            <p class="text-secondary" style="font-size: 0.85rem; max-width: 280px; margin-bottom:0;">Klik tombol "Mulai Analisis Holt-Winters" di sebelah kiri untuk melihat hasil prediksi kunjungan berikutnya.</p>
                        </div>
                        
                        <div class="analysis-card mb-0 d-flex flex-column align-items-center justify-content-center text-center flex-grow-1" style="border: 2px dashed #cbd5e1; background: #f8fafc; min-height: 250px;">
                            <i class="bi bi-table" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                            <h6 class="fw-bold text-secondary mb-0" style="color: #94a3b8 !important;">Menunggu Tabel Prediksi</h6>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($can_forecast): ?>
                    <div class="col-xl-5 hw-analysis-container" id="hwContainer">
                        <div class="analysis-card mb-0">
                            <div class="mb-4">
                                <div class="d-flex align-items-center gap-3 mb-4">
                                    <div style="width: 42px; height: 42px; border-radius: 12px; background: #e0e7ff; display: flex; align-items: center; justify-content: center; color: #4f46e5; flex-shrink: 0;">
                                        <i class="bi bi-graph-up-arrow" style="font-size: 1.3rem;"></i>
                                    </div>
                                    <h6 class="fw-bold mb-0" style="color: #1e293b; font-size: 1.1rem; letter-spacing: -0.3px;">Grafik Perbandingan Aktual vs Forecast</h6>
                                </div>
                                <div style="position: relative; height: 350px; width: 100%;">
                                    <canvas id="hwChart"></canvas>
                                </div>
                            </div>
                            
                            <hr class="my-4" style="border-color: #e2e8f0;">
                            
                            <div>
                                <div class="d-flex align-items-center gap-3 mb-4">
                                    <div style="width: 42px; height: 42px; border-radius: 12px; background: #d1fae5; display: flex; align-items: center; justify-content: center; color: #059669; flex-shrink: 0;">
                                        <i class="bi bi-table" style="font-size: 1.3rem;"></i>
                                    </div>
                                    <h6 class="fw-bold mb-0" style="color: #1e293b; font-size: 1.1rem; letter-spacing: -0.3px;">Tabel Prediksi Kunjungan Selanjutnya</h6>
                                </div>
                                <div class="table-responsive">
                                        <table class="custom-table forecast-table" style="font-size: 0.85rem;">
                                            <thead>
                                                <tr>
                                                    <th>Waktu</th>
                                                    <th>Aktual P1</th>
                                                    <th>Aktual P2</th>
                                                    <th class="text-primary">Forecast</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($hw_buckets as $i => $time): ?>
                                                <tr>
                                                    <td><?php echo $time; ?></td>
                                                    <td><?php echo $Y1[$i]; ?></td>
                                                    <td><?php echo $Y2[$i]; ?></td>
                                                    <td class="fw-bold text-primary"><?php echo $hw_forecast[$i]; ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <tr style="font-weight:800; background: #f8fafc;">
                                                    <td colspan="3" class="text-end" style="color: #64748b; border-top: 2px solid #e2e8f0;">Total Kunjungan Berikutnya:</td>
                                                    <td style="color: #4f46e5; font-size:1.1rem; border-top: 2px solid #e2e8f0;"><?php echo $hw_total_forecast; ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <?php if ($max_count > 0): ?>
                                    <div class="insight-box mt-4">
                                        <div class="insight-icon"><i class="bi bi-lightbulb-fill"></i></div>
                                        <div class="insight-text">
                                            <h6>Analisis Peak Hour (Jam Sibuk)</h6>
                                            <p>Berdasarkan data 2 sesi terakhir, tingkat kedatangan pasien yang paling tinggi (Peak Hour) berada di kisaran <strong><?php echo $peak_threshold; ?> hingga <?php echo $max_count; ?> orang</strong> per 30 menit. Penumpukan ini terpantau pada rentang waktu: <strong><?php echo implode(', ', $peak_info); ?></strong>.<br><br>
                                            <strong>Rekomendasi:</strong> Disarankan untuk mengalokasikan staf tambahan atau memaksimalkan jumlah server/layanan aktif pada rentang waktu tersebut untuk mencegah antrean panjang (bottleneck).</p>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php if ($can_forecast): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('hwChart').getContext('2d');
    const labels = <?php echo json_encode($hw_buckets); ?>;
    const y1 = <?php echo json_encode($Y1); ?>;
    const y2 = <?php echo json_encode($Y2); ?>;
    const forecast = <?php echo json_encode($hw_forecast); ?>;
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '<?php echo addslashes($hw_p1_name); ?> (P1)',
                    data: y1,
                    borderColor: '#10b981',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    tension: 0.4
                },
                {
                    label: '<?php echo addslashes($hw_p2_name); ?> (P2)',
                    data: y2,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Forecast Sesi Berikutnya',
                    data: forecast,
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    borderDash: [6, 4],
                    borderWidth: 3,
                    pointBackgroundColor: '#f59e0b',
                    pointRadius: 5,
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                tooltip: { mode: 'index', intersect: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    const btn = document.getElementById('btnStartAnalysis');
    const container = document.getElementById('hwContainer');
    
    if (btn && container) {
        btn.addEventListener('click', function() {
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Menganalisis...';
            btn.disabled = true;

            setTimeout(() => {
                btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Analisis Selesai';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-success');
                
                const emptyState = document.getElementById('emptyStateContainer');
                if (emptyState) {
                    emptyState.classList.remove('d-flex');
                    emptyState.classList.add('d-none');
                }
                
                container.style.display = 'block';
                void container.offsetWidth;
                container.classList.add('show');

                const btnRestart = document.getElementById('btnRestartAnalysis');
                if (btnRestart) {
                    btnRestart.classList.remove('d-none');
                    btnRestart.classList.add('d-inline-flex');
                }
                
                setTimeout(() => {
                    container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 100);
            }, 1200);
        });
    }

    const btnRestart = document.getElementById('btnRestartAnalysis');
    if (btnRestart && btn) {
        btnRestart.addEventListener('click', function() {
            // Kembalikan tombol start ke semula
            btn.innerHTML = '<i class="bi bi-play-fill fs-5" style="margin-right: -4px;"></i> Mulai Analisis Holt-Winters';
            btn.classList.remove('btn-success');
            btn.classList.add('btn-primary');
            btn.disabled = false;

            btnRestart.classList.remove('d-inline-flex');
            btnRestart.classList.add('d-none');

            container.classList.remove('show');
            setTimeout(() => {
                container.style.display = 'none';

                const emptyState = document.getElementById('emptyStateContainer');
                if (emptyState) {
                    emptyState.classList.remove('d-none');
                    emptyState.classList.add('d-flex');
                }
            }, 400);
        });
    }
});
</script>
<?php endif; ?>
</body>
</html>
