<?php
session_start();
include 'db.php';

// Protect the page
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Weekly revenue SQL
$weekly_sql = "
    SELECT u.username,
           YEARWEEK(o.delivery_date, 1) AS year_week,
           SUM(o.total + o.avans) AS weekly_revenue
    FROM orders o
    JOIN users u ON o.assigned_to = u.user_id
    WHERE o.status = 'delivered'
      AND o.delivery_date >= '2025-12-01'
    GROUP BY u.user_id, YEARWEEK(o.delivery_date, 1)
    ORDER BY year_week ASC;
";

$weekly_result = $conn->query($weekly_sql);

$weeks = [];
$weekly_data = [];

// Citești rezultatele și construiești mapă pe utilizator + săptămână
while ($row = $weekly_result->fetch_assoc()) {
    $weeks[$row['year_week']] = true;
    $weekly_data[$row['username']][$row['year_week']] = (float)$row['weekly_revenue'];
}

// Obții lista completă de săptămâni
$allWeeks = array_keys($weeks);
sort($allWeeks);

$weekly_series = [];
foreach ($weekly_data as $username => $points) {
    $dataPoints = [];
    foreach ($allWeeks as $week) {
        $dataPoints[] = [
            'x' => $week,
            'y' => $points[$week] ?? 0   // dacă nu are date, pune 0
        ];
    }
    $weekly_series[] = [
        'name' => $username,
        'data' => $dataPoints
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
        'x' => $row['day'] . "T00:00:00+02:00",  // Romania offset
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
    GROUP BY u.user_id
    ORDER BY delivered_count DESC
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
            <h2>Comenzi livrate pe utilizator</h2>
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
        const weeklySeries = <?php echo json_encode($weekly_series); ?>;
        const weeklyColors = weeklySeries.map(s => userColors[s.name] || "gray");

        // Helper to display ISO year-week as "WNN YYYY"
        const formatYearWeek = (yw) => {
            const s = String(yw);
            const year = s.slice(0, 4);
            const week = s.slice(4);
            return `Săptămâna ${week} ${year}`;
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
                // We’re using numeric yearweek codes on x
                type: 'category',
                labels: {
                    rotate: -45,
                    formatter: formatYearWeek
                },
                title: {
                    text: 'Săptămâna'
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
                    formatter: formatYearWeek
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