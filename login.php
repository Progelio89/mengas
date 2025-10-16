<?php
session_start();
require_once 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    try {
        // Crear instancia de Database (usando empresa A por defecto)
        $db = new Database('A');
        $conn = $db->getConnection();
        
        // Consulta usando sqlsrv en lugar de PDO
        $sql = "SELECT * FROM usuarios WHERE email = ? AND activo = 1";
        $stmt = sqlsrv_query($conn, $sql, array($email));
        
        if ($stmt === false) {
            $error = "Error en la consulta: " . print_r(sqlsrv_errors(), true);
        } else {
            $usuario = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            
            if ($usuario && password_verify($password, $usuario['password'])) {
                $_SESSION['usuario_id'] = $usuario['id'];
                $_SESSION['nombre'] = $usuario['nombre'];
                $_SESSION['rol'] = $usuario['rol'];
                $_SESSION['email'] = $usuario['email'];
                
                // Liberar statement
                sqlsrv_free_stmt($stmt);
                
                header('Location: index.php');
                exit();
            } else {
                $error = "Credenciales incorrectas";
            }
            
            // Liberar statement
            sqlsrv_free_stmt($stmt);
        }
        
    } catch (Exception $e) {
        $error = "Error al iniciar sesión: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Sistema de Pagos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }
        
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h2 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .login-header i {
            color: #3498db;
            font-size: 2em;
            margin-bottom: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }
        
        input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        input:focus {
            border-color: #3498db;
            outline: none;
        }
        
        .btn-login {
            width: 100%;
            padding: 12px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .btn-login:hover {
            background: #2980b9;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid #f5c6cb;
        }
        
        .server-info {
            margin-top: 20px;
            padding: 10px;
            background: #e8f4fd;
            border-radius: 5px;
            text-align: center;
            font-size: 12px;
            color: #2c3e50;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <i class="fas fa-money-bill-wave"></i>
            <h2>Sistema de Pagos</h2>
            <p>Iniciar Sesión</p>
        </div>
        
        <?php if (isset($error)): ?>
        <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required 
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="password">Contraseña:</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn-login">Iniciar Sesión</button>
        </form>
        
        <?php
        // Mostrar información del servidor si hay una instancia de Database
        if (isset($db) && $db->getCurrentServer()): 
        ?>
        <div class="server-info">
            Conectado a: <?php echo $db->getCurrentServer(); ?> - 
            Base: <?php echo $db->getDatabaseName(); ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>