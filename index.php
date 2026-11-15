<?php
require 'db.php';

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
<html>
<head><title>Inventory</title></head>
<body>
<h1>Computer Parts Inventory</h1>

<form method="post">
  Name: <input name="name" required>
  Category: <input name="category">
  Price: <input name="price" type="number" step="0.01" required>
  Quantity: <input name="quantity" type="number" value="0" required>
  <button type="submit">Add Part</button>
</form>

<table border="1" cellpadding="5">
<tr><th>ID</th><th>Name</th><th>Category</th><th>Price</th><th>Quantity</th></tr>
<?php while ($row = $result->fetch_assoc()): ?>
<tr>
  <td><?= $row['id'] ?></td>
  <td><?= htmlspecialchars($row['name']) ?></td>
  <td><?= htmlspecialchars($row['category']) ?></td>
  <td><?= $row['price'] ?></td>
  <td><?= $row['quantity'] ?></td>
</tr>
<?php endwhile; ?>
</table>
</body>
</html>

