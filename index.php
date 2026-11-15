<?php
require __DIR__ . '/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $conn->prepare("INSERT INTO parts (name, category, price, quantity) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssdi", $_POST['name'], $_POST['category'], $_POST['price'], $_POST['quantity']);
    $stmt->execute();
    header("Location: index.php");
    exit;
}

$result = $conn->query("SELECT * FROM parts ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Inventory</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
  <h1>Computer Parts Inventory</h1>
  <div class="userbox">
    Signed in as <strong><?= e($_SESSION['username']) ?></strong>
    <a class="btn btn-ghost" href="logout.php">Log out</a>
  </div>
</header>

<main class="container">
  <section class="card">
    <h2>Add a part</h2>
    <form method="post" class="part-form">
      <label>Name <input name="name" required></label>
      <label>Category <input name="category"></label>
      <label>Price <input name="price" type="number" step="0.01" required></label>
      <label>Quantity <input name="quantity" type="number" value="0" required></label>
      <button type="submit" class="btn btn-primary">Add part</button>
    </form>
  </section>

  <section class="card">
    <h2>Parts</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>ID</th><th>Name</th><th>Category</th><th>Price</th><th>Quantity</th></tr>
        </thead>
        <tbody>
        <?php while ($row = $result->fetch_assoc()): ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            <td><?= e($row['name']) ?></td>
            <td><?= e($row['category']) ?></td>
            <td>₱<?= number_format((float)$row['price'], 2) ?></td>
            <td><?= (int)$row['quantity'] ?></td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
</body>
</html>
