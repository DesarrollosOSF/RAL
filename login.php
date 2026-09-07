<?php
require_once __DIR__ . '/config/config.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: ' . urlInicioApp());
    exit;
}

$error = '';
$registered = isset($_GET['registered']) && (string)$_GET['registered'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizar($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Por favor complete email y contraseña.';
    } else {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare('SELECT id, nombre_completo, email, password_hash, activo, rol FROM usuarios WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || (int)$user['activo'] !== 1) {
                $error = 'Credenciales inválidas o cuenta desactivada.';
            } else if (!password_verify($password, $user['password_hash'])) {
                $error = 'Credenciales inválidas.';
            } else {
                // Iniciar sesión
                $_SESSION['usuario_id'] = (int)$user['id'];
                $_SESSION['nombre_completo'] = $user['nombre_completo'];
                $_SESSION['email'] = $user['email'];
                $rolSesion = $user['rol'] ?? 'usuario';
                if ($rolSesion === 'auxiliar_administr') {
                    $rolSesion = ROL_AUXILIAR_ADMINISTRATIVO;
                } elseif ($rolSesion === 'auxiliar_administrativos') {
                    $rolSesion = ROL_AUXILIAR_ADMINISTRATIVO;
                }
                $_SESSION['rol'] = $rolSesion;

                header('Location: ' . urlInicioApp());
                exit;
            }
        } catch (Exception $e) {
            $error = 'Error al iniciar sesión.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Control Sedes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(145deg, #2563eb 0%, #1d4ed8 28%, #0f2d6b 55%, #082252 100%);
            position: relative;
            overflow-x: hidden;
        }

        /* Franja clara diagonal (similar al reflejo de la imagen) */
        body::before {
            content: "";
            position: fixed;
            inset: -20% -10% -20% -10%;
            background: linear-gradient(
                125deg,
                transparent 38%,
                rgba(96, 165, 250, 0.45) 48%,
                rgba(147, 197, 253, 0.25) 52%,
                transparent 62%
            );
            pointer-events: none;
            z-index: 0;
        }

        .login-shell {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 400px;
        }

        .login-heading {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin: 0 0 32px;
            color: #fff;
            font-size: 1.5rem;
            font-weight: 500;
            letter-spacing: 0.02em;
        }

        .login-heading .heading-icon {
            font-size: 1.75rem;
            line-height: 1;
            opacity: 0.95;
        }

        .input-wrap {
            position: relative;
            margin-bottom: 14px;
        }

        .input-wrap input {
            width: 100%;
            padding: 16px 52px 16px 22px;
            border: none;
            border-radius: 50px;
            background: #fff;
            font-size: 1rem;
            color: #1f2937;
            outline: none;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        }

        .input-wrap input::placeholder {
            color: #9ca3af;
        }

        .input-wrap input:focus {
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.35), 0 2px 12px rgba(0, 0, 0, 0.08);
        }

        .input-wrap:has(.pw-toggle) input {
            padding-right: 3.5rem;
        }

        .input-wrap .field-icon {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 1.15rem;
            pointer-events: none;
            line-height: 1;
        }

        .pw-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            padding: 0;
            border: none;
            background: transparent;
            color: #9ca3af;
            font-size: 1.15rem;
            line-height: 1;
            cursor: pointer;
            border-radius: 50%;
            transition: color 0.15s ease, background 0.15s ease;
        }

        .pw-toggle:hover,
        .pw-toggle:focus-visible {
            color: #6b7280;
            background: rgba(0, 0, 0, 0.04);
            outline: none;
        }

        .btn-signin {
            width: 100%;
            margin-top: 4px;
            padding: 16px 24px;
            border: none;
            border-radius: 50px;
            background: #0c2d5c;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
            transition: background 0.2s ease, transform 0.15s ease;
        }

        .btn-signin:hover {
            background: #0a2449;
        }

        .btn-signin:active {
            transform: scale(0.99);
        }

        .login-footer {
            margin-top: 28px;
            text-align: center;
            font-size: 0.875rem;
            color: #fff;
            line-height: 1.5;
        }

        .login-footer a {
            color: #fff;
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .login-footer a:hover {
            opacity: 0.9;
        }

        .alert {
            border-radius: 50px;
            padding: 12px 20px;
            margin-bottom: 18px;
            font-size: 0.9rem;
            text-align: center;
        }

        .alert-danger {
            background: rgba(254, 226, 226, 0.95);
            color: #991b1b;
            border: 1px solid rgba(248, 113, 113, 0.4);
        }

        .alert-success {
            background: rgba(220, 252, 231, 0.95);
            color: #166534;
            border: 1px solid rgba(74, 222, 128, 0.45);
        }
    </style>
</head>
<body>
    <div class="login-shell">
        <h1 class="login-heading">
            <i class="bi bi-person-fill heading-icon" aria-hidden="true"></i>
            <span>Inicio de sesión</span>
        </h1>

        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($registered): ?>
            <div class="alert alert-success" role="alert">
                Usuario creado correctamente. Ya puedes iniciar sesión.
            </div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="on">
            <div class="input-wrap">
                <input type="email" name="email" placeholder="Email" required autofocus
                    value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    inputmode="email" autocomplete="username">
                <span class="field-icon" aria-hidden="true"><i class="bi bi-person"></i></span>
            </div>
            <div class="input-wrap">
                <input type="password" name="password" id="login-password" placeholder="Contraseña" required
                    autocomplete="current-password">
                <button type="button" class="pw-toggle" id="pw-toggle" aria-label="Mostrar contraseña"
                    aria-pressed="false" aria-controls="login-password">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
            </div>
            <button class="btn-signin" type="submit">Iniciar sesión</button>
        </form>
    </div>
    <script>
        (function () {
            var input = document.getElementById('login-password');
            var btn = document.getElementById('pw-toggle');
            if (!input || !btn) return;
            var icon = btn.querySelector('i');
            if (!icon) return;

            function showPw() {
                input.type = 'text';
                icon.className = 'bi bi-eye-slash';
                btn.setAttribute('aria-pressed', 'true');
                btn.setAttribute('aria-label', 'Ocultar contraseña');
            }

            function hidePw() {
                input.type = 'password';
                icon.className = 'bi bi-eye';
                btn.setAttribute('aria-pressed', 'false');
                btn.setAttribute('aria-label', 'Mostrar contraseña');
            }

            btn.addEventListener('mouseenter', showPw);
            btn.addEventListener('mouseleave', hidePw);
            btn.addEventListener('focus', showPw);
            btn.addEventListener('blur', hidePw);

            btn.addEventListener('mousedown', function (e) {
                e.preventDefault();
            });

            btn.addEventListener('touchstart', function (e) {
                e.preventDefault();
                showPw();
            }, { passive: false });

            btn.addEventListener('touchend', hidePw);
            btn.addEventListener('touchcancel', hidePw);
        })();
    </script>
</body>
</html>
