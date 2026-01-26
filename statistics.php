<?php
session_start();
include 'db.php';

// Protect the page
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Weekly revenue
$weekly_sql = "
    SELECT 
        u.username,
        DATE(o.delivery_date) AS day,
        SUM(o.total + o.avans) AS revenue
    FROM orders o
    JOIN users u ON o.assigned_to = u.user_id
    WHERE o.status = 'delivered'
      AND o.delivery_date >= '2025-12-01'
    GROUP BY u.user_id, DATE(o.delivery_date)
    ORDER BY day ASC;
";

$weekly_result = $conn->query($weekly_sql);

$weekly_raw = [];
while ($row = $weekly_result->fetch_assoc()) {
    $weekly_raw[] = [
        'username' => $row['username'],
        'day'      => $row['day'],
        'revenue'  => (float)$row['revenue']
    ];
}

/* ------------------ Revenue per User chart ------------------ */
$revenue_sql = "
    SELECT u.username,
           DATE(o.delivery_date) AS day,
           SUM(o.total + o.avans) AS total_revenue
    FROM orders o
    JOIN users u ON o.assigned_to = u.user_id
    WHERE o.status = 'delivered'
      AND o.delivery_date >= '2025-12-01'
    GROUP BY u.user_id, DATE(o.delivery_date)
    ORDER BY day ASC;
";
$revenue_result = $conn->query($revenue_sql);

$revenue_data = [];
while ($row = $revenue_result->fetch_assoc()) {
    $revenue_data[$row['username']][] = [
        'x' => $row['day'],
        'y' => (float)$row['total_revenue']
    ];
}

$revenue_series = [];
foreach ($revenue_data as $username => $dataPoints) {
    $revenue_series[] = [
        'name' => $username,
        'data' => $dataPoints
    ];
}

/* ------------------ PIE CHART: Delivered Orders by User ------------------ */
$delivered_sql = "
    SELECT u.username, COUNT(o.order_id) AS delivered_count
FROM orders o
JOIN users u ON o.assigned_to = u.user_id
WHERE o.status = 'delivered'
  AND o.delivery_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
GROUP BY u.user_id
ORDER BY delivered_count DESC;
";
$result = $conn->query($delivered_sql);
$labels = [];
$series = [];
while ($row = $result->fetch_assoc()) {
    $labels[] = $row['username'];
    $series[] = (int)$row['delivered_count'];
}

?>

<!DOCTYPE html>
<html lang="ro">

<head>
    <meta charset="UTF-8">
    <title>Statistici Comenzi</title>
    <link rel="stylesheet" href="styles.css">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: #f9f9f9;
            margin: 0;
            padding: 0;
        }

        .stats-container {
            max-width: 1000px;
            margin: 40px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }

        h1 {
            text-align: center;
            margin: 0 auto 30px;
            color: #333;
        }

        .chart-box {
            margin: 40px 0;
        }
    </style>
    <!-- Weekly revenue chart JS -->
    <script>
        const weeklyRaw = <?php echo json_encode($weekly_raw); ?>;

        const weeklyMap = {};

        weeklyRaw.forEach(row => {
            const date = new Date(row.day); // local date
            const {
                year,
                week,
                weekStart
            } = getISOWeekWithStart(date);

            const key = weekStart.getTime(); // timestamp for Monday of that ISO week

            if (!weeklyMap[row.username]) weeklyMap[row.username] = {};
            if (!weeklyMap[row.username][key]) weeklyMap[row.username][key] = 0;

            weeklyMap[row.username][key] += row.revenue;
        });

        // Collect all week keys (timestamps)
        const allWeeks = [...new Set(
            Object.values(weeklyMap).flatMap(u => Object.keys(u))
        )].map(Number).sort((a, b) => a - b);

        // Build ApexCharts series
        const weeklySeries = Object.entries(weeklyMap).map(([username, weeks]) => ({
            name: username,
            data: allWeeks.map(w => ({
                x: w, // timestamp
                y: weeks[w] || 0
            }))
        }));

        function getISOWeekWithStart(date) {
            const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
            const dayNum = d.getUTCDay() || 7;

            // Move to Thursday of this week
            d.setUTCDate(d.getUTCDate() + 4 - dayNum);

            const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
            const weekNo = Math.ceil((((d - yearStart) / 86400000) + 1) / 7);

            // Now compute Monday of this ISO week
            const weekStart = new Date(d);
            weekStart.setUTCDate(d.getUTCDate() - 3); // Thursday - 3 days = Monday

            return {
                year: d.getUTCFullYear(),
                week: weekNo,
                weekStart
            };
        }
    </script>
    <header class="no-print" id="header">
        <button class="no-print" onclick="window.history.back()">
            <i class="fa-solid fa-chevron-left"></i> Înapoi la panou comenzi
        </button>
    </header>
</head>

<body>
    <div class="stats-container">
        <h1>📊 Statistici Comenzi</h1>

        <!-- Revenue Race Chart -->
        <div class="chart-box">
            <h2>🏁 Cursa banilor – Venituri pe utilizator</h2>
            <div id="revenueRace"></div>
        </div>

        <!-- Weekly Revenue Chart -->
        <div class="chart-box">
            <h2>Venituri pe săptămână (pe utilizator)</h2>
            <div id="weeklyRevenue"></div>
        </div>

        <!-- Pie Chart -->
        <div class="chart-box">
            <h2>Comenzi livrate pe utilizator în ultimele 3 luni</h2>
            <div id="ordersPie"></div>
        </div>

    </div>

    <script>
        const userColors = {
            "Bogdan": "limegreen",
            "Bob": "yellow",
            "Stefan": "steelblue",
            "Adrian": "firebrick",
            "Seby": "black",
            "Petronela": "violet"
        };

        // Build colors array aligned to series order
        const revenueSeries = <?php echo json_encode($revenue_series); ?>;
        const revenueColors = revenueSeries.map(s => userColors[s.name] || "gray");

        new ApexCharts(document.querySelector("#revenueRace"), {
            chart: {
                type: 'line',
                background: '#fff',
                zoom: {
                    enabled: false
                }
            },
            series: revenueSeries,
            xaxis: {
                type: 'datetime',
                labels: {
                    rotate: -45,
                    format: 'dd MMM'
                }
            },
            yaxis: {
                title: {
                    text: 'Venituri (RON)'
                }
            },
            colors: revenueColors,
            stroke: {
                width: 3,
                curve: 'smooth'
            },
            markers: {
                size: 4
            },
            legend: {
                position: 'bottom'
            },
            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: val => val.toLocaleString('ro-RO') + " RON"
                }
            }
        }).render();


        // Weekly series from PHP
        const weeklyColors = weeklySeries.map(s => userColors[s.name] || "gray");

        const formatYearWeek = (ts) => {
            const d = new Date(ts);
            const {
                year,
                week
            } = getISOWeekWithStart(d);
            return `Săptămâna ${String(week).padStart(2, '0')} ${year}`;
        };

        new ApexCharts(document.querySelector("#weeklyRevenue"), {
            chart: {
                type: 'line',
                background: '#fff',
                zoom: {
                    enabled: false
                }
            },
            series: weeklySeries,
            colors: weeklyColors,
            xaxis: {
                type: 'datetime',
                tickAmount: allWeeks.length, // force one tick per week
                labels: {
                    rotate: -45,
                    formatter: formatYearWeek
                }
            },
            yaxis: {
                title: {
                    text: 'Venituri (RON)'
                }
            },
            stroke: {
                width: 3,
                curve: 'smooth'
            },
            markers: {
                size: 4
            },
            legend: {
                position: 'bottom'
            },
            tooltip: {
                shared: true,
                intersect: false,
                x: {
                    formatter: (val) => formatYearWeek(val)
                },
                y: {
                    formatter: val => val.toLocaleString('ro-RO') + " RON"
                }
            }
        }).render();

        // Make labels available to JS
        const pieLabels = <?php echo json_encode($labels); ?>;

        // Build colors array per label, no function here
        const pieColors = pieLabels.map(name => userColors[name] || "gray");

        new ApexCharts(document.querySelector("#ordersPie"), {
            chart: {
                type: 'pie',
                background: '#fff'
            },
            series: <?php echo json_encode($series); ?>,
            labels: pieLabels,
            colors: pieColors, // <- array, not a function
            legend: {
                position: 'bottom'
            }
        }).render();
    </script>
</body>

</html>