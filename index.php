<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QueueFlow Pro - Simulation Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <style>
        .stage-card-horizontal {
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: space-between;
            padding: 1.15rem 1.75rem !important;
            border-radius: 18px !important;
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.04), 0 1px 3px rgba(0, 0, 0, 0.02) !important;
            border: 1px solid #f1f5f9 !important;
            position: relative;
            padding-left: 1.75rem !important;
            min-height: 0;
            transition: var(--transition-smooth);
            background: #ffffff !important;
        }

        .stage-card-horizontal::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 6px;
            background: var(--primary-gradient);
            border-radius: 20px 0 0 20px;
        }

        .stage-card-horizontal:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(99, 102, 241, 0.08), 0 4px 6px rgba(0, 0, 0, 0.02) !important;
            border-color: #e2e8f0 !important;
        }

        .metric-box-horizontal {
            background-color: #f8fafc;
            border-radius: 14px;
            padding: 0.85rem 1.4rem;
            display: flex;
            flex-direction: row;
            align-items: center;
            border: 1px solid #f1f5f9;
            transition: var(--transition-smooth);
            height: 100%;
            min-width: 0;
        }

        .metric-box-horizontal .metric-label {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 1px;
            color: var(--text-muted);
            margin-bottom: 0;
            margin-right: 1.25rem;
            width: 60px;
            flex-shrink: 0;
        }

        .metric-box-horizontal .metric-value-queue {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.25rem;
            height: 100%;
            overflow-y: auto;
            width: 100%;
        }

        .metric-box-horizontal .metric-value-stage {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            width: 100%;
        }

        .btn-lanjut-style {
            background: var(--primary-gradient) !important;
            border: none !important;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2) !important;
            border-radius: 10px !important;
            font-weight: 700 !important;
            letter-spacing: 0.5px !important;
            transition: var(--transition-smooth) !important;
            color: white !important;
        }
        .btn-lanjut-style:hover:not(:disabled) {
            transform: translateY(-1px) !important;
            box-shadow: 0 6px 16px rgba(79, 70, 229, 0.3) !important;
            filter: brightness(1.05);
        }
        .btn-lanjut-style:disabled {
            background: #f1f5f9 !important;
            color: #94a3b8 !important;
            box-shadow: none !important;
            cursor: not-allowed;
            opacity: 0.65;
        }

        .btn-keluar-style {
            background: transparent !important;
            border: 1.5px solid #fecaca !important;
            color: #f43f5e !important;
            border-radius: 10px !important;
            font-weight: 700 !important;
            letter-spacing: 0.5px !important;
            transition: var(--transition-smooth) !important;
        }
        .btn-keluar-style:hover:not(:disabled) {
            background: #fef2f2 !important;
            border-color: #ef4444 !important;
            color: #dc2626 !important;
            transform: translateY(-1px) !important;
        }
        .btn-keluar-style:disabled {
            background: transparent !important;
            border-color: #f1f5f9 !important;
            color: #cbd5e1 !important;
            cursor: not-allowed;
            opacity: 0.65;
        }

        .queue-badge {
            background-color: #e0e7ff !important;
            color: #4f46e5 !important;
            border: 1px solid rgba(79, 70, 229, 0.15) !important;
            font-size: 0.82rem !important;
            font-weight: 700 !important;
            padding: 0.35rem 0.65rem !important;
            border-radius: 8px !important;
            margin: 2px !important;
            display: inline-flex !important;
            align-items: center !important;
        }

        .h-breadcrumb { font-size: 0.78rem; margin-bottom: 0.85rem; }
        .h-breadcrumb a { color: #6366f1; text-decoration: none; font-weight: 500; }
        .h-breadcrumb a:hover { text-decoration: underline; }
        .h-breadcrumb .sep { margin: 0 0.4rem; color: #94a3b8; }
        .h-breadcrumb .current { color: #6366f1; font-weight: 600; }

        .h-page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
        .h-page-title { font-size: 1.65rem; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; margin-bottom: 0.35rem; }
        .h-page-subtitle { color: #6366f1; font-size: 0.83rem; font-weight: 500; max-width: 600px; line-height: 1.5; }
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
            <a href="index.php" class="nav-link active rounded-1 d-flex align-items-center gap-3 py-3 px-3">
                <i class="bi bi-grid-1x2-fill"></i> DASHBOARD
            </a>
            <a href="history.php" class="nav-link text-secondary rounded-1 d-flex align-items-center gap-3 py-3 px-3">
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

    <main class="d-flex flex-column flex-grow-1 overflow-hidden">
        <div class="top-header-bar">
            <div class="th-page-label">Simulation Dashboard - Web-based Multi-stage Queue System</div>
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

        <div class="d-flex flex-grow-1 p-4 gap-4 overflow-hidden align-items-stretch" style="min-height: 0;">
            <div class="d-flex flex-column flex-grow-1 h-100 overflow-hidden" style="min-height: 0;">
                <div class="h-breadcrumb">
                    <a href="index.php">Dashboard</a>
                    <span class="sep">›</span>
                    <span class="current">Simulation Dashboard</span>
                </div>

                <div class="h-page-header align-items-center">
                    <div>
                        <h1 class="h-page-title">Simulation Dashboard</h1>
                        <p class="h-page-subtitle">Kelola, pantau, dan jalankan simulasi sistem antrean multi-tahap secara langsung untuk analisis alur pelayanan.</p>
                    </div>
                    <div class="d-flex gap-3 align-self-start mt-2">
                        <a href="history.php" class="btn btn-light text-secondary fw-bold d-flex align-items-center gap-2 px-3 py-2 border shadow-sm rounded-3 text-nowrap" style="font-size: 0.8rem; height: 38px; border-color: #e2e8f0 !important; background: white; text-decoration: none;">
                            <i class="bi bi-clock-history"></i> Lihat Riwayat
                        </a>
                        <button id="btnEndSim" class="btn btn-danger fw-bold d-flex align-items-center gap-2 px-4 py-2 border-0 shadow-sm rounded-3 text-nowrap" style="font-size: 0.8rem; height: 38px; background: linear-gradient(135deg, #f43f5e, #e11d48); box-shadow: 0 4px 12px rgba(225, 29, 72, 0.2) !important;">
                            <i class="bi bi-stop-circle-fill"></i> Akhiri Simulasi
                        </button>
                        <button id="btnAddUser" class="btn btn-primary fw-bold d-flex align-items-center gap-2 px-4 py-2 border-0 shadow-sm rounded-3 text-nowrap" style="font-size: 0.8rem; height: 38px; background: var(--primary-gradient); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2) !important;">
                            <i class="bi bi-plus-circle-fill"></i> Tambah Antrian
                        </button>
                    </div>
                </div>

                <div class="d-flex flex-column gap-2 flex-grow-1 overflow-hidden justify-content-between mb-3" id="stages-container" style="min-height: 0;">
                </div>
            </div>

            <div class="log-column flex-shrink-0 h-100 position-relative" style="width: 440px;">
                <div class="card bg-white-panel text-dark h-100 border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="window-controls d-flex align-items-center px-4 py-3 gap-2 border-bottom" style="border-color: #f1f5f9 !important; background-color: #f8fafc;">
                        <div class="mac-dot bg-danger"></div>
                        <div class="mac-dot bg-warning"></div>
                        <div class="mac-dot bg-success"></div>
                        <span class="ms-3 text-muted text-uppercase fw-bold" style="font-size: 0.65rem; letter-spacing: 2px;">Activity Log</span>
                    </div>

                    <div class="card-body log-panel custom-scrollbar-light p-4" id="logPanel">
                        <div class="log-entry text-muted p-0 border-0 mb-2">
                            <span class="fst-italic">_ Listening for system events...</span>
                        </div>
                    </div>
                </div>

                <button id="fabAddUser" class="btn btn-primary fab-btn position-absolute shadow-lg d-flex align-items-center justify-content-center" style="width: 56px; height: 56px; border-radius: 16px; bottom: 20px; right: 20px; z-index: 10;">
                    <i class="bi bi-plus-lg fs-4"></i>
                </button>
            </div>
            
        </div>
    </main>
</div>

<script src="script.js?v=<?php echo time(); ?>"></script>

</body>
</html>
