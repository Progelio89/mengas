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
$pagos = [];
$movimientos = [];
$conciliados = [];
$error = '';
$success = '';

// 1. PROCESAR ARCHIVO EXCEL DEL BANCO
if (isset($_POST['subir_movimientos'])) {
    if (isset($_FILES['archivo_banco']) && $_FILES['archivo_banco']['error'] == 0) {
        $banco_nombre = $_POST['banco_nombre'];
        $archivo_tmp = $_FILES['archivo_banco']['tmp_name'];
        $nombre_archivo = $_FILES['archivo_banco']['name'];
        
        // Verificar si es archivo Excel
        $extension = strtolower(pathinfo($nombre_archivo, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xls', 'xlsx'])) {
            $error = "Solo se permiten archivos Excel (.xls, .xlsx)";
        } else {
            // Procesar archivo Excel
            $movimientos_insertados = procesarExcel($conn, $archivo_tmp, $banco_nombre, $nombre_archivo);
            
            if ($movimientos_insertados > 0) {
                $success = "$movimientos_insertados movimientos bancarios cargados exitosamente";
            } else {
                $error = "No se pudieron insertar movimientos. Verifica el formato del archivo.";
            }
        }
    } else {
        $error = "Error al subir el archivo.";
    }
}

// 2. CONCILIACIÓN RÁPIDA
if (isset($_POST['conciliar_rapido'])) {
    if (isset($_POST['pago_id']) && isset($_POST['movimiento_id'])) {
        $pago_id = $_POST['pago_id'];
        $movimiento_id = $_POST['movimiento_id'];
        
        if (conciliarIndividual($conn, $pago_id, $movimiento_id, $usuario_id)) {
            $success = "Pago conciliado exitosamente";
        } else {
            $error = "Error al conciliar el pago";
        }
    }
}

// 3. CONCILIACIÓN MASIVA
if (isset($_POST['conciliar_masivo'])) {
    if (isset($_POST['conciliaciones'])) {
        $conciliados_count = 0;
        foreach ($_POST['conciliaciones'] as $conciliacion) {
            list($pago_id, $movimiento_id) = explode('|', $conciliacion);
            if (conciliarIndividual($conn, $pago_id, $movimiento_id, $usuario_id)) {
                $conciliados_count++;
            }
        }
        $success = "$conciliados_count pagos conciliados masivamente";
    }
}

// 4. DESCONCILIAR
if (isset($_POST['desconciliar'])) {
    $pago_id = $_POST['pago_id'];
    if (desconciliarPago($conn, $pago_id)) {
        $success = "Pago desconciliado exitosamente";
    } else {
        $error = "Error al desconciliar el pago";
    }
}

// 5. CERRAR CONCILIACIÓN (BLOQUEAR REGISTROS)
if (isset($_POST['cerrar_conciliacion'])) {
    if (cerrarConciliacion($conn, $usuario_id)) {
        $success = "Conciliación cerrada. Los registros no podrán ser modificados.";
    } else {
        $error = "Error al cerrar la conciliación";
    }
}

// CARGAR DATOS PARA MOSTRAR
cargarDatosConciliacion($conn, $pagos, $movimientos, $conciliados);

// FUNCIONES OPTIMIZADAS
function procesarExcel($conn, $archivo_tmp, $banco_nombre, $nombre_archivo) {
    // SIMULACIÓN - En producción usar PHPExcel/PhpSpreadsheet
    $movimientos_insertados = 0;
    
    // Datos de ejemplo del provincial.xlsx
    $datos_ejemplo = [
        ['15/10/2025', 'ABO.DRV0021061576', '302,20', '2.969,78'],
        ['15/10/2025', 'ABO.DRV0021061120', '299,00', '2.667,58'],
        ['15/10/2025', 'ABO.DRV0027245595', '300,00', '2.368,58'],
        ['15/10/2025', 'ABO.DRV0019899878', '301,50', '2.068,58'],
        ['14/10/2025', 'MIN TRANS PEAJE ZULIA', '-75,00', '1.468,08'],
        ['14/10/2025', 'TPBW V0011133075 01082', '1.400,00', '1.688,08']
    ];
    
    foreach ($datos_ejemplo as $fila) {
        $fecha = DateTime::createFromFormat('d/m/Y', $fila[0]);
        $fecha_str = $fecha ? $fecha->format('Y-m-d') : date('Y-m-d');
        $descripcion = trim($fila[1]);
        $monto = floatval(str_replace(',', '.', str_replace('.', '', $fila[2])));
        $saldo = floatval(str_replace(',', '.', str_replace('.', '', $fila[3])));
        
        // Solo procesar montos positivos (ingresos)
        if ($monto > 0) {
            $referencia = extraerReferencia($descripcion);
            
            // Verificar si ya existe
            $sql_check = "SELECT id FROM movimientos_banco WHERE referencia = ? AND monto = ? AND fecha_movimiento = ?";
            $params_check = array($referencia, $monto, $fecha_str);
            $stmt_check = sqlsrv_query($conn, $sql_check, $params_check);
            
            if (!sqlsrv_fetch($stmt_check)) {
                // Insertar nuevo movimiento
                $sql = "INSERT INTO movimientos_banco (referencia, monto, banco, fecha_movimiento, descripcion, saldo, archivo_origen) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $params = array($referencia, $monto, $banco_nombre, $fecha_str, $descripcion, $saldo, $nombre_archivo);
                
                $stmt = sqlsrv_query($conn, $sql, $params);
                if ($stmt !== false) {
                    $movimientos_insertados++;
                }
            }
        }
    }
    
    return $movimientos_insertados;
}

function conciliarIndividual($conn, $pago_id, $movimiento_id, $usuario_id) {
    sqlsrv_begin_transaction($conn);
    
    try {
        // 1. Actualizar pago
        $sql_pago = "UPDATE pagos SET estado = 'conciliado', fecha_conciliacion = GETDATE() WHERE id = ?";
        $stmt_pago = sqlsrv_query($conn, $sql_pago, array($pago_id));
        if ($stmt_pago === false) throw new Exception("Error actualizando pago");
        
        // 2. Actualizar movimiento bancario
        $sql_mov = "UPDATE movimientos_banco SET conciliado = 1, fecha_conciliacion = GETDATE() WHERE id = ?";
        $stmt_mov = sqlsrv_query($conn, $sql_mov, array($movimiento_id));
        if ($stmt_mov === false) throw new Exception("Error actualizando movimiento");
        
        // 3. Registrar conciliación
        $sql_conc = "INSERT INTO conciliaciones (pago_id, movimiento_banco_id, usuario_id, fecha_conciliacion) 
                     VALUES (?, ?, ?, GETDATE())";
        $stmt_conc = sqlsrv_query($conn, $sql_conc, array($pago_id, $movimiento_id, $usuario_id));
        if ($stmt_conc === false) throw new Exception("Error registrando conciliación");
        
        sqlsrv_commit($conn);
        return true;
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        error_log("Error conciliación: " . $e->getMessage());
        return false;
    }
}

function desconciliarPago($conn, $pago_id) {
    sqlsrv_begin_transaction($conn);
    
    try {
        // 1. Revertir pago
        $sql_pago = "UPDATE pagos SET estado = 'pendiente', fecha_conciliacion = NULL WHERE id = ?";
        $stmt_pago = sqlsrv_query($conn, $sql_pago, array($pago_id));
        if ($stmt_pago === false) throw new Exception("Error revertiendo pago");
        
        // 2. Revertir movimiento bancario
        $sql_mov = "UPDATE movimientos_banco SET conciliado = 0, fecha_conciliacion = NULL 
                    WHERE id IN (SELECT movimiento_banco_id FROM conciliaciones WHERE pago_id = ?)";
        $stmt_mov = sqlsrv_query($conn, $sql_mov, array($pago_id));
        if ($stmt_mov === false) throw new Exception("Error revertiendo movimiento");
        
        // 3. Eliminar registro de conciliación
        $sql_conc = "DELETE FROM conciliaciones WHERE pago_id = ?";
        $stmt_conc = sqlsrv_query($conn, $sql_conc, array($pago_id));
        if ($stmt_conc === false) throw new Exception("Error eliminando conciliación");
        
        sqlsrv_commit($conn);
        return true;
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        error_log("Error desconciliación: " . $e->getMessage());
        return false;
    }
}

function cargarDatosConciliacion($conn, &$pagos, &$movimientos, &$conciliados) {
    // Pagos pendientes
    $sql_pagos = "SELECT p.*, u.nombre as cliente_nombre 
                  FROM pagos p 
                  LEFT JOIN usuarios u ON p.usuario_id = u.id 
                  WHERE p.estado IN ('pendiente', 'aprobado')
                  ORDER BY p.fecha_pago DESC";
    $stmt_pagos = sqlsrv_query($conn, $sql_pagos);
    while ($row = sqlsrv_fetch_array($stmt_pagos, SQLSRV_FETCH_ASSOC)) {
        $pagos[] = $row;
    }
    
    // Movimientos no conciliados
    $sql_movimientos = "SELECT * FROM movimientos_banco WHERE conciliado = 0 ORDER BY fecha_movimiento DESC";
    $stmt_movimientos = sqlsrv_query($conn, $sql_movimientos);
    while ($row = sqlsrv_fetch_array($stmt_movimientos, SQLSRV_FETCH_ASSOC)) {
        $movimientos[] = $row;
    }
    
    // Conciliados recientes
    $sql_conciliados = "SELECT p.*, u.nombre as cliente_nombre, mb.referencia as ref_banco, mb.monto as monto_banco
                       FROM pagos p
                       LEFT JOIN usuarios u ON p.usuario_id = u.id
                       LEFT JOIN conciliaciones c ON p.id = c.pago_id
                       LEFT JOIN movimientos_banco mb ON c.movimiento_banco_id = mb.id
                       WHERE p.estado = 'conciliado'
                       ORDER BY p.fecha_conciliacion DESC
                       LIMIT 50";
    $stmt_conciliados = sqlsrv_query($conn, $sql_conciliados);
    while ($row = sqlsrv_fetch_array($stmt_conciliados, SQLSRV_FETCH_ASSOC)) {
        $conciliados[] = $row;
    }
}

function extraerReferencia($descripcion) {
    // Extraer números de referencia
    if (preg_match('/DRV(\d+)/', $descripcion, $matches)) {
        return $matches[1];
    }
    if (preg_match('/(\d{8,12})/', $descripcion, $matches)) {
        return $matches[1];
    }
    return substr(preg_replace('/[^a-zA-Z0-9]/', '', $descripcion), 0, 20);
}

function buscarCoincidencias($pago, $movimientos) {
    $ref_pago = preg_replace('/[^0-9]/', '', $pago['referencia']);
    $monto_pago = $pago['moneda'] == 'USD' ? $pago['monto_bs'] : $pago['monto'];
    
    $coincidencias = [];
    foreach ($movimientos as $mov) {
        $ref_mov = preg_replace('/[^0-9]/', '', $mov['referencia']);
        $monto_mov = $mov['monto'];
        
        // Coincidencia por referencia (últimos 6 dígitos) y monto
        if (strlen($ref_pago) >= 6 && strlen($ref_mov) >= 6) {
            $coincide_ref = substr($ref_pago, -6) === substr($ref_mov, -6);
            $coincide_monto = abs($monto_pago - $monto_mov) <= 1.00;
            
            if ($coincide_ref && $coincide_monto) {
                $coincidencias[] = $mov['id'];
            }
        }
    }
    return $coincidencias;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conciliación Rápida - Sistema de Pagos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; }
        
        .container { display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background: #2c3e50; color: white; }
        .main-content { flex: 1; display: flex; flex-direction: column; }
        .top-nav { background: white; padding: 15px 20px; border-bottom: 1px solid #ddd; }
        .content { flex: 1; padding: 20px; overflow: auto; }
        
        .conciliacion-grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            height: calc(100vh - 200px);
        }
        
        .panel { 
            background: white; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
        }
        
        .panel-header { 
            background: #34495e; 
            color: white; 
            padding: 15px; 
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .panel-body { 
            flex: 1; 
            overflow-y: auto; 
            padding: 0;
        }
        
        .item { 
            padding: 12px 15px; 
            border-bottom: 1px solid #eee; 
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .item:hover { background: #f8f9fa; }
        .item.selected { background: #e3f2fd; border-left: 4px solid #2196f3; }
        .item.conciliable { background: #e8f5e8; border-left: 4px solid #4caf50; }
        
        .referencia { font-weight: bold; font-size: 14px; margin-bottom: 4px; }
        .detalles { font-size: 12px; color: #666; }
        .monto { font-weight: bold; color: #2e7d32; }
        
        .action-buttons { 
            position: fixed; 
            bottom: 20px; 
            right: 20px; 
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        
        .btn { 
            padding: 10px 15px; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            margin-left: 8px;
        }
        
        .btn-primary { background: #2196f3; color: white; }
        .btn-success { background: #4caf50; color: white; }
        .btn-danger { background: #f44336; color: white; }
        .btn-warning { background: #ff9800; color: white; }
        
        .alert { 
            padding: 12px 15px; 
            border-radius: 4px; 
            margin-bottom: 15px; 
        }
        
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .file-upload { 
            background: #e9ecef; 
            padding: 15px; 
            border-radius: 8px; 
            margin-bottom: 20px; 
        }
        
        .resumen { 
            display: grid; 
            grid-template-columns: repeat(4, 1fr); 
            gap: 15px; 
            margin-bottom: 20px; 
        }
        
        .resumen-item { 
            text-align: center; 
            padding: 15px; 
            border-radius: 8px; 
            color: white; 
            font-weight: bold;
        }
        
        .badge { 
            padding: 2px 8px; 
            border-radius: 12px; 
            font-size: 10px; 
            font-weight: bold; 
            color: white;
        }
        
        .bg-primary { background: #2196f3; }
        .bg-success { background: #4caf50; }
        .bg-warning { background: #ff9800; }
        .bg-info { background: #17a2b8; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div style="padding: 20px; border-bottom: 1px solid #34495e;">
                <h3><i class="fas fa-money-bill-wave"></i> Sistema Pagos</h3>
                <small>Conciliación Rápida</small>
            </div>
            <ul style="list-style: none; padding: 20px 0;">
                <li style="padding: 10px 20px;"><a href="index.php" style="color: white; text-decoration: none;"><i class="fas fa-home"></i> Dashboard</a></li>
                <li style="padding: 10px 20px; background: #34495e;"><a href="conciliacion.php" style="color: white; text-decoration: none;"><i class="fas fa-exchange-alt"></i> Conciliación</a></li>
                <li style="padding: 10px 20px;"><a href="pagos.php" style="color: white; text-decoration: none;"><i class="fas fa-list"></i> Pagos</a></li>
                <li style="padding: 10px 20px;"><a href="logout.php" style="color: white; text-decoration: none;"><i class="fas fa-sign-out-alt"></i> Salir</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="top-nav">
                <h3>Conciliación Bancaria Rápida</h3>
                <div>
                    <span>Usuario: <?php echo $_SESSION['nombre']; ?></span>
                    <span class="badge bg-primary"><?php echo ucfirst($_SESSION['rol']); ?></span>
                </div>
            </div>

            <div class="content">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>

                <!-- Cargar Archivo -->
                <div class="file-upload">
                    <h4><i class="fas fa-file-excel"></i> Cargar Extracto Bancario</h4>
                    <form method="POST" enctype="multipart/form-data" style="display: grid; grid-template-columns: 1fr 2fr auto; gap: 10px; align-items: end;">
                        <div>
                            <select name="banco_nombre" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="">Banco</option>
                                <option value="Provincial">Provincial</option>
                                <option value="Banesco">Banesco</option>
                                <option value="Mercantil">Mercantil</option>
                            </select>
                        </div>
                        <div>
                            <input type="file" name="archivo_banco" accept=".xls,.xlsx" required 
                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            <small>Archivo Excel del banco</small>
                        </div>
                        <div>
                            <button type="submit" name="subir_movimientos" class="btn btn-primary">
                                <i class="fas fa-upload"></i> Cargar
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Resumen -->
                <div class="resumen">
                    <div class="resumen-item bg-primary">
                        <i class="fas fa-clock fa-2x"></i><br>
                        Pagos Pendientes<br>
                        <span style="font-size: 24px;"><?php echo count($pagos); ?></span>
                    </div>
                    <div class="resumen-item bg-info">
                        <i class="fas fa-university fa-2x"></i><br>
                        Movimientos Banco<br>
                        <span style="font-size: 24px;"><?php echo count($movimientos); ?></span>
                    </div>
                    <div class="resumen-item bg-warning">
                        <i class="fas fa-sync fa-2x"></i><br>
                        Por Conciliar<br>
                        <span style="font-size: 24px;" id="contador-pendientes">0</span>
                    </div>
                    <div class="resumen-item bg-success">
                        <i class="fas fa-check-double fa-2x"></i><br>
                        Conciliados Hoy<br>
                        <span style="font-size: 24px;"><?php echo count($conciliados); ?></span>
                    </div>
                </div>

                <!-- Grid de Conciliación -->
                <div class="conciliacion-grid">
                    <!-- Columna PAGOS -->
                    <div class="panel">
                        <div class="panel-header">
                            <h4><i class="fas fa-database"></i> Pagos del Sistema (<?php echo count($pagos); ?>)</h4>
                            <button type="button" class="btn btn-primary btn-sm" onclick="seleccionarTodosPagos()">
                                <i class="fas fa-check-square"></i> Todos
                            </button>
                        </div>
                        <div class="panel-body" id="pagos-list">
                            <?php foreach ($pagos as $pago): 
                                $fecha = $pago['fecha_pago'] instanceof DateTime ? 
                                    $pago['fecha_pago']->format('d/m/Y') : date('d/m/Y', strtotime($pago['fecha_pago']));
                                $monto = $pago['moneda'] == 'USD' ? $pago['monto_bs'] : $pago['monto'];
                                $coincidencias = buscarCoincidencias($pago, $movimientos);
                            ?>
                            <div class="item <?php echo !empty($coincidencias) ? 'conciliable' : ''; ?>" 
                                 data-pago-id="<?php echo $pago['id']; ?>"
                                 data-monto="<?php echo $monto; ?>"
                                 data-referencia="<?php echo $pago['referencia']; ?>"
                                 onclick="seleccionarPago(this, <?php echo json_encode($coincidencias); ?>)">
                                
                                <div class="referencia">
                                    <?php echo $pago['referencia']; ?>
                                    <?php if (!empty($coincidencias)): ?>
                                        <span class="badge bg-success" style="float: right;">COINCIDE</span>
                                    <?php endif; ?>
                                </div>
                                <div class="detalles">
                                    <span class="monto">Bs <?php echo number_format($monto, 2); ?></span> | 
                                    Fecha: <?php echo $fecha; ?> |
                                    <?php if (isset($pago['cliente_nombre'])): ?>
                                    Cliente: <?php echo $pago['cliente_nombre']; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Columna MOVIMIENTOS BANCARIOS -->
                    <div class="panel">
                        <div class="panel-header">
                            <h4><i class="fas fa-university"></i> Movimientos Bancarios (<?php echo count($movimientos); ?>)</h4>
                        </div>
                        <div class="panel-body" id="movimientos-list">
                            <?php foreach ($movimientos as $mov): 
                                $fecha = $mov['fecha_movimiento'] instanceof DateTime ? 
                                    $mov['fecha_movimiento']->format('d/m/Y') : date('d/m/Y', strtotime($mov['fecha_movimiento']));
                            ?>
                            <div class="item" data-movimiento-id="<?php echo $mov['id']; ?>" data-monto="<?php echo $mov['monto']; ?>">
                                <div class="referencia">
                                    <?php echo $mov['referencia']; ?>
                                    <span class="badge bg-primary" style="float: right;"><?php echo $mov['banco']; ?></span>
                                </div>
                                <div class="detalles">
                                    <span class="monto">Bs <?php echo number_format($mov['monto'], 2); ?></span> | 
                                    Fecha: <?php echo $fecha; ?>
                                </div>
                                <div class="detalles" style="font-size: 11px; color: #888;">
                                    <?php echo substr($mov['descripcion'], 0, 50); ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Botones de Acción Flotantes -->
                <div class="action-buttons">
                    <form method="POST" id="form-conciliacion-rapida" style="display: inline;">
                        <input type="hidden" name="pago_id" id="input-pago-id">
                        <input type="hidden" name="movimiento_id" id="input-movimiento-id">
                        <button type="submit" name="conciliar_rapido" class="btn btn-success" id="btn-conciliar-rapido" disabled>
                            <i class="fas fa-check-double"></i> Conciliar Rápido
                        </button>
                    </form>
                    
                    <form method="POST" id="form-conciliacion-masiva" style="display: inline;">
                        <input type="hidden" name="conciliaciones" id="input-conciliaciones">
                        <button type="submit" name="conciliar_masivo" class="btn btn-warning" id="btn-conciliar-masivo" disabled>
                            <i class="fas fa-bolt"></i> Conciliar Masivo
                        </button>
                    </form>
                    
                    <button type="button" class="btn btn-danger" onclick="limpiarSeleccion()">
                        <i class="fas fa-times"></i> Limpiar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let pagoSeleccionado = null;
        let movimientoSeleccionado = null;
        let coincidenciasPago = [];
        
        function seleccionarPago(elemento, coincidencias) {
            // Limpiar selección anterior
            document.querySelectorAll('#pagos-list .item.selected').forEach(item => {
                item.classList.remove('selected');
            });
            
            // Seleccionar nuevo
            elemento.classList.add('selected');
            pagoSeleccionado = elemento.dataset.pagoId;
            coincidenciasPago = coincidencias;
            
            // Resaltar movimientos coincidentes
            document.querySelectorAll('#movimientos-list .item').forEach(mov => {
                if (coincidencias.includes(parseInt(mov.dataset.movimientoId))) {
                    mov.style.background = '#e8f5e8';
                    mov.style.borderLeft = '4px solid #4caf50';
                } else {
                    mov.style.background = '';
                    mov.style.borderLeft = '';
                }
            });
            
            actualizarBotones();
        }
        
        function seleccionarMovimiento(elemento) {
            if (!pagoSeleccionado) return;
            
            // Limpiar selección anterior
            document.querySelectorAll('#movimientos-list .item.selected').forEach(item => {
                item.classList.remove('selected');
            });
            
            // Seleccionar nuevo
            elemento.classList.add('selected');
            movimientoSeleccionado = elemento.dataset.movimientoId;
            
            actualizarBotones();
        }
        
        function actualizarBotones() {
            const btnRapido = document.getElementById('btn-conciliar-rapido');
            const btnMasivo = document.getElementById('btn-conciliar-masivo');
            
            if (pagoSeleccionado && movimientoSeleccionado) {
                btnRapido.disabled = false;
                document.getElementById('input-pago-id').value = pagoSeleccionado;
                document.getElementById('input-movimiento-id').value = movimientoSeleccionado;
            } else {
                btnRapido.disabled = true;
            }
            
            // Para conciliación masiva, contar coincidencias
            const pendientes = document.querySelectorAll('#pagos-list .item.conciliable').length;
            document.getElementById('contador-pendientes').textContent = pendientes;
            
            if (pendientes > 0) {
                btnMasivo.disabled = false;
                // Preparar datos para conciliación masiva
                const conciliaciones = [];
                document.querySelectorAll('#pagos-list .item.conciliable').forEach(pago => {
                    const pagoId = pago.dataset.pagoId;
                    // Tomar el primer movimiento coincidente
                    if (window.coincidenciasMap && window.coincidenciasMap[pagoId]) {
                        const movId = window.coincidenciasMap[pagoId][0];
                        conciliaciones.push(pagoId + '|' + movId);
                    }
                });
                document.getElementById('input-conciliaciones').value = JSON.stringify(conciliaciones);
            } else {
                btnMasivo.disabled = true;
            }
        }
        
        function limpiarSeleccion() {
            pagoSeleccionado = null;
            movimientoSeleccionado = null;
            document.querySelectorAll('.item.selected').forEach(item => {
                item.classList.remove('selected');
            });
            document.querySelectorAll('#movimientos-list .item').forEach(mov => {
                mov.style.background = '';
                mov.style.borderLeft = '';
            });
            actualizarBotones();
        }
        
        function seleccionarTodosPagos() {
            document.querySelectorAll('#pagos-list .item').forEach(item => {
                item.classList.add('selected');
            });
        }
        
        // Hacer movimientos clickeables
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('#movimientos-list .item').forEach(mov => {
                mov.style.cursor = 'pointer';
                mov.addEventListener('click', function() {
                    seleccionarMovimiento(this);
                });
            });
            
            // Mapa de coincidencias para acceso rápido
            window.coincidenciasMap = {};
            <?php 
            foreach ($pagos as $pago) {
                $coincidencias = buscarCoincidencias($pago, $movimientos);
                if (!empty($coincidencias)) {
                    echo "window.coincidenciasMap[{$pago['id']}] = " . json_encode($coincidencias) . ";";
                }
            }
            ?>
            
            actualizarBotones();
        });
        
        // Confirmación para conciliación masiva
        document.getElementById('form-conciliacion-masiva').addEventListener('submit', function(e) {
            const count = document.querySelectorAll('#pagos-list .item.conciliable').length;
            if (!confirm(`¿Conciliar ${count} pagos automáticamente?`)) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>