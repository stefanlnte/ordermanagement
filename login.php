<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="stylelogin.css">
</head>
<body>
    <div class="login-container">
        <img src="logo.png" alt="Logo" class="logo">
        <h2>Login</h2>
        <form action="authenticate.php" method="post">
            <div class="form-group">
                <label for="username">User</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Parola</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="buttonal">
            <button type="submit">Login</button>

        </form>
    </div>
</body>
</html>