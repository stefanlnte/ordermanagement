<?php
session_start();
include 'db.php';

// Protect the page
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
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

/* ------------------ LINE CHART: Orders per Day ------------------ */
$line_sql = "
    SELECT DATE(o.due_date) AS order_day, COUNT(*) AS total_orders
    FROM orders o
    GROUP BY DATE(o.due_date)
    ORDER BY order_day ASC
";
$line_result = $conn->query($line_sql);
$line_dates = [];
$line_counts = [];
while ($row = $line_result->fetch_assoc()) {
    $line_dates[] = $row['order_day'];
    $line_counts[] = (int)$row['total_orders'];
}

/* ------------------ STACKED CHART: Status Counts ------------------ */
$status_sql = "
    SELECT o.status, COUNT(*) AS count
    FROM orders o
    GROUP BY o.status
";
$status_result = $conn->query($status_sql);
$status_labels = [];
$status_counts = [];
while ($row = $status_result->fetch_assoc()) {
    $status_labels[] = $row['status'];
    $status_counts[] = (int)$row['count'];
}

/* ------------------ CLIENT CHART: Most Loyal Clients ------------------ */
$clients_sql = "
    SELECT c.client_name, COUNT(o.order_id) AS orders_count
    FROM orders o
    JOIN clients c ON o.client_id = c.client_id
    GROUP BY c.client_id
    ORDER BY orders_count DESC
    LIMIT 10
";
$clients_result = $conn->query($clients_sql);
$client_labels = [];
$client_counts = [];
while ($row = $clients_result->fetch_assoc()) {
    $client_labels[] = $row['client_name'];
    $client_counts[] = (int)$row['orders_count'];
}

/* ------------------ CLIENT VALUE CHART: Total Value Locked ------------------ */
// optional: only count delivered
/* ------------------ CLIENT VALUE CHART: Total Value Locked ------------------ */
$tvl_sql = "
    SELECT c.client_name, SUM(o.total) AS total_value_locked
    FROM orders o
    JOIN clients c ON o.client_id = c.client_id
    WHERE o.status = 'delivered'
    GROUP BY c.client_id
    ORDER BY total_value_locked DESC
    LIMIT 10
";
$tvl_result = $conn->query($tvl_sql);

if (!$tvl_result) {
    die("TVL query failed: " . $conn->error);
}

$tvl_labels = [];
$tvl_values = [];
while ($row = $tvl_result->fetch_assoc()) {
    $tvl_labels[] = $row['client_name'];
    $tvl_values[] = (float)$row['total_value_locked'];
}
$tvl_result = $conn->query($tvl_sql);

$tvl_labels = [];
$tvl_values = [];
while ($row = $tvl_result->fetch_assoc()) {
    $tvl_labels[] = $row['client_name'];
    $tvl_values[] = (float)$row['total_value_locked'];
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
        <button class="no-print" onclick="window.location.href='dashboard.php'">
            <i class="fa-solid fa-chevron-left"></i> Înapoi la panou comenzi
        </button>
    </header>
</head>

<body>
    <div class="stats-container">
        <h1>📊 Statistici Comenzi</h1>

        <!-- Pie Chart -->
        <div class="chart-box">
            <h2>Comenzi livrate pe utilizator</h2>
            <div id="ordersPie"></div>
        </div>

        <!-- Line Chart -->
        <div class="chart-box">
            <h2>Comenzi pe zi</h2>
            <div id="ordersLine"></div>
        </div>

        <!-- Stacked Chart -->
        <div class="chart-box">
            <h2>Status comenzi</h2>
            <div id="statusStacked"></div>
        </div>

        <!-- Loyal Clients Chart -->
        <div class="chart-box">
            <h2>Cei mai fideli clienți (Top 10)</h2>
            <div id="loyalClients"></div>
        </div>

        <div class="chart-box">
            <h2>Total Value Locked (Top 10 Clienți)</h2>
            <div id="tvlChart"></div>
        </div>

    </div>

    <script>
        /* TOTAL VALUE LOCKED CHART */
        new ApexCharts(document.querySelector("#tvlChart"), {
            chart: {
                type: 'bar',
                background: '#fff'
            },
            series: [{
                name: 'Valoare totală',
                data: <?php echo json_encode($tvl_values); ?>
            }],
            xaxis: {
                categories: <?php echo json_encode($tvl_labels); ?>
            },
            colors: ['#00BCD4']
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

        /* STACKED CHART */
        new ApexCharts(document.querySelector("#statusStacked"), {
            chart: {
                type: 'bar',
                stacked: true,
                background: '#fff'
            },
            series: [{
                name: 'Comenzi',
                data: <?php echo json_encode($status_counts); ?>
            }],
            xaxis: {
                categories: <?php echo json_encode($status_labels); ?>
            },
            colors: ['#4CAF50', '#FF5722', '#FFD700', '#9C27B0']
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