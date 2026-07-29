<?php
date_default_timezone_set('Asia/Jakarta');
$file = 'results.json';
$data = [];

if (file_exists($file)) {
    $fileData = file_get_contents($file);
    if (!empty($fileData)) {
        $data = json_decode($fileData, true) ?: [];
    }
}

$max_session_id = 1;
if (file_exists('max_session.txt')) {
    $max_session_id = (int)file_get_contents('max_session.txt');
}

$sessions = [];

$deleted_sessions = [];
if (file_exists('deleted_sessions.json')) {
    $content = file_get_contents('deleted_sessions.json');
    if (!empty($content)) {
        $deleted_sessions = json_decode($content, true) ?: [];
    }
}

for ($i = 1; $i <= $max_session_id; $i++) {
    if (in_array($i, $deleted_sessions)) continue;

    $sessions[$i] = [
        'id' => $i,
        'name' => 'Vaksinasi ' . $i,
        'start_time' => null,
        'patient_count' => 0
    ];
}

foreach ($data as $user) {
    $s_id = isset($user['session_id']) ? $user['session_id'] : 1;
    
    if (!isset($sessions[$s_id])) {
        if (in_array($s_id, $deleted_sessions)) continue;
        
        $sessions[$s_id] = [
            'id' => $s_id,
            'name' => 'Vaksinasi ' . $s_id,
            'start_time' => null,
            'patient_count' => 0
        ];
    }
    
    $sessions[$s_id]['patient_count']++;
    
    foreach ($user['history'] as $h) {
        if ($h['stage'] == 1 && isset($h['masuk_queue'])) {
            if ($sessions[$s_id]['start_time'] === null || $h['masuk_queue'] < $sessions[$s_id]['start_time']) {
                $sessions[$s_id]['start_time'] = $h['masuk_queue'];
            }
        }
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($search !== '') {
    $sessions = array_filter($sessions, function($s) use ($search) {
        return stripos($s['name'], $search) !== false || stripos((string)$s['id'], $search) !== false;
    });
}

$sort = isset($_GET['sort']) ? $_GET['sort'] : 'latest';
usort($sessions, function($a, $b) use ($sort) {
    if ($a['start_time'] === null && $b['start_time'] !== null) return -1;
    if ($a['start_time'] !== null && $b['start_time'] === null) return 1;
    
    $timeA = $a['start_time'] ?? 0;
    $timeB = $b['start_time'] ?? 0;
    
    if ($timeA == $timeB) {
        return $b['id'] <=> $a['id'];
    }
    
    return ($sort === 'oldest') ? ($timeA <=> $timeB) : ($timeB <=> $timeA);
});

$limit = 7;
$total_items = count($sessions);
$total_pages = ceil($total_items / $limit);
$page = isset($_GET['page']) ? max(1, min($total_pages, (int)$_GET['page'])) : 1;
$offset = ($page - 1) * $limit;
$paginated_sessions = array_slice($sessions, $offset, $limit);

$all_sessions_raw = [];
for ($i = 1; $i <= $max_session_id; $i++) {
    if (in_array($i, $deleted_sessions)) continue;
    $all_sessions_raw[$i] = [
        'id' => $i,
        'name' => 'Vaksinasi ' . $i,
        'patient_count' => 0
    ];
}
foreach ($data as $user) {
    $s_id = isset($user['session_id']) ? $user['session_id'] : 1;
    if (isset($all_sessions_raw[$s_id])) {
        $all_sessions_raw[$s_id]['patient_count']++;
    }
}

$non_zero_sessions = array_filter($all_sessions_raw, fn($s) => $s['patient_count'] > 0);
$avg_patients = 0;
if (count($non_zero_sessions) > 0) {
    $avg_patients = array_sum(array_column($non_zero_sessions, 'patient_count')) / count($non_zero_sessions);
}

$last_session = end($all_sessions_raw);
reset($all_sessions_raw);

$last_analyzed = null;
for ($i = $max_session_id; $i >= 1; $i--) {
    if (isset($all_sessions_raw[$i]) && $all_sessions_raw[$i]['patient_count'] > 0) {
        $last_analyzed = $all_sessions_raw[$i];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QueueFlow Pro - Riwayat Simulasi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <style>
        .top-header-bar {
            height: 64px;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            padding: 0 1.75rem;
            gap: 1rem;
            flex-shrink: 0;
        }
        .th-page-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #64748b;
            flex: 1;
        }
        .th-icon-btn {
            width: 38px;
            height: 38px;
            border: none;
            background: transparent;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .th-icon-btn:hover { background: #f1f5f9; color: #4f46e5; }
        .th-divider {
            width: 1px;
            height: 30px;
            background: #e2e8f0;
            margin: 0 0.35rem;
            flex-shrink: 0;
        }
        .th-user-area {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            cursor: pointer;
            padding: 0.3rem 0.65rem;
            border-radius: 12px;
            transition: background 0.2s;
        }
        .th-user-area:hover { background: #f1f5f9; }
        .th-user-info { text-align: right; line-height: 1.25; }
        .th-user-name { font-size: 0.82rem; font-weight: 700; color: #0f172a; }
        .th-user-role { font-size: 0.6rem; font-weight: 600; color: #94a3b8; letter-spacing: 0.8px; text-transform: uppercase; }
        .th-avatar {
            width: 36px; height: 36px; border-radius: 10px;
            object-fit: cover; border: 2px solid #e0e7ff; flex-shrink: 0;
        }
        .th-avatar-fallback {
            width: 36px; height: 36px; border-radius: 10px;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 0.9rem; font-weight: 700; flex-shrink: 0;
        }

        .history-content {
            padding: 1.75rem 2rem;
        }

        .h-breadcrumb { font-size: 0.78rem; margin-bottom: 0.85rem; }
        .h-breadcrumb a { color: #6366f1; text-decoration: none; font-weight: 500; }
        .h-breadcrumb a:hover { text-decoration: underline; }
        .h-breadcrumb .sep { margin: 0 0.4rem; color: #94a3b8; }
        .h-breadcrumb .current { color: #6366f1; font-weight: 600; }

        .h-page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
        .h-page-title { font-size: 1.65rem; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; margin-bottom: 0.35rem; }
        .h-page-subtitle { color: #6366f1; font-size: 0.83rem; font-weight: 500; max-width: 480px; line-height: 1.5; }
        .btn-reset-global {
            background: white; border: 1.5px solid #ef4444; color: #ef4444;
            font-weight: 700; font-size: 0.8rem; border-radius: 10px;
            padding: 0.52rem 1.1rem; display: flex; align-items: center;
            gap: 0.45rem; text-decoration: none; transition: all 0.2s; white-space: nowrap; margin-top: 0.25rem;
        }
        .btn-reset-global:hover { background: #fef2f2; color: #dc2626; border-color: #dc2626; transform: translateY(-1px); }

        .history-card {
            background: white; border-radius: 18px;
            box-shadow: 0 4px 20px rgba(99,102,241,0.06), 0 1px 3px rgba(0,0,0,0.03);
            border: 1px solid #f1f5f9; overflow: hidden; margin-bottom: 1.25rem;
        }

        .h-toolbar {
            display: flex; align-items: center; justify-content: flex-end;
            padding: 1rem 1.4rem; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap; gap: 0.7rem;
        }
        .h-toolbar-right { display: flex; align-items: center; gap: 0.65rem; }
        .h-btn-filter {
            background: white; border: 1px solid #e2e8f0; color: #374151;
            font-size: 0.8rem; font-weight: 600; border-radius: 10px; padding: 0.48rem 0.95rem;
            display: flex; align-items: center; gap: 0.4rem; cursor: pointer; transition: all 0.2s; white-space: nowrap;
        }
        .h-btn-filter:hover { background: #f8fafc; border-color: #c7d2fe; color: #4f46e5; }
        .h-status-badge { display: flex; align-items: center; gap: 0.35rem; font-size: 0.77rem; font-weight: 600; color: #374151; white-space: nowrap; }
        .h-status-dot { width: 7px; height: 7px; border-radius: 50%; background: #10b981; animation: pulse-green 2s infinite; flex-shrink: 0; }
        @keyframes pulse-green {
            0%, 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0.4); }
            50% { box-shadow: 0 0 0 4px rgba(16,185,129,0); }
        }
        .h-status-text { color: #059669; font-weight: 700; background: #d1fae5; padding: 0.18rem 0.5rem; border-radius: 6px; font-size: 0.72rem; }

        .h-table { width: 100%; border-collapse: collapse; }
        .h-table thead tr { background: #f8fafc; }
        .h-table th { padding: 0.8rem 1.2rem; font-size: 0.67rem; font-weight: 700; letter-spacing: 1px; color: #64748b; text-transform: uppercase; border-bottom: 1px solid #f1f5f9; white-space: nowrap; }
        .h-table tbody tr { border-bottom: 1px solid #f8fafc; transition: background 0.15s; }
        .h-table tbody tr:last-child { border-bottom: none; }
        .h-table tbody tr:hover { background: #fafbff; }
        .h-table td { padding: 0.95rem 1.2rem; vertical-align: middle; }

        .session-icon-box {
            width: 36px; height: 36px; border-radius: 10px;
            background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
            display: flex; align-items: center; justify-content: center;
            color: #4f46e5; font-size: 1rem; flex-shrink: 0;
        }
        .session-name { font-weight: 700; font-size: 0.86rem; color: #0f172a; }
        .date-main { font-weight: 600; font-size: 0.84rem; color: #0f172a; }
        .date-time { font-size: 0.73rem; color: #94a3b8; font-weight: 500; }
        .tba-text { font-size: 0.8rem; color: #94a3b8; font-style: italic; }
        .tba-sub { font-size: 0.7rem; color: #c4b5fd; }

        .patient-badge {
            display: inline-flex; align-items: center;
            background: #e0e7ff; color: #3730a3;
            font-size: 0.76rem; font-weight: 700; border-radius: 20px;
            padding: 0.28rem 0.85rem; white-space: nowrap;
        }
        .btn-view-result {
            background: #e0e7ff; color: #3730a3; font-size: 0.76rem; font-weight: 700;
            border-radius: 20px; padding: 0.35rem 0.95rem; border: none; text-decoration: none;
            display: inline-flex; align-items: center; gap: 0.3rem; transition: all 0.2s; white-space: nowrap;
        }
        .btn-view-result:hover { background: #c7d2fe; color: #1e1b4b; transform: translateY(-1px); }
        .btn-delete {
            background: transparent; border: none; color: #ef4444;
            font-size: 0.95rem; padding: 0.28rem 0.45rem;
            cursor: pointer; border-radius: 8px; transition: all 0.2s; line-height: 1;
        }
        .btn-delete:hover { background: #fef2f2; color: #dc2626; }

        .h-pagination-bar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.8rem 1.4rem; border-top: 1px solid #f1f5f9; background: #fafafa; flex-wrap: wrap; gap: 0.5rem;
        }
        .h-pag-info { font-size: 0.78rem; color: #64748b; }
        .h-pag-info strong { color: #0f172a; }
        .h-pag-btns { display: flex; align-items: center; gap: 0.28rem; }
        .h-pag-btn {
            width: 32px; height: 32px; border-radius: 8px; border: 1px solid #e2e8f0;
            background: white; display: flex; align-items: center; justify-content: center;
            font-size: 0.78rem; font-weight: 700; color: #374151; text-decoration: none;
            cursor: pointer; transition: all 0.18s;
        }
        .h-pag-btn:hover { background: #f1f5f9; border-color: #c7d2fe; color: #4f46e5; }
        .h-pag-btn.active { background: linear-gradient(135deg, #6366f1, #4f46e5); border-color: transparent; color: white; box-shadow: 0 3px 8px rgba(99,102,241,0.3); }
        .h-pag-btn.disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }

        .bottom-cards { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-top: 1.25rem; }

        .card-avg {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            border-radius: 16px; padding: 1.35rem; position: relative; overflow: hidden;
            color: white; box-shadow: 0 8px 24px rgba(99,102,241,0.3);
        }
        .card-avg::before { content: ''; position: absolute; right: -15px; bottom: -20px; width: 90px; height: 90px; border-radius: 50%; background: rgba(255,255,255,0.08); }
        .card-avg::after { content: ''; position: absolute; right: 22px; bottom: 8px; width: 55px; height: 55px; border-radius: 50%; background: rgba(255,255,255,0.06); }
        .card-avg-bg-icon { position: absolute; right: 1rem; bottom: 0.5rem; font-size: 3.2rem; color: rgba(255,255,255,0.12); line-height: 1; }
        .card-avg-label { font-size: 0.71rem; font-weight: 700; opacity: 0.85; margin-bottom: 0.45rem; }
        .card-avg-value { font-size: 2.4rem; font-weight: 800; line-height: 1; margin-bottom: 0.5rem; letter-spacing: -1px; }
        .card-avg-trend { font-size: 0.71rem; opacity: 0.85; display: flex; align-items: center; gap: 0.3rem; }

        .card-last-session {
            background: white; border-radius: 16px; padding: 1.35rem;
            border: 1px solid #f1f5f9; box-shadow: 0 4px 16px rgba(0,0,0,0.04); overflow: hidden;
        }
        .card-last-session-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.8rem; }
        .last-session-icon {
            width: 40px; height: 40px; border-radius: 12px;
            background: linear-gradient(135deg, #fde68a, #f59e0b);
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 1.05rem; box-shadow: 0 4px 12px rgba(245,158,11,0.3);
        }
        .last-session-tag { font-size: 0.63rem; font-weight: 700; letter-spacing: 0.8px; color: #94a3b8; text-transform: uppercase; }
        .last-session-name { font-size: 1.15rem; font-weight: 800; color: #0f172a; margin-bottom: 0.25rem; }
        .last-session-status { font-size: 0.73rem; color: #94a3b8; font-style: italic; }

        .card-log {
            background: white; border-radius: 16px; padding: 1.35rem;
            border: 1px solid #f1f5f9; box-shadow: 0 4px 16px rgba(0,0,0,0.04);
        }
        .card-log-header { display: flex; align-items: center; gap: 0.65rem; margin-bottom: 0.9rem; }
        .log-icon-box { width: 36px; height: 36px; border-radius: 10px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 0.95rem; }
        .card-log-title { font-size: 0.88rem; font-weight: 700; color: #0f172a; }
        .log-entry-row { display: flex; align-items: flex-start; gap: 0.45rem; margin-bottom: 0.6rem; font-size: 0.77rem; }
        .log-dot { width: 7px; height: 7px; border-radius: 50%; background: #6366f1; flex-shrink: 0; margin-top: 0.28rem; }
        .log-dot-admin { background: #f59e0b; }
        .log-text { color: #374151; font-weight: 500; line-height: 1.4; }
        .log-link { color: #6366f1; font-weight: 700; }
        .log-admin { color: #f59e0b; font-weight: 700; }

        .dropdown-menu { border: 1px solid #e2e8f0 !important; border-radius: 12px !important; box-shadow: 0 8px 24px rgba(0,0,0,0.08) !important; padding: 0.4rem !important; min-width: 150px; }
        .dropdown-item { border-radius: 8px; font-size: 0.8rem; font-weight: 600; padding: 0.45rem 0.8rem !important; color: #374151; transition: all 0.15s; }
        .dropdown-item:hover { background: #f1f5f9; color: #4f46e5; }
        .dropdown-item.active-sort { background: #e0e7ff !important; color: #3730a3 !important; }
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
            <a href="index.php" class="nav-link text-secondary rounded-1 d-flex align-items-center gap-3 py-3 px-3">
                <i class="bi bi-grid-1x2-fill"></i> DASHBOARD
            </a>
            <a href="#" class="nav-link active rounded-1 d-flex align-items-center gap-3 py-3 px-3">
                <i class="bi bi-clock-history"></i> HISTORY
            </a>
            <a href="analysis.php" class="nav-link text-secondary rounded-1 d-flex align-items-center gap-3 py-3 px-3">
                <i class="bi bi-bar-chart-line-fill"></i> ANALYSIS
            </a>
        </nav>

        <div class="mt-auto border-top mx-3 mb-3 pt-3">
            <a href="index.php" class="btn btn-primary w-100 fw-bold d-flex align-items-center justify-content-center gap-2 mb-4 py-2">
                <i class="bi bi-plus-lg"></i> New Simulation
            </a>
            <a href="#" class="nav-link text-secondary rounded-1 d-flex align-items-center gap-3 px-3 py-2 fw-semibold">
                <i class="bi bi-gear-fill"></i> SETTINGS
            </a>
        </div>
    </aside>

    <div class="d-flex flex-column flex-grow-1 overflow-hidden">

        <div class="top-header-bar">
            <div class="th-page-label">Riwayat Simulasi - Daftar Rekaman Sesi</div>

            <div class="d-flex align-items-center gap-1">
                <button class="th-icon-btn" title="Notifikasi">
                    <i class="bi bi-bell"></i>
                </button>
                <button class="th-icon-btn" title="Riwayat">
                    <i class="bi bi-clock-history"></i>
                </button>
                <div class="th-divider"></div>
                <div class="th-user-area">
                    <div class="th-user-info">
                        <div class="th-user-name">Admin Utama</div>
                        <div class="th-user-role">Administrator</div>
                    </div>
                    <img src="admin_avatar.png" alt="Admin" class="th-avatar"
                         onerror="this.outerHTML='<div class=&quot;th-avatar-fallback&quot;>A</div>'">
                </div>
            </div>
        </div>

        <main class="flex-grow-1 overflow-auto bg-app">
            <div class="history-content">

                <div class="h-breadcrumb">
                    <a href="index.php">Dashboard</a>
                    <span class="sep">›</span>
                    <span class="current">Riwayat Simulasi</span>
                </div>

                <div class="h-page-header">
                    <div>
                        <h1 class="h-page-title">Riwayat Simulasi (Daftar Sesi)</h1>
                        <p class="h-page-subtitle">Kelola dan tinjau semua rekaman aktivitas simulasi sistem untuk evaluasi performa antrean dan distribusi vaksinasi.</p>
                    </div>
                    <a href="reset_all.php" class="btn-reset-global"
                       onclick="return confirm('KOSONGKAN SEMUA DATA?\nAnda yakin ingin menghapus SELURUH riwayat dan mengulang sistem simulasi kembali dari Vaksinasi 1?');">
                        <i class="bi bi-arrow-clockwise"></i> Reset Sistem Global
                    </a>
                </div>

                <div class="bottom-cards" style="margin-top: 0; margin-bottom: 1.25rem;">

                    <div class="card-avg">
                        <div class="card-avg-label">Rata-rata Pasien</div>
                        <div class="card-avg-value"><?php echo number_format($avg_patients, 1); ?></div>
                        <div class="card-avg-trend">
                            <i class="bi bi-graph-up-arrow"></i>
                            <span>Meningkat 12% bulan ini</span>
                        </div>
                        <div class="card-avg-bg-icon"><i class="bi bi-bar-chart-fill"></i></div>
                    </div>

                    <div class="card-last-session">
                        <div class="card-last-session-top">
                            <div class="last-session-icon">
                                <i class="bi bi-stopwatch-fill"></i>
                            </div>
                            <div class="last-session-tag">Sesi Terakhir</div>
                        </div>
                        <div class="last-session-name">
                            <?php echo $last_session ? htmlspecialchars($last_session['name']) : 'Tidak ada sesi'; ?>
                        </div>
                        <div class="last-session-status">
                            <?php
                            if ($last_session && $last_session['patient_count'] === 0) {
                                echo 'Menunggu inisialisasi petugas lapangan...';
                            } elseif ($last_session) {
                                echo $last_session['patient_count'] . ' pasien tercatat';
                            } else {
                                echo '—';
                            }
                            ?>
                        </div>
                    </div>

                    <div class="card-log">
                        <div class="card-log-header">
                            <div class="log-icon-box">
                                <i class="bi bi-activity"></i>
                            </div>
                            <div class="card-log-title">Log Aktivitas</div>
                        </div>

                        <?php if ($last_analyzed): ?>
                        <div class="log-entry-row">
                            <div class="log-dot"></div>
                            <div class="log-text">
                                <span class="log-link"><?php echo htmlspecialchars($last_analyzed['name']); ?></span>
                                selesai dianalisis
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="log-entry-row">
                            <div class="log-dot log-dot-admin"></div>
                            <div class="log-text">
                                Sesi baru dibuat oleh <span class="log-admin">Admin</span>
                            </div>
                        </div>
                    </div>

                </div>
                <!-- End Bottom Cards -->

                <div class="history-card">

                    <form method="GET" action="history.php" class="m-0">
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                        <div class="h-toolbar justify-content-between">
                            <div class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 480px;">
                                <div class="position-relative w-100">
                                    <i class="bi bi-search position-absolute text-muted" style="left: 14px; top: 50%; transform: translateY(-50%); font-size: 0.85rem;"></i>
                                    <input type="text" name="search" class="form-control" placeholder="Cari ID Vaksinasi..." 
                                           value="<?php echo htmlspecialchars($search); ?>" 
                                           style="padding-left: 2.25rem; font-size: 0.8rem; border-radius: 10px; height: 38px; border: 1px solid #e2e8f0;">
                                </div>
                            </div>

                            <div class="h-toolbar-right d-flex align-items-center gap-3">
                                <div class="dropdown">
                                    <button class="h-btn-filter dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="bi bi-filter" style="font-size:0.95rem;"></i>
                                        Filter: <?php echo ($sort === 'latest' ? 'Terbaru' : 'Terlama'); ?>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item <?php echo $sort === 'latest' ? 'active-sort' : ''; ?>"
                                               href="?sort=latest&search=<?php echo urlencode($search); ?>">
                                                <i class="bi bi-sort-down me-1"></i> Terbaru
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item <?php echo $sort === 'oldest' ? 'active-sort' : ''; ?>"
                                               href="?sort=oldest&search=<?php echo urlencode($search); ?>">
                                                <i class="bi bi-sort-up me-1"></i> Terlama
                                            </a>
                                        </li>
                                    </ul>
                                </div>

                                <?php if ($sort !== 'latest' || $search !== ''): ?>
                                    <a href="history.php" class="h-btn-filter" style="border-color:#fecaca; color:#ef4444; text-decoration:none;">
                                        <i class="bi bi-x"></i> Reset
                                    </a>
                                <?php endif; ?>

                                <div class="h-status-badge">
                                    <span>Status:</span>
                                    <span class="h-status-dot"></span>
                                    <span class="h-status-text">Sistem Aktif</span>
                                </div>
                            </div>

                            <button type="submit" class="d-none"></button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="h-table">
                            <thead>
                                <tr>
                                    <th style="padding-left:1.4rem;">Vaksinasi-ID</th>
                                    <th>Tanggal &amp; Waktu Mulai</th>
                                    <th>Jumlah Pasien Tercatat</th>
                                    <th style="text-align:center; padding-right:1.4rem;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($paginated_sessions)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align:center; padding:3rem; color:#94a3b8; font-style:italic; font-size:0.85rem;">
                                            Tidak ditemukan data yang sesuai pencarian.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($paginated_sessions as $s): ?>
                                    <tr>
                                        <td style="padding-left:1.4rem;">
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="session-icon-box">
                                                    <i class="bi bi-bandaid-fill"></i>
                                                </div>
                                                <span class="session-name"><?php echo htmlspecialchars($s['name']); ?></span>
                                            </div>
                                        </td>

                                        <td>
                                            <?php
                                            if ($s['start_time']) {
                                                $months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'];
                                                $ts = (int)($s['start_time'] / 1000);
                                                $m = (int)date('n', $ts) - 1;
                                                $dateStr = date('d ', $ts) . $months[$m] . date(' Y', $ts);
                                                $timeStr = date('H:i', $ts) . ' WIB';
                                                echo '<div class="date-main">' . $dateStr . '</div>';
                                                echo '<div class="date-time">' . $timeStr . '</div>';
                                            } else {
                                                echo '<div class="date-main tba-text">TBA</div>';
                                                echo '<div class="tba-sub">Belum dijadwalkan</div>';
                                            }
                                            ?>
                                        </td>

                                        <td>
                                            <span class="patient-badge"><?php echo $s['patient_count']; ?> Pasien</span>
                                        </td>

                                        <td style="text-align:center; padding-right:1.4rem;">
                                            <div class="d-flex align-items-center justify-content-center gap-2">
                                                <a href="results.php?session=<?php echo urlencode($s['id']); ?>" class="btn-view-result">
                                                    <i class="bi bi-eye-fill"></i> Lihat Hasil Simulasi
                                                </a>
                                                <form method="POST" action="delete.php" class="m-0"
                                                      onsubmit="return confirm('HAPUS DATA?\nApakah Anda yakin ingin menghapus Riwayat Simulasi ini? Semua data pasien di sesi ini akan hangus dan tidak dapat dikembalikan.');">
                                                    <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($s['id']); ?>">
                                                    <button type="submit" class="btn-delete" title="Hapus Sesi">
                                                        <i class="bi bi-trash3-fill"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="h-pagination-bar">
                        <div class="h-pag-info">
                            <?php if ($total_items > 0): ?>
                                Menampilkan <strong><?php echo ($offset + 1); ?> - <?php echo min($offset + $limit, $total_items); ?></strong>
                                dari <strong><?php echo $total_items; ?></strong> sesi
                            <?php else: ?>
                                Tidak ada data untuk ditampilkan
                            <?php endif; ?>
                        </div>

                        <?php if ($total_pages > 1): ?>
                        <div class="h-pag-btns">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo ($page-1); ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>" class="h-pag-btn">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="h-pag-btn disabled"><i class="bi bi-chevron-left"></i></span>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>"
                                   class="h-pag-btn <?php echo ($i === $page) ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo ($page+1); ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort; ?>" class="h-pag-btn">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="h-pag-btn disabled"><i class="bi bi-chevron-right"></i></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </main>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
