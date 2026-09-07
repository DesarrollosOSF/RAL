<?php
require_once __DIR__ . '/../config/config.php';

// Solo permitir si no existe ningún usuario (bootstrap inicial)
$pdo = getDBConnection();
$count = (int)$pdo->query('SELECT COUNT(*) AS n FROM usuarios')->fetch()['n'];

$error = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = sanitizar($_POST['nombre_completo'] ?? '');
    $email = sanitizar($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($count > 0) {
        $error = 'Ya existen usuarios. Use `admin/usuarios.php` para crear nuevos.';
    } else if ($nombre === '' || $email === '' || $password === '') {
        $error = 'Nombre, email y contraseña son obligatorios.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO usuarios (nombre_completo, email, password_hash, activo, rol) VALUES (?, ?, ?, 1, ?)');
            $stmt->execute([$nombre, $email, $hash, 'admin']);
            // Asegura asignación de actividades para la jornada de hoy
            getOrCreateJornadaHoy($pdo);
            $msg = 'Admin creado. Ya puedes iniciar sesión.';
            header('Location: ' . BASE_URL . 'login.php');
            exit;
        } catch (Exception $e) {
            $error = 'Error al crear el admin.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Admin - Control Sedes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-6">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h4 class="mb-2">Bootstrap inicial</h4>
                    <div class="text-muted small mb-3">
                        Crea el primer usuario admin. Se permite solo si no existen usuarios.
                    </div>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <?php if ($msg): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label">Nombre completo</label>
                            <input class="form-control" name="nombre_completo" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contraseña</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        <button class="btn btn-primary w-100" type="submit" <?php echo $count>0 ? 'disabled' : ''; ?>>
                            Crear admin
                        </button>
                    </form>
                    <div class="text-muted small mt-3">
                        Usuarios existentes: <?php echo $count; ?>
                    </div>
                    <div class="text-muted small mt-2">
                        <a href="<?php echo BASE_URL; ?>login.php">Ir a login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

