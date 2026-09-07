<?php
require_once __DIR__ . '/config/config.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: ' . urlInicioApp());
    exit;
}

$error = '';
$csrfToken = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = verifyCsrfToken($_POST['csrf_token'] ?? '');
    if (!$csrfOk) {
        $error = 'Token CSRF inválido.';
    } else {
        $nombre = sanitizar($_POST['nombre_completo'] ?? '');
        $email = sanitizar($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($nombre === '' || $email === '' || $password === '') {
            $error = 'Nombre, email y contraseña son obligatorios.';
        } else if (mb_strlen($password) < 6) {
            $error = 'La contraseña debe tener mínimo 6 caracteres.';
        } else {
            try {
                $pdo = getDBConnection();
                $stmtCheck = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? LIMIT 1');
                $stmtCheck->execute([$email]);
                if ($stmtCheck->fetch()) {
                    $error = 'El email ya está registrado.';
                } else {
                    $pdo->beginTransaction();

                $jornadaId = getOrCreateJornadaHoy($pdo);

                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmtInsU = $pdo->prepare('
                        INSERT INTO usuarios (nombre_completo, email, password_hash, activo, rol)
                        VALUES (?, ?, ?, 1, ?)
                    ');
                    $stmtInsU->execute([$nombre, $email, $passwordHash, 'usuario']);
                    $usuarioId = (int)$pdo->lastInsertId();

                    // Asignar actividades existentes al nuevo usuario en estado "Asignadas"
                    $stmtEstado = $pdo->prepare('SELECT id FROM actividad_estados WHERE slug = ? LIMIT 1');
                    $stmtEstado->execute(['asignadas']);
                    $estadoAsignadas = $stmtEstado->fetch();
                    if (!$estadoAsignadas) {
                        throw new Exception('No existe estado "Asignadas".');
                    }

                    $stmtInsUA = $pdo->prepare('
                        INSERT INTO actividades_usuario
                            (jornada_id, actividad_id, usuario_id, estado_id, tiempo_acumulado_seg, tiempo_intervalo_inicio_at)
                        SELECT
                            ?, a.id, ?, ?, 0, NULL
                        FROM actividades a
                        WHERE a.activo = 1
                          AND NOT EXISTS (
                            SELECT 1
                            FROM actividades_usuario au
                            WHERE au.jornada_id = ?
                              AND au.actividad_id = a.id
                              AND au.usuario_id = ?
                        )
                    ');
                    $stmtInsUA->execute([$jornadaId, $usuarioId, (int)$estadoAsignadas['id'], $jornadaId, $usuarioId]);

                    $pdo->commit();

                    header('Location: ' . BASE_URL . 'login.php?registered=1');
                    exit;
                }
            } catch (Exception $e) {
                if (!empty($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Error al crear el usuario.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Usuario - Control Sedes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
      body{background:#f2f4f7;}
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-6 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <i class="bi bi-person-plus fs-1 text-primary"></i>
                        <h4 class="mt-2 mb-0">Crear usuario</h4>
                        <div class="text-muted small">Acceso al tablero</div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-3">
                            <label class="form-label"><i class="bi bi-tag me-2"></i>Nombre completo</label>
                            <input type="text" class="form-control" name="nombre_completo" maxlength="120" required autofocus>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><i class="bi bi-envelope me-2"></i>Email</label>
                            <input type="email" class="form-control" name="email" maxlength="160" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><i class="bi bi-lock me-2"></i>Contraseña</label>
                            <input type="password" class="form-control" name="password" minlength="6" required>
                        </div>

                        <button class="btn btn-primary w-100" type="submit">
                            <i class="bi bi-person-check me-2"></i>Crear cuenta
                        </button>
                    </form>

                    <div class="text-muted small mt-3 text-center">
                        ¿Ya tienes cuenta? <a href="<?php echo BASE_URL; ?>login.php">Iniciar sesión</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

