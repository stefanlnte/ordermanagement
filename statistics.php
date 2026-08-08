<?php
session_start();
include 'db.php';

// Protect the page
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

/* =========================================================
   HELPER: Generate continuous date ranges
   ========================================================= */
function getDateRange(string $start, string $end, string $interval = 'P1D'): array
{
    $dates = [];
    $current = new DateTime($start);
    $endDate = new DateTime($end);
    $period = new DatePeriod($current, new DateInterval($interval), $endDate->modify('+1 day'));
    foreach ($period as $dt) {
        $dates[] = $dt->format('Y-m-d');
    }
    return $dates;
}

/* =========================================================
   1. DAILY REVENUE (last 30 days) - for the race chart
   ========================================================= */
$daily_sql = "
    SELECT 
        u.username,
        DATE(o.delivery_date) AS day,
        SUM(o.total + o.avans) AS revenue
    FROM orders o
    JOIN users u ON o.assigned_to = u.user_id
    WHERE o.status = 'delivered'
      AND o.delivery_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY u.user_id, DATE(o.delivery_date)
    ORDER BY day ASC
";
$daily_result = $conn->query($daily_sql);

$daily_map = []; // username => [day => revenue]
while ($row = $daily_result->fetch_assoc()) {
    $daily_map[$row['username']][$row['day']] = (float)$row['revenue'];
}

// Continuous last 30 days
$daily_days = [];
for ($i = 29; $i >= 0; $i--) {
    $daily_days[] = date('Y-m-d', strtotime("-$i days"));
}

$daily_series = [];
foreach ($daily_map as $username => $days) {
    $data = [];
    foreach ($daily_days as $day) {
        $data[] = [
            'x' => $day,
            'y' => $days[$day] ?? 0
        ];
    }
    $daily_series[] = [
        'name' => $username,
        'data' => $data
    ];
}

/* =========================================================
   2. WEEKLY REVENUE (last 25 ISO weeks)
   ========================================================= */
$weekly_sql = "
    SELECT 
        u.username,
        YEARWEEK(o.delivery_date, 3) AS yw,          -- ISO week (mode 3)
        MIN(DATE(o.delivery_date)) AS any_day,       -- for week start calculation
        SUM(o.total + o.avans) AS revenue
    FROM orders o
    JOIN users u ON o.assigned_to = u.user_id
    WHERE o.status = 'delivered'
      AND o.delivery_date >= DATE_SUB(CURDATE(), INTERVAL 200 DAY)
    GROUP BY u.user_id, YEARWEEK(o.delivery_date, 3)
    ORDER BY yw ASC
";
$weekly_result = $conn->query($weekly_sql);

$weekly_map = []; // username => [week_start => revenue]
while ($row = $weekly_result->fetch_assoc()) {
    // Calculate Monday of that ISO week
    $date = new DateTime($row['any_day']);
    $dayOfWeek = (int)$date->format('N'); // 1 (Mon) - 7 (Sun)
    $date->modify('-' . ($dayOfWeek - 1) . ' days');
    $weekStart = $date->format('Y-m-d');

    $weekly_map[$row['username']][$weekStart] = (float)$row['revenue'];
}

// Generate last 25 ISO week starts (Mondays)
$weekly_starts = [];
$current = new DateTime();
$current->modify('monday this week'); // most recent Monday
for ($i = 0; $i < 25; $i++) {
    $weekly_starts[] = $current->format('Y-m-d');
    $current->modify('-7 days');
}
$weekly_starts = array_reverse($weekly_starts); // oldest → newest

$weekly_series = [];
foreach ($weekly_map as $username => $weeks) {
    $data = [];
    foreach ($weekly_starts as $ws) {
        $data[] = [
            'x' => $ws,
            'y' => $weeks[$ws] ?? 0
        ];
    }
    $weekly_series[] = [
        'name' => $username,
        'data' => $data
    ];
}

/* =========================================================
   3. MONTHLY REVENUE (last 12 months)
   ========================================================= */
$monthly_sql = "
    SELECT 
        u.username,
        DATE_FORMAT(o.delivery_date, '%Y-%m-01') AS month_start,
        SUM(o.total + o.avans) AS revenue
    FROM orders o
    JOIN users u ON o.assigned_to = u.user_id
    WHERE o.status = 'delivered'
      AND o.delivery_date >= DATE_SUB(CURDATE(), INTERVAL 13 MONTH)
    GROUP BY u.user_id, DATE_FORMAT(o.delivery_date, '%Y-%m')
    ORDER BY month_start ASC
";
$monthly_result = $conn->query($monthly_sql);

$monthly_map = []; // username => [month_start => revenue]
while ($row = $monthly_result->fetch_assoc()) {
    $monthly_map[$row['username']][$row['month_start']] = (float)$row['revenue'];
}

// Continuous last 12 months
$monthly_starts = [];
$current = new DateTime('first day of this month');
for ($i = 0; $i < 12; $i++) {
    $monthly_starts[] = $current->format('Y-m-d');
    $current->modify('-1 month');
}
$monthly_starts = array_reverse($monthly_starts);

$monthly_series = [];
foreach ($monthly_map as $username => $months) {
    $data = [];
    foreach ($monthly_starts as $ms) {
        $data[] = [
            'x' => $ms,
            'y' => $months[$ms] ?? 0
        ];
    }
    $monthly_series[] = [
        'name' => $username,
        'data' => $data
    ];
}

/* =========================================================
   4. PIE: Delivered orders count (last 90 days)
   ========================================================= */
$delivered_sql = "
    SELECT u.username, COUNT(o.order_id) AS delivered_count
    FROM orders o
    JOIN users u ON o.assigned_to = u.user_id
    WHERE o.status = 'delivered'
      AND o.delivery_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    GROUP BY u.user_id
    ORDER BY delivered_count DESC
";
$result = $conn->query($delivered_sql);

$pie_labels = [];
$pie_series = [];
while ($row = $result->fetch_assoc()) {
    $pie_labels[] = $row['username'];
    $pie_series[] = (int)$row['delivered_count'];
}

/* =========================================================
   User colors (consistent across all charts)
   ========================================================= */
$userColors = [
    "Bogdan"    => "#32CD32", // limegreen
    "Bob"       => "#FFD700", // gold
    "Stefan"    => "#4682B4", // steelblue
    "Adrian"    => "#B22222", // firebrick
    "Seby"      => "#1a1a1a", // near black
    "Petronela" => "#EE82EE", // violet
];

function getColorArray(array $series, array $userColors): array
{
    $colors = [];
    foreach ($series as $s) {
        $colors[] = $userColors[$s['name']] ?? "#888888";
    }
    return $colors;
}
?>
<!DOCTYPE html>
<html lang="ro">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistici Comenzi</title>
    <link rel="stylesheet" href="styles.css">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        * {
            box-sizing: border-box;
        }

        @font-face {
            font-family: 'Poppins';
            font-style: normal;
            font-weight: 400;
            src: url('https://color-print.ro/magazincp/fonts/Poppins-Regular.ttf') format('truetype');
        }

        html,
        body {
            margin: 30px;
            font-family: 'Poppins', sans-serif;
            background-color: white;
            color: #1a1a1a;
            z-index: 1;
            -ms-overflow-style: none;
            /* IE and Edge */
            scrollbar-width: none;
            /* Firefox */
        }

        html::-webkit-scrollbar,
        body::-webkit-scrollbar {
            display: none;
            /* Chrome, Safari and Opera */
        }

        .stats-container {
            max-width: 1100px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 32px 40px;
        }

        h1 {
            text-align: center;
            margin: 0 0 8px;
            font-size: 1.8rem;
            font-weight: 700;
            color: #111;
        }

        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 36px;
            font-size: 0.95rem;
        }

        .chart-box {
            margin-bottom: 48px;
            padding-bottom: 24px;
            border-bottom: 1px solid #eee;
        }

        .chart-box:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .chart-box h2 {
            font-size: 1.15rem;
            font-weight: 600;
            margin: 0 0 16px;
            color: #222;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-box h2 span {
            font-size: 0.85rem;
            font-weight: 400;
            color: #888;
        }

        #revenueRace,
        #weeklyRevenue,
        #monthlyRevenue,
        #ordersPie {
            min-height: 320px;
        }

        @media (max-width: 640px) {
            .stats-container {
                padding: 20px 16px;
            }

            h1 {
                font-size: 1.4rem;
            }
        }
    </style>
</head>

<body>
    <div class="stats-container">
        <h1>📊 Statistici Comenzi</h1>
        <p class="subtitle">Date actualizate automat • doar comenzi livrate</p>

        <!-- 1. Daily Race -->
        <div class="chart-box">
            <h2>🏁 Cursa banilor <span>– venituri zilnice, ultimele 30 zile</span></h2>
            <div id="revenueRace"></div>
        </div>

        <!-- 2. Weekly -->
        <div class="chart-box">
            <h2>📅 Venituri pe săptămână <span>– ultimele 25 săptămâni (ISO)</span></h2>
            <div id="weeklyRevenue"></div>
        </div>

        <!-- 3. Monthly -->
        <div class="chart-box">
            <h2>📆 Venituri pe lună <span>– ultimele 12 luni</span></h2>
            <div id="monthlyRevenue"></div>
        </div>

        <!-- 4. Pie -->
        <div class="chart-box">
            <h2>🥧 Comenzi livrate <span>– ultimele 90 zile</span></h2>
            <div id="ordersPie"></div>
        </div>
    </div>

    <script>
        // ========== Data from PHP ==========
        const dailySeries = <?php echo json_encode($daily_series, JSON_UNESCAPED_UNICODE); ?>;
        const weeklySeries = <?php echo json_encode($weekly_series, JSON_UNESCAPED_UNICODE); ?>;
        const monthlySeries = <?php echo json_encode($monthly_series, JSON_UNESCAPED_UNICODE); ?>;
        const pieLabels = <?php echo json_encode($pie_labels, JSON_UNESCAPED_UNICODE); ?>;
        const pieSeries = <?php echo json_encode($pie_series); ?>;

        const userColors = <?php echo json_encode($userColors); ?>;

        // Helper: get colors in series order
        function colorsFor(series) {
            return series.map(s => userColors[s.name] || "#888888");
        }

        // Common chart options
        const commonLineOptions = {
            chart: {
                type: 'line',
                background: 'transparent',
                toolbar: {
                    show: false
                },
                zoom: {
                    enabled: false
                },
                fontFamily: 'inherit',
                animations: {
                    enabled: true,
                    speed: 400
                }
            },
            stroke: {
                width: 3,
                curve: 'smooth'
            },
            markers: {
                size: 3,
                strokeWidth: 0,
                hover: {
                    size: 5
                }
            },
            legend: {
                position: 'bottom',
                fontSize: '13px',
                markers: {
                    width: 10,
                    height: 10,
                    radius: 10
                }
            },
            grid: {
                borderColor: '#f0f0f0',
                strokeDashArray: 3
            },
            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: val => Number(val).toLocaleString('ro-RO') + ' RON'
                }
            },
            yaxis: {
                title: {
                    text: 'Venituri (RON)',
                    style: {
                        fontSize: '12px',
                        color: '#666'
                    }
                },
                labels: {
                    formatter: val => Number(val).toLocaleString('ro-RO')
                }
            }
        };

        // ========== 1. Daily Race Chart ==========
        new ApexCharts(document.querySelector("#revenueRace"), {
            ...commonLineOptions,
            series: dailySeries,
            colors: colorsFor(dailySeries),
            xaxis: {
                type: 'datetime',
                labels: {
                    format: 'dd MMM',
                    rotate: -45,
                    style: {
                        fontSize: '11px'
                    }
                },
                tooltip: {
                    enabled: false
                }
            }
        }).render();

        // ========== 2. Weekly Chart ==========
        new ApexCharts(document.querySelector("#weeklyRevenue"), {
            ...commonLineOptions,
            series: weeklySeries,
            colors: colorsFor(weeklySeries),
            xaxis: {
                type: 'datetime',
                labels: {
                    formatter: function(val) {
                        const d = new Date(val);
                        // ISO week number
                        const tmp = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
                        const dayNum = tmp.getUTCDay() || 7;
                        tmp.setUTCDate(tmp.getUTCDate() + 4 - dayNum);
                        const yearStart = new Date(Date.UTC(tmp.getUTCFullYear(), 0, 1));
                        const weekNo = Math.ceil((((tmp - yearStart) / 86400000) + 1) / 7);
                        return `S${String(weekNo).padStart(2,'0')} ${tmp.getUTCFullYear()}`;
                    },
                    rotate: -45,
                    style: {
                        fontSize: '11px'
                    }
                },
                tooltip: {
                    enabled: false
                }
            },
            tooltip: {
                ...commonLineOptions.tooltip,
                x: {
                    formatter: function(val) {
                        const d = new Date(val);
                        return d.toLocaleDateString('ro-RO', {
                                day: 'numeric',
                                month: 'short',
                                year: 'numeric'
                            }) +
                            ' (luni)';
                    }
                }
            }
        }).render();

        // ========== 3. Monthly Chart ==========
        new ApexCharts(document.querySelector("#monthlyRevenue"), {
            ...commonLineOptions,
            series: monthlySeries,
            colors: colorsFor(monthlySeries),
            xaxis: {
                type: 'datetime',
                labels: {
                    format: 'MMM yyyy',
                    rotate: -45,
                    style: {
                        fontSize: '11px'
                    }
                },
                tooltip: {
                    enabled: false
                }
            },
            tooltip: {
                ...commonLineOptions.tooltip,
                x: {
                    formatter: val => new Date(val).toLocaleDateString('ro-RO', {
                        month: 'long',
                        year: 'numeric'
                    })
                }
            }
        }).render();

        // ========== 4. Pie Chart ==========
        new ApexCharts(document.querySelector("#ordersPie"), {
            chart: {
                type: 'pie',
                background: 'transparent',
                fontFamily: 'inherit',
                toolbar: {
                    show: false
                }
            },
            series: pieSeries,
            labels: pieLabels,
            colors: pieLabels.map(name => userColors[name] || "#888888"),
            legend: {
                position: 'bottom',
                fontSize: '13px'
            },
            dataLabels: {
                enabled: true,
                formatter: (val, opts) => {
                    const count = opts.w.config.series[opts.seriesIndex];
                    return count;
                },
                style: {
                    fontSize: '12px',
                    fontWeight: 600
                }
            },
            tooltip: {
                y: {
                    formatter: val => val + ' comenzi'
                }
            },
            responsive: [{
                breakpoint: 480,
                options: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }]
        }).render();
    </script>
</body>

</html>