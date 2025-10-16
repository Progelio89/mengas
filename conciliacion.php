<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'consolidador')) {
    header('Location: login.php');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$db = new Database('A');
$conn = $db->getConnection();

// Inicializar arrays
$bancos = [];
$pagos = [];
$movimientos = [];
$error = '';

// Procesar archivo del banco
if (isset($_POST['subir_movimientos'])) {
    if (isset($_FILES['archivo_banco']) && $_FILES['archivo_banco']['error'] == 0) {
        $banco_id = $_POST['banco_id'];
        $archivo_tmp = $_FILES['archivo_banco']['tmp_name'];
        
        // Procesar como CSV simple
        if (($handle = fopen($archivo_tmp, 'r')) !== FALSE) {
            $fila = 0;
            while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                $fila++;
                if ($fila == 1) continue; // Saltar encabezados
                
                // Formato: Fecha, Descripción, Monto, Saldo
                if (count($data) >= 4) {
                    $fecha = DateTime::createFromFormat('d/m/Y', $data[0]);
                    $fecha_str = $fecha ? $fecha->format('Y-m-d') : date('Y-m-d');
                    $descripcion = trim($data[1]);
                    $monto = floatval(str_replace(',', '.', str_replace('.', '', $data[2])));
                    
                    if ($monto > 0) {
                        // Insertar movimiento bancario
                        $sql = "INSERT INTO movimientos_banco (banco_id, referencia, descripcion, monto, fecha_movimiento, fecha_registro) 
                                VALUES (?, ?, ?, ?, ?, GETDATE())";
                        $referencia = extraerReferencia($descripcion);
                        $params = array($banco_id, $referencia, $descripcion, $monto, $fecha_str);
                        
                        $stmt = sqlsrv_query($conn, $sql, $params);
                        if ($stmt === false) {
                            $error = "Error al insertar movimiento: " . print_r(sqlsrv_errors(), true);
                        }
                    }
                }
            }
            fclose($handle);
            if (empty($error)) {
                $_SESSION['success'] = "Movimientos bancarios cargados exitosamente";
            }
        }
    }
}

// Conciliación manual/automática
if (isset($_POST['conciliar'])) {
    if (isset($_POST['pagos_seleccionados'])) {
        $contador = 0;
        foreach ($_POST['pagos_seleccionados'] as $pago_id) {
            // Marcar pago como conciliado
            $sql = "UPDATE pagos SET estado = 'conciliado', fecha_conciliacion = GETDATE() WHERE id = ?";
            $stmt = sqlsrv_query($conn, $sql, array($pago_id));
            if ($stmt !== false) {
                $contador++;
            }
        }
        $_SESSION['success'] = $contador . " pagos conciliados exitosamente";
        
        // Aquí podrías agregar el envío de correos
        // enviarNotificaciones($_POST['pagos_seleccionados']);
    }
}

// Obtener bancos - con manejo de errores
try {
    $bancos_sql = "SELECT * FROM bancos WHERE activo = 1";
    $bancos_stmt = sqlsrv_query($conn, $bancos_sql);
    if ($bancos_stmt === false) {
        throw new Exception("Error en consulta de bancos: " . print_r(sqlsrv_errors(), true));
    }
    
    while ($row = sqlsrv_fetch_array($bancos_stmt, SQLSRV_FETCH_ASSOC)) {
        $bancos[] = $row;
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Obtener pagos pendientes - con manejo de errores
try {
    // Primero verificar si la tabla pagos tiene las columnas necesarias
    $pagos_sql = "SELECT p.*, b.nombre as banco_nombre, b.color as banco_color 
                  FROM pagos p 
                  LEFT JOIN bancos b ON p.banco_id = b.id 
                  WHERE p.estado IN ('pendiente', 'aprobado')
                  ORDER BY p.fecha_pago DESC";
    
    $pagos_stmt = sqlsrv_query($conn, $pagos_sql);
    if ($pagos_stmt === false) {
        // Intentar consulta más simple si falla
        $pagos_sql_simple = "SELECT * FROM pagos WHERE estado IN ('pendiente', 'aprobado') ORDER BY fecha_pago DESC";
        $pagos_stmt = sqlsrv_query($conn, $pagos_sql_simple);
        
        if ($pagos_stmt === false) {
            throw new Exception("Error en consulta de pagos: " . print_r(sqlsrv_errors(), true));
        }
    }
    
    while ($row = sqlsrv_fetch_array($pagos_stmt, SQLSRV_FETCH_ASSOC)) {
        // Asegurar que tenga los campos necesarios
        if (!isset($row['banco_nombre'])) $row['banco_nombre'] = 'Sin banco';
        if (!isset($row['banco_color'])) $row['banco_color'] = '#6c757d';
        if (!isset($row['monto_bs'])) $row['monto_bs'] = $row['monto']; // Asumir que monto_bs es igual a monto si no existe
        
        $pagos[] = $row;
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Obtener movimientos bancarios no conciliados - con manejo de errores
try {
    $movimientos_sql = "SELECT mb.*, b.nombre as banco_nombre, b.color as banco_color 
                        FROM movimientos_banco mb 
                        JOIN bancos b ON mb.banco_id = b.id 
                        WHERE mb.conciliado = 0 
                        ORDER BY mb.fecha_movimiento DESC";
    
    $movimientos_stmt = sqlsrv_query($conn, $movimientos_sql);
    if ($movimientos_stmt === false) {
        // La tabla movimientos_banco probablemente no existe
        $movimientos = []; // Array vacío
    } else {
        while ($row = sqlsrv_fetch_array($movimientos_stmt, SQLSRV_FETCH_ASSOC)) {
            $movimientos[] = $row;
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
    $movimientos = [];
}

// Función para extraer referencia
function extraerReferencia($descripcion) {
    if (preg_match('/DRV(\d+)/', $descripcion, $matches)) {
        return $matches[1];
    }
    if (preg_match('/(\d{8,10})/', $descripcion, $matches)) {
        return $matches[1];
    }
    return substr($descripcion, 0, 20); // Limitar longitud
}

// Función para verificar coincidencias
function coinciden($pago, $movimiento) {
    // Si no hay movimientos, no hay coincidencia
    if (empty($movimiento)) return false;
    
    // Comparar referencias (búsqueda flexible)
    $ref_pago = preg_replace('/[^0-9]/', '', $pago['referencia']);
    $ref_mov = preg_replace('/[^0-9]/', '', $movimiento['referencia']);
    
    $coincide_ref = false;
    if ($ref_pago === $ref_mov) {
        $coincide_ref = true;
    } else if (strlen($ref_pago) >= 6 && strlen($ref_mov) >= 6) {
        // Coincidencia parcial (últimos 6 dígitos)
        $coincide_ref = substr($ref_pago, -6) === substr($ref_mov, -6);
    }
    
    // Comparar montos
    $monto_pago = (isset($pago['moneda']) && $pago['moneda'] == 'USD') ? 
                 (isset($pago['monto_bs']) ? $pago['monto_bs'] : $pago['monto']) : 
                 $pago['monto'];
    
    $coincide_monto = abs($monto_pago - $movimiento['monto']) < 1.00;
    
    return $coincide_ref && $coincide_monto;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conciliación Bancaria - Sistema de Pagos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        .conciliacion-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }
        .panel {
            border: 2px solid #ddd;
            border-radius: 10px;
            background: white;
        }
        .panel-header {
            background: #2c3e50;
            color: white;
            padding: 15px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .pago-item, .movimiento-item {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
        }
        .pago-item:hover, .movimiento-item:hover {
            background: #f8f9fa;
        }
        .pago-item.coincide {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        .pago-item.no-coincide {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }
        .item-info {
            flex: 1;
        }
        .referencia {
            font-weight: bold;
            font-size: 14px;
        }
        .detalles {
            font-size: 12px;
            color: #666;
        }
        .banco-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            color: white;
        }
        .action-buttons {
            text-align: center;
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .file-upload {
            background: #e9ecef;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .resumen {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        .resumen-item {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            color: white;
            font-weight: bold;
        }
        .resumen-pendientes { background: #ffc107; color: black; }
        .resumen-conciliados { background: #28a745; }
        .resumen-movimientos { background: #17a2b8; }
        .resumen-coinciden { background: #6f42c1; }
        .alert {
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .alert-danger {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
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
                <li><a href="index.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="registrar_pago.php"><i class="fas fa-plus-circle"></i> Registrar Pago</a></li>
                <li><a href="pagos.php"><i class="fas fa-list"></i> Mis Pagos</a></li>
                <li><a href="conciliacion.php" class="active"><i class="fas fa-exchange-alt"></i> Conciliación</a></li>
                <?php if ($_SESSION['rol'] == 'admin'): ?>
                <li><a href="usuarios.php"><i class="fas fa-users"></i> Usuarios</a></li>
                <li><a href="configuracion.php"><i class="fas fa-cog"></i> Configuración</a></li>
                <?php endif; ?>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="top-nav">
                <h3>Conciliación Bancaria Visual</h3>
                <div class="user-info">
                    <span>Bienvenido, <?php echo $_SESSION['nombre']; ?></span>
                    <span class="badge"><?php echo ucfirst($_SESSION['rol']); ?></span>
                </div>
            </div>

            <div class="content">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger">
                        <strong>Error:</strong> <?php echo $error; ?>
                        <br><small>Es posible que necesites crear las tablas en la base de datos.</small>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>

                <!-- Resumen -->
                <div class="resumen">
                    <div class="resumen-item resumen-pendientes">
                        <i class="fas fa-clock fa-2x"></i><br>
                        Pagos Pendientes<br>
                        <span style="font-size: 24px;"><?php echo count($pagos); ?></span>
                    </div>
                    <div class="resumen-item resumen-movimientos">
                        <i class="fas fa-university fa-2x"></i><br>
                        Movimientos Banco<br>
                        <span style="font-size: 24px;"><?php echo count($movimientos); ?></span>
                    </div>
                    <div class="resumen-item resumen-coinciden">
                        <i class="fas fa-check-circle fa-2x"></i><br>
                        Coincidencias<br>
                        <span style="font-size: 24px;" id="contador-coincidencias">0</span>
                    </div>
                    <div class="resumen-item resumen-conciliados">
                        <i class="fas fa-check-double fa-2x"></i><br>
                        Por Conciliar<br>
                        <span style="font-size: 24px;" id="contador-seleccionados">0</span>
                    </div>
                </div>

                <!-- Cargar Movimientos Bancarios -->
                <div class="file-upload">
                    <h4><i class="fas fa-file-upload"></i> Cargar Estado de Cuenta</h4>
                    <form method="POST" enctype="multipart/form-data">
                        <div style="display: grid; grid-template-columns: 1fr 2fr auto; gap: 10px; align-items: end;">
                            <div>
                                <label>Banco:</label>
                                <select name="banco_id" required class="form-control">
                                    <option value="">Seleccionar Banco</option>
                                    <?php foreach ($bancos as $banco): ?>
                                        <option value="<?php echo $banco['id']; ?>"><?php echo $banco['nombre']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Archivo del Banco (CSV):</label>
                                <input type="file" name="archivo_banco" accept=".csv" required class="form-control">
                                <small>Formato: Fecha | Descripción | Monto | Saldo</small>
                            </div>
                            <div>
                                <button type="submit" name="subir_movimientos" class="btn btn-primary">
                                    <i class="fas fa-upload"></i> Cargar
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Panel de Conciliación Visual -->
                <form method="POST" id="form-conciliacion">
                    <div class="conciliacion-container">
                        <!-- Columna Izquierda: Pagos del Sistema -->
                        <div class="panel">
                            <div class="panel-header">
                                <h4><i class="fas fa-database"></i> Pagos del Sistema</h4>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="seleccionarCoincidencias()">
                                    <i class="fas fa-check-double"></i> Seleccionar Coincidencias
                                </button>
                            </div>
                            <div class="panel-body">
                                <?php foreach ($pagos as $pago): 
                                    $fecha_pago = $pago['fecha_pago'] instanceof DateTime ? 
                                        $pago['fecha_pago']->format('d/m/Y') : 
                                        date('d/m/Y', strtotime($pago['fecha_pago']));
                                    
                                    // Buscar coincidencias
                                    $coincide = false;
                                    foreach ($movimientos as $movimiento) {
                                        if (coinciden($pago, $movimiento)) {
                                            $coincide = true;
                                            break;
                                        }
                                    }
                                ?>
                                <div class="pago-item <?php echo $coincide ? 'coincide' : 'no-coincide'; ?>" 
                                     data-pago-id="<?php echo $pago['id']; ?>"
                                     data-referencia="<?php echo $pago['referencia']; ?>"
                                     data-coincide="<?php echo $coincide ? '1' : '0'; ?>">
                                    
                                    <input type="checkbox" name="pagos_seleccionados[]" value="<?php echo $pago['id']; ?>" 
                                           class="pago-checkbox" onchange="actualizarContadores()"
                                           <?php echo $coincide ? 'checked' : ''; ?>>
                                    
                                    <div class="item-info">
                                        <div class="referencia">
                                            <?php echo $pago['referencia']; ?>
                                            <?php if ($coincide): ?>
                                                <span style="color: #28a745; margin-left: 10px;">
                                                    <i class="fas fa-check-circle"></i> COINCIDE
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #dc3545; margin-left: 10px;">
                                                    <i class="fas fa-times-circle"></i> NO COINCIDE
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="detalles">
                                            Monto: <strong><?php echo (isset($pago['moneda']) && $pago['moneda'] == 'USD') ? '$' : 'Bs '; ?>
                                            <?php echo number_format($pago['monto'], 2); ?></strong> | 
                                            Fecha: <?php echo $fecha_pago; ?> | 
                                            <span class="banco-badge" style="background: <?php echo $pago['banco_color']; ?>">
                                                <?php echo $pago['banco_nombre']; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <?php if (empty($pagos)): ?>
                                    <div style="padding: 40px; text-align: center; color: #6c757d;">
                                        <i class="fas fa-receipt fa-3x"></i>
                                        <p>No hay pagos pendientes de conciliación</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Columna Derecha: Movimientos Bancarios -->
                        <div class="panel">
                            <div class="panel-header">
                                <h4><i class="fas fa-university"></i> Movimientos Bancarios</h4>
                                <span class="badge"><?php echo count($movimientos); ?> movimientos</span>
                            </div>
                            <div class="panel-body">
                                <?php foreach ($movimientos as $movimiento): 
                                    $fecha_mov = $movimiento['fecha_movimiento'] instanceof DateTime ? 
                                        $movimiento['fecha_movimiento']->format('d/m/Y') : 
                                        date('d/m/Y', strtotime($movimiento['fecha_movimiento']));
                                ?>
                                <div class="movimiento-item">
                                    <div class="item-info">
                                        <div class="referencia">
                                            <?php echo $movimiento['referencia']; ?>
                                        </div>
                                        <div class="detalles">
                                            Monto: <strong>Bs <?php echo number_format($movimiento['monto'], 2); ?></strong> | 
                                            Fecha: <?php echo $fecha_mov; ?> | 
                                            <span class="banco-badge" style="background: <?php echo $movimiento['banco_color']; ?>">
                                                <?php echo $movimiento['banco_nombre']; ?>
                                            </span>
                                        </div>
                                        <div style="font-size: 11px; color: #888;">
                                            <?php echo $movimiento['descripcion']; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <?php if (empty($movimientos)): ?>
                                    <div style="padding: 40px; text-align: center; color: #6c757d;">
                                        <i class="fas fa-file-excel fa-3x"></i>
                                        <p>No hay movimientos bancarios cargados</p>
                                        <small>Suba un archivo CSV con los movimientos del banco</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Botones de Acción -->
                    <?php if (!empty($pagos)): ?>
                    <div class="action-buttons">
                        <button type="submit" name="conciliar" class="btn btn-success btn-lg">
                            <i class="fas fa-check-double"></i> Conciliar Seleccionados 
                            (<span id="contador-boton">0</span>)
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="deseleccionarTodo()">
                            <i class="fas fa-times"></i> Deseleccionar Todo
                        </button>
                        <div style="margin-top: 10px;">
                            <small class="text-muted">
                                Los pagos conciliados se marcarán como confirmados y se enviarán notificaciones
                            </small>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>

                <!-- Script para crear tablas si no existen -->
                <?php if (!empty($error) && strpos($error, 'movimientos_banco') !== false): ?>
                <div class="alert alert-warning">
                    <h5>¿Primera vez usando conciliación?</h5>
                    <p>Parece que las tablas necesarias no existen. Ejecuta estos comandos SQL:</p>
                    <pre style="background: #f8f9fa; padding: 15px; border-radius: 5px; font-size: 12px;">
-- Tabla de movimientos bancarios
CREATE TABLE movimientos_banco (
    id INT IDENTITY(1,1) PRIMARY KEY,
    banco_id INT NOT NULL,
    referencia VARCHAR(100),
    descripcion VARCHAR(255),
    monto DECIMAL(15,2) NOT NULL,
    fecha_movimiento DATE NOT NULL,
    conciliado BIT DEFAULT 0,
    fecha_conciliacion DATETIME NULL,
    fecha_registro DATETIME DEFAULT GETDATE()
);

-- Agregar color a bancos si no existe
IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('bancos') AND name = 'color')
BEGIN
    ALTER TABLE bancos ADD color VARCHAR(7) DEFAULT '#6c757d';
END

-- Actualizar bancos con colores
UPDATE bancos SET color = '#dc3545' WHERE nombre = 'Provincial';
UPDATE bancos SET color = '#28a745' WHERE nombre = 'Venezuela'; 
UPDATE bancos SET color = '#17a2b8' WHERE nombre = 'Banesco';
UPDATE bancos SET color = '#6f42c1' WHERE nombre = 'Mercantil';
                    </pre>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function actualizarContadores() {
            const checkboxes = document.querySelectorAll('.pago-checkbox:checked');
            const coincidencias = document.querySelectorAll('.pago-item[data-coincide="1"]');
            const seleccionados = checkboxes.length;
            
            document.getElementById('contador-seleccionados').textContent = seleccionados;
            document.getElementById('contador-coincidencias').textContent = coincidencias.length;
            document.getElementById('contador-boton').textContent = seleccionados;
        }
        
        function seleccionarCoincidencias() {
            document.querySelectorAll('.pago-item[data-coincide="1"] .pago-checkbox').forEach(checkbox => {
                checkbox.checked = true;
            });
            actualizarContadores();
        }
        
        function deseleccionarTodo() {
            document.querySelectorAll('.pago-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
            actualizarContadores();
        }
        
        // Inicializar contadores
        document.addEventListener('DOMContentLoaded', function() {
            actualizarContadores();
        });
    </script>
</body>
</html>