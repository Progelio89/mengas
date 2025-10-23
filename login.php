<?php
// Configuración de seguridad ANTES de iniciar sesión
ini_set('session.cookie_httponly', 1);
// ini_set('session.cookie_secure', 1); // Descomentar solo si está en HTTPS

session_start();
require_once 'config/database.php';

// Inicializar variables de sesión si no existen
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}

// Prevenir ataques de fuerza bruta
$max_attempts = 5;
$lockout_time = 900; // 15 minutos
$client_ip = $_SERVER['REMOTE_ADDR'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Verificar token CSRF de forma segura
    $csrf_valid = isset($_POST['csrf_token']) && 
                  isset($_SESSION['csrf_token']) && 
                  hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    
    if (!$csrf_valid) {
        $error = "Error de seguridad. Por favor, recargue la página.";
    } else {
        $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'];
        $remember_me = isset($_POST['remember_me']) ? true : false;
        
        // Verificar intentos fallidos
        $attempts_key = 'login_attempts_' . $client_ip;
        $last_attempt_key = 'last_attempt_' . $client_ip;
        
        $current_attempts = $_SESSION['login_attempts'][$attempts_key] ?? 0;
        $last_attempt_time = $_SESSION['login_attempts'][$last_attempt_key] ?? 0;
        
        if ($current_attempts >= $max_attempts) {
            $remaining_time = $lockout_time - (time() - $last_attempt_time);
            if ($remaining_time > 0) {
                $error = "Demasiados intentos fallidos. Espere " . ceil($remaining_time / 60) . " minutos.";
            } else {
                // Resetear contador después del tiempo de bloqueo
                $_SESSION['login_attempts'][$attempts_key] = 0;
                unset($_SESSION['login_attempts'][$last_attempt_key]);
            }
        }
        
        if (!isset($error)) {
            if (!$email) {
                $error = "Por favor, ingrese un email válido.";
            } elseif (empty($password)) {
                $error = "Por favor, ingrese su contraseña.";
            } else {
                try {
                    $db = new Database('A');
                    $conn = $db->getConnection();
                    
                    $sql = "SELECT id, nombre, email, password, rol, activo FROM usuarios WHERE email = ? AND activo = 1";
                    $stmt = sqlsrv_query($conn, $sql, array($email));
                    
                    if ($stmt === false) {
                        error_log("Error en consulta de login: " . print_r(sqlsrv_errors(), true));
                        $error = "Error interno del sistema. Por favor, intente más tarde.";
                    } else {
                        $usuario = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                        
                        if ($usuario && password_verify($password, $usuario['password'])) {
                            // Login exitoso - resetear contador de intentos
                            $_SESSION['login_attempts'][$attempts_key] = 0;
                            unset($_SESSION['login_attempts'][$last_attempt_key]);
                            
                            // Regenerar ID de sesión por seguridad
                            session_regenerate_id(true);
                            
                            $_SESSION['usuario_id'] = $usuario['id'];
                            $_SESSION['nombre'] = $usuario['nombre'];
                            $_SESSION['rol'] = $usuario['rol'];
                            $_SESSION['email'] = $usuario['email'];
                            $_SESSION['last_activity'] = time();
                            $_SESSION['ip_address'] = $client_ip;
                            
                            // Configurar cookie "Recordarme" si se seleccionó
                            if ($remember_me) {
                                $token = bin2hex(random_bytes(32));
                                $expiry = time() + (30 * 24 * 60 * 60); // 30 días
                                
                                // Guardar token en la base de datos
                                $sql_update = "UPDATE usuarios SET remember_token = ?, token_expiry = ? WHERE id = ?";
                                $params = array($token, date('Y-m-d H:i:s', $expiry), $usuario['id']);
                                sqlsrv_query($conn, $sql_update, $params);
                                
                                setcookie('remember_token', $token, $expiry, '/', '', false, true);
                            }
                            
                            // Liberar statement
                            sqlsrv_free_stmt($stmt);
                            
                            // Registrar login exitoso
                            error_log("Login exitoso para usuario: " . $email . " desde IP: " . $client_ip);
                            
                            // Regenerar nuevo token CSRF
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                            
                            header('Location: index.php');
                            exit();
                        } else {
                            // Login fallido - incrementar contador
                            $_SESSION['login_attempts'][$attempts_key] = $current_attempts + 1;
                            $_SESSION['login_attempts'][$last_attempt_key] = time();
                            
                            $remaining_attempts = $max_attempts - ($current_attempts + 1);
                            if ($remaining_attempts > 0) {
                                $error = "Credenciales incorrectas. Le quedan " . $remaining_attempts . " intentos.";
                            } else {
                                $error = "Demasiados intentos fallidos. Su cuenta ha sido bloqueada temporalmente.";
                            }
                            
                            // Registrar intento fallido
                            error_log("Intento de login fallido para: " . $email . " desde IP: " . $client_ip);
                        }
                        
                        // Liberar statement
                        sqlsrv_free_stmt($stmt);
                    }
                    
                } catch (Exception $e) {
                    error_log("Error en login: " . $e->getMessage());
                    $error = "Error al iniciar sesión. Por favor, intente más tarde.";
                }
            }
        }
    }
}

// Regenerar token CSRF periódicamente para mayor seguridad
if (!isset($_SESSION['csrf_generated']) || time() - $_SESSION['csrf_generated'] > 3600) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_generated'] = time();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Sistema de Gestión de Pagos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #3b82f6;
            --secondary: #64748b;
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --background: #f8fafc;
            --surface: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border: #e2e8f0;
            --border-focus: #3b82f6;
            --shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-blue: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--gradient-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
            line-height: 1.5;
            color: var(--text-primary);
        }

        .login-container {
            background: var(--surface);
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 400px;
            overflow: hidden;
            position: relative;
        }

        .login-header {
            background: var(--gradient-blue);
            color: white;
            padding: 35px 25px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
        }

        .logo {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            z-index: 2;
        }

        .logo i {
            font-size: 24px;
            color: white;
        }

        .login-header h1 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 6px;
            letter-spacing: -0.025em;
            position: relative;
            z-index: 2;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 14px;
            font-weight: 400;
            position: relative;
            z-index: 2;
        }

        .login-content {
            padding: 30px 25px;
            position: relative;
            z-index: 1;
        }

        .form-group {
            margin-bottom: 18px;
            position: relative;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 13px;
        }

        .input-container {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            z-index: 1;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 14px 14px 14px 42px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: var(--background);
            color: var(--text-primary);
            font-weight: 400;
        }

        input:focus {
            outline: none;
            border-color: var(--border-focus);
            background: var(--surface);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        input:focus + .input-icon {
            color: var(--primary);
        }

        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 6px;
            border-radius: 5px;
            transition: all 0.3s ease;
            z-index: 2;
            font-size: 14px;
        }

        .password-toggle:hover {
            background: var(--background);
            color: var(--text-primary);
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .checkbox-container input[type="checkbox"] {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            border: 2px solid var(--border);
            background: var(--surface);
            cursor: pointer;
            position: relative;
            appearance: none;
            -webkit-appearance: none;
            transition: all 0.3s ease;
        }

        .checkbox-container input[type="checkbox"]:checked {
            background: var(--primary);
            border-color: var(--primary);
        }

        .checkbox-container input[type="checkbox"]:checked::after {
            content: '✓';
            position: absolute;
            color: white;
            font-size: 12px;
            font-weight: bold;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .checkbox-container label {
            margin: 0;
            font-size: 13px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .checkbox-container:hover label {
            color: var(--text-primary);
        }

        .forgot-password {
            color: var(--primary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .forgot-password:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: var(--gradient-blue);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .loading {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-error {
            background: #fef2f2;
            color: var(--error);
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #f0fdf4;
            color: var(--success);
            border: 1px solid #bbf7d0;
        }

        .alert i {
            font-size: 16px;
        }

        .server-info {
            margin-top: 20px;
            padding: 12px;
            background: #f1f5f9;
            border-radius: 8px;
            text-align: center;
            font-size: 11px;
            color: var(--text-secondary);
            border-left: 3px solid var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid var(--border);
            color: var(--text-secondary);
            font-size: 11px;
        }

        @media (max-width: 480px) {
            body {
                padding: 10px;
            }
            
            .login-container {
                border-radius: 12px;
                max-width: 100%;
            }
            
            .login-header {
                padding: 25px 20px;
            }
            
            .login-content {
                padding: 25px 20px;
            }
            
            .logo {
                width: 50px;
                height: 50px;
            }
            
            .logo i {
                font-size: 20px;
            }
            
            .login-header h1 {
                font-size: 20px;
            }
            
            .form-options {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
        }

        @media (max-height: 700px) {
            body {
                padding: 10px;
                align-items: flex-start;
            }
            
            .login-container {
                margin: 20px 0;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h1>Sistema de Gestión</h1>
            <p>Acceso Seguro a la Plataforma</p>
        </div>
        
        <div class="login-content">
            <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['success']) && $_GET['success'] == 'logout'): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span>Ha cerrado sesión correctamente.</span>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div class="form-group">
                    <label for="email">Correo Electrónico</label>
                    <div class="input-container">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" id="email" name="email" required 
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                               placeholder="usuario@empresa.com">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <div class="input-container">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" required 
                               placeholder="Ingrese su contraseña">
                        <button type="button" class="password-toggle" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="form-options">
                    <div class="checkbox-container">
                        <input type="checkbox" id="remember_me" name="remember_me">
                        <label for="remember_me">Recordar sesión</label>
                    </div>
                    <a href="recuperar-password.php" class="forgot-password">¿Olvidó su contraseña?</a>
                </div>
                
                <button type="submit" class="btn-login" id="submitBtn">
                    <div class="loading" id="loadingSpinner"></div>
                    <i class="fas fa-sign-in-alt"></i>
                    <span id="submitText">Iniciar Sesión</span>
                </button>
            </form>
            
            <?php if (isset($db) && $db->getCurrentServer()): ?>
            <div class="server-info">
                <i class="fas fa-server"></i>
                <span>Conectado a: <?php echo htmlspecialchars($db->getCurrentServer()); ?> | Base: <?php echo htmlspecialchars($db->getDatabaseName()); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="footer">
                <p>&copy; 2024 Sistema de Gestión de Pagos. Todos los derechos reservados.</p>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.getElementById('loginForm');
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            const emailInput = document.getElementById('email');
            
            // Toggle para mostrar/ocultar contraseña
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? 
                    '<i class="fas fa-eye"></i>' : 
                    '<i class="fas fa-eye-slash"></i>';
            });
            
            // Mostrar indicador de carga al enviar formulario
            loginForm.addEventListener('submit', function() {
                submitBtn.disabled = true;
                submitText.textContent = 'Iniciando sesión...';
                loadingSpinner.style.display = 'block';
            });
            
            // Validación en tiempo real
            emailInput.addEventListener('blur', function() {
                if (!this.value.includes('@') || !this.value.includes('.')) {
                    this.style.borderColor = 'var(--error)';
                } else {
                    this.style.borderColor = '';
                }
            });
        });
    </script>
</body>
</html>