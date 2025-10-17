<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autentificare</title>
    <link rel="stylesheet" href="stylelogin.css">
    <link rel="icon" type="image/png" href="https://color-print.ro/magazincp/favicon.png" />
</head>

<body>
    <div class="login-container">
        <img src="comenzi.svg" alt="Logo" class="logo">
        <h2>Autentificare</h2>
        <form action="authenticate.php" method="post">
            <div class="form-group">
                <label for="username">Utilizator</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Parola</label>
                <input type="password" id="password" name="password" required>
            </div>
            <label for="remember_me">
                <input type="checkbox" id="remember_me" name="remember_me" checked>Ține-mă minte
            </label>
            <div class="buttonal">
                <button type="submit">Autentificare</button>

        </form>
    </div>
</body>

</html>