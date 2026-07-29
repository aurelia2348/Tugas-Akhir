<?php
date_default_timezone_set('Asia/Jakarta');
$file = 'results.json';
$data = [];

if (file_exists($file)) {
    $fileData = file_get_contents($file);
    if (!empty($fileData)) {
        $raw = json_decode($fileData, true) ?: [];
        $targetSession = isset($_GET['session']) ? intval($_GET['session']) : 1;

        foreach ($raw as $u) {
            $sid = isset($u['session_id']) ? $u['session_id'] : 1;
            if ($sid == $targetSession) {
                $data[] = $u;
            }
        }

        // Sort user data ascending by ID
        usort($data, function ($a, $b) {
            return $a['id'] <=> $b['id'];
        });
    }
}

function formatDuration($ms)
{
    if ($ms == -1)
        return 'NR';
    if ($ms <= 0)
        return '00:00:00';
    $totalSeconds = round($ms / 1000);
    $hours = floor($totalSeconds / 3600);
    $minutes = floor(($totalSeconds % 3600) / 60);
    $seconds = $totalSeconds % 60;

    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

function calculateStageWait($history, $stageNum)
{
    foreach ($history as $h) {
        if ($h['stage'] == $stageNum && isset($h['masuk_queue']) && isset($h['masuk_stage'])) {
            return $h['masuk_stage'] - $h['masuk_queue'];
        }
    }
    return -1;
}

function calculateStageService($history, $stageNum)
{
    foreach ($history as $h) {
        if ($h['stage'] == $stageNum && isset($h['masuk_stage']) && isset($h['keluar_stage'])) {
            return $h['keluar_stage'] - $h['masuk_stage'];
        }
    }
    return -1;
}

function getStageEntryTime($user, $stageNum)
{
    foreach ($user['history'] as $h) {
        if ($h['stage'] == $stageNum && isset($h['masuk_stage'])) {
            return $h['masuk_stage'];
        }
    }
    return -1;
}

function calculateStageSpecificInterarrival($data, $currentIndex, $stageNum)
{
    $currEntry = getStageEntryTime($data[$currentIndex], $stageNum);
    if ($currEntry == -1)
        return -1;

    $nextEntry = -1;
    for ($j = $currentIndex + 1; $j < count($data); $j++) {
        $entry = getStageEntryTime($data[$j], $stageNum);
        if ($entry != -1) {
            $nextEntry = $entry;
            break;
        }
    }

    if ($nextEntry != -1) {
        return max(0, $nextEntry - $currEntry);
    }

    return 0;
}

function getArrivalTime($user)
{
    foreach ($user['history'] as $h) {
        if ($h['stage'] == 1 && isset($h['masuk_queue'])) {
            return $h['masuk_queue'];
        }
    }
    return 0;
}

function formatTimestamp($ms)
{
    if ($ms == 0)
        return '-';
    return date('H:i:s', (int) ($ms / 1000));
}

function getTotalTime($user)
{
    $firstQueue = 0;
    foreach ($user['history'] as $h) {
        if ($h['stage'] == 1 && isset($h['masuk_queue'])) {
            $firstQueue = $h['masuk_queue'];
            break;
        }
    }

    if ($firstQueue > 0 && isset($user['selesai'])) {
        return $user['selesai'] - $firstQueue;
    }
    return 0;
}

// Calculate Summary Metrics
$totalPatients = count($data);
$simMin = PHP_INT_MAX;
$simMax = 0;

foreach ($data as $user) {
    if (isset($user['history'])) {
        foreach ($user['history'] as $h) {
            if ($h['stage'] == 1 && isset($h['masuk_queue'])) {
                $simMin = min($simMin, $h['masuk_queue']);
            }
            if ($h['stage'] == 4 && isset($h['keluar_stage'])) {
                $simMax = max($simMax, $h['keluar_stage']);
            }
        }
    }
}
$simTimeStr = '00h 00m';
if ($simMax > $simMin && $simMin !== PHP_INT_MAX) {
    $diffSec = round(($simMax - $simMin) / 1000);
    $h = floor($diffSec / 3600);
    $m = floor(($diffSec % 3600) / 60);
    $simTimeStr = sprintf('%02dh %02dm', $h, $m);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report - Queue Simulation</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="style.css" rel="stylesheet">
     <style>
        /* ===== PAGE CONTENT ===== */
        .history-content {
            padding: 1.75rem 2rem;
        }

        /* Breadcrumb */
        .h-breadcrumb { font-size: 0.78rem; margin-bottom: 0.85rem; }
        .h-breadcrumb a { color: #6366f1; text-decoration: none; font-weight: 500; }
        .h-breadcrumb a:hover { text-decoration: underline; }
        .h-breadcrumb .sep { margin: 0 0.4rem; color: #94a3b8; }
        .h-breadcrumb .current { color: #6366f1; font-weight: 600; }

        /* Summary Cards (history-style) */
        .sum-card-avg {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            border-radius: 20px; padding: 1.5rem; position: relative; overflow: hidden;
            color: white; box-shadow: 0 8px 24px rgba(99,102,241,0.35);
            cursor: pointer; transition: transform 0.25s cubic-bezier(0.4,0,0.2,1), box-shadow 0.25s;
            border: 1px solid transparent; height: 100%;
        }
        .sum-card-avg::before { content: ''; position: absolute; right: -18px; bottom: -22px; width: 100px; height: 100px; border-radius: 50%; background: rgba(255,255,255,0.08); }
        .sum-card-avg::after { content: ''; position: absolute; right: 28px; bottom: 10px; width: 60px; height: 60px; border-radius: 50%; background: rgba(255,255,255,0.06); }
        .sum-card-avg:hover { transform: translateY(-5px); box-shadow: 0 16px 36px rgba(99,102,241,0.45); }
        .sum-card-avg:active { transform: translateY(-2px) scale(0.98); }
        .sum-card-avg .bg-icon { position: absolute; right: 1.1rem; bottom: 0.6rem; font-size: 3.6rem; color: rgba(255,255,255,0.13); line-height: 1; pointer-events: none; }
        .sum-card-avg .sc-label { font-size: 0.68rem; font-weight: 700; letter-spacing: 1.5px; opacity: 0.85; margin-bottom: 0.5rem; text-transform: uppercase; }
        .sum-card-avg .sc-value { font-size: 2.6rem; font-weight: 800; line-height: 1; margin-bottom: 0.5rem; letter-spacing: -1.5px; }
        .sum-card-avg .sc-trend { font-size: 0.7rem; opacity: 0.85; display: flex; align-items: center; gap: 0.3rem; }

        .sum-card-white {
            background: white; border-radius: 20px; padding: 1.5rem;
            border: 1px solid #f1f5f9; box-shadow: 0 4px 20px rgba(0,0,0,0.05); overflow: hidden;
            cursor: pointer; transition: transform 0.25s cubic-bezier(0.4,0,0.2,1), box-shadow 0.25s, border-color 0.25s;
            height: 100%;
        }
        .sum-card-white:hover { transform: translateY(-5px); box-shadow: 0 14px 32px rgba(59,130,246,0.13); border-color: #bfdbfe; }
        .sum-card-white:active { transform: translateY(-2px) scale(0.98); }
        .sum-card-white .sc-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.9rem; }
        .sum-card-white .sc-icon {
            width: 44px; height: 44px; border-radius: 13px;
            background: linear-gradient(135deg, #fde68a, #f59e0b);
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 1.15rem; box-shadow: 0 4px 14px rgba(245,158,11,0.32);
        }
        .sum-card-white .sc-tag { font-size: 0.62rem; font-weight: 700; letter-spacing: 0.9px; color: #94a3b8; text-transform: uppercase; }
        .sum-card-white .sc-value { font-size: 2.1rem; font-weight: 800; color: #0f172a; margin-bottom: 0.2rem; letter-spacing: -1px; line-height: 1; }
        .sum-card-white .sc-sub { font-size: 0.72rem; color: #94a3b8; font-style: italic; }

        /* Page Header */
        .h-page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
        .h-page-title { font-size: 1.65rem; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; margin-bottom: 0.35rem; }
        .h-page-subtitle { color: #6366f1; font-size: 0.83rem; font-weight: 500; max-width: 600px; line-height: 1.5; }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background-color: var(--bg-app);
            color: var(--text-main);
        }

        .table-custom th {
            color: white;
            font-weight: 700;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            border-color: rgba(255, 255, 255, 0.05);
        }

        .table-custom td {
            vertical-align: middle;
            color: var(--text-main);
            font-size: 0.88rem;
            padding: 1rem 0.75rem;
            border-color: var(--border-color);
        }

        .report-card {
            border-radius: 20px;
            background: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border: 1px solid transparent;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 20px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.03);
        }

        /* Shine effect on hover */
        .report-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: -150%;
            width: 60%;
            height: 100%;
            background: linear-gradient(to right,
                    rgba(255, 255, 255, 0) 0%,
                    rgba(255, 255, 255, 0.3) 30%,
                    rgba(255, 255, 255, 0.6) 50%,
                    rgba(255, 255, 255, 0.3) 70%,
                    rgba(255, 255, 255, 0) 100%);
            transform: skewX(-25deg);
            transition: none;
            pointer-events: none;
        }

        .report-card:hover::after {
            left: 150%;
            transition: left 1.5s cubic-bezier(0.3, 1, 0.3, 1);
        }

        /* Green card hover border and glow */
        .card-green-glow:hover {
            border-color: #10b981 !important;
            box-shadow: 0 12px 24px -5px rgba(16, 185, 129, 0.18), 0 4px 12px -2px rgba(16, 185, 129, 0.12) !important;
        }

        /* Blue card hover border and glow */
        .card-blue-glow:hover {
            border-color: #3b82f6 !important;
            box-shadow: 0 12px 24px -5px rgba(59, 130, 246, 0.18), 0 4px 12px -2px rgba(59, 130, 246, 0.12) !important;
        }

        /* Dark card for inputs */
        .report-card-dark {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%) !important;
            border: 1px solid #334155 !important;
            color: #f8fafc;
        }

        .report-card-dark:hover {
            border-color: #3b82f6 !important;
            box-shadow: 0 12px 24px -5px rgba(59, 130, 246, 0.25), 0 4px 12px -2px rgba(59, 130, 246, 0.15) !important;
        }

        .report-card-dark h6 {
            color: #ffffff !important;
        }

        .report-card-dark h6 i {
            color: #3b82f6 !important;
        }

        .report-card-dark label,
        .report-card-dark .form-label {
            color: #94a3b8 !important;
        }

        .report-card-dark input {
            background-color: #1e293b !important;
            border: 1px solid #334155 !important;
            color: #ffffff !important;
            transition: all 0.2s ease;
        }

        .report-card-dark input:focus {
            background-color: #0f172a !important;
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.4) !important;
        }

        .report-card-dark .text-secondary {
            color: #94a3b8 !important;
        }

        .text-purple {
            color: var(--primary) !important;
        }

        .text-warning-red {
            color: var(--danger) !important;
        }

        .form-control {
            border-radius: 12px;
            border: 1px solid var(--border-color);
            padding: 0.75rem 1rem;
            transition: var(--transition-smooth);
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.15);
        }

        /* Custom Header Styling untuk Raw Execution Data */
        .th-neutral {
            background-color: #0f172a !important;
            border-top: 4px solid #64748b !important;
            color: #f8fafc !important;
        }
        .th-interarrival {
            background-color: #0c1a19 !important;
            border-top: 4px solid #0d9488 !important;
            color: #2dd4bf !important;
        }
        .th-waiting {
            background-color: #101026 !important;
            border-top: 4px solid #6366f1 !important;
            color: #a5b4fc !important;
        }
        .th-service {
            background-color: #0b1426 !important;
            border-top: 4px solid #3b82f6 !important;
            color: #93c5fd !important;
        }

        /* Sub-headers styling */
        .th-sub-inter {
            background-color: #122624 !important;
            color: #99f6e4 !important;
            border-top: 1px solid #14b8a6 !important;
            font-size: 0.7rem;
        }
        .th-sub-waiting {
            background-color: #161633 !important;
            color: #c7d2fe !important;
            border-top: 1px solid #6366f1 !important;
            font-size: 0.7rem;
        }
        .th-sub-waiting-total {
            background-color: #1e1b4b !important;
            color: #e0e7ff !important;
            border-top: 1px solid #818cf8 !important;
            font-size: 0.7rem;
            font-weight: 700;
        }
        .th-sub-service {
            background-color: #101d36 !important;
            color: #bfdbfe !important;
            border-top: 1px solid #3b82f6 !important;
            font-size: 0.7rem;
        }
        .th-sub-service-total {
            background-color: #172554 !important;
            color: #dbeafe !important;
            border-top: 1px solid #60a5fa !important;
            font-size: 0.7rem;
            font-weight: 700;
        }
    </style>
</head>

<body class="bg-app">

<div class="d-flex vw-100 vh-100 overflow-hidden">

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar bg-white border-end d-flex flex-column flex-shrink-0">
        <!-- Brand -->
        <div class="p-4 d-flex align-items-center gap-3">
            <div class="brand-icon text-white rounded-3 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                <i class="bi bi-bar-chart-fill" style="font-size: 1.1rem;"></i>
            </div>
            <h5 class="fw-bold mb-0 text-dark" style="line-height: 1.2; letter-spacing: -0.5px;">QueueFlow<span class="text-primary">.</span><br><span class="fs-6 fw-semibold text-secondary">Pro Simulation</span></h5>
        </div>

        <!-- Navigation -->
        <nav class="nav flex-column gap-2 px-3 fw-semibold mt-2">
            <a href="index.php" class="nav-link text-secondary rounded-1 d-flex align-items-center gap-3 py-3 px-3">
                <i class="bi bi-grid-1x2-fill"></i> DASHBOARD
            </a>
            <a href="history.php" class="nav-link active rounded-1 d-flex align-items-center gap-3 py-3 px-3">
                <i class="bi bi-clock-history"></i> HISTORY
            </a>
            <a href="analysis.php" class="nav-link text-secondary rounded-1 d-flex align-items-center gap-3 py-3 px-3">
                <i class="bi bi-bar-chart-line-fill"></i> ANALYSIS
            </a>
        </nav>

        <!-- Bottom Nav -->
        <div class="mt-auto border-top mx-3 mb-3 pt-3">
            <a href="index.php" class="btn btn-primary w-100 fw-bold d-flex align-items-center justify-content-center gap-2 mb-4 py-2">
                <i class="bi bi-plus-lg"></i> New Simulation
            </a>
            <a href="#" class="nav-link text-secondary rounded-1 d-flex align-items-center gap-3 px-3 py-2 fw-semibold">
                <i class="bi bi-gear-fill"></i> SETTINGS
            </a>
        </div>
    </aside>

    <!-- ===== RIGHT SIDE: Top Nav + Main Content ===== -->
    <div class="d-flex flex-column flex-grow-1 overflow-hidden">

        <!-- TOP NAVIGATION BAR -->
        <div class="top-header-bar">
            <!-- Page Title -->
            <div class="th-page-label">Hasil Simulasi - Detail Rekaman Sesi Vaksinasi #<?php echo isset($targetSession) ? $targetSession : 1; ?></div>

            <!-- Actions -->
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

        <!-- MAIN SCROLLABLE CONTENT -->
        <main class="flex-grow-1 overflow-auto bg-app">
            <div class="history-content">

                <!-- Breadcrumb -->
                <div class="h-breadcrumb">
                    <a href="index.php">Dashboard</a>
                    <span class="sep">›</span>
                    <a href="history.php">Riwayat Simulasi</a>
                    <span class="sep">›</span>
                    <span class="current">Hasil Simulasi (Vaksinasi #<?php echo isset($targetSession) ? $targetSession : 1; ?>)</span>
                </div>

                <!-- Page Header -->
                <div class="h-page-header">
                    <div>
                        <h1 class="h-page-title">Hasil Simulasi <span class="text-primary">(Vaksinasi #<?php echo isset($targetSession) ? $targetSession : 1; ?>)</span></h1>
                        <p class="h-page-subtitle">Detailed breakdown of patient queue dynamics across multiple service stages. Optimized for bottleneck identification and throughput analysis.</p>
                    </div>
                    <a href="history.php" class="btn btn-light shadow-sm text-primary fw-bold px-4 py-2 d-flex align-items-center gap-2" style="border-radius: 10px; border: 1px solid var(--border-color); font-size: 0.82rem; text-decoration: none; align-self: flex-start; height: 38px;">
                        <i class="bi bi-arrow-left"></i> Kembali ke Riwayat
                    </a>
                </div>

        <!-- Summary Cards -->
        <div class="row g-4 mb-5">
            <!-- Card 1: Total Patients (gradient) -->
            <div class="col-md-3">
                <div class="sum-card-avg">
                    <div class="sc-label">Total Patients</div>
                    <div class="sc-value"><?php echo $totalPatients; ?></div>
                    <div class="sc-trend">
                        <i class="bi bi-people-fill"></i>
                        <span>Pasien tercatat dalam sesi ini</span>
                    </div>
                    <div class="bg-icon"><i class="bi bi-person-lines-fill"></i></div>
                </div>
            </div>
            <!-- Card 2: Simulation Time (white card) -->
            <div class="col-md-3">
                <div class="sum-card-white">
                    <div class="sc-top">
                        <div class="sc-icon">
                            <i class="bi bi-stopwatch-fill"></i>
                        </div>
                        <div class="sc-tag">Durasi Simulasi</div>
                    </div>
                    <div class="sc-value"><?php echo $simTimeStr; ?></div>
                    <div class="sc-sub">Total waktu proses dari awal hingga akhir</div>
                </div>
            </div>
        </div>

        <!-- Data Table Card -->
        <div class="report-card overflow-hidden">
            <div class="p-4 d-flex justify-content-between align-items-center border-bottom border-light">
                <h5 class="fw-bold mb-0 text-dark">Raw Execution Data</h5>
                <div class="d-flex gap-3">
                    <button class="btn btn-link text-secondary p-0"><i class="bi bi-filter text-dark fs-5"></i></button>
                    <button id="btnDownloadPdf" class="btn btn-link text-secondary p-0" title="Download PDF Hitam Putih"><i
                            class="bi bi-download text-dark fs-5"></i></button>
                </div>
            </div>

            <div class="table-responsive">
                <table id="rawDataTable" class="table table-custom mb-0 text-center">
                    <thead>
                        <tr>
                            <th rowspan="2" class="align-middle text-nowrap th-neutral">ID PASIEN</th>
                            <th rowspan="2" class="align-middle text-nowrap th-neutral">WAKTU KEDATANGAN</th>
                            <th rowspan="2" class="align-middle text-nowrap th-neutral">INTERARRIVAL TIME</th>
                            <th colspan="4" class="py-3 text-nowrap th-interarrival">INTERARRIVAL TIME (MASUK STAGE)</th>
                            <th colspan="5" class="py-3 text-nowrap th-waiting">WAITING TIME (MASUK STAGE - MASUK QUEUE)</th>
                            <th colspan="5" class="py-3 text-nowrap th-service">SERVICE TIME</th>
                            <th rowspan="2" class="align-middle text-nowrap th-neutral">TOTAL TIME</th>
                        </tr>
                        <tr>
                            <th class="py-2 px-1 text-nowrap th-sub-inter">STAGE 1</th>
                            <th class="py-2 px-1 text-nowrap th-sub-inter">STAGE 2</th>
                            <th class="py-2 px-1 text-nowrap th-sub-inter">STAGE 3</th>
                            <th class="py-2 px-1 text-nowrap th-sub-inter">STAGE 4</th>

                            <th class="py-2 px-1 text-nowrap th-sub-waiting">STAGE 1</th>
                            <th class="py-2 px-1 text-nowrap th-sub-waiting">STAGE 2</th>
                            <th class="py-2 px-1 text-nowrap th-sub-waiting">STAGE 3</th>
                            <th class="py-2 px-1 text-nowrap th-sub-waiting">STAGE 4</th>
                            <th class="py-2 px-1 text-nowrap th-sub-waiting-total">TOTAL WQ</th>

                            <th class="py-2 px-1 text-nowrap th-sub-service">STAGE 1</th>
                            <th class="py-2 px-1 text-nowrap th-sub-service">STAGE 2</th>
                            <th class="py-2 px-1 text-nowrap th-sub-service">STAGE 3</th>
                            <th class="py-2 px-1 text-nowrap th-sub-service">STAGE 4</th>
                            <th class="py-2 px-1 text-nowrap th-sub-service-total">TOTAL SRV</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white">
                        <?php
                        $sWait = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 'total' => 0];
                        $cWait = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 'total' => 0];
                        $sSrv = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 'total' => 0];
                        $cSrv = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 'total' => 0];
                        $sInter = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
                        $cInter = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
                        $sTot = 0;
                        $cTot = 0;
                        ?>
                        <?php if (empty($data)): ?>
                            <tr>
                                <td colspan="19" class="text-muted py-5 fst-italic">Belum ada rekaman eksekusi simulasi.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($data as $index => $user): ?>
                                <tr>
                                    <td class="fw-bold text-primary px-3 align-middle">
                                        PA-<?php echo sprintf('%03d', $user['id']); ?>
                                    </td>

                                    <td class="align-middle text-secondary">
                                        <?php echo formatTimestamp(getArrivalTime($user)); ?>
                                    </td>

                                    <td class="align-middle fw-bold text-dark">
                                        <?php
                                        $nextUser = isset($data[$index + 1]) ? $data[$index + 1] : null;
                                        $currArr = getArrivalTime($user);
                                        $nextArr = $nextUser ? getArrivalTime($nextUser) : 0;

                                        $interarrival = 0;
                                        if ($nextArr > 0 && $currArr > 0) {
                                            $interarrival = $nextArr - $currArr;
                                        }
                                        echo formatDuration($interarrival);
                                        ?>
                                    </td>

                                    <!-- Interarrival Times (Per Stage) -->
                                    <?php for ($i = 1; $i <= 4; $i++): ?>
                                        <td class="align-middle text-secondary">
                                            <?php
                                            $val = calculateStageSpecificInterarrival($data, $index, $i);
                                            if ($val >= 0) {
                                                $sInter[$i] += $val;
                                                $cInter[$i]++;
                                            }
                                            echo formatDuration($val);
                                            ?>
                                        </td>
                                    <?php endfor; ?>

                                    <!-- Waiting Times -->
                                    <?php $totalWait = 0; ?>
                                    <?php for ($i = 1; $i <= 4; $i++): ?>
                                        <?php
                                        $wait = calculateStageWait($user['history'], $i);
                                        if ($wait != -1) {
                                            $totalWait += $wait;
                                            if ($wait >= 0) {
                                                $sWait[$i] += $wait;
                                                $cWait[$i]++;
                                            }
                                        }
                                        ?>
                                        <td
                                            class="align-middle <?php echo ($wait > 4000) ? 'text-warning-red fw-bold' : 'text-secondary'; ?>">
                                            <?php echo formatDuration($wait); ?>
                                        </td>
                                    <?php endfor; ?>

                                    <?php if ($totalWait >= 0) {
                                        $sWait['total'] += $totalWait;
                                        $cWait['total']++;
                                    } ?>
                                    <td class="align-middle fw-bold text-purple" style="background-color: #faf5ff;">
                                        <?php echo formatDuration($totalWait); ?>
                                    </td>

                                    <!-- Service Times -->
                                    <?php $totalService = 0; ?>
                                    <?php for ($i = 1; $i <= 4; $i++): ?>
                                        <?php
                                        $service = calculateStageService($user['history'], $i);
                                        if ($service != -1) {
                                            $totalService += $service;
                                            if ($service >= 0) {
                                                $sSrv[$i] += $service;
                                                $cSrv[$i]++;
                                            }
                                        }
                                        ?>
                                        <td class="align-middle text-secondary">
                                            <?php echo formatDuration($service); ?>
                                        </td>
                                    <?php endfor; ?>

                                    <?php if ($totalService >= 0) {
                                        $sSrv['total'] += $totalService;
                                        $cSrv['total']++;
                                    } ?>
                                    <td class="align-middle fw-bold text-primary" style="background-color: #eff6ff;">
                                        <?php echo formatDuration($totalService); ?>
                                    </td>

                                    <td class="align-middle fw-bold text-dark">
                                        <?php
                                        $tSys = getTotalTime($user);
                                        if ($tSys >= 0) {
                                            $sTot += $tSys;
                                            $cTot++;
                                        }
                                        echo formatDuration($tSys);
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <!-- RATA - RATA -->
                            <tr style="background-color: #f1f5f9; border-top: 2px solid #e2e8f0;">
                                <td colspan="3" class="fw-bold text-end pe-4 align-middle text-dark">Average Values:</td>

                                <!-- Inter Times -->
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <td class="align-middle fw-bold text-dark">
                                        <?php echo $cInter[$i] > 0 ? formatDuration($sInter[$i] / $cInter[$i]) : '-'; ?>
                                    </td>
                                <?php endfor; ?>

                                <!-- Wait Times -->
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <td class="align-middle fw-bold text-dark">
                                        <?php echo $cWait[$i] > 0 ? formatDuration($sWait[$i] / $cWait[$i]) : '-'; ?>
                                    </td>
                                <?php endfor; ?>
                                <td class="align-middle fw-bold text-purple" style="background-color: #faf5ff;">
                                    <?php echo $cWait['total'] > 0 ? formatDuration($sWait['total'] / $cWait['total']) : '-'; ?>
                                </td>

                                <!-- Service Times -->
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <td class="align-middle fw-bold text-dark">
                                        <?php echo $cSrv[$i] > 0 ? formatDuration($sSrv[$i] / $cSrv[$i]) : '-'; ?>
                                    </td>
                                <?php endfor; ?>
                                <td class="align-middle fw-bold text-primary" style="background-color: #eff6ff;">
                                    <?php echo $cSrv['total'] > 0 ? formatDuration($sSrv['total'] / $cSrv['total']) : '-'; ?>
                                </td>

                                <!-- Total System Time -->
                                <td class="align-middle fw-bold text-dark">
                                    <?php echo $cTot > 0 ? formatDuration($sTot / $cTot) : '-'; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ACTUAL QUEUE PARAMETERS TABLE -->
        <div class="report-card p-4 p-md-5 mt-5" style="border: 1px solid #f1f5f9;">
            <h6 class="text-dark fw-bold text-uppercase mb-4 d-flex align-items-center gap-2"
                style="font-size: 0.85rem; letter-spacing: 1.5px;">
                <i class="bi bi-clock-history text-success p-1 bg-success bg-opacity-10 rounded"></i> ACTUAL QUEUE PARAMETERS PER STAGE
            </h6>
            <div class="table-responsive">
                <table class="table text-center align-middle mb-0" style="font-size: 0.8rem;">
                    <thead>
                        <tr class="text-secondary fw-bold text-uppercase"
                            style="font-size: 0.65rem; border-bottom: 2px solid #e2e8f0 !important; color: #475569 !important;">
                            <th class="border-0 pb-3 text-nowrap">STAGE</th>
                            <th class="border-0 pb-3 text-nowrap">λi<br><small class="text-muted fw-normal text-capitalize">(Mean Arrival)</small></th>
                            <th class="border-0 pb-3 text-nowrap">σAi<br><small class="text-muted fw-normal text-capitalize">(Std. Dev)</small></th>
                            <th class="border-0 pb-3 text-nowrap">CAi<br><small class="text-muted fw-normal text-capitalize">(CoV)</small></th>
                            <th class="border-0 pb-3 text-nowrap">Si<br><small class="text-muted fw-normal text-capitalize">(Mean Service)</small></th>
                            <th class="border-0 pb-3 text-nowrap">σSi<br><small class="text-muted fw-normal text-capitalize">(Std. Dev)</small></th>
                            <th class="border-0 pb-3 text-nowrap">CSi<br><small class="text-muted fw-normal text-capitalize">(CoV)</small></th>
                            <th class="border-0 pb-3 text-nowrap">Λi<br><small class="text-muted fw-normal text-capitalize">(Arrival Rate)</small></th>
                            <th class="border-0 pb-3 text-nowrap">ρi<br><small class="text-muted fw-normal text-capitalize">(Utilization Factor)</small></th>
                            <th class="border-0 pb-3 text-nowrap">μi<br><small class="text-muted fw-normal text-capitalize">(Service Rate)</small></th>
                        </tr>
                    </thead>
                    <tbody id="actualParamsBody" class="border-top-0">
                        <tr>
                            <td colspan="10" class="py-4 text-muted fst-italic">Loading actual data analysis...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>


        <!-- Desktop DES Section -->
        <div class="mt-5 border-top border-light pt-5 pb-5">

            <!-- DES Header -->
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h2 class="fw-bold mb-3 d-flex align-items-center flex-wrap gap-3"
                        style="font-size: 2rem; letter-spacing: -0.5px; color: #0f172a;">
                        Discrete Event Simulation (DES)
                        <span class="badge"
                            style="background-color: #e0e7ff; color: #3730a3; font-size: 0.75rem; letter-spacing: 1px; padding: 0.6rem 1rem; border-radius: 6px; border: 1px solid #c7d2fe;">PREDICTIVE
                            ANALYSIS</span>
                    </h2>
                    <p class="text-secondary mb-0" style="max-width: 650px; font-size: 0.95rem; line-height: 1.6;">
                        High-fidelity computational modeling for complex queue systems. Adjust parameters below to
                        calculate architectural system efficiency.
                    </p>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <!-- Left Column: SYSTEM INPUTS -->
                <div class="col-lg-4 d-flex flex-column">
                    <div class="report-card report-card-dark p-4 h-100 d-flex flex-column">
                        <h6 class="fw-bold text-dark mb-4 d-flex align-items-center gap-2"
                            style="font-size: 0.85rem; letter-spacing: 1px;">
                            <i class="bi bi-sliders text-primary"></i> SYSTEM INPUTS
                        </h6>

                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <label class="form-label text-secondary fw-bold text-uppercase"
                                    style="font-size: 0.65rem; letter-spacing: 1px;">REPS (REPLIKASI)</label>
                                <input type="number" id="desReps" class="form-control border-0 shadow-sm" value="100"
                                    style="padding: 0.75rem;">
                            </div>
                            <div class="col-6">
                                <label class="form-label text-secondary fw-bold text-uppercase"
                                    style="font-size: 0.65rem; letter-spacing: 1px;">WARMUP (ABAIKAN)</label>
                                <input type="number" id="desWarmup" class="form-control border-0 shadow-sm" value="10"
                                    style="padding: 0.75rem;">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-secondary fw-bold text-uppercase"
                                style="font-size: 0.65rem; letter-spacing: 1px;">OBS (DIOBSERVASI)</label>
                            <input type="number" id="desObs" class="form-control border-0 shadow-sm" value="30"
                                style="padding: 0.75rem;">
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-secondary fw-bold text-uppercase"
                                style="font-size: 0.65rem; letter-spacing: 1px;">BATAS KAPASITAS PER JAM</label>
                            <input type="number" id="desQuota" class="form-control border-0 shadow-sm" value="0"
                                style="padding: 0.75rem;" placeholder="0 = Unlimited">
                        </div>

                        <div class="mb-2">
                            <label class="form-label text-secondary fw-bold text-uppercase"
                                style="font-size: 0.65rem; letter-spacing: 1px;">SERVER ALLOCATION PER STAGE</label>
                            <div class="d-flex gap-2">
                                <div class="text-center w-100">
                                    <div class="text-secondary fw-bold"
                                        style="font-size: 0.55rem; letter-spacing: 1px; margin-bottom: 4px;">ST-1</div>
                                    <input type="number" id="srv1"
                                        class="form-control border-0 shadow-sm text-center px-1" value="1" min="1">
                                </div>
                                <div class="text-center w-100">
                                    <div class="text-secondary fw-bold"
                                        style="font-size: 0.55rem; letter-spacing: 1px; margin-bottom: 4px;">ST-2</div>
                                    <input type="number" id="srv2"
                                        class="form-control border-0 shadow-sm text-center px-1" value="1" min="1">
                                </div>
                                <div class="text-center w-100">
                                    <div class="text-secondary fw-bold"
                                        style="font-size: 0.55rem; letter-spacing: 1px; margin-bottom: 4px;">ST-3</div>
                                    <input type="number" id="srv3"
                                        class="form-control border-0 shadow-sm text-center px-1" value="2" min="1">
                                </div>
                                <div class="text-center w-100">
                                    <div class="text-secondary fw-bold"
                                        style="font-size: 0.55rem; letter-spacing: 1px; margin-bottom: 4px;">ST-4</div>
                                    <input type="number" id="srv4"
                                        class="form-control border-0 shadow-sm text-center px-1" value="1" min="1">
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="mt-auto pt-3 border-top border-secondary border-opacity-25 d-flex flex-column gap-2">
                            <button id="btnStartDes" class="btn btn-primary fw-bold text-white shadow-sm d-flex align-items-center justify-content-center gap-2"
                                style="border-radius: 12px; padding: 0.75rem 1.5rem; font-size: 0.85rem; letter-spacing: 0.5px;">
                                <i class="bi bi-play-fill fs-5"></i> START SIMULATION
                            </button>
                            <button id="btnResetDes" class="btn fw-semibold"
                                style="border-radius: 12px; padding: 0.75rem 1.5rem; font-size: 0.85rem; letter-spacing: 0.5px; border: 1px solid #334155; color: #94a3b8; background-color: transparent;">RESET</button>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Kondisi Awal + DES Results -->
                <div class="col-lg-8 position-relative d-flex flex-column">

                    <!-- OVERALL SYSTEM METRICS label -->
                    <div class="fw-bold text-secondary text-uppercase mb-3" style="font-size:0.7rem; letter-spacing:1.5px;">OVERALL SYSTEM METRICS</div>

                    <!-- 2 cards: Kondisi Awal (kiri) + DES Result (kanan) -->
                    <div class="row g-3 mb-3 flex-grow-1">
                        <!-- ===== KONDISI AWAL: Overall Metrics ===== -->
                        <div class="col-md-6">
                            <div class="report-card card-green-glow p-4 h-100" style="border: 1px solid #d1fae5; background: linear-gradient(135deg,#f0fdf4 0%,#ffffff 100%);">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="badge fw-bold" style="background:#d1fae5;color:#065f46;font-size:0.58rem;letter-spacing:1px;">KONDISI AWAL</span>
                                    <span class="text-muted" style="font-size:0.62rem;">Data aktual (sebelum input)</span>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="text-secondary fw-bold text-uppercase mb-1" style="font-size:0.55rem;letter-spacing:0.5px;">WQ (QUEUE TIME)</div>
                                        <div class="fw-bold" style="font-size:1.5rem;color:#059669;line-height:1;"><span id="initWq">—</span></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-secondary fw-bold text-uppercase mb-1" style="font-size:0.55rem;letter-spacing:0.5px;">W (SYSTEM TIME)</div>
                                        <div class="fw-bold text-dark" style="font-size:1.5rem;line-height:1;"><span id="initW">—</span></div>
                                    </div>
                                    <div class="col-6 mt-2">
                                        <div class="text-secondary fw-bold text-uppercase mb-1" style="font-size:0.55rem;letter-spacing:0.5px;">LQ (AVG QUEUE)</div>
                                        <div class="fw-bold" style="font-size:1.5rem;color:#059669;line-height:1;"><span id="initLq">—</span></div>
                                    </div>
                                    <div class="col-6 mt-2">
                                        <div class="text-secondary fw-bold text-uppercase mb-1" style="font-size:0.55rem;letter-spacing:0.5px;">L (AVG SYSTEM)</div>
                                        <div class="fw-bold text-dark" style="font-size:1.5rem;line-height:1;"><span id="initL">—</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ===== HASIL DES: Overall Metrics (EXISTING — tidak diubah) ===== -->
                        <div class="col-md-6">
                            <div class="report-card card-blue-glow p-4 h-100" style="border: 1px solid #dbeafe; background: linear-gradient(135deg,#eff6ff 0%,#ffffff 100%);">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="badge fw-bold" style="background:#dbeafe;color:#1e40af;font-size:0.58rem;letter-spacing:1px;">HASIL DES</span>
                                    <span class="text-muted" style="font-size:0.62rem;">Berubah sesuai input</span>
                                </div>
                                <!-- existing sysWq/W/Lq/L spans — tidak diubah sama sekali -->
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="text-secondary fw-bold text-uppercase mb-1" style="font-size:0.55rem;letter-spacing:0.5px;">WQ (QUEUE TIME)</div>
                                        <div class="fw-bold" style="font-size:1.5rem;color:#2563eb;line-height:1;"><span id="sysWq">—</span></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-secondary fw-bold text-uppercase mb-1" style="font-size:0.55rem;letter-spacing:0.5px;">W (SYSTEM TIME)</div>
                                        <div class="fw-bold text-dark" style="font-size:1.5rem;line-height:1;"><span id="sysW">—</span></div>
                                    </div>
                                    <div class="col-6 mt-2">
                                        <div class="text-secondary fw-bold text-uppercase mb-1" style="font-size:0.55rem;letter-spacing:0.5px;">LQ (AVG QUEUE)</div>
                                        <div class="fw-bold" style="font-size:1.5rem;color:#3b82f6;line-height:1;"><span id="sysLq">—</span></div>
                                    </div>
                                    <div class="col-6 mt-2">
                                        <div class="text-secondary fw-bold text-uppercase mb-1" style="font-size:0.55rem;letter-spacing:0.5px;">L (AVG SYSTEM)</div>
                                        <div class="fw-bold text-dark" style="font-size:1.5rem;line-height:1;"><span id="sysL">—</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PER STAGE PERFORMANCE label -->
                    <div class="fw-bold text-secondary text-uppercase mb-3" style="font-size:0.7rem; letter-spacing:1.5px;">PER STAGE PERFORMANCE</div>

                    <!-- 2 tables: Kondisi Awal (kiri) + DES Result (kanan) -->
                    <div class="row g-3 mb-3 flex-grow-1">
                        <!-- ===== KONDISI AWAL: Per Stage ===== -->
                        <div class="col-md-6">
                            <div class="report-card card-green-glow p-4 h-100" style="border: 1px solid #d1fae5; background: linear-gradient(135deg,#f0fdf4 0%,#ffffff 100%);">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="badge fw-bold" style="background:#d1fae5;color:#065f46;font-size:0.58rem;letter-spacing:1px;">KONDISI AWAL</span>
                                    <span class="text-muted" style="font-size:0.62rem;">Data aktual (sebelum input)</span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table mb-0 align-middle text-center" style="font-size:0.82rem;">
                                        <thead>
                                            <tr class="text-uppercase fw-bold" style="font-size:0.6rem;letter-spacing:0.5px;border-bottom:2px solid #d1fae5;">
                                                <th class="border-0 pb-2" style="color:#064e3b;">STAGE ID</th>
                                                <th class="border-0 pb-2" style="color:#064e3b;">AVG WAITING<br>TIME (MIN)</th>
                                                <th class="border-0 pb-2" style="color:#064e3b;">AVG SERVICE<br>TIME (MIN)</th>
                                            </tr>
                                        </thead>
                                        <tbody id="initStageBody">
                                            <tr>
                                                <td colspan="3" class="text-muted fst-italic py-3 text-center small">Memuat...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ===== HASIL DES: Per Stage (EXISTING t1Body — tidak diubah) ===== -->
                        <div class="col-md-6">
                            <div class="report-card card-blue-glow p-4 h-100" style="border: 1px solid #dbeafe; background: linear-gradient(135deg,#eff6ff 0%,#ffffff 100%);">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="badge fw-bold" style="background:#dbeafe;color:#1e40af;font-size:0.58rem;letter-spacing:1px;">HASIL DES</span>
                                    <span class="text-muted" style="font-size:0.62rem;">Berubah sesuai input</span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table mb-0 align-middle text-center" style="font-size:0.82rem;">
                                        <thead>
                                            <tr class="text-uppercase fw-bold" style="font-size:0.6rem;letter-spacing:0.5px;border-bottom:2px solid #dbeafe;">
                                                <th class="border-0 pb-2" style="color:#1e3a8a;">STAGE ID</th>
                                                <th class="border-0 pb-2" style="color:#1e3a8a;">AVG WAITING<br>TIME (MIN)</th>
                                                <th class="border-0 pb-2" style="color:#1e3a8a;">AVG SERVICE<br>TIME (MIN)</th>
                                            </tr>
                                        </thead>
                                        <tbody id="t1Body">
                                            <tr>
                                                <td class="fw-bold">Tahap 1</td>
                                                <td>—</td>
                                                <td>—</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Tahap 2</td>
                                                <td>—</td>
                                                <td>—</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Tahap 3</td>
                                                <td>—</td>
                                                <td>—</td>
                                            </tr>
                                            <tr>
                                                <td class="fw-bold">Tahap 4</td>
                                                <td>—</td>
                                                <td>—</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Placeholder shown before DES runs -->
                    <div id="desPlaceholder"
                        class="report-card d-flex flex-column justify-content-center align-items-center text-secondary w-100 mx-auto"
                        style="min-height: 200px; border: 2px dashed #e2e8f0; background-color: #f8fafc;">
                        <i class="bi bi-box-seam" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem;"></i>
                        <p class="mt-2 text-muted fw-semibold mb-0" style="letter-spacing: 0.5px;">Klik START SIMULATION untuk melihat Hasil DES</p>
                    </div>
                </div>
            </div>

            <!-- Full Width Tables & Alerts -->
            <div id="desBottomContainer" class="d-none animate-fade-in">
                <!-- Table 2: Queue Parameters -->
                <div class="report-card p-4 p-md-5 mb-4" style="border: 1px solid #f1f5f9;">
                    <h6 class="text-dark fw-bold text-uppercase mb-4 d-flex align-items-center gap-2"
                        style="font-size: 0.85rem; letter-spacing: 1.5px;">
                        <i class="bi bi-table text-primary p-1 bg-primary bg-opacity-10 rounded"></i> QUEUE
                        PARAMETERS PER STAGE
                    </h6>
                    <div class="table-responsive">
                        <table class="table text-center align-middle mb-0" style="font-size: 0.8rem;">
                            <thead>
                                <tr class="text-secondary fw-bold text-uppercase"
                                    style="font-size: 0.65rem; border-bottom: 2px solid #e2e8f0 !important; color: #475569 !important;">
                                    <th class="border-0 pb-3 text-nowrap">STAGE</th>
                                    <th class="border-0 pb-3 text-nowrap">λi<br><small class="text-muted fw-normal text-capitalize">(Mean Arrival)</small></th>
                                    <th class="border-0 pb-3 text-nowrap">σAi<br><small class="text-muted fw-normal text-capitalize">(Std. Dev)</small></th>
                                    <th class="border-0 pb-3 text-nowrap">CAi<br><small class="text-muted fw-normal text-capitalize">(CoV)</small></th>
                                    <th class="border-0 pb-3 text-nowrap">Si<br><small class="text-muted fw-normal text-capitalize">(Mean Service)</small></th>
                                    <th class="border-0 pb-3 text-nowrap">σSi<br><small class="text-muted fw-normal text-capitalize">(Std. Dev)</small></th>
                                    <th class="border-0 pb-3 text-nowrap">CSi<br><small class="text-muted fw-normal text-capitalize">(CoV)</small></th>
                                    <th class="border-0 pb-3 text-nowrap">Λi<br><small class="text-muted fw-normal text-capitalize">(Arrival Rate)</small></th>
                                    <th class="border-0 pb-3 text-nowrap">ρi<br><small class="text-muted fw-normal text-capitalize">(Utilization)</small></th>
                                    <th class="border-0 pb-3 text-nowrap">μi<br><small class="text-muted fw-normal text-capitalize">(Service Rate)</small></th>
                                </tr>
                            </thead>
                            <tbody id="t2Body" class="border-top-0">
                                <!-- Populated automatically -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- DES METRICS PER STAGE TABLE -->
                <div id="desStageMetricsContainer" class="report-card p-4 p-md-5 mb-4 d-none animate-fade-in"
                    style="border: 1px solid #f1f5f9;">
                    <h6 class="text-dark fw-bold text-uppercase mb-4 d-flex align-items-center gap-2"
                        style="font-size: 0.85rem; letter-spacing: 1.5px;">
                        <i class="bi bi-graph-up-arrow text-primary p-1 bg-primary bg-opacity-10 rounded"></i> DES SYSTEM
                        METRICS PER STAGE
                    </h6>
                    <div class="table-responsive">
                        <table class="table text-center align-middle mb-0" style="font-size: 0.8rem;">
                            <thead>
                                <tr class="text-secondary fw-bold text-uppercase"
                                    style="font-size: 0.65rem; border-bottom: 2px solid #e2e8f0 !important; color: #475569 !important;">
                                    <th class="border-0 pb-3 text-nowrap">STAGE</th>
                                    <th class="border-0 pb-3 text-nowrap">Wq<br><small class="text-muted fw-normal text-capitalize">(Wait in Queue)</small></th>
                                    <th class="border-0 pb-3 text-nowrap" style="color: #6366f1 !important;">CI (Wq)<br><small class="text-muted fw-normal text-capitalize">(95% Conf)</small></th>
                                    <th class="border-0 pb-3 text-nowrap">Lq<br><small class="text-muted fw-normal text-capitalize">(Avg Queue Length)</small></th>
                                    <th class="border-0 pb-3 text-nowrap" style="color: #6366f1 !important;">CI (Lq)<br><small class="text-muted fw-normal text-capitalize">(95% Conf)</small></th>
                                    <th class="border-0 pb-3 text-nowrap">W<br><small class="text-muted fw-normal text-capitalize">(Time in System)</small></th>
                                    <th class="border-0 pb-3 text-nowrap" style="color: #6366f1 !important;">CI (W)<br><small class="text-muted fw-normal text-capitalize">(95% Conf)</small></th>
                                    <th class="border-0 pb-3 text-nowrap">L<br><small class="text-muted fw-normal text-capitalize">(Avg in System)</small></th>
                                    <th class="border-0 pb-3 text-nowrap" style="color: #6366f1 !important;">CI (L)<br><small class="text-muted fw-normal text-capitalize">(95% Conf)</small></th>
                                </tr>
                            </thead>
                            <tbody id="desStageMetricsBody" class="border-top-0">
                                <!-- Will be populated on simulation run -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- SCENARIO COMPARISON CHARTS -->
                <div id="desScenarioSection" class="mt-5 report-card p-4 p-md-5" style="border:1px solid #e2e8f0;background:#ffffff;">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div style="width:4px;height:28px;background:linear-gradient(180deg,#6366f1,#a78bfa);border-radius:2px;"></div>
                        <h6 class="fw-bold text-dark mb-0 text-uppercase" style="font-size:0.85rem;letter-spacing:1.5px;">
                            <i class="bi bi-bar-chart-steps text-primary me-1"></i> Scenario Comparison — Overall System Metrics
                        </h6>
                        <span class="badge ms-2" style="background:#e0e7ff;color:#3730a3;font-size:0.65rem;letter-spacing:1px;">AKTUAL vs DES</span>
                    </div>
                    <p class="text-secondary mb-4" style="font-size:0.75rem;padding-left:6px;">Setiap kali simulasi dijalankan, skenario baru ditambahkan. Tekan RESET untuk memulai ulang.</p>
                    <div id="desScenarioCards" class="d-flex flex-column gap-4"></div>
                </div>
                <!-- END SCENARIO COMPARISON CHARTS -->

                <!-- Insight Alert -->
                <div class="p-4 rounded-4 shadow-sm mb-4" style="background-color: #f8fafc; border: 1px solid #e2e8f0;">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <div style="background-color: #feefc3; border-radius: 50%; padding: 6px; display: inline-flex;">
                            <i class="bi bi-lightbulb-fill text-warning fs-5"></i>
                        </div>
                        <h6 class="fw-bold mb-0 text-primary" style="font-size: 1rem;">Insight Teori Antrean</h6>
                    </div>

                    <div class="mb-3">
                        <h6 class="fw-bold text-dark d-block mb-1" style="font-size: 0.85rem;">Tingkat Keacakan Waktu
                            (CA / CS)</h6>
                        <p class="text-secondary mb-2" style="font-size: 0.85rem; line-height: 1.6;">Parameter ini
                            mengukur seberapa besar fluktuasi (ketidakpastian) waktu antrean terbentuk maupun waktu
                            layanan diselesaikan.</p>
                        <ul class="text-secondary" style="font-size: 0.85rem; padding-left: 1.2rem; line-height: 1.6;">
                            <li><strong>Bernilai &gt; 1</strong>: Durasi layanan sangat bervariasi. Hal ini secara
                                matematis akan memicu penumpukan antrean yang sulit diprediksi.</li>
                            <li><strong>Bernilai &lt; 1</strong>: Laju kedatangan tipe pasien dan waktu penanganan
                                tergolong cukup konsisten dan stabil.</li>
                        </ul>
                    </div>

                    <div>
                        <h6 class="fw-bold text-dark d-block mb-1" style="font-size: 0.85rem;">Beban Kapasitas Layanan
                            (ρ / Utilisasi)</h6>
                        <p class="text-secondary mb-2" style="font-size: 0.85rem; line-height: 1.6;">Parameter ini
                            menunjukkan utilisasi (proporsi) kesibukan staf terhadap jumlah pasien yang harus ditanggung
                            pada tahap tertentu.</p>
                        <ul class="text-secondary mb-0"
                            style="font-size: 0.85rem; padding-left: 1.2rem; line-height: 1.6;">
                            <li><strong>Di bawah 1</strong>: Kapasitas tenaga kerja masih sangat memadai untuk menangani
                                kedatangan pasien.</li>
                            <li><strong>Mendekati 1</strong>: Beban kerja staf mencapai batas maksimal tanpa jeda
                                istirahat; antrean rentan terbentuk.</li>
                            <li><strong>Melebihi 1</strong>: Kapasitas berlebih (<em>Overload / Bottleneck</em>). Volume
                                pasien jauh melampaui kemampuan maksimal staf, dipastikan menyebabkan kemacetan
                                sistemik.</li>
                        </ul>
                    </div>
                </div>

                <!-- Kingman Warning Alert -->
                <div id="kingmanWarning" class="p-4 rounded-4 shadow-sm d-none"
                    style="background-color: #fef2f2; border: 1px solid #fecaca;">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-exclamation-triangle-fill text-danger fs-5"></i>
                        <h6 class="fw-bold mb-0 text-danger" style="font-size: 1rem;">Peringatan Kestabilan Sistem &
                            Analitik G/G/1</h6>
                    </div>
                    <p id="kingmanWarningText" class="text-danger mb-0" style="font-size: 0.85rem; line-height: 1.6;">
                    </p>
                </div>
            </div>

        </div>
            </div>
        </main>
    </div>
</div>

    <style>
        .animate-fade-in {
            animation: fadeIn 0.4s ease-out forwards;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Basic row styling for T1 and T2 */
        #t1Body tr {
            border-bottom: 1px solid #f1f5f9;
        }

        #t1Body tr:last-child {
            border-bottom: none;
        }

        #t2Body tr {
            border-bottom: 1px solid #f1f5f9;
        }

        #t2Body tr:last-child {
            border-bottom: none;
        }
    </style>

    <script>
        const targetSessionId = <?php echo isset($targetSession) ? $targetSession : 1; ?>;

        // UI Enhancements over DES.js
        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('btnStartDes').addEventListener('click', function() {
                // Hide placeholder once simulation starts
                let pl = document.getElementById('desPlaceholder');
                if (pl) {
                    pl.classList.remove('d-flex');
                    pl.classList.add('d-none');
                }
                // Show bottom containers (Queue Params, DES Stage Metrics, Alerts)
                let btm = document.getElementById('desBottomContainer');
                if (btm) btm.classList.remove('d-none');
            });

            document.getElementById('btnResetDes').addEventListener('click', function() {
                let pl = document.getElementById('desPlaceholder');
                if (pl) {
                    pl.classList.remove('d-none');
                    pl.classList.add('d-flex');
                }
                let btm = document.getElementById('desBottomContainer');
                if (btm) btm.classList.add('d-none');
            });
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- jsPDF and AutoTable for PDF Download -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const btnDownload = document.getElementById('btnDownloadPdf');
            if (btnDownload) {
                btnDownload.addEventListener('click', () => {
                    const { jsPDF } = window.jspdf;
                    const doc = new jsPDF('landscape'); // Landscape to fit many columns
                    
                    doc.text("Raw Execution Data (Vaksinasi #" + targetSessionId + ")", 14, 15);
                    
                    doc.autoTable({
                        html: '#rawDataTable',
                        startY: 20,
                        theme: 'grid', // Plain grid with borders
                        styles: {
                            font: 'helvetica',
                            fontSize: 7,
                            textColor: 0,       // Black text
                            fillColor: 255,     // White background
                            lineColor: 0,       // Black borders
                            lineWidth: 0.1,
                            valign: 'middle',
                            halign: 'center'
                        },
                        headStyles: {
                            fillColor: 255,     // White background for headers
                            textColor: 0,       // Black text for headers
                            fontStyle: 'bold'
                        },
                        alternateRowStyles: {
                            fillColor: 255,     // White background for alternate rows
                        },
                        footStyles: {
                            fillColor: 255,
                            textColor: 0,
                            fontStyle: 'bold'
                        }
                    });
                    
                    doc.save('raw_execution_data_session_' + targetSessionId + '.pdf');
                });
            }
        });
    </script>
    <script src="des.js"></script>

</body>

</html>