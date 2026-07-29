// Variabel global yang dibutuhin: targetSessionId

let interarrivalSamples = [];
let serviceTimes = { 1: [], 2: [], 3: [], 4: [] };
let probNext = { 2: 1, 3: 1, 4: 1 };
let desSessionData = null;

// State buat ngebandingin skenario
let _scenarioCount = 0;
let _actualOverallMetrics = { Wq: 0, W: 0, Lq: 0, L: 0 };
let _scenarioCharts = []; // nyimpen instance Chart.js buat dibersihin nanti

// Fungsi bantuan buat ngubah ke menit biar metriknya konsisten
const toMin = ms => ms / 60000;

async function loadDESData() {
    try {
        const res = await fetch('results.json');
        const allData = await res.json();

        let sessionData = allData.filter(d => d.session_id == targetSessionId);
        desSessionData = sessionData;

        let stage1Data = sessionData.filter(d => d.history.find(h => h.stage === 1));
        stage1Data.sort((a, b) => {
            let tA = a.history.find(h => h.stage === 1).masuk_queue;
            let tB = b.history.find(h => h.stage === 1).masuk_queue;
            return tA - tB;
        });

        interarrivalSamples = [];
        for (let i = 1; i < stage1Data.length; i++) {
            let t0 = stage1Data[i - 1].history.find(h => h.stage === 1).masuk_queue;
            let t1 = stage1Data[i].history.find(h => h.stage === 1).masuk_queue;
            if (t1 >= t0) {
                interarrivalSamples.push(toMin(t1 - t0));
            }
        }

        if (interarrivalSamples.length === 0) interarrivalSamples = [1];

        serviceTimes = { 1: [], 2: [], 3: [], 4: [] };
        let cCounts = { 1: 0, 2: 0, 3: 0, 4: 0 };

        sessionData.forEach(d => {
            if (d.history.find(h => h.stage === 1)) cCounts[1]++;
            if (d.history.find(h => h.stage === 2)) cCounts[2]++;
            if (d.history.find(h => h.stage === 3)) cCounts[3]++;
            if (d.history.find(h => h.stage === 4)) cCounts[4]++;

            d.history.forEach(h => {
                let st = toMin(h.keluar_stage - h.masuk_stage);
                // Benerin 5: Validasi data waktu pelayanan (normalisasi/buang data aneh yang lebih dari 60 menit)
                if (!isNaN(st) && st >= 0 && st <= 60) {
                    serviceTimes[h.stage].push(st);
                }
            });
        });

        // Ngitung probabilitas empiris buat lanjut ke tahap berikutnya
        probNext[2] = cCounts[1] > 0 ? (cCounts[2] / cCounts[1]) : 1;
        probNext[3] = cCounts[2] > 0 ? (cCounts[3] / cCounts[2]) : 1;
        probNext[4] = cCounts[3] > 0 ? (cCounts[4] / cCounts[3]) : 1;

        for (let i = 1; i <= 4; i++) {
            if (serviceTimes[i].length === 0) serviceTimes[i] = [1];
        }

        renderActualMetrics(sessionData);

    } catch (err) {
        console.error("Loading DES data failed", err);
    }
}

function runDES() {
    let reps = parseInt(document.getElementById('desReps').value) || 100;
    let warmup = parseInt(document.getElementById('desWarmup').value) || 10;
    let obs = parseInt(document.getElementById('desObs').value) || 30;

    // Parameter G/G/c
    let qps = document.getElementById('desQuota');
    let quota = qps ? parseInt(qps.value) || 0 : 0;

    let elS1 = document.getElementById('srv1'); let srv1 = elS1 ? parseInt(elS1.value) || 1 : 1;
    let elS2 = document.getElementById('srv2'); let srv2 = elS2 ? parseInt(elS2.value) || 1 : 1;
    let elS3 = document.getElementById('srv3'); let srv3 = elS3 ? parseInt(elS3.value) || 1 : 1;
    let elS4 = document.getElementById('srv4'); let srv4 = elS4 ? parseInt(elS4.value) || 1 : 1;

    let serversCount = { 1: srv1, 2: srv2, 3: srv3, 4: srv4 };

    if (isNaN(reps) || isNaN(warmup) || isNaN(obs) || reps <= 0 || obs <= 0) {
        alert("Harap masukkan nilai yang valid untuk REPS, WARMUP, dan OBS.");
        return;
    }

    const btn = document.getElementById('btnStartDes');
    btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Simulating...`;
    btn.disabled = true;

    setTimeout(() => {
        let totalCustomers = warmup + obs;

        // Kondisi awal: ngitung deterministik langsung dari data aktual sesinya
        // (nggak pake random - actual parameters should not change based on DES input)
        if (desSessionData) renderActualMetrics(desSessionData);

        // Simulasi hasil DES (serversCount ditentuin user, pake reps/warmup/obs)
        let config = { warmup, obs, totalCustomers, quota, serversCount };
        let repMetricsDes = [];
        for (let r = 0; r < reps; r++) {
            repMetricsDes.push(simOneRep(config));
        }
        let finalMetrics = averageRepMetrics(repMetricsDes, reps);

        // Nampilin hasil DES
        renderDESResults(finalMetrics, { reps, warmup, obs, quota, serversCount });

        btn.innerHTML = `🌟 Start Simulation`;
        btn.disabled = false;
    }, 50);
}

function simOneRep(config) {
    let warmup = config.warmup;
    let obs = config.obs;
    let totalCustomers = config.totalCustomers;
    let quota = config.quota;
    let srvC = config.serversCount;

    let customers = [];
    let currentTime = 8 * 60; // Basis jam 08:00 (480 minutes)
    let currentHourLimit = 9 * 60; // Next hour block (09:00:00)
    let patientsInHourBlock = 0;

    for (let i = 0; i < totalCustomers; i++) {
        let inter = interarrivalSamples[Math.floor(Math.random() * interarrivalSamples.length)];
        if (i === 0) inter = 0;

        let rawArr = currentTime + inter;

        // Reset counter jam kalo emang udah ganti jam
        while (rawArr >= currentHourLimit) {
            currentHourLimit += 60;
            patientsInHourBlock = 0;
        }

        if (quota > 0) {
            patientsInHourBlock++;
            if (patientsInHourBlock > quota) {
                // Kuota abis! Masukin kedatangan ini dan selanjutnya ke jam berikutnya
                // Kita majuin currentTime ke bates jamnya, jadi urutan kedatangannya 'maju'
                currentTime = currentHourLimit;
                currentHourLimit += 60;
                patientsInHourBlock = 1;
            } else {
                currentTime = rawArr;
            }
        } else {
            currentTime = rawArr;
        }

        customers.push({
            id: i,
            arrivalSystem: currentTime,
            interArrivalSystem: (i === 0) ? 0 : inter,
            stages: {}
        });
    }

    // Engine array G/G/c - ngebagi server tiap tahap
    let server_free = {
        1: new Array(srvC[1]).fill(8 * 60),
        2: new Array(srvC[2]).fill(8 * 60),
        3: new Array(srvC[3]).fill(8 * 60),
        4: new Array(srvC[4]).fill(8 * 60)
    };

    function getEarliestFreeServer(serverArray) {
        let minTime = serverArray[0];
        let minIdx = 0;
        for (let k = 1; k < serverArray.length; k++) {
            if (serverArray[k] < minTime) { minTime = serverArray[k]; minIdx = k; }
        }
        return { time: minTime, idx: minIdx };
    }
    let last_arr = { 1: null, 2: null, 3: null, 4: null };

    for (let i = 0; i < totalCustomers; i++) {
        let c = customers[i];

        // --- TAHAP 1 ---
        let arr_1 = c.arrivalSystem;
        let pSrv1 = getEarliestFreeServer(server_free[1]);
        let start_service_1 = Math.max(arr_1, pSrv1.time);
        let wait_1 = start_service_1 - arr_1;
        let sArr1 = serviceTimes[1];
        let service_1 = sArr1[Math.floor(Math.random() * sArr1.length)];
        let end_1 = start_service_1 + service_1;
        server_free[1][pSrv1.idx] = end_1; // Kasih ke kasir paling cepet

        c.stages[1] = {
            arrival: arr_1,
            interArrival: (last_arr[1] !== null) ? (arr_1 - last_arr[1]) : 0,
            waitTime: wait_1,
            serviceTime: service_1,
            endService: end_1
        };
        last_arr[1] = arr_1;

        // --- TAHAP 2 ---
        // Routing empiris: logika keluar antrian berdasarkan histori
        if (Math.random() <= probNext[2]) {
            // Output tahap sebelumnya => input buat tahap ini
            let arr_2 = end_1;
            let pSrv2 = getEarliestFreeServer(server_free[2]);
            let start_service_2 = Math.max(arr_2, pSrv2.time);
            let wait_2 = start_service_2 - arr_2;
            let sArr2 = serviceTimes[2];
            let service_2 = sArr2[Math.floor(Math.random() * sArr2.length)];
            let end_2 = start_service_2 + service_2;
            server_free[2][pSrv2.idx] = end_2;

            c.stages[2] = {
                arrival: arr_2,
                interArrival: (last_arr[2] !== null) ? (arr_2 - last_arr[2]) : 0,
                waitTime: wait_2,
                serviceTime: service_2,
                endService: end_2
            };
            last_arr[2] = arr_2;

            // --- TAHAP 3 ---
            if (Math.random() <= probNext[3]) {
                let arr_3 = end_2;
                let pSrv3 = getEarliestFreeServer(server_free[3]);
                let start_service_3 = Math.max(arr_3, pSrv3.time);
                let wait_3 = start_service_3 - arr_3;
                let sArr3 = serviceTimes[3];
                let service_3 = sArr3[Math.floor(Math.random() * sArr3.length)];
                let end_3 = start_service_3 + service_3;
                server_free[3][pSrv3.idx] = end_3;

                c.stages[3] = {
                    arrival: arr_3,
                    interArrival: (last_arr[3] !== null) ? (arr_3 - last_arr[3]) : 0,
                    waitTime: wait_3,
                    serviceTime: service_3,
                    endService: end_3
                };
                last_arr[3] = arr_3;

                // --- TAHAP 4 ---
                if (Math.random() <= probNext[4]) {
                    let arr_4 = end_3;
                    let pSrv4 = getEarliestFreeServer(server_free[4]);
                    let start_service_4 = Math.max(arr_4, pSrv4.time);
                    let wait_4 = start_service_4 - arr_4;
                    let sArr4 = serviceTimes[4];
                    let service_4 = sArr4[Math.floor(Math.random() * sArr4.length)];
                    let end_4 = start_service_4 + service_4;
                    server_free[4][pSrv4.idx] = end_4;

                    c.stages[4] = {
                        arrival: arr_4,
                        interArrival: (last_arr[4] !== null) ? (arr_4 - last_arr[4]) : 0,
                        waitTime: wait_4,
                        serviceTime: service_4,
                        endService: end_4
                    };
                    last_arr[4] = arr_4;
                }
            }
        }
    }

    // Benerin 6: Pake filter WARMUP yang bener
    let obsCustomers = customers.slice(warmup, warmup + obs);
    let cCount = obsCustomers.length;

    let metrics = {
        stages: { 1: {}, 2: {}, 3: {}, 4: {} },
        WqSum: 0, WSum: 0,
        srvC: srvC
    };

    for (let stage = 1; stage <= 4; stage++) {
        let wSum = 0, sSum = 0;
        let interArrivals = [];
        let rServiceTimes = [];

        let validCustCount = 0;
        let waitCustCount = 0;

        for (let i = 0; i < cCount; i++) {
            if (!obsCustomers[i].stages[stage]) continue; // Skip pelanggan yang keluar antrian

            let s = obsCustomers[i].stages[stage];
            if (s.waitTime >= 0) {
                wSum += s.waitTime;
                waitCustCount++;
            }

            sSum += s.serviceTime;
            if (validCustCount > 0) interArrivals.push(s.interArrival);
            rServiceTimes.push(s.serviceTime);

            validCustCount++;
            metrics.WqSum += s.waitTime;
        }

        metrics.stages[stage].avgWait = waitCustCount > 0 ? (wSum / waitCustCount) : 0;
        metrics.stages[stage].avgService = validCustCount > 0 ? (sSum / validCustCount) : 0;

        let meanInter = calculateMean(interArrivals);
        let stdInter = calculateStdDev(interArrivals, meanInter);
        let meanService = calculateMean(rServiceTimes);
        let stdService = calculateStdDev(rServiceTimes, meanService);

        metrics.stages[stage].Ai = meanInter;
        metrics.stages[stage].SigmaAi = stdInter;
        metrics.stages[stage].Si = meanService;
        metrics.stages[stage].SigmaSi = stdService;
    }

    for (let i = 0; i < cCount; i++) {
        let finalStage = 1;
        if (obsCustomers[i].stages[4]) finalStage = 4;
        else if (obsCustomers[i].stages[3]) finalStage = 3;
        else if (obsCustomers[i].stages[2]) finalStage = 2;

        metrics.WSum += (obsCustomers[i].stages[finalStage].endService - obsCustomers[i].arrivalSystem);
    }

    metrics.W = cCount > 0 ? (metrics.WSum / cCount) : 0;
    metrics.Wq = cCount > 0 ? (metrics.WqSum / cCount) : 0;

    return metrics;
}

function calculateMean(arr) {
    if (arr.length === 0) return 0;
    let sum = arr.reduce((a, b) => a + b, 0);
    return sum / arr.length;
}

function calculateStdDev(arr, mean) {
    if (arr.length <= 1) return 0;
    let sumSq = arr.reduce((a, b) => a + Math.pow(b - mean, 2), 0);
    return Math.sqrt(sumSq / (arr.length - 1));
}

/**
 * Ngasih nilai t buat tingkat kepercayaan 95% berdasarkan derajat kebebasan (df)
 */
function getStudentT95(df) {
    if (df <= 0) return 0;
    // Tabel lookup biasa buat df kecil
    const tTable = {
        1: 12.706, 2: 4.303, 3: 3.182, 4: 2.776, 5: 2.571,
        6: 2.447, 7: 2.365, 8: 2.306, 9: 2.262, 10: 2.228,
        15: 2.131, 20: 2.086, 25: 2.060, 30: 2.042, 40: 2.021,
        50: 2.009, 60: 2.000, 70: 1.994, 80: 1.990, 90: 1.987,
        100: 1.984, 120: 1.980
    };

    // Benerin 1: Biar stabil buat sampel gede (REPS >= 30 berarti df >= 29)
    if (df >= 29) return 1.96;

    if (tTable[df]) return tTable[df];

    // Cari yang paling deket atau pake perkiraan buat df > 120
    if (df > 120) return 1.96 + (1.58 / df);

    // Interpolasi simpel buat nilai di antara key lookup
    let keys = Object.keys(tTable).map(Number).sort((a, b) => a - b);
    for (let i = 0; i < keys.length - 1; i++) {
        if (df > keys[i] && df < keys[i + 1]) {
            let t0 = tTable[keys[i]];
            let t1 = tTable[keys[i + 1]];
            return t0 + (t1 - t0) * (df - keys[i]) / (keys[i + 1] - keys[i]);
        }
    }
    return 1.96;
}

function averageRepMetrics(repMetrics, reps) {
    let final = {
        stages: { 1: {}, 2: {}, 3: {}, 4: {} },
        Wq: 0, W: 0, Lq: 0, L: 0,
        srvC: repMetrics.length > 0 ? repMetrics[0].srvC : { 1: 1, 2: 1, 3: 1, 4: 1 }
    };

    let sumWq = 0, sumW = 0;

    let stageSums = {
        1: { avgWait: 0, avgService: 0, Ai: 0, SigmaAi: 0, Si: 0, SigmaSi: 0 },
        2: { avgWait: 0, avgService: 0, Ai: 0, SigmaAi: 0, Si: 0, SigmaSi: 0 },
        3: { avgWait: 0, avgService: 0, Ai: 0, SigmaAi: 0, Si: 0, SigmaSi: 0 },
        4: { avgWait: 0, avgService: 0, Ai: 0, SigmaAi: 0, Si: 0, SigmaSi: 0 }
    };

    repMetrics.forEach(m => {
        sumWq += m.Wq;
        sumW += m.W;
        for (let stage = 1; stage <= 4; stage++) {
            stageSums[stage].avgWait += m.stages[stage].avgWait;
            stageSums[stage].avgService += m.stages[stage].avgService;
            stageSums[stage].Ai += m.stages[stage].Ai;
            stageSums[stage].SigmaAi += m.stages[stage].SigmaAi;
            stageSums[stage].Si += m.stages[stage].Si;
            stageSums[stage].SigmaSi += m.stages[stage].SigmaSi;
        }
    });

    for (let stage = 1; stage <= 4; stage++) {
        let s = final.stages[stage];
        let sums = stageSums[stage];
        s.avgWait = sums.avgWait / reps;
        s.avgService = sums.avgService / reps;
        s.Ai = sums.Ai / reps;
        s.SigmaAi = sums.SigmaAi / reps;
        s.Si = sums.Si / reps;
        s.SigmaSi = sums.SigmaSi / reps;

        s.CAi = s.Ai > 0 ? (s.SigmaAi / s.Ai) : 0;
        s.CSi = s.Si > 0 ? (s.SigmaSi / s.Si) : 0;
        s.lambda = s.Ai > 0 ? (1 / s.Ai) : 0;
        s.mu = s.Si > 0 ? (1 / s.Si) : 0;

        let c = final.srvC[stage] || 1;
        s.rho = s.mu > 0 ? (s.lambda / (c * s.mu)) : 0;

        // BARU: Metrik DES tiap tahap
        s.W = s.avgWait + s.avgService;

        // --- HITUNGAN CI ---
        let tVal = getStudentT95(reps - 1);
        let divisor = Math.sqrt(reps);

        // Kita butuh standar deviasi dari tiap metrik di semua replikasi
        let wqVals = [], wVals = [], lqVals = [], lVals = [];

        repMetrics.forEach(m => {
            let rStage = m.stages[stage];
            let rWq = rStage.avgWait;
            let rW = rStage.avgWait + rStage.avgService;

            // Benerin 3: Pake rata-rata lambda global buat ngitung CI dari L/Lq
            let rLambda = s.lambda;

            wqVals.push(rWq);
            wVals.push(rW);
            lqVals.push(rLambda * rWq);
            lVals.push(rLambda * rW);
        });

        // Ngitung rata-rata (harusnya sama kayak s.avgWait dll, tapi dihitung ulang biar jelas buat LQ/L)
        s.Wq = s.avgWait; // yang udah ada
        s.Lq = calculateMean(lqVals);
        s.L = calculateMean(lVals);

        // Bantuan buat ngeformat string CI [bawah , atas]
        const getCI = (vals, mean) => {
            let sd = calculateStdDev(vals, mean);
            let h = tVal * (sd / divisor);
            return `${formatNum(mean - h)} , ${formatNum(mean + h)}`;
        };

        s.ciWq = getCI(wqVals, s.Wq);
        s.ciW = getCI(wVals, s.W);
        s.ciLq = getCI(lqVals, s.Lq);
        s.ciL = getCI(lVals, s.L);
    }

    final.Wq = sumWq / reps;
    final.W = sumW / reps;

    let globalLambda = final.stages[1].lambda;
    final.Lq = globalLambda * final.Wq;
    final.L = globalLambda * final.W;

    return final;
}

function formatNum(num) {
    return Number(num).toFixed(7);
}

function formatDurationH(mins) {
    if (!isFinite(mins) || mins < 0) return '0.0000000 min';
    return Number(mins).toFixed(7) + ' min';
}

function getUtilizationClass(rho) {
    if (rho > 1) return 'bg-danger text-white px-2 py-1 rounded shadow-sm fw-bold';
    if (rho >= 0.8) return 'bg-warning text-dark px-2 py-1 rounded shadow-sm fw-bold';
    return 'bg-success text-white px-2 py-1 rounded shadow-sm fw-bold';
}

function getCVClass(cv) {
    if (cv > 1) return 'text-danger fw-bold';
    return 'text-success fw-bold';
}

function renderDESResults(metrics, simParams) {
    let _rc = document.getElementById('desResultsContainer');
    if (_rc) _rc.classList.remove('d-none');

    // Tabel 1
    let t1Body = '';
    for (let i = 1; i <= 4; i++) {
        t1Body += `<tr>
            <td class="fw-bold">Tahap ${i}</td>
            <td class="text-primary fw-semibold">${formatDurationH(metrics.stages[i].avgWait)}</td>
            <td class="text-info fw-semibold">${formatDurationH(metrics.stages[i].avgService)}</td>
        </tr>`;
    }
    document.getElementById('t1Body').innerHTML = t1Body;

    // Tabel 2
    let t2Body = '';
    for (let i = 1; i <= 4; i++) {
        let s = metrics.stages[i];
        t2Body += `<tr>
            <td class="fw-bold">Tahap ${i}</td>
            <td>${formatNum(s.Ai)}</td>
            <td>${formatNum(s.SigmaAi)}</td>
            <td class="${getCVClass(s.CAi)}">${formatNum(s.CAi)}</td>
            <td>${formatNum(s.Si)}</td>
            <td>${formatNum(s.SigmaSi)}</td>
            <td class="${getCVClass(s.CSi)}">${formatNum(s.CSi)}</td>
            <td>${formatNum(s.lambda)}</td>
            <td><span class="${getUtilizationClass(s.rho)}">${formatNum(s.rho)}</span></td>
            <td>${formatNum(s.mu)}</td>
        </tr>`;
    }
    document.getElementById('t2Body').innerHTML = t2Body;

    // Tabel 3
    document.getElementById('sysWq').innerText = formatDurationH(metrics.Wq);
    document.getElementById('sysW').innerText = formatDurationH(metrics.W);
    document.getElementById('sysLq').innerText = formatNum(metrics.Lq) + " cust";
    document.getElementById('sysL').innerText = formatNum(metrics.L) + " cust";

    // Tabel: Metrik DES tiap tahap
    document.getElementById('desStageMetricsContainer').classList.remove('d-none');
    let dsmBody = '';
    for (let i = 1; i <= 4; i++) {
        let s = metrics.stages[i];
        dsmBody += `<tr>
            <td class="fw-bold">Tahap ${i}</td>
            <td class="text-primary fw-semibold">${formatNum(s.avgWait)} min</td>
            <td class="text-muted small">${s.ciWq}</td>
            <td class="text-primary fw-semibold">${formatNum(s.Lq)} cust</td>
            <td class="text-muted small">${s.ciLq}</td>
            <td class="text-dark fw-semibold">${formatNum(s.W)} min</td>
            <td class="text-muted small">${s.ciW}</td>
            <td class="text-dark fw-semibold">${formatNum(s.L)} cust</td>
            <td class="text-muted small">${s.ciL}</td>
        </tr>`;
    }
    document.getElementById('desStageMetricsBody').innerHTML = dsmBody;

    // Evaluasi peringatan Kingman
    let cReasons = [];
    let rhoReasons = [];
    let rhoStages = [];

    for (let i = 1; i <= 4; i++) {
        let s = metrics.stages[i];
        if (s.rho > 1) {
            rhoReasons.push(`Tahap ${i} (ρ = ${formatNum(s.rho)})`);
            rhoStages.push(`Tahap ${i}`);
        }
        if (s.CAi > 1) cReasons.push(`Tahap ${i} (CA = ${formatNum(s.CAi)})`);
        if (s.CSi > 1) cReasons.push(`Tahap ${i} (CS = ${formatNum(s.CSi)})`);
    }

    let warnDiv = document.getElementById('kingmanWarning');
    let warnText = document.getElementById('kingmanWarningText');
    if (warnDiv) {
        if (cReasons.length > 0 || rhoReasons.length > 0) {
            warnDiv.classList.remove('d-none');
            let txt = 'Berdasarkan parameter dasar sistem simulasi ini:<br><ul class="mb-0 mt-2">';

            if (cReasons.length > 0) {
                txt += `<li class="mb-2"><strong>CA / CS > 1:</strong> Ditemukan pada ${cReasons.join(", ")}. Ini menandakan laju kedatangan tipe pasien dan waktu penanganan tergolong fluktuatif/bervariasi, sehingga rentan memicu ketidakpastian antrean.</li>`;
            }
            if (rhoReasons.length > 0) {
                txt += `<li><strong>ρ > 1 (Overload):</strong> Ditemukan pada ${rhoReasons.join(", ")}. Kondisi parameter ρ di atas menunjukkan bahwa sistem antrean terindikasi sangat tidak stabil. Mengacu pada aturan batasan mekanika antrean, jika model matematis G/G/1 (Pendekatan Kingman) diterapkan secara teori rumus langsung pada kondisi ini, perhitungan waktu tunggu rata-rata (Wq) secara kalkulator akan menghasilkan nilai deviasi menjadi nilai negatif, yang berarti hasilnya tidak valid secara mutlak matematis. Di DES komputer, sistem tidak dibatasi rumus Kingman, beban murni disimulasikan sebagai penumpukan membludak sehingga nilai Wq yang muncul secara logis adalah positif raksasa yang mengular.
                <div class="mt-3 p-3 bg-white border border-warning rounded shadow-sm text-dark">
                    <strong>💡 Saran:</strong> Batasi jumlah kedatangan atau tambah jumlah server pada <strong>${rhoStages.join(" dan ")}</strong>.
                </div>
                </li>`;
            }
            txt += '</ul>';
            warnText.innerHTML = txt;
        } else {
            warnDiv.classList.add('d-none');
        }
    }

    // Tambahin chart perbandingan skenario baru
    _scenarioCount++;
    addScenarioChart(_scenarioCount, simParams, _actualOverallMetrics, {
        Wq: metrics.Wq,
        W: metrics.W,
        Lq: metrics.Lq,
        L: metrics.L
    });
}

function resetDES() {
    document.getElementById('desReps').value = 100;
    document.getElementById('desWarmup').value = 10;
    document.getElementById('desObs').value = 30;

    let quotaEl = document.getElementById('desQuota');
    if (quotaEl) quotaEl.value = 0;

    for (let i = 1; i <= 4; i++) {
        let srv = document.getElementById('srv' + i);
        if (srv) srv.value = 1;
    }

    let pl = document.getElementById('desPlaceholder');
    if (pl) {
        pl.classList.remove('d-none');
        pl.classList.add('d-flex');
    }

    let btm = document.getElementById('desBottomContainer');
    if (btm) btm.classList.add('d-none');


    _scenarioCharts.forEach(ch => { try { ch.destroy(); } catch (e) { } });
    _scenarioCharts = [];
    _scenarioCount = 0;
    let scCards = document.getElementById('desScenarioCards');
    if (scCards) scCards.innerHTML = '';

    // Reset nilai kartu Hasil DES ke placeholder
    let elSysWq = document.getElementById('sysWq');
    let elSysW = document.getElementById('sysW');
    let elSysLq = document.getElementById('sysLq');
    let elSysL = document.getElementById('sysL');
    if (elSysWq) elSysWq.innerText = '—';
    if (elSysW) elSysW.innerText = '—';
    if (elSysLq) elSysLq.innerText = '—';
    if (elSysL) elSysL.innerText = '—';

    let t1BodyEl = document.getElementById('t1Body');
    if (t1BodyEl) {
        t1BodyEl.innerHTML = `
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
        `;
    }

    // Balikin nilai aktual statis Kondisi Awal
    if (desSessionData) {
        renderActualMetrics(desSessionData);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadDESData();
    document.getElementById('btnStartDes').addEventListener('click', runDES);
    document.getElementById('btnResetDes').addEventListener('click', resetDES);
});

function renderDESCharts(metrics) {
    const labels = ['Tahap 1', 'Tahap 2', 'Tahap 3', 'Tahap 4'];

    // Bantuan: parse string CI "bawah , atas" -> {lo, hi}
    function parseCI(ciStr) {
        if (!ciStr) return { lo: 0, hi: 0 };
        let parts = ciStr.split(',');
        return {
            lo: parseFloat(parts[0]) || 0,
            hi: parseFloat(parts[1]) || 0
        };
    }


    function buildChart(canvasId, storageKey, meanVals, ciLos, ciHis, color, bgColor, unit) {
        if (window[storageKey]) {
            window[storageKey].destroy();
            window[storageKey] = null;
        }
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        // Bikin data error bar sebagai overlay custom plugin daripada dataset misah
        // Kita bakal pake 3 dataset: pita CI atas (fill ke bawah), bar rata-rata, pita CI bawah
        const ciUpperFill = ciHis;
        const ciLowerFill = ciLos;

        window[storageKey] = new Chart(ctx, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    // CI atas (transparan, cuma buat referensi fill)
                    {
                        label: '95% CI Upper',
                        data: ciUpperFill,
                        backgroundColor: 'transparent',
                        borderColor: 'transparent',
                        borderWidth: 0,
                        type: 'line',
                        fill: '+1',
                        backgroundColor: bgColor,
                        pointRadius: 0,
                        order: 3,
                        tension: 0.3
                    },
                    // CI bawah (base fill transparan)
                    {
                        label: '95% CI Lower',
                        data: ciLowerFill,
                        backgroundColor: 'transparent',
                        borderColor: 'transparent',
                        borderWidth: 0,
                        type: 'line',
                        fill: false,
                        pointRadius: 0,
                        order: 3,
                        tension: 0.3
                    },
                    // Bar rata-rata
                    {
                        label: 'Mean ' + unit,
                        data: meanVals,
                        backgroundColor: color.replace(')', ', 0.85)').replace('rgb', 'rgba'),
                        borderColor: color,
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false,
                        type: 'bar',
                        order: 1,
                        barPercentage: 0.55,
                        categoryPercentage: 0.7
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#f1f5f9',
                        bodyColor: '#cbd5e1',
                        padding: 12,
                        cornerRadius: 10,
                        callbacks: {
                            label: function (ctx2) {
                                let idx = ctx2.dataIndex;
                                if (ctx2.datasetIndex === 2) {
                                    return ` Mean: ${meanVals[idx].toFixed(4)} ${unit}`;
                                }
                                if (ctx2.datasetIndex === 0) {
                                    return ` CI: [${ciLowerFill[idx].toFixed(4)}, ${ciUpperFill[idx].toFixed(4)}]`;
                                }
                                return null;
                            },
                            filter: function (item) {
                                return item.datasetIndex !== 1; // sembunyiin CI bawah biar tooltip nggak dobel
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: {
                            color: '#64748b',
                            font: { size: 11, weight: '600' }
                        }
                    },
                    y: {
                        grid: {
                            color: 'rgba(148,163,184,0.15)',
                            lineWidth: 1
                        },
                        border: { display: false, dash: [4, 4] },
                        ticks: {
                            color: '#64748b',
                            font: { size: 10 },
                            maxTicksLimit: 6,
                            callback: function (val) {
                                return val.toFixed(2);
                            }
                        },
                        beginAtZero: true
                    }
                },
                animation: {
                    duration: 700,
                    easing: 'easeInOutQuart'
                }
            }
        });
    }

    // Ngumpulin data tiap metrik
    let wqMeans = [], wqLos = [], wqHis = [];
    let lqMeans = [], lqLos = [], lqHis = [];
    let wMeans = [], wLos = [], wHis = [];
    let lMeans = [], lLos = [], lHis = [];

    for (let i = 1; i <= 4; i++) {
        let s = metrics.stages[i];
        wqMeans.push(parseFloat(s.Wq) || 0);
        let ciWq = parseCI(s.ciWq); wqLos.push(ciWq.lo); wqHis.push(ciWq.hi);

        lqMeans.push(parseFloat(s.Lq) || 0);
        let ciLq = parseCI(s.ciLq); lqLos.push(ciLq.lo); lqHis.push(ciLq.hi);

        wMeans.push(parseFloat(s.W) || 0);
        let ciW = parseCI(s.ciW); wLos.push(ciW.lo); wHis.push(ciW.hi);

        lMeans.push(parseFloat(s.L) || 0);
        let ciL = parseCI(s.ciL); lLos.push(ciL.lo); lHis.push(ciL.hi);
    }

    buildChart('chartWq', '_chartWq', wqMeans, wqLos, wqHis, 'rgb(99,102,241)', 'rgba(99,102,241,0.12)', 'min');
    buildChart('chartLq', '_chartLq', lqMeans, lqLos, lqHis, 'rgb(59,130,246)', 'rgba(59,130,246,0.12)', 'cust');
    buildChart('chartW', '_chartW', wMeans, wLos, wHis, 'rgb(16,185,129)', 'rgba(16,185,129,0.12)', 'min');
    buildChart('chartL', '_chartL', lMeans, lLos, lHis, 'rgb(245,158,11)', 'rgba(245,158,11,0.12)', 'cust');
}

// ============================================================
// CHART BUAT BANDINGIN SKENARIO
// ============================================================
function addScenarioChart(num, params, actual, des) {
    const container = document.getElementById('desScenarioCards');
    if (!container) return;

    // Palet tiap skenario (muter) — JANGAN pake ijo (#10b981)
    // soalnya ijo udah dipake buat bar Aktual
    const palettes = [
        { border: '#6366f1', bg: 'rgba(99,102,241,0.82)' },   // indigo
        { border: '#f59e0b', bg: 'rgba(245,158,11,0.82)' },   // amber
        { border: '#ef4444', bg: 'rgba(239,68,68,0.82)' },    // red
        { border: '#8b5cf6', bg: 'rgba(139,92,246,0.82)' },   // violet
        { border: '#06b6d4', bg: 'rgba(6,182,212,0.82)' },    // cyan
        { border: '#ec4899', bg: 'rgba(236,72,153,0.82)' },   // pink
        { border: '#f97316', bg: 'rgba(249,115,22,0.82)' },   // orange
        { border: '#0ea5e9', bg: 'rgba(14,165,233,0.82)' },   // sky
    ];
    const pal = palettes[(num - 1) % palettes.length];

    // Bikin HTML info param
    const srvStr = params
        ? `ST-1:${params.serversCount[1]} · ST-2:${params.serversCount[2]} · ST-3:${params.serversCount[3]} · ST-4:${params.serversCount[4]}`
        : '—';
    const paramItems = params ? [
        { label: 'REPS', val: params.reps },
        { label: 'WARMUP', val: params.warmup },
        { label: 'OBS', val: params.obs },
        { label: 'QUOTA/JAM', val: params.quota || 'Unlimited' },
        { label: 'SERVER', val: srvStr },
    ] : [];

    const paramHtml = paramItems.map(p =>
        `<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 14px;display:flex;flex-direction:column;gap:2px;">
            <div style="font-size:0.58rem;font-weight:700;letter-spacing:1px;color:#94a3b8;text-transform:uppercase;">${p.label}</div>
            <div style="font-size:0.82rem;font-weight:700;color:#1e293b;">${p.val}</div>
        </div>`
    ).join('');

    // ID kanvas unik
    const canvasTimeId = `scChart_time_${num}`;
    const canvasQueueId = `scChart_queue_${num}`;

    // Bikin kartu
    const card = document.createElement('div');
    card.className = 'p-4 rounded-4 animate-fade-in mb-4';
    card.style.cssText = `border:1px solid #e2e8f0;background:#f8fafc;`;
    card.innerHTML = `
        <!-- Header Skenario -->
        <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
            <div style="background:${pal.border};color:#fff;font-size:0.65rem;font-weight:800;letter-spacing:2px;padding:6px 14px;border-radius:20px;text-transform:uppercase;">
                Skenario ${num}
            </div>
            <div style="font-size:0.82rem;font-weight:600;color:#475569;">Overall System Metrics — Aktual vs DES</div>
            <span style="font-size:0.68rem;color:#94a3b8;margin-left:auto;">Simulasi #${num}</span>
        </div>

        <!-- Baris Param -->
        ${params ? `<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:24px;">${paramHtml}</div>` : ''}

        <!-- 2 kolom: Chart waktu + Chart antrian -->
        <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:0;align-items:stretch;">
            <!-- Metrik Waktu: Wq & W -->
            <div style="padding-right:28px;">
                <div style="font-size:0.7rem;font-weight:700;color:#64748b;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px;">
                    ⏱ Time Metrics (min) — Wq & W
                </div>
                <div style="position:relative;height:220px;">
                    <canvas id="${canvasTimeId}"></canvas>
                </div>
            </div>
            <!-- Pembatas Vertikal -->
            <div style="width:1px;background:linear-gradient(180deg,transparent 0%,#e2e8f0 15%,#e2e8f0 85%,transparent 100%);margin:0 4px;"></div>
            <!-- Metrik Antrian: Lq & L -->
            <div style="padding-left:28px;">
                <div style="font-size:0.7rem;font-weight:700;color:#64748b;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px;">
                    👥 Queue Length (cust) — Lq & L
                </div>
                <div style="position:relative;height:220px;">
                    <canvas id="${canvasQueueId}"></canvas>
                </div>
            </div>
        </div>

        <!-- Keterangan -->
        <div style="display:flex;gap:20px;margin-top:16px;font-size:0.7rem;color:#64748b;">
            <span>
                <span style="display:inline-block;width:14px;height:10px;background:rgba(16,185,129,0.8);border-radius:3px;vertical-align:middle;margin-right:4px;"></span>
                Aktual
            </span>
            <span>
                <span style="display:inline-block;width:14px;height:10px;background:${pal.bg};border-radius:3px;vertical-align:middle;margin-right:4px;"></span>
                DES Skenario ${num}
            </span>
        </div>
    `;
    container.appendChild(card);

    // Bikin chart waktu: Wq & W
    const chartTimeCtx = document.getElementById(canvasTimeId);
    const chartQueueCtx = document.getElementById(canvasQueueId);

    const commonOptions = (unit) => ({
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1e293b',
                titleColor: '#f1f5f9',
                bodyColor: '#cbd5e1',
                padding: 12,
                cornerRadius: 10,
                callbacks: {
                    label: ctx => ` ${ctx.dataset.label}: ${Number(ctx.raw).toFixed(4)} ${unit}`
                }
            }
        },
        scales: {
            x: {
                grid: { display: false }, border: { display: false },
                ticks: { color: '#64748b', font: { size: 11, weight: '600' } }
            },
            y: {
                grid: { color: 'rgba(148,163,184,0.15)' }, border: { display: false },
                ticks: {
                    color: '#64748b', font: { size: 10 }, maxTicksLimit: 6,
                    callback: v => v.toFixed(2)
                },
                beginAtZero: true
            }
        },
        animation: { duration: 650, easing: 'easeInOutQuart' }
    });

    const makeGrouped = (ctx, labels, datasets, unit) => new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: datasets.map(d => ({
                ...d,
                borderRadius: 7,
                borderSkipped: false,
                barPercentage: 0.6,
                categoryPercentage: 0.75
            }))
        },
        options: commonOptions(unit)
    });

    const chTime = makeGrouped(chartTimeCtx,
        ['Wq', 'W'],
        [
            { label: 'Aktual', data: [actual.Wq, actual.W], backgroundColor: 'rgba(16,185,129,0.82)', borderColor: '#10b981', borderWidth: 2 },
            { label: `DES Skenario ${num}`, data: [des.Wq, des.W], backgroundColor: pal.bg, borderColor: pal.border, borderWidth: 2 }
        ],
        'min'
    );

    const chQueue = makeGrouped(chartQueueCtx,
        ['Lq', 'L'],
        [
            { label: 'Aktual', data: [actual.Lq, actual.L], backgroundColor: 'rgba(16,185,129,0.82)', borderColor: '#10b981', borderWidth: 2 },
            { label: `DES Skenario ${num}`, data: [des.Lq, des.L], backgroundColor: pal.bg, borderColor: pal.border, borderWidth: 2 }
        ],
        'cust'
    );

    _scenarioCharts.push(chTime, chQueue);
}

function renderActualMetrics(sessionData) {
    // Pake serversCount default 1 (kondisi aktual lapangan biasanya 1 per stage, atau disesuaikan)
    let serversCount = { 1: 1, 2: 1, 3: 1, 4: 1 };
    let actuals = {};
    for (let stage = 1; stage <= 4; stage++) {
        let inters = [];
        let servs = [];
        let lastArrival = null;

        // Kelompokin waktu aktual
        sessionData.forEach(d => {
            let h = d.history.find(st => st.stage === stage);
            if (h) {
                if (h.masuk_stage && h.keluar_stage) {
                    servs.push(toMin(h.keluar_stage - h.masuk_stage));
                }
                if (h.masuk_queue) {
                    if (lastArrival !== null) {
                        inters.push(toMin(h.masuk_queue - lastArrival));
                    }
                    lastArrival = h.masuk_queue;
                }
            }
        });

        let meanAi = calculateMean(inters);
        let stdAi = calculateStdDev(inters, meanAi);
        let meanSi = calculateMean(servs);
        let stdSi = calculateStdDev(servs, meanSi);

        let lambda = meanAi > 0 ? (1 / meanAi) : 0;
        let mu = meanSi > 0 ? (1 / meanSi) : 0;

        actuals[stage] = {
            Ai: meanAi,
            SigmaAi: stdAi,
            CAi: meanAi > 0 ? (stdAi / meanAi) : 0,
            Si: meanSi,
            SigmaSi: stdSi,
            CSi: meanSi > 0 ? (stdSi / meanSi) : 0,
            lambda: lambda,
            mu: mu,
            rho: mu > 0 ? (lambda / ((serversCount[stage] || 1) * mu)) : 0 // Gunakan server aktual (default 1)
        };
    }

    let body = '';
    for (let i = 1; i <= 4; i++) {
        let s = actuals[i];
        body += `<tr>
            <td class="fw-bold">Tahap ${i}</td>
            <td>${formatNum(s.Ai)}</td>
            <td>${formatNum(s.SigmaAi)}</td>
            <td class="${getCVClass(s.CAi)}">${formatNum(s.CAi)}</td>
            <td>${formatNum(s.Si)}</td>
            <td>${formatNum(s.SigmaSi)}</td>
            <td class="${getCVClass(s.CSi)}">${formatNum(s.CSi)}</td>
            <td>${formatNum(s.lambda)}</td>
            <td><span class="${getUtilizationClass(s.rho)}">${formatNum(s.rho)}</span></td>
            <td>${formatNum(s.mu)}</td>
        </tr>`;
    }
    document.getElementById('actualParamsBody').innerHTML = body;

    // ============================================================
    // TAMBAHAN: Isi kartu Kondisi Awal & tabel tiap tahap
    // Ngitungnya pake data aktual dari sessionData
    // NGGAK ngubah apapun di atas — cuma nambahin output baru
    // ============================================================

    // Ngitung rata-rata tunggu & pelayanan tiap tahap dari data aktual
    let initWaitPerStage = { 1: [], 2: [], 3: [], 4: [] };
    let initServicePerStage = { 1: [], 2: [], 3: [], 4: [] };

    sessionData.forEach(d => {
        d.history.forEach(h => {
            if (h.stage >= 1 && h.stage <= 4) {
                if (h.masuk_queue && h.masuk_stage) {
                    let wt = toMin(h.masuk_stage - h.masuk_queue);
                    if (wt >= 0) initWaitPerStage[h.stage].push(wt);
                }
                if (h.masuk_stage && h.keluar_stage) {
                    let srv = toMin(h.keluar_stage - h.masuk_stage);
                    if (srv >= 0) initServicePerStage[h.stage].push(srv);
                }
            }
        });
    });

    // Performa Tiap Tahap — tabel Kondisi Awal
    let initStageHtml = '';
    for (let i = 1; i <= 4; i++) {
        let avgWait = initWaitPerStage[i].length > 0 ? calculateMean(initWaitPerStage[i]) : 0;
        let avgServ = initServicePerStage[i].length > 0 ? calculateMean(initServicePerStage[i]) : 0;
        initStageHtml += `<tr>
            <td class="fw-bold text-success">Tahap ${i}</td>
            <td class="text-success fw-semibold">${formatDurationH(avgWait)}</td>
            <td class="text-info fw-semibold">${formatDurationH(avgServ)}</td>
        </tr>`;
    }
    let initStageBodyEl = document.getElementById('initStageBody');
    if (initStageBodyEl) initStageBodyEl.innerHTML = initStageHtml;

    // Metrik Sistem Keseluruhan — kartu Kondisi Awal
    // Wq = rata-rata total waktu tunggu tiap pasien (jumlah semua tahap)
    // W = rata-rata total waktu di sistem tiap pasien (tunggu + pelayanan semua tahap)
    let wqSum = 0, wSum = 0, count = 0;
    sessionData.forEach(d => {
        let patWq = 0, patW = 0, hasData = false;
        d.history.forEach(h => {
            if (h.masuk_queue && h.masuk_stage) {
                patWq += toMin(h.masuk_stage - h.masuk_queue);
                hasData = true;
            }
            if (h.masuk_queue && h.keluar_stage) {
                patW += toMin(h.keluar_stage - h.masuk_queue);
            }
        });
        if (hasData) { wqSum += patWq; wSum += patW; count++; }
    });

    let initWq = count > 0 ? (wqSum / count) : 0;
    let initW = count > 0 ? (wSum / count) : 0;
    // Lq & L pake Little's Law: L = λ × W (pake lambda tahap 1)
    let lam1 = actuals[1].lambda;
    let initLq = lam1 * initWq;
    let initL = lam1 * initW;

    // Simpen ke global biar bisa dipake di chart skenario
    _actualOverallMetrics = { Wq: initWq, W: initW, Lq: initLq, L: initL };

    let elIWq = document.getElementById('initWq');
    let elIW = document.getElementById('initW');
    let elILq = document.getElementById('initLq');
    let elIL = document.getElementById('initL');
    if (elIWq) elIWq.innerText = formatDurationH(initWq);
    if (elIW) elIW.innerText = formatDurationH(initW);
    if (elILq) elILq.innerText = formatNum(initLq) + ' cust';
    if (elIL) elIL.innerText = formatNum(initL) + ' cust';
}

function renderInitialDESResults(metrics) {
    let elIWq = document.getElementById('initWq');
    let elIW = document.getElementById('initW');
    let elILq = document.getElementById('initLq');
    let elIL = document.getElementById('initL');
    if (elIWq) elIWq.innerText = formatDurationH(metrics.Wq);
    if (elIW) elIW.innerText = formatDurationH(metrics.W);
    if (elILq) elILq.innerText = formatNum(metrics.Lq) + ' cust';
    if (elIL) elIL.innerText = formatNum(metrics.L) + ' cust';

    let initStageHtml = '';
    for (let i = 1; i <= 4; i++) {
        initStageHtml += `<tr>
            <td class="fw-bold text-success">Tahap ${i}</td>
            <td class="text-success fw-semibold">${formatDurationH(metrics.stages[i].avgWait)}</td>
            <td class="text-info fw-semibold">${formatDurationH(metrics.stages[i].avgService)}</td>
        </tr>`;
    }
    let initStageBodyEl = document.getElementById('initStageBody');
    if (initStageBodyEl) initStageBodyEl.innerHTML = initStageHtml;
}

