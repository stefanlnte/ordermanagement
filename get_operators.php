<?php
/**
 * get_operators.php
 *
 * Query partajat pentru lista de operatori.
 * Presupune că $conn (mysqli) este deja definit — include acest fișier
 * DUPĂ db.php.
 *
 * Rezultat: $operators (array de ['user_id' => ..., 'username' => ...])
 */

$operators_sql = "SELECT user_id, username FROM users WHERE user_id NOT IN (3, 4) ORDER BY username";
$operators_result = $conn->query($operators_sql);

$operators = [];
if ($operators_result && $operators_result->num_rows > 0) {
    while ($op = $operators_result->fetch_assoc()) {
        $operators[] = $op;
    }
}
