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
        
        // Procesar como CSV simple (como tenías en tu código original)
        if (($handle = fopen($archivo_tmp, 'r')) !== FALSE) {
            $fila = 0;
            $movimientos_insertados = 0;
            
            while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                $fila++;
                if ($fila == 1) continue; // Saltar encabezados
                
                // Formato Provincial: Fecha, Descripción, Monto, Saldo
                if (count($data) >= 4) {
                    $fecha = DateTime::createFromFormat('d/m/Y', $data[0]);
                    $fecha_str = $fecha ? $fecha->format('Y-m-d') : date('Y-m-d');
                    $descripcion = trim($data[1]);
                    $monto = floatval(str_replace(',', '.', str_replace('.', '', $data[2])));
                    $saldo = floatval(str_replace(',', '.', str_replace('.', '', $data[3])));
                    
                    if ($monto > 0) {
                        // Insertar movimiento bancario usando tu estructura
                        $sql = "INSERT INTO movimientos_banco (referencia, monto, banco, fecha_movimiento, descripcion, saldo, archivo_origen) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $referencia = extraerReferencia($descripcion);
                        $params = array($referencia, $monto, $banco_nombre, $fecha_str, $descripcion, $saldo, $nombre_archivo);
                        
                        $stmt = sqlsrv_query($conn, $sql, $params);
                        if ($stmt !== false) {
                            $movimientos_insertados++;
                        }
                    }
                }
            }
            fclose($handle);
            
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

// 3. CONCILIACIÓN MANUAL (como tenías originalmente)
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
        $success = $contador . " pagos conciliados exitosamente";
    } else {
        $error = "Debe seleccionar al menos un pago para conciliar.";
    }
}

// CARGAR DATOS PARA MOSTRAR - CORREGIDO
function cargarDatosConciliacion($conn, &$pagos, &$movimientos, &$conciliados) {
    // Pagos pendientes - CONSULTA CORREGIDA
    $sql_pagos = "SELECT p.*, u.nombre as cliente_nombre 
                  FROM pagos p 
                  LEFT JOIN usuarios u ON p.usuario_id = u.id 
                  WHERE p.estado IN ('pendiente', 'aprobado')
                  ORDER BY p.fecha_pago DESC";
    
    $stmt_pagos = sqlsrv_query($conn, $sql_pagos);
    if ($stmt_pagos !== false) {
        while ($row = sqlsrv_fetch_array($stmt_pagos, SQLSRV_FETCH_ASSOC)) {
            $pagos[] = $row;
        }
        sqlsrv_free_stmt($stmt_pagos);
    }

    // Movimientos no conciliados - CONSULTA CORREGIDA  
    $sql_movimientos = "SELECT * FROM movimientos_banco WHERE conciliado = 0 ORDER BY fecha_movimiento DESC";
    $stmt_movimientos = sqlsrv_query($conn, $sql_movimientos);
    if ($stmt_movimientos !== false) {
        while ($row = sqlsrv_fetch_array($stmt_movimientos, SQLSRV_FETCH_ASSOC)) {
            $movimientos[] = $row;
        }
        sqlsrv_free_stmt($stmt_movimientos);
    }

    // Conciliados recientes - CONSULTA CORREGIDA
    $sql_conciliados = "SELECT TOP 50 p.*, u.nombre as cliente_nombre, mb.referencia as ref_banco, mb.monto as monto_banco
                       FROM pagos p
                       LEFT JOIN usuarios u ON p.usuario_id = u.id
                       LEFT JOIN conciliaciones c ON p.id = c.pago_id
                       LEFT JOIN movimientos_banco mb ON c.movimiento_banco_id = mb.id
                       WHERE p.estado = 'conciliado'
                       ORDER BY p.fecha_conciliacion DESC";
    
    $stmt_conciliados = sqlsrv_query($conn, $sql_conciliados);
    if ($stmt_conciliados !== false) {
        while ($row = sqlsrv_fetch_array($stmt_conciliados, SQLSRV_FETCH_ASSOC)) {
            $conciliados[] = $row;
        }
        sqlsrv_free_stmt($stmt_conciliados);
    }
}

// FUNCIONES DE TU CÓDIGO ORIGINAL
function extraerReferencia($descripcion) {
    // Ejemplo: "ABO.DRV0021061576" -> extraer "0021061576"
    if (preg_match('/DRV(\d+)/', $descripcion, $matches)) {
        return $matches[1];
    }
    // Buscar números de 8-10 dígitos
    if (preg_match('/(\d{8,10})/', $descripcion, $matches)) {
        return $matches[1];
    }
    return substr($descripcion, 0, 20); // Limitar longitud
}

function coinciden($pago, $movimiento) {
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
    $monto_pago = isset($pago['monto_bs_calculado']) ? $pago['monto_bs_calculado'] : $pago['monto'];
    $coincide_monto = abs($monto_pago - $movimiento['monto']) < 1.00;
    
    return $coincide_ref && $coincide_monto;
}

function conciliarIndividual($conn, $pago_id, $movimiento_id, $usuario_id) {
    if (sqlsrv_begin_transaction($conn) === false) {
        return false;
    }
    
    try {
        // 1. Actualizar pago
        $sql_pago = "UPDATE pagos SET estado = 'conciliado', fecha_conciliacion = GETDATE() WHERE id = ?";
        $stmt_pago = sqlsrv_query($conn, $sql_pago, array($pago_id));
        if ($stmt_pago === false) return false;
        
        // 2. Actualizar movimiento bancario
        $sql_mov = "UPDATE movimientos_banco SET conciliado = 1, fecha_conciliacion = GETDATE() WHERE id = ?";
        $stmt_mov = sqlsrv_query($conn, $sql_mov, array($movimiento_id));
        if ($stmt_mov === false) return false;
        
        // 3. Registrar conciliación
        $sql_conc = "INSERT INTO conciliaciones (pago_id, referencia_banco, monto_banco, fecha_movimiento_banco, banco, usuario_id) 
                    VALUES (?, ?, ?, ?, ?, ?)";
        
        // Obtener datos del movimiento para la conciliación
        $sql_mov_data = "SELECT referencia, monto, fecha_movimiento, banco FROM movimientos_banco WHERE id = ?";
        $stmt_mov_data = sqlsrv_query($conn, $sql_mov_data, array($movimiento_id));
        if ($stmt_mov_data === false) return false;
        
        $mov_data = sqlsrv_fetch_array($stmt_mov_data, SQLSRV_FETCH_ASSOC);
        if (!$mov_data) return false;
        
        $stmt_conc = sqlsrv_query($conn, $sql_conc, array(
            $pago_id,
            $mov_data['referencia'],
            $mov_data['monto'],
            $mov_data['fecha_movimiento'],
            $mov_data['banco'],
            $usuario_id
        ));
        if ($stmt_conc === false) return false;
        
        return sqlsrv_commit($conn);
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        return false;
    }
}

// Cargar los datos
cargarDatosConciliacion($conn, $pagos, $movimientos, $conciliados);
?>

<!-- MANTENGO TU HTML ORIGINAL CON LAS MEJORAS DE USABILIDAD -->
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
            min-height: 500px;
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
            cursor: pointer;
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
        .pago-item.selected {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
        }
        .movimiento-item.selected {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
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
        .provincial { background: #dc3545; }
        .venezuela { background: #28a745; }
        .banesco { background: #17a2b8; }
        .mercantil { background: #6f42c1; }
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
        .conciliacion-rapida {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar (MANTENIENDO TU ESTRUCTURA ORIGINAL) -->
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
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
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
                                <select name="banco_nombre" required class="form-control">
                                    <option value="">Seleccionar Banco</option>
                                    <option value="Provincial">Provincial</option>
                                    <option value="Venezuela">Venezuela</option>
                                    <option value="Banesco">Banesco</option>
                                    <option value="Mercantil">Mercantil</option>
                                    <option value="BOD">BOD</option>
                                    <option value="Bancaribe">Bancaribe</option>
                                </select>
                            </div>
                            <div>
                                <label>Archivo del Banco (CSV/Excel):</label>
                                <input type="file" name="archivo_banco" accept=".csv,.xls,.xlsx" required class="form-control">
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

                <!-- Formulario de Conciliación Rápida -->
                <form method="POST" id="form-conciliacion-rapida" class="conciliacion-rapida">
                    <input type="hidden" name="pago_id" id="input-pago-id">
                    <input type="hidden" name="movimiento_id" id="input-movimiento-id">
                    <button type="submit" name="conciliar_rapido" class="btn btn-success">
                        <i class="fas fa-bolt"></i> Conciliar Rápido
                    </button>
                    <button type="button" class="btn btn-warning" onclick="ocultarConciliacionRapida()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </form>

                <!-- Panel de Conciliación Visual -->
                <form method="POST" id="form-conciliacion">
                    <div class="conciliacion-container">
                        <!-- Columna Izquierda: Pagos del Sistema -->
                        <div class="panel">
                            <div class="panel-header">
                                <h4><i class="fas fa-database"></i> Pagos del Sistema (<?php echo count($pagos); ?>)</h4>
                                <div>
                                    <button type="button" class="btn btn-sm btn-success" onclick="seleccionarCoincidencias()">
                                        <i class="fas fa-check-double"></i> Coincidencias
                                    </button>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="seleccionarTodo()">
                                        <i class="fas fa-check-square"></i> Todos
                                    </button>
                                </div>
                            </div>
                            <div class="panel-body">
                                <?php 
                                $coincidencias_totales = 0;
                                foreach ($pagos as $pago): 
                                    $fecha_pago = $pago['fecha_pago'] instanceof DateTime ? 
                                        $pago['fecha_pago']->format('d/m/Y') : 
                                        date('d/m/Y', strtotime($pago['fecha_pago']));
                                    
                                    // Buscar coincidencias
                                    $coincide = false;
                                    $movimientos_coincidentes = [];
                                    foreach ($movimientos as $movimiento) {
                                        if (coinciden($pago, $movimiento)) {
                                            $coincide = true;
                                            $coincidencias_totales++;
                                            $movimientos_coincidentes[] = $movimiento['id'];
                                            break;
                                        }
                                    }
                                ?>
                                <div class="pago-item <?php echo $coincide ? 'coincide' : 'no-coincide'; ?>" 
                                     data-pago-id="<?php echo $pago['id']; ?>"
                                     data-referencia="<?php echo $pago['referencia']; ?>"
                                     data-coincide="<?php echo $coincide ? '1' : '0'; ?>"
                                     data-movimientos-coincidentes="<?php echo implode(',', $movimientos_coincidentes); ?>"
                                     onclick="seleccionarPago(this)">
                                    
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
                                                    <i class="fas fa-times-circle"></i> SIN COINCIDENCIA
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="detalles">
                                            Monto: <strong>
                                                <?php echo ($pago['moneda'] == 'USD') ? '$' : 'Bs '; ?>
                                                <?php echo number_format($pago['monto'], 2); ?>
                                            </strong> | 
                                            Fecha: <?php echo $fecha_pago; ?> | 
                                            Estado: <span class="badge"><?php echo ucfirst($pago['estado']); ?></span>
                                            <?php if (isset($pago['cliente_nombre'])): ?>
                                            | Cliente: <?php echo $pago['cliente_nombre']; ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($pago['moneda'] == 'USD' && isset($pago['monto_bs_calculado'])): ?>
                                        <div class="detalles">
                                            Equivalente: <strong>Bs <?php echo number_format($pago['monto_bs_calculado'], 2); ?></strong>
                                        </div>
                                        <?php endif; ?>
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
                                <h4><i class="fas fa-university"></i> Movimientos Bancarios (<?php echo count($movimientos); ?>)</h4>
                            </div>
                            <div class="panel-body">
                                <?php foreach ($movimientos as $movimiento): 
                                    $fecha_mov = $movimiento['fecha_movimiento'] instanceof DateTime ? 
                                        $movimiento['fecha_movimiento']->format('d/m/Y') : 
                                        date('d/m/Y', strtotime($movimiento['fecha_movimiento']));
                                    $clase_banco = strtolower($movimiento['banco']);
                                ?>
                                <div class="movimiento-item" data-movimiento-id="<?php echo $movimiento['id']; ?>" onclick="seleccionarMovimiento(this)">
                                    <div class="item-info">
                                        <div class="referencia">
                                            <?php echo $movimiento['referencia']; ?>
                                            <span class="banco-badge <?php echo $clase_banco; ?>">
                                                <?php echo $movimiento['banco']; ?>
                                            </span>
                                        </div>
                                        <div class="detalles">
                                            Monto: <strong>Bs <?php echo number_format($movimiento['monto'], 2); ?></strong> | 
                                            Fecha: <?php echo $fecha_mov; ?>
                                        </div>
                                        <div style="font-size: 11px; color: #888; margin-top: 2px;">
                                            <i class="fas fa-file-alt"></i> <?php echo $movimiento['descripcion']; ?>
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
                        <button type="button" class="btn btn-warning" onclick="deseleccionarTodo()">
                            <i class="fas fa-times"></i> Deseleccionar Todo
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <script>
        let pagoSeleccionado = null;
        let movimientoSeleccionado = null;

        function seleccionarPago(elemento) {
            // Limpiar selección anterior
            document.querySelectorAll('.pago-item.selected').forEach(item => {
                item.classList.remove('selected');
            });
            
            // Seleccionar nuevo
            elemento.classList.add('selected');
            pagoSeleccionado = elemento.dataset.pagoId;
            
            // Resaltar movimientos coincidentes
            const movimientosCoincidentes = elemento.dataset.movimientosCoincidentes.split(',');
            document.querySelectorAll('.movimiento-item').forEach(mov => {
                if (movimientosCoincidentes.includes(mov.dataset.movimientoId)) {
                    mov.style.background = '#e8f5e8';
                } else {
                    mov.style.background = '';
                }
            });
            
            mostrarConciliacionRapida();
        }

        function seleccionarMovimiento(elemento) {
            if (!pagoSeleccionado) return;
            
            // Limpiar selección anterior
            document.querySelectorAll('.movimiento-item.selected').forEach(item => {
                item.classList.remove('selected');
            });
            
            // Seleccionar nuevo
            elemento.classList.add('selected');
            movimientoSeleccionado = elemento.dataset.movimientoId;
            
            mostrarConciliacionRapida();
        }

        function mostrarConciliacionRapida() {
            if (pagoSeleccionado && movimientoSeleccionado) {
                document.getElementById('input-pago-id').value = pagoSeleccionado;
                document.getElementById('input-movimiento-id').value = movimientoSeleccionado;
                document.getElementById('form-conciliacion-rapida').style.display = 'block';
            }
        }

        function ocultarConciliacionRapida() {
            document.getElementById('form-conciliacion-rapida').style.display = 'none';
            pagoSeleccionado = null;
            movimientoSeleccionado = null;
            document.querySelectorAll('.selected').forEach(item => {
                item.classList.remove('selected');
            });
            document.querySelectorAll('.movimiento-item').forEach(mov => {
                mov.style.background = '';
            });
        }

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
        
        function seleccionarTodo() {
            document.querySelectorAll('.pago-checkbox').forEach(checkbox => {
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

        // Confirmación para conciliación rápida
        document.getElementById('form-conciliacion-rapida').addEventListener('submit', function(e) {
            if (!confirm('¿Confirmar conciliación rápida?')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>