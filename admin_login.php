<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Simple hardcoded admin (change this later to database)
    if ($username === 'admin' && $password === 'admin123') {
        $_SESSION['admin_logged_in'] = true;
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Invalid username or password";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login • Ubi Street</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-zinc-950 text-white min-h-screen flex items-center justify-center">
  <div class="max-w-md w-full bg-zinc-900 rounded-3xl p-10">
    <div class="text-center mb-10">
      <img src="logo/file.jpg" alt="Logo" class="h-16 mx-auto mb-4">
      <h1 class="text-3xl font-semibold display-font">Admin Login</h1>
    </div>

    <?php if (isset($error)): ?>
      <div class="bg-red-500/10 border border-red-500 text-red-400 px-4 py-3 rounded-2xl mb-6">
        <?= $error ?>
      </div>
    <?php endif; ?>

    <form method="POST">
      <div class="mb-6">
        <label class="block text-sm text-zinc-400 mb-2">Username</label>
        <input type="text" name="username" required 
               class="w-full bg-black border border-zinc-700 rounded-2xl px-6 py-4 focus:outline-none focus:border-green-500">
      </div>
      <div class="mb-8">
        <label class="block text-sm text-zinc-400 mb-2">Password</label>
        <input type="password" name="password" required 
               class="w-full bg-black border border-zinc-700 rounded-2xl px-6 py-4 focus:outline-none focus:border-green-500">
      </div>
      <button type="submit" 
              class="w-full bg-green-500 hover:bg-green-400 text-black font-semibold py-4 rounded-2xl text-lg">
        Login
      </button>
    </form>

    <p class="text-center text-xs text-zinc-500 mt-8">
      Default: admin / admin123
    </p>
  </div>
</body>
</html>