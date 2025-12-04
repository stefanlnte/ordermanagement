<?php
session_start();
include 'db.php';

// Protect the page
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

/* ------------------ LINE CHART: Revenue per User ------------------ */
$revenue_sql = "
    SELECT u.username, DATE(o.order_date) AS day, SUM(o.total + o.avans) AS total_revenue
    FROM orders o
    JOIN users u ON o.assigned_to = u.user_id
    WHERE o.status = 'delivered'
    GROUP BY u.user_id, DATE(o.order_date)
    ORDER BY day ASC
";
$revenue_result = $conn->query($revenue_sql);

$revenue_data = [];
while ($row = $revenue_result->fetch_assoc()) {
    $revenue_data[$row['username']][] = [
        'x' => $row['day'] . "T00:00:00",
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

/* ------------------ AREA CHART ------------------ */
$area_sql = "
    SELECT u.username, DATE(o.order_date) AS day, COUNT(o.order_id) AS delivered_count
    FROM orders o
    JOIN users u ON o.assigned_to = u.user_id
    WHERE o.status = 'delivered'
    GROUP BY u.user_id, DATE(o.order_date)
    ORDER BY day ASC
";
$area_result = $conn->query($area_sql);

$area_data = [];
while ($row = $area_result->fetch_assoc()) {
    $area_data[$row['username']][] = [
        // format as ISO datetime for ApexCharts
        'x' => $row['day'] . "T00:00:00",
        'y' => (int)$row['delivered_count']
    ];
}

$area_series = [];
foreach ($area_data as $username => $dataPoints) {
    $area_series[] = [
        'name' => $username,
        'data' => $dataPoints
    ];
}

/* ------------------ LINE CHART: Orders per Day ------------------ */
$line_sql = "
    SELECT DATE(o.order_date) AS order_day, COUNT(*) AS total_orders
    FROM orders o
    WHERE o.status <> 'cancelled'
    GROUP BY DATE(o.order_date)
    ORDER BY order_day ASC;
";
$line_result = $conn->query($line_sql);
$line_dates = [];
$line_counts = [];
while ($row = $line_result->fetch_assoc()) {
    $line_dates[] = $row['order_day'];
    $line_counts[] = (int)$row['total_orders'];
}

/* ------------------ CLIENT CHART: Most Loyal Clients ------------------ */
$clients_sql = "
    SELECT c.client_name, COUNT(o.order_id) AS delivered_orders_count
    FROM orders o
    JOIN clients c ON o.client_id = c.client_id
    WHERE o.status = 'delivered'
      AND c.client_name NOT IN ('Test','Ciprian','Bogdan Bacosca','Leonte Stefan','Bob','Alexandra Gherasimescu','Bogdan Boss')
    GROUP BY c.client_id, c.client_name
    ORDER BY delivered_orders_count DESC
    LIMIT 20
";
$clients_result = $conn->query($clients_sql);

$client_labels = [];
$client_counts = [];
while ($row = $clients_result->fetch_assoc()) {
    $client_labels[] = $row['client_name'];
    $client_counts[] = (int)$row['delivered_orders_count'];
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
            max-width: 1200px;
            margin: 40px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }

        h1 {
            text-align: center;
            margin-bottom: 30px;
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

        <!-- Pie Chart -->
        <div class="chart-box">
            <h2>Comenzi livrate pe utilizator</h2>
            <div id="ordersPie"></div>
        </div>

        <!-- Stacked area Chart -->
        <div class="chart-box">
            <h2>Evoluția comenzilor livrate pe utilizator</h2>
            <div id="ordersArea"></div>
        </div>

        <!-- Line Chart -->
        <div class="chart-box">
            <h2>Comenzi pe zi</h2>
            <div id="ordersLine"></div>
        </div>

        <!-- Loyal Clients Chart -->
        <div class="chart-box">
            <h2>Cei mai fideli clienți (Top 20)</h2>
            <div id="loyalClients"></div>
        </div>

    </div>

    <script>
        /* REVENUE RACE LINE CHART */
        new ApexCharts(document.querySelector("#revenueRace"), {
            chart: {
                type: 'line',
                background: '#fff'
            },
            series: <?php echo json_encode($revenue_series); ?>,
            xaxis: {
                type: 'datetime',
                labels: {
                    rotate: -45,
                    format: 'dd MMM'
                }
            },
            yaxis: {
                title: {
                    text: 'Valoare comenzi (RON)'
                }
            },
            colors: [
                '#FF9800', // portocaliu
                '#4CAF50', // verde
                '#2196F3', // albastru
                '#9C27B0', // mov
                '#E91E63', // roz
                '#00BCD4', // turcoaz
                '#FFD700' // galben auriu
            ],
            legend: {
                position: 'bottom'
            },
            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: function(val) {
                        return val.toLocaleString('ro-RO') + " RON";
                    }
                }
            }
        }).render();
        /* PIE CHART */
        new ApexCharts(document.querySelector("#ordersPie"), {
            chart: {
                type: 'pie',
                background: '#fff'
            },
            series: <?php echo json_encode($series); ?>,
            labels: <?php echo json_encode($labels); ?>,
            colors: ['#FFD700', '#4CAF50', '#2196F3', '#9C27B0', '#FF5722', '#00BCD4', '#E91E63'],
            legend: {
                position: 'bottom'
            }
        }).render();

        /* STACKED BAR CHART: Delivered Orders Per Day by User */
        new ApexCharts(document.querySelector("#ordersArea"), {
            chart: {
                type: 'bar',
                background: '#fff',
                stacked: true
            },
            series: <?php echo json_encode($area_series); ?>,
            xaxis: {
                type: 'datetime',
                labels: {
                    rotate: -45,
                    format: 'dd MMM'
                }
            },
            dataLabels: {
                enabled: false
            },
            colors: ['#2196F3', '#4CAF50', '#FF5722', '#9C27B0', '#00BCD4'],
            legend: {
                position: 'bottom'
            },
            tooltip: {
                shared: true,
                intersect: false
            },
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: '70%'
                }
            }
        }).render();

        /* LINE CHART */
        new ApexCharts(document.querySelector("#ordersLine"), {
            chart: {
                type: 'line',
                background: '#fff'
            },
            series: [{
                name: 'Comenzi',
                data: <?php echo json_encode($line_counts); ?>
            }],
            xaxis: {
                categories: <?php echo json_encode($line_dates); ?>
            }
        }).render();

        /* LOYAL CLIENTS CHART */
        new ApexCharts(document.querySelector("#loyalClients"), {
            chart: {
                type: 'bar',
                background: '#fff'
            },
            series: [{
                name: 'Comenzi',
                data: <?php echo json_encode($client_counts); ?>
            }],
            xaxis: {
                categories: <?php echo json_encode($client_labels); ?>
            },
            colors: ['#2196F3']
        }).render();
    </script>
</body>

</html>