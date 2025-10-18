<?php
session_start();
require_once 'config/database.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Funciones para calcular estados según las nuevas reglas
function calcularEstado($fecha_pago) {
    $hoy = new DateTime();
    $fechaPago = new DateTime($fecha_pago);
    
    // Último día del mes + 5 días de prórroga
    $ultimoDiaMes = new DateTime($fechaPago->format('Y-m-t'));
    $fechaLimite = clone $ultimoDiaMes;
    $fechaLimite->modify('+5 days');
    
    if ($hoy <= $fechaLimite) {
        return 'pendiente';
    } else {
        return 'moroso';
    }
}

// Función para calcular fecha de vencimiento (último día del mes + 5 días)
function calcularFechaVencimiento($fecha_pago) {
    $fecha = new DateTime($fecha_pago);
    $ultimoDiaMes = new DateTime($fecha->format('Y-m-t'));
    $ultimoDiaMes->modify('+5 days'); // 5 días de prórroga
    return $ultimoDiaMes->format('d/m/Y');
}

$usuario_id = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];

try {
    // Conectar a la base de datos (empresa A por defecto)
    $db = new Database('A');
    $conn = $db->getConnection();
    
    // Obtener tasa BCV actual para conversiones
    $tasa_sql = "SELECT TOP 1 tasa_usd FROM tasas_bcv WHERE activa = 1 ORDER BY fecha_actualizacion DESC";
    $tasa_stmt = sqlsrv_query($conn, $tasa_sql);
    $tasa_row = sqlsrv_fetch_array($tasa_stmt, SQLSRV_FETCH_ASSOC);
    $tasa_actual = $tasa_row ? $tasa_row['tasa_usd'] : 0;
    sqlsrv_free_stmt($tasa_stmt);
    
} catch (Exception $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestión de Pagos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        .status.moroso {
            background-color: #e74c3c;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .status.pendiente {
            background-color: #f39c12;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status.aprobado {
            background-color: #27ae60;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-money-bill-wave"></i> Sistema Pagos</h2>
                <small>Base: sistema_pagos</small>
            </div>
            <ul class="sidebar-menu">
                <li><a href="index.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="registrar_pago.php"><i class="fas fa-plus-circle"></i> Registrar Pago</a></li>
                <li><a href="pagos.php"><i class="fas fa-list"></i> Mis Pagos</a></li>
                
                <?php if ($_SESSION['rol'] == 'admin' || $_SESSION['rol'] == 'consolidador'): ?>
                <li><a href="importar_banco.php"><i class="fas fa-exchange-alt"></i> Importar Banco</a></li>    
                <li><a href="conciliacion.php"><i class="fas fa-exchange-alt"></i> Conciliación</a></li>
                <li><a href="conciliacion_visual.php"><i class="fas fa-exchange-alt"></i> Conciliación VISUAL</a></li>
                <li><a href="usuarios.php"><i class="fas fa-users"></i> Usuarios</a></li>
                <?php endif; ?>
                
                <?php if ($_SESSION['rol'] == 'admin'): ?>
                <li><a href="configuracion.php"><i class="fas fa-cog"></i> Configuración</a></li>
                <?php endif; ?>
                
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="top-nav">
                <h3><?php echo isset($page_title) ? $page_title : 'Dashboard'; ?></h3>
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div class="user-info">
                        <span>Bienvenido, <?php echo $_SESSION['nombre']; ?></span>
                        <span class="badge"><?php echo ucfirst($_SESSION['rol']); ?></span>
                    </div>
                    <div class="server-info">
                        Servidor: <?php echo $db->getCurrentServer(); ?>
                    </div>
                </div>
            </div>

            <div class="content">
                <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>